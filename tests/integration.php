<?php
declare(strict_types=1);
require __DIR__ . '/run.php';
require __DIR__ . '/../src/StateStore.php';
require __DIR__ . '/../src/ActionCoordinator.php';
use RecoveryGuard\RecoveryPolicy;
use RecoveryGuard\StateStore;
use RecoveryGuard\ActionCoordinator;

$directories = [];
function directoryForTest(): string {
    global $directories;
    $path = sys_get_temp_dir() . '/recovery-guard-test-' . bin2hex(random_bytes(8));
    mkdir($path, 0700); $directories[] = $path; return $path;
}
function mustThrow(Closure $operation, string $label): void {
    try { $operation(); } catch (Throwable) { check(true, $label); return; }
    check(false, $label);
}
// Windows does not supply the target filesystem's directory-fsync semantics.
// Its injected no-op is ONLY for logic tests. FreeBSD/Linux use the real operation.
$sync = PHP_OS_FAMILY === 'Windows' ? static function (string $path): void {} : null;
$dir = directoryForTest(); $store = new StateStore($dir, $sync);
mustThrow(fn() => $store->read(), 'read requires journal lock');
mustThrow(fn() => $store->exclusive(fn($s) => $s->read()), 'missing journal is not empty history');
$store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
mustThrow(fn() => $store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision())), 'initialization cannot reset budget');
check($store->exclusive(fn($s) => $s->read()) === RecoveryPolicy::provision(), 'journal round trip');
$other = new StateStore($dir, $sync);
$store->exclusive(function () use ($other): void {
    mustThrow(fn() => $other->exclusive(fn() => null), 'concurrent supervisor cannot own action lock');
});
$time = 1000; $executions = []; $evidence = [];
$interlocks = ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false,
    'other_repair' => false, 'shutting_down' => false, 'boot_id' => 'boot-a'];
