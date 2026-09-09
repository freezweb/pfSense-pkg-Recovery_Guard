<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || posix_geteuid() !== 0 || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated root FreeBSD laboratory required');
foreach (['RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'DiagnosticJournal', 'NotificationOutbox', 'NotificationDelivery'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, ActionCoordinator, DiagnosticJournal, NotificationOutbox, NotificationDelivery};
umask(0077);
$dir = '/root/recovery-guard-storage-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700) || realpath($dir) !== $dir) throw new RuntimeException('Private fixture path required');
$mount = $dir . '/filesystem'; mkdir($mount, 0700);
$checks = 0; $mounted = false; $readOnly = false; $records = [];
function check(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function command(array $argv): void {
    $process = proc_open(['/usr/bin/timeout', '-k', '0.5', '10', ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start private filesystem command');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Private filesystem command failed: ' . $err);
}
function sample(int $time): array {
    return ['time' => $time, 'boot_id' => 'fixture-boot', 'uptime' => 3600, 'php_ok' => false,
        'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true, 'maintenance' => false, 'upgrade' => false];
}
function provision(string $path): StateStore {
    mkdir($path, 0700); $store = new StateStore($path);
    $store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision())); return $store;
}
function rejects(Closure $operation, string $label): array {
    try { $operation(); } catch (Throwable $error) { check(true, $label); return ['class' => get_class($error), 'message' => $error->getMessage()]; }
    throw new RuntimeException($label);
}
function fill(string $mount): void {
    $handle = fopen($mount . '/filler', 'xb');
    if (!$handle) throw new RuntimeException('Cannot start bounded fill');
    $total = 0;
    try {
        // A failed large allocation can leave several pages for a small journal.
        // Exhaust the remainder too, without ever filling the guest root disk.
        foreach ([65536, 4096, 1] as $size) {
            $bytes = str_repeat('x', $size);
            while ($total < 16777216) {
                $written = @fwrite($handle, $bytes);
                if ($written === false || $written === 0) break;
                $total += $written;
            }
        }
        check($total > 0 && $total < 16777216, 'private 8 MiB filesystem reaches real write failure within bound');
    } finally { fclose($handle); }
}
try {
    command(['/sbin/mount', '-t', 'tmpfs', '-o', 'size=8m', 'tmpfs', $mount]); $mounted = true;
    foreach (['full', 'read-only'] as $fault) {
        $store = provision($mount . '/' . $fault);
        $executions = 0; $captures = 0; $now = 1000;
        $guard = new ActionCoordinator(new RecoveryPolicy(), $store,
            fn() => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false, 'boot_id' => 'fixture-boot'],
            function () use (&$captures) { $captures++; },
            function ($proposal) use (&$executions) { $executions++; return ['id' => $proposal['id'], 'outcome' => 'completed']; },
            function () use (&$now) { return $now; });
        for ($now = 1000; $now <= 1090; $now += 30) $guard->tick(sample($now), 'recover');
        $hash = hash_file('sha256', $mount . '/' . $fault . '/state.json');
        if ($fault === 'full') fill($mount);
        else { command(['/sbin/mount', '-u', '-o', 'ro', $mount]); $readOnly = true; }
        $now = 1120;
        $records[$fault] = rejects(fn() => $guard->tick(sample(1120), 'recover'), 'failed durable reservation must reject action: ' . $fault);
        check($executions === 0 && $captures === 0, 'failed reservation cannot reach diagnostics or executor: ' . $fault);
        check(hash_file('sha256', $mount . '/' . $fault . '/state.json') === $hash, 'failed replacement retains exact prior budget: ' . $fault);
        if ($fault === 'full') unlink($mount . '/filler');
        else { command(['/sbin/mount', '-u', '-o', 'rw', $mount]); $readOnly = false; }
        check(glob($mount . '/' . $fault . '/.pending-*') === [], 'failed write leaves no pending file: ' . $fault);
        for ($now = 1150; $now <= 1240; $now += 30) $guard->tick(sample($now), 'recover');
        check($executions === 0, 'storage recovery requires fresh fault confirmation: ' . $fault);
        $now = 1270; $guard->tick(sample($now), 'recover');
        check($executions === 1 && $captures === 1, 'fresh confirmed episode may reserve exactly one fake repair: ' . $fault);
    }
    // Evidence can fail on a separate filesystem after the action budget was reserved.
    $budget = provision($dir . '/budget');
    mkdir($mount . '/diagnostics', 0700); $ds = new StateStore($mount . '/diagnostics');
    $ds->exclusive(fn($s) => $s->provision(DiagnosticJournal::emptyState())); $journal = new DiagnosticJournal($ds);
    $executions = 0; $now = 2000;
    $guard = new ActionCoordinator(new RecoveryPolicy(), $budget,
        fn() => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false, 'boot_id' => 'fixture-boot'],
        fn($p, $s, $m) => $journal->capture($p, $s, $m),
        function ($p) use (&$executions) { $executions++; return ['id' => $p['id'], 'outcome' => 'completed']; },
        function () use (&$now) { return $now; });
    for ($now = 2000; $now <= 2090; $now += 30) $guard->tick(sample($now), 'recover');
    $diagnosticHash = hash_file('sha256', $mount . '/diagnostics/state.json'); fill($mount); $now = 2120;
    $records['full-evidence'] = rejects(fn() => $guard->tick(sample(2120), 'recover'), 'full evidence store must inhibit reserved action');
    check($executions === 0, 'evidence failure cannot reach executor');
    check(hash_file('sha256', $mount . '/diagnostics/state.json') === $diagnosticHash, 'full evidence filesystem retains prior diagnostics');
    $state = $budget->exclusive(fn($s) => $s->read());
    check(count($state['repair_times']) === 1 && $state['reboot_times'] === [], 'failed evidence retains conservative repair reservation');
    unlink($mount . '/filler');
    for ($now = 2150; $now <= 2900; $now += 30) $guard->tick(sample($now), 'recover');
    check($executions === 0, 'no immediate retry or reboot escalation after evidence storage recovers');
    // A real outbox claim failure must also prevent its injected sender.
    mkdir($mount . '/mail', 0700); $ms = new StateStore($mount . '/mail');
    $ms->exclusive(fn($s) => $s->provision(NotificationOutbox::emptyState())); $outbox = new NotificationOutbox($ms);
    $outbox->enqueue(str_repeat('a', 64), ['action_id' => 'fixture:3000', 'kind' => 'reboot', 'mode' => 'recover', 'result' => 'handoff_pending', 'time' => 3000]);
    $mailHash = hash_file('sha256', $mount . '/mail/state.json'); $sends = 0;
    $delivery = new NotificationDelivery($outbox, function () use (&$sends) { $sends++; return 'accepted'; }, fn() => 3000);
    fill($mount); $records['full-mail'] = rejects(fn() => $delivery->once(), 'full outbox prevents unreserved delivery');
    check($sends === 0 && hash_file('sha256', $mount . '/mail/state.json') === $mailHash, 'failed mail claim neither sends nor rewrites retained message');
    unlink($mount . '/filler');
    check($delivery->once() === 'accepted' && $sends === 1, 'persisted message resumes after storage recovery');
    file_put_contents($dir . '/results.json', json_encode(['checks' => $checks, 'faults' => $records], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "PASS: {$checks} real private filesystem failure checks; fake executors and sender only.\nEvidence directory: {$dir}\n";
} finally {
    if ($mounted) {
        if ($readOnly) command(['/sbin/mount', '-u', '-o', 'rw', $mount]);
        command(['/sbin/umount', $mount]);
    }
}
