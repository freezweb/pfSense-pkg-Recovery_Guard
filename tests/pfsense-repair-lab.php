<?php
declare(strict_types=1);
// Disruptive: stops and restores the isolated pfSense guest's actual PHP-FPM.
if (PHP_OS !== 'FreeBSD' || posix_geteuid() !== 0 || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB') || !is_file('/etc/version') ||
    ($argv[1] ?? '') !== 'stop-and-repair-native-fpm') throw new RuntimeException('Explicit isolated pfSense repair test required');
foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'ActionCoordinator', 'Configuration', 'NativeSnapshot',
    'ProbeProcess', 'FastCgiProbe', 'NativeUpgradeLease', 'NativeRecoveryExecutor', 'RepairProcess', 'RepairExecutor'] as $name) {
    require __DIR__ . '/../src/' . $name . '.php';
}
use RecoveryGuard\{RecoveryPolicy, StateStore, DiagnosticJournal, ActionCoordinator, Configuration, NativeSnapshot,
    ProbeProcess, FastCgiProbe, NativeUpgradeLease, NativeRecoveryExecutor, RepairProcess, RepairExecutor};
umask(0077);
$runner = new ProbeProcess(); $probe = new FastCgiProbe();
$initial = NativeSnapshot::read($runner);
$config = Configuration::compile($initial['settings'], $initial['interfaces'], $initial['vlans'], $initial['virtual_ips']);
if (!$config['enabled'] || $config['mode'] !== 'monitor' || !$config['maintenance'] || $config['notifications']) {
    throw new RuntimeException('Installed package must remain enabled, monitor-only, maintenance on, notifications off');
}
foreach ($initial['interlocks'] as $value) if ($value !== false) throw new RuntimeException('Native lifecycle is not clear');
if ($probe->check()['ok'] !== true) throw new RuntimeException('Healthy native FPM required initially');
$originalPid = (int)file_get_contents('/var/run/php-fpm.pid');
$identity = $runner->run(['/bin/ps', '-p', (string)$originalPid, '-o', 'command=']);
if ($originalPid < 2 || $identity['status'] !== 'exited' || $identity['exit_code'] !== 0 ||
    !str_contains($identity['stdout'], 'php-fpm: master process')) throw new RuntimeException('Native FPM PID identity unverified');
$root = '/root/recovery-guard-native-repair-' . bin2hex(random_bytes(6)); mkdir($root, 0700);
foreach (['budget' => RecoveryPolicy::provision(), 'evidence' => DiagnosticJournal::emptyState()] as $name => $state) {
    mkdir($root . '/' . $name, 0700); (new StateStore($root . '/' . $name))->exclusive(fn($s) => $s->provision($state));
}
$configHash = hash_file('sha256', '/cf/conf/config.xml');
$packageBudget = hash_file('sha256', '/cf/conf/recovery_guard/state.json');
$store = new StateStore($root . '/budget'); $journal = new DiagnosticJournal(new StateStore($root . '/evidence'));
// Only this harness's in-memory mode/maintenance differs. Real boot and native
// lifecycle observations remain intact; no package configuration is armed.
$snapshot = static function () use ($runner): array {
    $s = NativeSnapshot::read($runner); $s['settings']['mode'] = 'repair'; unset($s['settings']['maintenance']); return $s;
};
$interlocks = static function () use ($snapshot): array {
    $s = $snapshot(); $c = Configuration::compile($s['settings'], $s['interfaces'], $s['vlans'], $s['virtual_ips']);
    return $s['interlocks'] + ['boot_id' => $s['boot_id'], 'context_id' => hash('sha256', json_encode([$s['boot_id'], $c], JSON_THROW_ON_ERROR))];
};
$process = new RepairProcess(); $repair = new RepairExecutor($process->run(...), fn() => $probe->check()['ok']);
$upgrade = new NativeUpgradeLease();
$native = new NativeRecoveryExecutor($snapshot, $probe->check(...), $upgrade->exclusive(...), $repair->execute(...),
    fn() => throw new LogicException('Reboot forbidden in this test'), time(...), fn() => hrtime(true));
