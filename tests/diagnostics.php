<?php
declare(strict_types=1);
foreach (['RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'DiagnosticJournal'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, ActionCoordinator, DiagnosticJournal};
$checks = 0; $directories = [];
$sync = PHP_OS_FAMILY === 'Windows' ? static function (string $p): void {} : null;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function rejects(Closure $f, string $name): void { try { $f(); } catch (Throwable) { check(true, $name); return; } check(false, $name); }
function store(array $state): array {
    global $directories, $sync;
    $dir = sys_get_temp_dir() . '/recovery-guard-evidence-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700); $directories[] = $dir;
    $s = new StateStore($dir, $sync); $s->exclusive(fn($s) => $s->provision($state));
    return [$s, $dir];
}
function sample(int $time): array { return ['time' => $time, 'uptime' => $time, 'boot_id' => 'lab-boot',
    'context_id' => str_repeat('a', 64), 'maintenance' => false, 'upgrade' => false,
    'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true]; }
try {
    [$state, $dir] = store(DiagnosticJournal::emptyState()); $journal = new DiagnosticJournal($state);
    $proposal = ['id' => 'lab-boot:1000', 'kind' => 'repair_php_fpm', 'credential' => 'MUST_NOT_EXPORT'];
    $input = sample(1000) + ['raw_config' => 'MUST_NOT_EXPORT', 'peer' => '192.0.2.9'];
    $journal->capture($proposal, $input, 'monitor');
    check(!str_contains(file_get_contents($dir . '/state.json'), 'MUST_NOT_EXPORT') &&
        !str_contains(file_get_contents($dir . '/state.json'), '192.0.2.9'), 'capture uses a field whitelist');
    $hash = hash_file('sha256', $dir . '/state.json');
    $journal->capture($proposal, $input, 'monitor');
    check(hash_file('sha256', $dir . '/state.json') === $hash, 'duplicate capture does not rewrite');
    $journal->outcome($proposal, 'mode_inhibited');
    check((new DiagnosticJournal(new StateStore($dir, $sync)))->records()[0]['result'] === 'mode_inhibited', 'outcome survives a new reader');
    $hash = hash_file('sha256', $dir . '/state.json');
    $journal->capture($proposal, $input, 'monitor');
    $journal->outcome($proposal, 'mode_inhibited');
    check(hash_file('sha256', $dir . '/state.json') === $hash, 'replay cannot erase a recorded result');
    rejects(fn() => $journal->capture($proposal, sample(1001), 'monitor'), 'conflicting evidence refused');
    rejects(fn() => $journal->outcome($proposal, 'completed'), 'contradictory result refused');
    rejects(fn() => $journal->outcome(['kind' => 'reboot', 'id' => 'missing'], 'completed'), 'missing evidence cannot gain an outcome');
    for ($i = 1; $i <= 65; $i++) $journal->capture(['id' => 'lab-boot:' . (1000 + $i), 'kind' => 'repair_php_fpm'], sample(1000 + $i), 'monitor');
    $records = $journal->records();
    check(count($records) === 64 && $records[0]['id'] === 'lab-boot:1002' && $records[63]['id'] === 'lab-boot:1065', 'bounded oldest-first retention');
    check(filesize($dir . '/state.json') < 65536, 'bounded evidence fits below 64 KiB');
    $state->exclusive(function ($s) { $data = $s->read(); $data['records'][0]['secret'] = 'invalid'; $s->commit($data); });
    rejects(fn() => $journal->records(), 'unexpected stored fields fail closed');

    foreach (['monitor', 'repair', 'capture_failure', 'executor_unknown', 'outcome_failure'] as $case) {
        [$budget, $budgetDir] = store(RecoveryPolicy::provision());
        [$evidence, $evidenceDir] = store(DiagnosticJournal::emptyState());
        $writes = 0;
        if (in_array($case, ['capture_failure', 'outcome_failure'], true)) {
            $evidence = new StateStore($evidenceDir, function (string $p) use (&$writes, $case, $sync): void {
                $writes++;
                if ($writes === ($case === 'capture_failure' ? 1 : 2)) throw new RuntimeException('Injected ambiguous durability');
                if ($sync !== null) { $sync($p); return; }
                $h = fopen($p, 'r'); try { if (!fsync($h)) throw new RuntimeException('Directory sync'); } finally { fclose($h); }
            });
        }
        $e = new DiagnosticJournal($evidence); $executions = 0; $time = 1000;
        $g = new ActionCoordinator(new RecoveryPolicy(), $budget,
            fn() => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false,
                'shutting_down' => false, 'boot_id' => 'lab-boot', 'context_id' => str_repeat('a', 64)], $e->capture(...),
            function ($p) use (&$executions, $e, $case): array {
                if ($e->records()[0]['result'] !== 'pending') throw new LogicException('Evidence was not captured first');
                $executions++;
                if ($case === 'executor_unknown') throw new RuntimeException('Uncertain executor');
                return ['id' => $p['id'], 'outcome' => 'failed'];
            }, function () use (&$time): int { return $time; }, $e->outcome(...));
        $threw = false;
        try { for ($time = 1000; $time <= 1120; $time += 30) $g->tick(sample($time), $case === 'monitor' ? 'monitor' : 'repair'); }
        catch (RuntimeException) { $threw = true; }
        $saved = $budget->exclusive(fn($s) => $s->read());
        if ($case === 'monitor') check(!$threw && $executions === 0 && $e->records()[0]['result'] === 'mode_inhibited', 'monitor records an inhibited proposal without execution');
        if ($case === 'repair') check(!$threw && $executions === 1 && $e->records()[0]['result'] === 'failed' && $saved['episode']['repair_completed'] === 1120, 'evidence and outcome precede repair acknowledgement');
        if ($case === 'capture_failure') check($threw && $executions === 0 && count($saved['repair_times']) === 1, 'ambiguous evidence commit inhibits action without erasing reservation');
        if ($case === 'executor_unknown') check($threw && $executions === 1 && $e->records()[0]['result'] === 'unknown' && $saved['episode']['repair_completed'] === null, 'unknown executor cannot authorize reboot escalation');
        if ($case === 'outcome_failure') check($threw && $executions === 1 && $saved['episode']['repair_completed'] === null, 'ambiguous outcome commit inhibits repair acknowledgement');
    }
    echo "PASS: {$checks} durable diagnostic and coordinator checks; fake action executors.\n";
} finally {
    foreach ($directories as $directory) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}
