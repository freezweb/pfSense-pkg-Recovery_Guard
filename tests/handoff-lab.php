<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD lab required');
foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'ActionCoordinator', 'ServiceLoop', 'RebootHandoff', 'RebootDispatcher'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, DiagnosticJournal, ActionCoordinator, ServiceLoop, RebootHandoff, RebootDispatcher};
umask(0077); $root = '/root/recovery-guard-handoff-' . bin2hex(random_bytes(6)); mkdir($root, 0700); $checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function fixture(): object {
    global $root;
    $f = (object)['path' => $root . '/' . bin2hex(random_bytes(4)), 'time' => 9400, 'token' => null]; mkdir($f->path, 0700);
    foreach (['budget' => RecoveryPolicy::provision(), 'evidence' => DiagnosticJournal::emptyState(), 'intent' => RebootHandoff::emptyState()] as $name => $initial) {
        mkdir($f->path . '/' . $name, 0700); $f->$name = new StateStore($f->path . '/' . $name);
        $f->$name->exclusive(fn($s) => $s->provision($initial));
    }
    mkdir($f->path . '/run', 0700); $f->lease = new ServiceLoop($f->path . '/run');
    $f->journal = new DiagnosticJournal($f->evidence);
    $f->handoff = new RebootHandoff($f->budget, $f->intent, $f->journal, fn() => $f->time);
    $f->checks = ['enabled' => true, 'mode' => 'recover', 'boot_id' => 'lab:boot', 'context_id' => str_repeat('a', 64),
        'maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false,
        'php_ok' => false, 'local_reachable' => false, 'critical_link_up' => false, 'log_storm' => null];
    $dispatch = new RebootDispatcher($f->handoff, function ($token) use ($f) { $f->token = $token; });
    $g = new ActionCoordinator(new RecoveryPolicy(), $f->budget, fn() => $f->checks, $f->journal->capture(...),
        fn($p) => $p['kind'] === 'reboot' ? $dispatch->execute($p) : ['id' => $p['id'], 'outcome' => 'failed'], fn() => $f->time, $f->journal->outcome(...));
    for (; $f->time <= 10000; $f->time += 30) {
        $sample = ['time' => $f->time, 'uptime' => 4000, 'boot_id' => 'lab:boot', 'context_id' => str_repeat('a', 64),
            'maintenance' => false, 'upgrade' => false, 'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true];
        $r = $g->tick($sample, 'recover');
        if ($r['execution'] === 'handoff_pending') {
            if ($r['executes_actions'] !== false) throw new RuntimeException('Dispatch was misreported as executed reboot');
            break;
        }
    }
    if (!is_string($f->token) || $f->time !== 10000) throw new RuntimeException('Real policy did not generate expected handoff');
    $f->hash = hash_file('sha256', $f->path . '/budget/state.json');
    return $f;
}
function attempt(object $f, ?Closure $fresh = null): int {
    $ran = 0;
    try { $f->handoff->invoke($f->token, $f->lease, $fresh ?? fn() => $f->checks, function () use (&$ran) { $ran++; }); }
    catch (RuntimeException) {}
    return $ran;
}
$f = fixture();
check($f->handoff->state()['intent']['claimed_at'] === null && $f->journal->records()[1]['result'] === 'handoff_pending', 'dispatch retains unclaimed intent and honest diagnostic status');
check(attempt($f) === 1 && $f->handoff->state()['intent']['claimed_at'] === 10000, 'claim is durable before fake reboot routine');
check(attempt($f) === 0 && hash_file('sha256', $f->path . '/budget/state.json') === $f->hash, 'duplicate invocation cannot reboot or rewrite budget');
foreach (['enabled' => false, 'mode' => 'monitor', 'boot_id' => 'another-boot', 'context_id' => str_repeat('b', 64),
    'maintenance' => true, 'upgrade' => true, 'ha_configured' => true, 'other_repair' => true, 'shutting_down' => true, 'upgrade_unknown' => null,
    'php_ok' => true, 'local_reachable' => true, 'critical_link_up' => true, 'php_unknown' => null, 'lan_unknown' => null] as $key => $value) {
    $f = fixture(); $f->checks[match ($key) { 'upgrade_unknown' => 'upgrade', 'php_unknown' => 'php_ok', 'lan_unknown' => 'local_reachable', default => $key }] = $value;
    check(attempt($f) === 0 && $f->handoff->state()['intent']['claimed_at'] === null, 'fresh gate: ' . $key);
}
foreach ([10061, 9999] as $time) { $f = fixture(); $f->time = $time; check(attempt($f) === 0, 'expired or backward clock inhibits'); }
foreach ([-1, 61000000000] as $delta) {
    $f = fixture(); $mono = $f->handoff->state()['intent']['monotonic_ns'];
    $f->handoff = new RebootHandoff($f->budget, $f->intent, $f->journal, fn() => $f->time, fn() => $mono + $delta);
    check(attempt($f) === 0, 'monotonic expiry or reversal inhibits despite apparently fresh wall clock');
}
$f = fixture(); $f->token = str_repeat($f->token[0] === 'a' ? 'b' : 'a', 32);
check(attempt($f) === 0, 'wrong worker token inhibits');
$f = fixture(); unlink($f->path . '/evidence/state.json');
check(attempt($f) === 0, 'missing diagnostic evidence inhibits');
$f = fixture(); $f->budget->exclusive(function ($s) { $b = $s->read(); $b['operating_mode'] = 'monitor'; $s->commit($b); });
check(attempt($f) === 0, 'changed durable reservation inhibits');
$f = fixture();
$other = new ServiceLoop($f->path . '/run');
check($other->exclusive(fn() => attempt($f)) === 0 && $f->handoff->state()['intent']['claimed_at'] === null, 'worker waits for actual supervisor lease');
$f = fixture(); $count = 0;
check(attempt($f, function () use ($f, &$count) { $c = $f->checks; if (++$count === 2) $c['maintenance'] = true; return $c; }) === 0 &&
    $f->handoff->state()['intent']['claimed_at'] !== null && attempt($f) === 0, 'post-claim maintenance change consumes intent without reboot');
foreach (['php_ok', 'local_reachable'] as $key) {
    $f = fixture(); $count = 0;
    check(attempt($f, function () use ($f, &$count, $key) { $c = $f->checks; if (++$count === 2) $c[$key] = true; return $c; }) === 0 &&
        $f->handoff->state()['intent']['claimed_at'] !== null, 'post-claim functional recovery inhibits invocation');
}
$f = fixture(); $f->checks['critical_link_up'] = true; $f->checks['log_storm'] = true;
check(attempt($f) === 1, 'fresh log storm can corroborate failure with an active link');
$f = fixture();
$bad = new StateStore($f->path . '/intent', static function () { throw new RuntimeException('Injected claim sync failure'); });
$f->handoff = new RebootHandoff($f->budget, $bad, $f->journal, fn() => $f->time);
check(attempt($f) === 0 && $f->handoff->state()['intent']['claimed_at'] !== null && attempt($f) === 0, 'ambiguous claim cannot execute or replay');
$f = fixture(); $pids = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('Cannot fork competitor');
    if ($pid === 0) {
        try { $f->handoff->invoke($f->token, $f->lease, fn() => $f->checks, function () use ($f) { file_put_contents($f->path . '/called', '1', FILE_APPEND | LOCK_EX); usleep(100000); }); }
        catch (RuntimeException) {}
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) pcntl_waitpid($pid, $status);
check(file_get_contents($f->path . '/called') === '1', 'two real workers invoke only once');
$f = fixture(); $pid = pcntl_fork();
if ($pid === 0) {
    $f->handoff->invoke($f->token, $f->lease, fn() => $f->checks, static function () { posix_kill(getmypid(), SIGKILL); });
    exit(1);
}
pcntl_waitpid($pid, $status);
check(pcntl_wifsignaled($status) && $f->handoff->state()['intent']['claimed_at'] !== null && attempt($f) === 0,
    'worker death after durable claim cannot replay');
echo "PASS: {$checks} native reboot handoff checks; fake reboot routines only.\nEvidence: {$root}\n";
