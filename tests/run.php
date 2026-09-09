<?php
declare(strict_types=1);
require __DIR__ . '/../src/RecoveryPolicy.php';
use RecoveryGuard\RecoveryPolicy;

$checks = 0;
function check(bool $test, string $name): void {
    global $checks;
    if (!$test) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
}
function sample(int $time, array $changes = []): array {
    return array_replace(['time' => $time, 'boot_id' => 'boot-a', 'uptime' => 3600,
        'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false,
        'log_storm' => true, 'maintenance' => false, 'upgrade' => false], $changes);
}
function runCase(array $changes = [], bool $receipt = true, ?array $initial = null, int $offset = 1000): array {
    $policy = new RecoveryPolicy(); $state = $initial ?? RecoveryPolicy::provision(); $proposals = [];
    for ($t = $offset; $t <= $offset + 900; $t += 30) {
        $result = $policy->step($state, sample($t, $changes)); $state = $result['state'];
        if ($result['proposal']) {
            $proposals[] = $result['proposal']['kind'];
            if ($receipt && $result['proposal']['kind'] === 'repair_php_fpm') {
                // Synthetic test receipt, never an actual service restart.
                $state = $policy->acknowledgeRepair($state, $result['proposal']['id'], $t);
            }
        }
    }
    return [$proposals, $state, $result];
}
$policy = new RecoveryPolicy();
[$actions, $ledger] = runCase();
check($actions === ['repair_php_fpm', 'reboot'], 'persistent combined fault escalates once');
check(count($ledger['reboot_times']) === 1, 'reboot reservation retained');
check(runCase([], false)[0] === ['repair_php_fpm'], 'no reboot without repair receipt');
check(runCase(['php_ok' => true, 'log_storm' => false])[0] === [], 'unplugged link alone does not reboot');
check(runCase(['critical_link_up' => true, 'local_reachable' => true])[0] === ['repair_php_fpm'], 'GUI failure with working LAN does not reboot');
check(runCase(['php_ok' => true, 'critical_link_up' => true, 'local_reachable' => true])[0] === [], 'healthy system does nothing');
check(runCase(['maintenance' => true])[0] === [], 'maintenance inhibits actions');
check(runCase(['upgrade' => true])[0] === [], 'upgrade inhibits actions');
check(runCase(['uptime' => 599])[0] === [], 'boot grace inhibits actions');
foreach (['php_ok', 'critical_link_up', 'local_reachable', 'log_storm'] as $key) {
    check(runCase([$key => null])[0] === [], 'unknown probe inhibits: ' . $key);
}
check($policy->step(null, sample(1000))['proposal'] === null, 'missing ledger fails closed');
check($policy->step(['version' => 999], sample(1000))['reason'] === 'invalid_or_missing_ledger', 'wrong ledger version fails closed');
$bad = RecoveryPolicy::provision(); $bad['reboot_times'] = ['invalid'];
check($policy->step($bad, sample(1000))['proposal'] === null, 'corrupt budget fails closed');
$bad = RecoveryPolicy::provision(); $bad['episode'] = ['since' => 'oops'];
check($policy->step($bad, sample(1000))['reason'] === 'invalid_or_missing_ledger', 'corrupt episode fails closed');
$newBoot = runCase(['boot_id' => 'boot-b'], true, $ledger, 2000);
check($newBoot[0] === ['repair_php_fpm'], 'budget survives an operating system reboot');
check($newBoot[2]['reason'] === 'reboot_budget_exhausted', 'budget exhaustion is explicit');
check(runCase(['boot_id' => 'boot-c'], true, $ledger, 100000)[0] === ['repair_php_fpm', 'reboot'], 'budget expires after configured window');
$r = $policy->step(RecoveryPolicy::provision(), sample(1000));
check($policy->step($r['state'], sample(999))['reason'] === 'clock_or_duplicate_sample', 'clock rollback blocks');
check($policy->step($r['state'], sample(1000))['reason'] === 'clock_or_duplicate_sample', 'duplicate sample cannot accumulate evidence');
$r = $policy->step($r['state'], sample(1200));
check($r['proposal'] === null && $r['state']['episode']['since'] === 1200, 'sampling gap restarts confirmation');
$s = RecoveryPolicy::provision();
for ($t = 1000; $t <= 1090; $t += 30) $s = $policy->step($s, sample($t))['state'];
$r = $policy->step($s, sample(1120, ['php_ok' => true]));
check($r['proposal'] === null, 'transient failure recovers before threshold');
$r = $policy->step($r['state'], sample(1150));
check($r['proposal'] === null && $r['state']['episode']['since'] === 1150, 'flapping cannot accumulate old failure duration');
$s = RecoveryPolicy::provision();
for ($t = 1000; $t <= 1180; $t += 30) {
    $r = $policy->step($s, sample($t)); $s = $r['state'];
    if ($r['proposal']) $s = $policy->acknowledgeRepair($s, $r['proposal']['id'], $t);
}
for ($t = 1210; $t <= 1330; $t += 30) {
    $r = $policy->step($s, sample($t, ['php_ok' => true])); $s = $r['state'];
}
check($s['episode'] === null, 'sustained service recovery clears episode');
check($s['reboot_times'] === [], 'successful repair does not spend reboot budget');
try { $policy->acknowledgeRepair($s, 'forged', 1330); check(false, 'reject forged receipt'); }
catch (InvalidArgumentException) { check(true, 'reject forged receipt'); }
foreach ([['php_ok' => 'false'], ['maintenance' => null], ['time' => -1], ['boot_id' => ';reboot']] as $bad) {
    check($policy->step(RecoveryPolicy::provision(), sample(1000, $bad))['proposal'] === null, 'malformed sensor data fails closed');
}
try { new RecoveryPolicy(['reboot_after' => 60]); check(false, 'unsafe ordering'); }
catch (InvalidArgumentException) { check(true, 'reject unsafe threshold order'); }
try { new RecoveryPolicy(['unknown' => 1]); check(false, 'unknown config'); }
catch (InvalidArgumentException) { check(true, 'reject misspelled config'); }
[$actions, $s] = runCase(['local_reachable' => true]);
for ($t = 1930; $t <= 2080; $t += 30) $s = $policy->step($s, sample($t, ['php_ok' => true]))['state'];
// New fault before repair-window expiry must not cause an unbounded restart series.
$limited = new RecoveryPolicy(['repair_window' => 10000]);
for ($t = 2110; $t <= 2350; $t += 30) { $r = $limited->step($s, sample($t)); $s = $r['state']; }
check($r['proposal'] === null && $r['reason'] === 'repair_budget_exhausted', 'repeated incident cannot bypass service repair budget');
$s = RecoveryPolicy::provision();
for ($t = 1000; $t <= 1150; $t += 30) {
    $r = $policy->step($s, sample($t)); $s = $r['state'];
    if ($r['proposal']) $s = $policy->acknowledgeRepair($s, $r['proposal']['id'], $t + 5);
}
check($policy->acceptsState($s), 'non-instant repair completion remains a valid journal');
$s = $policy->step($s, sample(1180, ['php_ok' => true]))['state'];
$s = $policy->step($s, sample(1210))['state'];
check($policy->acceptsState($s), 'brief recovery after repair retains valid historical receipt');
$s['episode']['repair_completed'] = 9999999;
check(!$policy->acceptsState($s), 'impossible future completion invalidates journal');
// Offline cases use synthetic data. No shell/reboot capability exists in this engine.
echo "PASS: {$checks} policy checks; no system actions executed.\n";