$guard = new ActionCoordinator(new RecoveryPolicy(), $store,
    fn() => $interlocks,
    function ($proposal) use (&$evidence): void { $evidence[] = $proposal['id']; },
    function ($proposal) use (&$executions, &$evidence, $dir): array {
        $disk = json_decode(file_get_contents($dir . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        check($disk['episode']['repair_request'] !== null, 'repair reservation on disk before executor');
        if ($proposal['kind'] === 'reboot') check($disk['reboot_times'] !== [], 'reboot reservation on disk before executor');
        check(in_array($proposal['id'], $evidence, true), 'evidence captured before executor');
        $executions[] = $proposal['kind'];
        return ['id' => $proposal['id'], 'outcome' => 'completed'];
    }, function () use (&$time): int { return $time; });
for ($time = 1000; $time <= 1900; $time += 30) $guard->tick(sample($time), 'recover');
check($executions === ['repair_php_fpm', 'reboot'], 'end-to-end fake executor sees one repair and one reboot');
$saved = $store->exclusive(fn($s) => $s->read());
check(count($saved['reboot_times']) === 1, 'reboot budget durably retained');
// Simulated process restart reads the same journal, not a new empty budget.
check((new StateStore($dir, $sync))->exclusive(fn($s) => $s->read()) === $saved, 'new supervisor observes prior action ledger');

foreach (['monitor', 'repair'] as $mode) {
    $d = directoryForTest(); $st = new StateStore($d, $sync);
    $st->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
    $ran = [];
    $g = new ActionCoordinator(new RecoveryPolicy(), $st, fn() => $interlocks, fn() => null,
        function ($p) use (&$ran): array { $ran[] = $p['kind']; return ['id' => $p['id'], 'outcome' => 'completed']; },
        function () use (&$time): int { return $time; });
    for ($time = 1000; $time <= 1900; $time += 30) $g->tick(sample($time), $mode);
    check($ran === ($mode === 'monitor' ? [] : ['repair_php_fpm']), 'operating mode gate: ' . $mode);
}
foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $inhibit) {
    $d = directoryForTest(); $st = new StateStore($d, $sync);
    $st->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
    $ran = 0; $changed = array_replace($interlocks, [$inhibit => true]);
    $g = new ActionCoordinator(new RecoveryPolicy(), $st, fn() => $changed, fn() => null,
        function () use (&$ran): array { $ran++; return []; }, function () use (&$time): int { return $time; });
    for ($time = 1000; $time <= 1150; $time += 30) $r = $g->tick(sample($time), 'recover');
    check($ran === 0, 'fresh action interlock: ' . $inhibit);
}
// Failure after atomic rename is an uncertain commit, never permission to execute.
$d = directoryForTest(); $st = new StateStore($d, $sync);
$st->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
$broken = new StateStore($d, static function (): void { throw new RuntimeException('injected directory sync failure'); });
$ran = 0; $time = 1000;
$g = new ActionCoordinator(new RecoveryPolicy(), $broken, fn() => $interlocks, fn() => null,
    function () use (&$ran): array { $ran++; return []; }, fn() => 1000);
mustThrow(fn() => $g->tick(sample(1000), 'recover'), 'durability failure bubbles up');
check($ran === 0, 'durability failure never invokes executor');
// Fail exactly at the action reservation, after earlier observation commits succeeded.
$d2 = directoryForTest(); $st2 = new StateStore($d2, $sync);
$st2->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
$failAtReservation = new StateStore($d2, static function (string $path) use ($sync): void {
    $state = json_decode(file_get_contents($path . '/state.json'), true);
    if (($state['episode']['repair_request'] ?? null) !== null) throw new RuntimeException('injected action-commit uncertainty');
    if ($sync !== null) $sync($path);
    else { $h = fopen($path, 'r'); try { if (!fsync($h)) throw new RuntimeException('fsync'); } finally { fclose($h); } }
});
$ran = 0;
$g = new ActionCoordinator(new RecoveryPolicy(), $failAtReservation, fn() => $interlocks, fn() => null,
    function () use (&$ran): array { $ran++; return []; }, function () use (&$time): int { return $time; });
for ($time = 1000; $time < 1120; $time += 30) $g->tick(sample($time), 'recover');
$time = 1120;
mustThrow(fn() => $g->tick(sample(1120), 'recover'), 'uncertain reservation blocks the pending action');
check($ran === 0, 'no execution after uncertain action commit');
$persisted = $st2->exclusive(fn($s) => $s->read());
check($persisted['repair_times'] === [1120], 'uncertain action still consumes its durable reservation');
$g = new ActionCoordinator(new RecoveryPolicy(), $st2, fn() => $interlocks, fn() => null,
    function () use (&$ran): array { $ran++; return []; }, function () use (&$time): int { return $time; });
for ($time = 1150; $time <= 1900; $time += 30) $g->tick(sample($time), 'recover');
check($ran === 0, 'supervisor restart never retries uncertain reserved action');

// Recheck inhibits between evidence capture and execution, closing a common race.
$d3 = directoryForTest(); $st3 = new StateStore($d3, $sync);
$st3->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
$maintenanceNow = false; $ran = 0;
$g = new ActionCoordinator(new RecoveryPolicy(), $st3,
    function () use (&$maintenanceNow, $interlocks): array { return array_replace($interlocks, ['maintenance' => $maintenanceNow]); },
    function () use (&$maintenanceNow): void { $maintenanceNow = true; },
    function () use (&$ran): array { $ran++; return []; }, function () use (&$time): int { return $time; });
for ($time = 1000; $time <= 1120; $time += 30) $r = $g->tick(sample($time), 'recover');
check($ran === 0 && $r['execution'] === 'interlock_inhibited', 'maintenance entered during capture blocks action');

// A change from monitor mode must not leave the new active mode waiting for an imaginary receipt.
$d4 = directoryForTest(); $st4 = new StateStore($d4, $sync);
$st4->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
$ran = [];
$g = new ActionCoordinator(new RecoveryPolicy(), $st4, fn() => $interlocks, fn() => null,
    function ($p) use (&$ran): array { $ran[] = $p['kind']; return ['id' => $p['id'], 'outcome' => 'completed']; },
    function () use (&$time): int { return $time; });
for ($time = 1000; $time <= 1900; $time += 30) $g->tick(sample($time), 'monitor');
for ($time = 1930; $time <= 2860; $time += 30) $g->tick(sample($time), 'recover');
check($ran === ['repair_php_fpm', 'reboot'], 'mode change reconfirms and can recover after conservative cooldown');
file_put_contents($d . '/state.json', '{broken');
mustThrow(fn() => $st->exclusive(fn($s) => $s->read()), 'corrupt JSON is not empty history');
unlink($d . '/state.json');
mustThrow(fn() => $st->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision())), 'lost journal cannot erase initialization marker');
foreach ($directories as $path) {
    // Only the random directories created by this test, never user-supplied paths.
    foreach (glob($path . '/*') as $file) unlink($file);
    rmdir($path);
}
echo 'PASS: ' . $checks . ' total policy/integration checks; directory durability=' .
    (PHP_OS_FAMILY === 'Windows' ? 'simulated on Windows' : 'native fsync') . "; fake executors only.\n";
