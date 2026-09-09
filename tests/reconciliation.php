<?php
declare(strict_types=1);
foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'ActionCoordinator', 'RebootHandoff', 'RebootDispatcher', 'NotificationOutbox', 'NotificationCycle', 'NotificationSmtp', 'NotificationDelivery', 'NotificationMessage'] as $n) require __DIR__ . '/../src/' . $n . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, DiagnosticJournal, ActionCoordinator, RebootHandoff, RebootDispatcher, NotificationOutbox, NotificationCycle};
$checks = 0; $dirs = []; $sync = PHP_OS_FAMILY === 'Windows' ? static function (string $p): void {} : null;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function fixture(): object {
    global $dirs, $sync;
    $f = (object)['root' => sys_get_temp_dir() . '/recovery-guard-reconcile-' . bin2hex(random_bytes(6)), 'time' => 9400];
    mkdir($f->root, 0700); $dirs[] = $f->root;
    foreach (['budget' => RecoveryPolicy::provision(), 'intent' => RebootHandoff::emptyState(), 'evidence' => DiagnosticJournal::emptyState(), 'outbox' => NotificationOutbox::emptyState()] as $n => $v) {
        mkdir($f->root . '/' . $n, 0700); $f->$n = new StateStore($f->root . '/' . $n, $sync); $f->$n->exclusive(fn($s) => $s->provision($v));
    }
    $f->journal = new DiagnosticJournal($f->evidence);
    $f->handoff = new RebootHandoff($f->budget, $f->intent, $f->journal, fn() => $f->time, fn() => 9000000000000);
    $dispatch = new RebootDispatcher($f->handoff, function ($token) {});
    $gates = ['boot_id' => 'boot:1000:0', 'context_id' => str_repeat('a', 64), 'maintenance' => false, 'upgrade' => false,
        'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false];
    $g = new ActionCoordinator(new RecoveryPolicy(), $f->budget, fn() => $gates, $f->journal->capture(...),
        fn($p) => $p['kind'] === 'reboot' ? $dispatch->execute($p) : ['id' => $p['id'], 'outcome' => 'failed'], fn() => $f->time, $f->journal->outcome(...));
    for (; $f->time <= 10000; $f->time += 30) $g->tick(['time' => $f->time, 'uptime' => $f->time - 1000, 'boot_id' => 'boot:1000:0',
        'context_id' => str_repeat('a', 64), 'maintenance' => false, 'upgrade' => false, 'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true], 'recover');
    $f->intent->exclusive(function ($s) { $v = $s->read(); $v['intent']['claimed_at'] = 10001; $s->commit($v); });
    $f->observation = ['boot_id' => 'boot:10002:0', 'time' => 10032, 'uptime' => 30, 'monotonic_ns' => 30000000000];
    return $f;
}
function hashes(object $f): array { return [hash_file('sha256', $f->root . '/budget/state.json'), hash_file('sha256', $f->root . '/intent/state.json')]; }
try {
    $f = fixture(); $hashes = hashes($f);
    $f->evidence->exclusive(function ($s) { $v = $s->read(); $v['version'] = 1; $s->commit($v); });
    check($f->handoff->reconcile($f->observation), 'new boot with monotonic reset reconciles a claimed request');
    $record = $f->journal->records()[1];
    check($record['result'] === 'boot_observed' && $record['boot_observation']['previous_result'] === 'handoff_pending' &&
        $record['boot_observation']['claimed_at'] === 10001, 'observation preserves original outcome and claim time');
    check($f->evidence->exclusive(fn($s) => $s->read())['version'] === 2, 'version-one history gains observation without reset');
    check(hashes($f) === $hashes, 'reconciliation never rewrites budgets or claimed intent');
    $bytes = file_get_contents($f->root . '/evidence/state.json');
    check(!$f->handoff->reconcile(array_replace($f->observation, ['time' => 10042, 'uptime' => 40])) && file_get_contents($f->root . '/evidence/state.json') === $bytes, 'repeated startup preserves first observation byte for byte');
    $blocked = false;
    try { $f->journal->outcome(['id' => $record['id'], 'kind' => 'reboot'], 'boot_observed'); } catch (InvalidArgumentException) { $blocked = true; }
    check($blocked, 'generic executor receipt cannot assert new boot');

    foreach ([['boot_id' => 'boot:1000:0'], ['monotonic_ns' => 9000000000000], ['monotonic_ns' => 9000000000001],
        ['monotonic_ns' => null], ['time' => 9999], ['uptime' => 9000], ['boot_id' => 'boot:9999:0', 'time' => 10029],
        ['boot_id' => 'unknown'], ['extra' => 'not-allowed']] as $change) {
        $f = fixture(); $before = hashes($f); $bytes = file_get_contents($f->root . '/evidence/state.json');
        check(!$f->handoff->reconcile(array_replace($f->observation, $change)) && hashes($f) === $before && file_get_contents($f->root . '/evidence/state.json') === $bytes, 'uncertain boot/clock evidence does not change outcome');
    }
    foreach (['unclaimed', 'no_intent', 'different_context', 'different_time', 'no_reservation'] as $case) {
        $f = fixture();
        if ($case === 'no_reservation') $f->budget->exclusive(function ($s) { $v = $s->read(); $v['reboot_times'] = []; $s->commit($v); });
        else $f->intent->exclusive(function ($s) use ($case) { $v = $s->read();
            if ($case === 'no_intent') $v['intent'] = null;
            elseif ($case === 'unclaimed') $v['intent']['claimed_at'] = null;
            elseif ($case === 'different_context') $v['intent']['context_id'] = str_repeat('b', 64);
            else $v['intent']['time'] = 9999;
            $s->commit($v);
        });
        $result = false; try { $result = $f->handoff->reconcile($f->observation); } catch (RuntimeException) {}
        check(!$result && $f->journal->records()[1]['result'] === 'handoff_pending', 'missing or mismatched durable proof: ' . $case);
    }
    $f = fixture(); $hashes = hashes($f);
    $bad = new DiagnosticJournal(new StateStore($f->root . '/evidence', static function ($p): void { throw new RuntimeException('Ambiguous fsync'); }));
    $handoff = new RebootHandoff($f->budget, $f->intent, $bad, fn() => 10032); $failed = false;
    try { $handoff->reconcile($f->observation); } catch (RuntimeException) { $failed = true; }
    check($failed && hashes($f) === $hashes, 'ambiguous observation commit preserves reboot budget and consumed claim');
    check(!$f->handoff->reconcile($f->observation), 'retry after ambiguous fsync does not duplicate persisted observation');

    $f = fixture(); $q = new NotificationOutbox($f->outbox); $sent = [];
    $settings = ['enabled' => true, 'booting' => false, 'hostname' => 'fixture', 'domain' => 'example.invalid',
        'smtp' => ['ipaddress' => 'smtp.example.invalid', 'notifyemailaddress' => 'fixture@example.invalid']];
    $cycle = new NotificationCycle($q, $f->journal, fn() => $settings, function ($job) use (&$sent): string { $sent[] = $job; return 'accepted'; }, fn() => 10032);
    $cycle->once(); $f->handoff->reconcile($f->observation);
    check($cycle->once() === 'accepted' && count($sent) === 1 && $sent[0]['event']['result'] === 'boot_observed' && $sent[0]['event']['time'] === 10032, 'new boot creates one notification with observation time');
    check($cycle->once() === 'idle', 'reconciliation notification is not repeated');
    check(str_contains(\RecoveryGuard\NotificationMessage::render($sent[0])['body'], 'does not establish the cause'), 'mail does not claim causality or service recovery');
    echo "PASS: {$checks} reboot-reconciliation checks; synthetic boots, no reboot or external messages.\n";
} finally {
    foreach ($dirs as $dir) { foreach (glob($dir . '/*') as $sub) { foreach (glob($sub . '/*') as $file) unlink($file); rmdir($sub); } rmdir($dir); }
}
