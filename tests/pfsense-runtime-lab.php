<?php
declare(strict_types=1);
// Disrupts only the designated isolated pfSense guest's real PHP service.
if (PHP_OS !== 'FreeBSD' || posix_geteuid() !== 0 || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB') || ($argv[1] ?? '') !== 'stop-and-repair-native-fpm') throw new RuntimeException('Explicit isolated native runtime test required');
foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'ActionCoordinator', 'Configuration', 'NativeSnapshot', 'ProbeProcess',
    'NetworkProbe', 'EndpointBaseline', 'FastCgiProbe', 'LogWorker', 'RuntimeSupervisor', 'NativeUpgradeLease',
    'NativeRecoveryExecutor', 'RepairProcess', 'RepairExecutor'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, DiagnosticJournal, Configuration, NativeSnapshot, ProbeProcess, NetworkProbe,
    FastCgiProbe, LogWorker, RuntimeSupervisor, NativeUpgradeLease, NativeRecoveryExecutor, RepairProcess, RepairExecutor};
umask(0077); $runner = new ProbeProcess(); $php = new FastCgiProbe(); $network = new NetworkProbe($runner->run(...));
$initial = NativeSnapshot::read($runner);
$config = Configuration::compile($initial['settings'], $initial['interfaces'], $initial['vlans'], $initial['virtual_ips']);
if (!$config['enabled'] || $config['mode'] !== 'monitor' || !$config['maintenance'] || $config['notifications'] || $initial['uptime'] < 600) throw new RuntimeException('Settled boot and installed monitor in maintenance required');
foreach ($initial['interlocks'] as $value) if ($value !== false) throw new RuntimeException('Native lifecycle must be clear');
if ($php->check()['ok'] !== true || $network->link($config['link_device']) !== true) throw new RuntimeException('Initially healthy native PHP/link required');
foreach ($config['peers'] as $peer) if ($network->localEndpoint($peer, $config['source'], $config['device']) !== true) throw new RuntimeException('Every explicitly configured isolated peer must reply');
$root = '/root/recovery-guard-native-runtime-' . bin2hex(random_bytes(6)); mkdir($root, 0700);
foreach (['budget' => RecoveryPolicy::provision(), 'evidence' => DiagnosticJournal::emptyState()] as $n => $state) {
    mkdir($root . '/' . $n, 0700); (new StateStore($root . '/' . $n))->exclusive(fn($s) => $s->provision($state));
}
$configHash = hash_file('sha256', '/cf/conf/config.xml'); $installedBudget = hash_file('sha256', '/cf/conf/recovery_guard/state.json');
$snapshot = static function () use ($runner): array {
    $s = NativeSnapshot::read($runner); $s['settings']['mode'] = 'repair'; unset($s['settings']['maintenance']); return $s;
};
$process = new RepairProcess(); $repair = new RepairExecutor($process->run(...), fn() => $php->check()['ok']);
$upgrade = new NativeUpgradeLease(); $log = new LogWorker();
$native = new NativeRecoveryExecutor($snapshot, $php->check(...), $upgrade->exclusive(...), $repair->execute(...),
    fn() => throw new LogicException('Reboot forbidden'), time(...), fn() => hrtime(true));
$journal = new DiagnosticJournal(new StateStore($root . '/evidence')); $store = new StateStore($root . '/budget');
$calls = 0;
$runtime = new RuntimeSupervisor(new RecoveryPolicy(), $store, $snapshot, $php->check(...), $network, $log->check(...),
    $journal->capture(...), function ($proposal, $sample) use ($native, $root, &$calls): array {
        $saved = json_decode(file_get_contents($root . '/budget/state.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($saved['episode']['repair_request']['id'] ?? null) !== $proposal['id']) throw new RuntimeException('Native action lacks durable reservation');
        $calls++; return $native->execute($proposal, $sample);
    }, time(...), fn() => hrtime(true), $journal->outcome(...));
$samples = []; $stopped = false;
echo "START: joined native runtime; actual PHP, link, configured peer replies, log worker, clocks and two-minute confirmation. In-memory repair-mode override; installed monitor stays in maintenance.\nEvidence: {$root}\n";
try {
    for ($i = 0; $i < 3; $i++) {
        if ($i > 0) sleep(15);
        $r = $runtime->cycle(); $samples[] = $r;
        if (($r['sample']['php_ok'] ?? null) !== true || ($r['sample']['critical_link_up'] ?? null) !== true ||
            ($r['sample']['local_reachable'] ?? null) !== true || $r['execution'] !== 'none') throw new RuntimeException('Healthy native collector cycle failed');
    }
    if ($r['sample']['log_storm'] !== false) throw new RuntimeException('Native bounded log interval not established');
    $pid = (int)file_get_contents('/var/run/php-fpm.pid');
    $identity = $runner->run(['/bin/ps', '-p', (string)$pid, '-o', 'command=']);
    if ($pid < 2 || $identity['exit_code'] !== 0 || !str_contains($identity['stdout'], 'php-fpm: master process')) throw new RuntimeException('Native master identity unavailable');
    if (!posix_kill($pid, SIGQUIT)) throw new RuntimeException('Cannot stop verified master');
    $stopped = true; $end = hrtime(true) + 15_000_000_000;
    while (posix_kill($pid, 0) && hrtime(true) < $end) usleep(100000);
    if (posix_kill($pid, 0) || $php->check()['ok'] !== false) throw new RuntimeException('Actual PHP fault not established');
    $end = hrtime(true) + 180_000_000_000;
    do {
        sleep(15); $r = $runtime->cycle(); $samples[] = $r;
        if ($r['execution'] !== 'none') break;
    } while (hrtime(true) < $end);
    if ($calls !== 1 || $r['execution'] !== 'completed' || $php->check()['ok'] !== true) throw new RuntimeException('Joined native runtime did not repair actual fault');
    $newPid = (int)file_get_contents('/var/run/php-fpm.pid');
    sleep(15); $r = $runtime->cycle(); $samples[] = $r;
    if (($r['sample']['php_ok'] ?? null) !== true || $r['execution'] !== 'none' || $calls !== 1 ||
        (int)file_get_contents('/var/run/php-fpm.pid') !== $newPid) throw new RuntimeException('Healthy post-repair cycle failed');
    if ($configHash !== hash_file('sha256', '/cf/conf/config.xml') || $installedBudget !== hash_file('sha256', '/cf/conf/recovery_guard/state.json')) throw new RuntimeException('Installed settings or budget changed');
    $saved = $store->exclusive(fn($s) => $s->read());
    if (count($saved['repair_times']) !== 1 || $saved['reboot_times'] !== []) throw new RuntimeException('Private action budget incorrect');
    echo "PASS: full RuntimeSupervisor native collection/qualification, actual FPM fault and confirmation, durable action/evidence, original native repair, healthy subsequent cycle, no second action, unchanged installed configuration/budget.\n";
} finally {
    $log->close(); file_put_contents($root . '/samples.json', json_encode($samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    if ($stopped && $php->check()['ok'] !== true) {
        $fallback = $process->run(); file_put_contents($root . '/fallback.json', json_encode($fallback, JSON_THROW_ON_ERROR));
        if ($php->check()['ok'] !== true) throw new RuntimeException('Isolated FPM requires SSH/serial recovery');
    }
}
