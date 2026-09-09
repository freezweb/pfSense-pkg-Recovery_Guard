<?php
declare(strict_types=1);
foreach (['Configuration', 'NativeRecoveryExecutor', 'RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'DiagnosticJournal'] as $n) require __DIR__ . '/../src/' . $n . '.php';
use RecoveryGuard\{Configuration, NativeRecoveryExecutor, RecoveryPolicy, StateStore, ActionCoordinator, DiagnosticJournal};
$checks = 0; $dirs = []; $sync = PHP_OS_FAMILY === 'Windows' ? static function ($p): void {} : null;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function fixture(): object {
    $f = (object)['time' => 10000, 'mono' => 100000000000, 'health' => false, 'repairs' => 0, 'dispatches' => 0, 'snapshots' => 0,
        'busy' => false, 'leaseLost' => false, 'throwRepair' => false, 'lateChange' => false, 'wallDelta' => 0, 'monoDelta' => 0];
    $f->snapshot = ['settings' => ['enabled' => 'on', 'mode' => 'recover', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3"],
        'interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']], 'vlans' => [], 'virtual_ips' => [],
        'boot_id' => 'boot:1000:0', 'uptime' => 9000, 'interlocks' => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false]];
    $f->sample = ['time' => 10000, 'uptime' => 9000, 'boot_id' => 'boot:1000:0', 'context_id' => hash('sha256', json_encode(['boot:1000:0', Configuration::compile($f->snapshot['settings'], $f->snapshot['interfaces'])], JSON_THROW_ON_ERROR)),
        'maintenance' => false, 'upgrade' => false, 'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false, 'log_storm' => true];
    $f->proposal = ['kind' => 'repair_php_fpm', 'id' => 'boot:1000:0:10000'];
    $f->executor = new NativeRecoveryExecutor(function () use ($f) { $f->snapshots++; return $f->snapshot; },
        function () use ($f) { if ($f->lateChange) $f->snapshot['interlocks']['maintenance'] = true; $f->time += $f->wallDelta; $f->mono += $f->monoDelta; return ['ok' => $f->health]; },
        function ($fresh, $operation) use ($f) {
            if ($f->busy || !$fresh()) throw new RuntimeException('Native upgrade busy');
            return $operation(function () use ($f) { if ($f->leaseLost) throw new RuntimeException('Lease inode changed'); });
        }, function ($p) use ($f) { $f->repairs++; if ($f->throwRepair) throw new RuntimeException('Unknown repair result'); return ['id' => $p['id'], 'outcome' => 'failed']; },
        function ($p) use ($f) { $f->dispatches++; return ['id' => $p['id'], 'outcome' => 'handoff_pending']; }, fn() => $f->time, fn() => $f->mono);
    return $f;
}
$f = fixture();
check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'failed' && $f->repairs === 1 && $f->snapshots === 2, 'repair runs only after lease, PHP and repeated native context checks');
foreach (['busy', 'leaseLost', 'lateChange'] as $key) {
    $f = fixture(); $f->$key = true;
    check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'interlock_inhibited' && $f->repairs === 0, 'late inhibition: ' . $key);
}
foreach ([true, null] as $health) {
    $f = fixture(); $f->health = $health;
    check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'interlock_inhibited' && $f->repairs === 0, 'recovered or unknown PHP does not get restarted');
}
foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) foreach ([true, null] as $value) {
    $f = fixture(); $f->snapshot['interlocks'][$key] = $value;
    check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'interlock_inhibited' && $f->repairs === 0, 'fresh lifecycle gate ' . $key);
}
foreach (['mode', 'context', 'boot', 'time', 'old_time', 'id'] as $case) {
    $f = fixture();
    if ($case === 'mode') $f->snapshot['settings']['mode'] = 'monitor';
    elseif ($case === 'context') $f->snapshot['settings']['peers'] = "192.0.2.4\n192.0.2.5";
    elseif ($case === 'boot') $f->snapshot['boot_id'] = 'boot:2000:0';
    elseif ($case === 'time') $f->time = 9999;
    elseif ($case === 'old_time') $f->time = 10031;
    else $f->proposal['id'] = 'boot:1000:0:9999';
    check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'interlock_inhibited' && $f->repairs === 0, 'changed or expired action context: ' . $case);
}
foreach ([[16, 16000000000], [8, 0], [0, -1]] as [$wallDelta, $monoDelta]) {
    $f = fixture(); $f->wallDelta = $wallDelta; $f->monoDelta = $monoDelta;
    check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'interlock_inhibited' && $f->repairs === 0, 'deadline or clock change during final PHP check inhibits action');
}
$f = fixture(); $f->throwRepair = true; $threw = false;
try { $f->executor->execute($f->proposal, $f->sample); } catch (RuntimeException) { $threw = true; }
check($threw && $f->repairs === 1, 'exception after action starts remains unknown rather than inhibited');
$f = fixture(); $f->proposal['kind'] = 'reboot';
check($f->executor->execute($f->proposal, $f->sample)['outcome'] === 'handoff_pending' && $f->dispatches === 1 && $f->repairs === 0, 'reboot dispatch keeps an unconfirmed handoff outcome');

// A late native interlock must not acknowledge a repair and authorize later reboot escalation.
try {
    $f = fixture(); $f->busy = true; $root = sys_get_temp_dir() . '/recovery-guard-executor-' . bin2hex(random_bytes(6)); mkdir($root, 0700); $dirs[] = $root;
    foreach (['budget' => RecoveryPolicy::provision(), 'evidence' => DiagnosticJournal::emptyState()] as $n => $v) {
        mkdir($root . '/' . $n, 0700); $s = new StateStore($root . '/' . $n, $sync); $s->exclusive(fn($s) => $s->provision($v)); $stores[$n] = $s;
    }
    $journal = new DiagnosticJournal($stores['evidence']);
    $gates = $f->snapshot['interlocks'] + ['boot_id' => $f->sample['boot_id'], 'context_id' => $f->sample['context_id']];
    $coordinator = new ActionCoordinator(new RecoveryPolicy(), $stores['budget'], fn() => $gates, $journal->capture(...), $f->executor->execute(...), fn() => $f->time, $journal->outcome(...));
    for ($f->time = 10000; $f->time <= 10600; $f->time += 30) $r = $coordinator->tick(array_replace($f->sample, ['time' => $f->time]), 'recover');
    $state = $stores['budget']->exclusive(fn($s) => $s->read());
    check($f->repairs === 0 && $f->dispatches === 0 && count($state['repair_times']) === 1 && $state['reboot_times'] === [] &&
        $state['episode']['repair_completed'] === null && $journal->records()[0]['result'] === 'interlock_inhibited', 'late interlock retains budget, records inhibition and never unlocks reboot escalation');
    echo "PASS: {$checks} native executor/coordinator checks; synthetic snapshots and fake action adapters.\n";
} finally { foreach ($dirs as $dir) { foreach (glob($dir . '/*') as $sub) { foreach (glob($sub . '/*') as $file) unlink($file); rmdir($sub); } rmdir($dir); } }
