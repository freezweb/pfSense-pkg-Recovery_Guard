<?php
declare(strict_types=1);
require __DIR__ . '/../src/RecoveryPolicy.php';
require __DIR__ . '/../src/StateStore.php';
require __DIR__ . '/../src/ActionCoordinator.php';
require __DIR__ . '/../src/ProbeProcess.php';
use RecoveryGuard\RecoveryPolicy;
use RecoveryGuard\StateStore;
use RecoveryGuard\ActionCoordinator;
use RecoveryGuard\ProbeProcess;
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) {
    fwrite(STDERR, "Requires the explicitly marked isolated FreeBSD lab.\n"); exit(2);
}
$mode = $argv[1] ?? '';
$root = $argv[2] ?? '/root/recovery-guard-reboot-check';
if (!in_array($mode, ['prepare', 'verify'], true) ||
    !preg_match('/\A\/root\/recovery-guard-reboot-[a-z0-9-]+\z/D', $root) || is_link($root)) {
    throw new InvalidArgumentException('Use prepare|verify and a dedicated reboot-check directory');
}
$boot = (new ProbeProcess())->run(['/sbin/sysctl', '-n', 'kern.boottime']);
if ($boot['status'] !== 'exited' || $boot['exit_code'] !== 0 ||
    !preg_match('/sec = ([0-9]+), usec = ([0-9]+)/', $boot['stdout'], $m)) {
    throw new RuntimeException('Cannot establish real guest boot identity');
}
$bootId = 'boot-' . hash('sha256', $m[1] . ':' . $m[2]);
$sample = static fn(int $time): array => ['time' => $time, 'boot_id' => $bootId, 'uptime' => 3600,
    'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true,
    'maintenance' => false, 'upgrade' => false];
$interlocks = static fn(): array => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false,
    'other_repair' => false, 'shutting_down' => false, 'boot_id' => $bootId];
$policy = new RecoveryPolicy();
$store = new StateStore($root . '/journal');
$clock = time();
$actions = [];
$guard = new ActionCoordinator($policy, $store, $interlocks, static fn() => null,
    static function (array $proposal) use (&$actions): array {
        // Synthetic action receipt. The actual VM reboot is performed separately by the operator.
        $actions[] = $proposal['kind'];
        return ['id' => $proposal['id'], 'outcome' => 'completed'];
    }, static function () use (&$clock): int { return $clock; });
if ($mode === 'prepare') {
    if (file_exists($root)) throw new RuntimeException('Existing fixture retained; use verify or a new test directory');
    if (!mkdir($root, 0700) || !mkdir($root . '/journal', 0700)) throw new RuntimeException('Cannot create fixture');
    $store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
    $end = time();
    // A synthetic historical fault timeline creates real durable budgets without any OS action.
    for ($clock = $end - 900; $clock <= $end; $clock += 30) $guard->tick($sample($clock), 'recover');
    $saved = $store->exclusive(fn($s) => $s->read());
    if ($actions !== ['repair_php_fpm', 'reboot']) throw new RuntimeException('Preparation did not reserve expected actions');
    $expected = ['boot_id' => $bootId, 'sha256' => hash_file('sha256', $root . '/journal/state.json'),
        'repair_times' => $saved['repair_times'], 'reboot_times' => $saved['reboot_times']];
    $path = $root . '/expected.json';
    $h = fopen($path, 'xb');
    if ($h === false) throw new RuntimeException('Cannot create expected evidence');
    try {
        chmod($path, 0600);
        $bytes = json_encode($expected, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($h, $bytes) !== strlen($bytes) || !fflush($h) || !fsync($h)) throw new RuntimeException('Cannot sync evidence');
    } finally { fclose($h); }
    $h = fopen($root, 'r');
    try { if (!fsync($h)) throw new RuntimeException('Cannot sync evidence directory'); }
    finally { fclose($h); }
    echo "PREPARED: durable repair/reboot reservations; fake executor only. Reboot the isolated guest, then run verify.\n";
    exit(0);
}
$expected = json_decode(file_get_contents($root . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
if ($bootId === $expected['boot_id']) throw new RuntimeException('Guest has not rebooted since preparation');
$saved = $store->exclusive(fn($s) => $s->read());
if (!$policy->acceptsState($saved) || hash_file('sha256', $root . '/journal/state.json') !== $expected['sha256'] ||
    $saved['repair_times'] !== $expected['repair_times'] || $saved['reboot_times'] !== $expected['reboot_times'] ||
    count($saved['reboot_times']) !== 1) throw new RuntimeException('Journal changed or lost its action budget across reboot');
$clock = time();
$result = $guard->tick($sample($clock), 'recover');
if ($actions !== [] || $result['state']['reboot_times'] !== $expected['reboot_times']) {
    throw new RuntimeException('Fresh supervisor did not preserve the reboot budget');
}
// Exercise the retained limit over synthetic future measurements, without writing them to disk.
$state = $result['state']; $repairs = 0;
for ($t = $clock + 30; $t <= $clock + 1200; $t += 30) {
    $r = $policy->step($state, $sample($t)); $state = $r['state'];
    if ($r['proposal'] === null) continue;
    if ($r['proposal']['kind'] === 'reboot') throw new RuntimeException('Reboot limit was lost after a real guest reboot');
    $repairs++;
    $state = $policy->acknowledgeRepair($state, $r['proposal']['id'], $t);
}
if ($repairs === 0) throw new RuntimeException('Synthetic post-reboot timeline did not reach recovery policy');
if (hash_file('sha256', $root . '/journal/state.json') !== $expected['sha256']) throw new RuntimeException('Verification rewrote durable evidence');
echo "PASS: real boot identity changed; exact journal hash survived; fresh supervisor retained budget; second reboot blocked in synthetic fault timeline.\n";
