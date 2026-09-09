<?php
declare(strict_types=1);
// DESTRUCTIVE TO THE LAB GUEST: prepare initiates one real guest reboot.
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Explicit isolated FreeBSD lab required');
foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'ActionCoordinator', 'ServiceLoop', 'RebootHandoff', 'RebootDispatcher', 'ProbeProcess'] as $n) require __DIR__ . '/../src/' . $n . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, DiagnosticJournal, ActionCoordinator, ServiceLoop, RebootHandoff, RebootDispatcher, ProbeProcess, SupervisorBusy};
umask(0077);
$mode = $argv[1] ?? ''; $root = $argv[2] ?? '';
if (!in_array($mode, ['prepare', 'worker', 'verify'], true) || !preg_match('~\A/root/recovery-guard-handoff-reboot-[a-z0-9-]+\z~D', $root) || is_link($root)) throw new RuntimeException('Explicit lab mode and private fixture path required');
function bootId(): string {
    $r = (new ProbeProcess())->run(['/sbin/sysctl', '-n', 'kern.boottime']);
    if ($r['status'] !== 'exited' || $r['exit_code'] !== 0 || !preg_match('/sec = ([0-9]+), usec = ([0-9]+)/', $r['stdout'], $m)) throw new RuntimeException('Cannot verify boot');
    return 'boot:' . $m[1] . ':' . $m[2];
}
function saveEvidence(string $path, array $data): void {
    $f = fopen($path, 'xb'); if (!$f) throw new RuntimeException('Evidence already exists');
    try { $s = json_encode($data, JSON_THROW_ON_ERROR); if (fwrite($f, $s) !== strlen($s) || !fflush($f) || !fsync($f)) throw new RuntimeException('Evidence sync failed'); }
    finally { fclose($f); }
    $d = fopen(dirname($path), 'r'); try { if (!fsync($d)) throw new RuntimeException('Evidence directory sync failed'); } finally { fclose($d); }
}
if ($mode === 'prepare') {
    if (file_exists($root) || !mkdir($root, 0700)) throw new RuntimeException('Use a new fixture directory');
    foreach (['budget' => RecoveryPolicy::provision(), 'intent' => RebootHandoff::emptyState(), 'evidence' => DiagnosticJournal::emptyState()] as $n => $state) {
        mkdir($root . '/' . $n, 0700); (new StateStore($root . '/' . $n))->exclusive(fn($s) => $s->provision($state));
    }
    mkdir($root . '/run', 0700);
}
$budget = new StateStore($root . '/budget'); $intent = new StateStore($root . '/intent');
$journal = new DiagnosticJournal(new StateStore($root . '/evidence')); $lease = new ServiceLoop($root . '/run');
$handoff = new RebootHandoff($budget, $intent, $journal, time(...));
if ($mode === 'verify') {
    $expected = json_decode(file_get_contents($root . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
    $claimed = json_decode(file_get_contents($root . '/claimed.json'), true, flags: JSON_THROW_ON_ERROR);
    $state = $handoff->state();
    if (bootId() === $expected['boot_id'] || $state['intent']['claimed_at'] === null ||
        hash_file('sha256', $root . '/budget/state.json') !== $expected['budget_sha256'] ||
        hash_file('sha256', $root . '/intent/state.json') !== $claimed['intent_sha256']) throw new RuntimeException('Reboot persistence not established');
    $ran = false;
    try { $handoff->invoke($expected['token'], $lease, fn() => [], function () use (&$ran) { $ran = true; }); } catch (RuntimeException) {}
    if ($ran) throw new RuntimeException('Consumed reboot was replayed');
    echo "PASS: handoff initiated a real isolated guest reboot; boot identity changed, budget and claimed intent hashes survived, replay inhibited.\n";
    exit(0);
}
if ($mode === 'worker') {
    $expected = json_decode(file_get_contents($root . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
    $fresh = fn() => ['enabled' => true, 'mode' => 'recover', 'boot_id' => bootId(), 'context_id' => $expected['context_id'],
        'maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false];
    $end = hrtime(true) + 20000000000;
    do {
        try {
            $handoff->invoke($argv[3] ?? '', $lease, $fresh, function () use ($root): void {
                saveEvidence($root . '/claimed.json', ['intent_sha256' => hash_file('sha256', $root . '/intent/state.json')]);
                // FreeBSD lab action, deliberately not presented as pfSense native cleanup.
                $p = proc_open(['/sbin/shutdown', '-r', 'now'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                proc_close($p);
            });
        } catch (SupervisorBusy) { usleep(100000); continue; }
        catch (RuntimeException) { exit(is_file($root . '/claimed.json') ? 0 : 1); }
    } while (hrtime(true) < $end);
    exit(1);
}
$lease->exclusive(function () use ($root, $budget, $journal, $handoff): void {
    $boot = bootId(); $context = hash('sha256', 'isolated-handoff-fixture'); $clock = time() - 600;
    $checks = ['boot_id' => $boot, 'context_id' => $context, 'maintenance' => false, 'upgrade' => false,
        'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false];
    $dispatch = new RebootDispatcher($handoff, function ($token) use ($root, $boot, $context): void {
        saveEvidence($root . '/expected.json', ['boot_id' => $boot, 'context_id' => $context, 'token' => $token,
            'budget_sha256' => hash_file('sha256', $root . '/budget/state.json')]);
        $p = proc_open(['/usr/sbin/daemon', '-f', PHP_BINARY, __FILE__, 'worker', $root, $token],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (proc_close($p) !== 0) throw new RuntimeException('Lab worker launch failed');
    });
    $g = new ActionCoordinator(new RecoveryPolicy(), $budget, fn() => $checks, $journal->capture(...),
        fn($p) => $p['kind'] === 'reboot' ? $dispatch->execute($p) : ['id' => $p['id'], 'outcome' => 'failed'],
        function () use (&$clock) { return $clock; }, $journal->outcome(...));
    $end = $clock + 600;
    for (; $clock <= $end; $clock += 30) {
        $r = $g->tick(['time' => $clock, 'uptime' => 4000, 'boot_id' => $boot, 'context_id' => $context, 'maintenance' => false,
            'upgrade' => false, 'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true], 'recover');
        if ($r['execution'] === 'handoff_pending') { echo "DISPATCHED: retiring lab supervisor; real isolated guest reboot requested.\n"; return; }
    }
    throw new RuntimeException('Handoff was not dispatched');
});