$executions = 0;
$guard = new ActionCoordinator(new RecoveryPolicy(), $store, $interlocks, $journal->capture(...),
    function ($proposal, $sample) use ($native, &$executions, $root): array {
        $saved = json_decode(file_get_contents($root . '/budget/state.json'), true, flags: JSON_THROW_ON_ERROR);
        if (count($saved['repair_times']) !== 1 || ($saved['episode']['repair_request']['id'] ?? null) !== $proposal['id']) throw new RuntimeException('Reservation missing before native action');
        $executions++; return $native->execute($proposal, $sample);
    }, time(...), $journal->outcome(...));
$results = []; $stopped = false;
echo "START: actual native FPM stop; real 120-second fault confirmation, private ledger, synthetic healthy LAN/log inputs.\nEvidence: {$root}\n";
try {
    if (!posix_kill($originalPid, SIGQUIT)) throw new RuntimeException('Cannot stop verified FPM master');
    $stopped = true; $deadline = hrtime(true) + 15_000_000_000;
    while (posix_kill($originalPid, 0) && hrtime(true) < $deadline) usleep(100000);
    if (posix_kill($originalPid, 0) || $probe->check()['ok'] !== false) throw new RuntimeException('Real stopped FPM fault not established');
    $deadline = hrtime(true) + 165_000_000_000;
    do {
        $checks = $interlocks(); $now = time(); $bootSeconds = (int)explode(':', $checks['boot_id'])[1];
        $sample = ['time' => $now, 'uptime' => $now - $bootSeconds, 'boot_id' => $checks['boot_id'],
            'context_id' => $checks['context_id'], 'maintenance' => false, 'upgrade' => $checks['upgrade'],
            'php_ok' => $probe->check()['ok'], 'critical_link_up' => true, 'local_reachable' => true, 'log_storm' => false];
        $result = $guard->tick($sample, 'repair');
        $results[] = ['time' => $now, 'php_ok' => $sample['php_ok'], 'reason' => $result['reason'], 'execution' => $result['execution']];
        if ($result['execution'] !== 'none') break;
        sleep(15);
    } while (hrtime(true) < $deadline);
    if ($executions !== 1 || ($result['execution'] ?? '') !== 'completed' || $probe->check()['ok'] !== true) throw new RuntimeException('Native repair did not restore functional FPM');
    $newPid = (int)file_get_contents('/var/run/php-fpm.pid');
    if ($newPid < 2 || $newPid === $originalPid || !posix_kill($newPid, 0)) throw new RuntimeException('Replacement FPM master unavailable');
    $checks = $interlocks();
    if ($native->execute(['kind' => 'repair_php_fpm', 'id' => $sample['boot_id'] . ':' . $sample['time']], $sample)['outcome'] !== 'interlock_inhibited' ||
        (int)file_get_contents('/var/run/php-fpm.pid') !== $newPid) throw new RuntimeException('Healthy FPM was restarted again');
    if ($configHash !== hash_file('sha256', '/cf/conf/config.xml') || $packageBudget !== hash_file('sha256', '/cf/conf/recovery_guard/state.json')) throw new RuntimeException('Installed configuration or budget changed');
    $saved = $store->exclusive(fn($s) => $s->read());
    if (count($saved['repair_times']) !== 1 || $saved['reboot_times'] !== [] || !is_int($saved['episode']['repair_completed'] ?? null)) throw new RuntimeException('Native action receipt not durably acknowledged');
    echo "PASS: native fault detected, real confirmation interval, reservation/evidence before action, native upgrade lease, installed fixed-command controller, original pfSense restart, healthy replacement, duplicate inhibition, unchanged installed configuration/budget.\n";
} finally {
    file_put_contents($root . '/samples.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    // Restore the disposable lab even when an assertion fails; report separately.
    if ($stopped && $probe->check()['ok'] !== true) {
        $fallback = $process->run(); file_put_contents($root . '/fallback.json', json_encode($fallback, JSON_THROW_ON_ERROR));
        if ($probe->check()['ok'] !== true) throw new RuntimeException('Lab FPM needs recovery through existing SSH/serial route');
    }
}
