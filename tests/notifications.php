<?php
declare(strict_types=1);
foreach (['StateStore', 'NotificationOutbox', 'NotificationDelivery', 'NotificationMessage', 'NotificationSmtp'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{StateStore, NotificationOutbox, NotificationDelivery, NotificationMessage, NotificationSmtp};
$checks = 0; $directories = [];
$sync = PHP_OS_FAMILY === 'Windows' ? static function (string $p): void {} : null;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function rejects(Closure $f, string $name): void { try { $f(); } catch (Throwable) { check(true, $name); return; } check(false, $name); }
function fixture(): array {
    global $directories, $sync;
    $dir = sys_get_temp_dir() . '/recovery-guard-mail-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700); $directories[] = $dir;
    $s = new StateStore($dir, $sync); $s->exclusive(fn($s) => $s->provision(NotificationOutbox::emptyState()));
    return [new NotificationOutbox($s), $s, $dir];
}
function event(int $n = 0): array { return ['action_id' => 'boot:' . $n, 'kind' => 'reboot', 'mode' => 'recover', 'result' => 'handoff_pending', 'time' => 1000]; }
$target = str_repeat('a', 64);
try {
    [$q, $store, $dir] = fixture();
    check($q->enqueue($target, event()), 'enqueue succeeds');
    $before = file_get_contents($dir . '/state.json');
    check($q->enqueue($target, event()) && file_get_contents($dir . '/state.json') === $before, 'duplicate enqueue preserves bytes');
    check($q->claim(999) === null && file_get_contents($dir . '/state.json') === $before, 'early idle poll preserves bytes');
    $job = $q->claim(1000);
    check($job['attempts'] === 1 && $job['next_attempt'] === 1060 && $job['status'] === 'sending', 'claim reserves retry before sending');
    $q = new NotificationOutbox(new StateStore($dir, $sync));
    check($q->state()['items'][0] === $job, 'new process retains outstanding attempt');
    check($q->claim(1030) === null && $q->claim(1059) === null, 'crash does not permit immediate retry');
    $next = $q->claim(1060);
    check($next['attempts'] === 2 && $next['token'] !== $job['token'], 'expired claim receives a new token');
    rejects(fn() => $q->finish($job['id'], $job['token'], 'accepted', 1060), 'stale worker cannot mark a newer attempt accepted');
    $q->finish($next['id'], $next['token'], 'accepted', 1061);
    check($q->state()['items'][0]['status'] === 'accepted' && $q->claim(99999) === null, 'accepted item is no longer sent');
    rejects(fn() => $q->finish($next['id'], $next['token'], 'accepted', 1062), 'receipt cannot be replayed');

    [$q] = fixture(); $q->enqueue($target, event()); $now = 1000;
    $calls = 0;
    $delivery = new NotificationDelivery($q, function ($job) use (&$calls, $q): string {
        check($q->state()['items'][0] === $job, 'durable claim is visible before sender executes'); $calls++;
        throw new RuntimeException('Transport uncertain');
    }, function () use (&$now): int { return $now; });
    foreach ([60, 300, 900, 3600, 7200, 14400, 21600, 43200] as $delay) {
        check($delivery->once() === 'unknown', 'transport exception remains unknown');
        $item = $q->state()['items'][0];
        check($item['next_attempt'] === $now + $delay, 'bounded exponential retry schedule');
        $now += $delay;
    }
    check($calls === 8 && $q->state()['items'][0]['status'] === 'held' && $delivery->once() === 'idle', 'eight uncertain attempts exhaust delivery without losing the item');

    foreach (['disabled', 'target_changed', 'rejected', 'accepted', 'unexpected'] as $result) {
        [$q] = fixture(); $q->enqueue($target, event());
        $d = new NotificationDelivery($q, fn() => $result, fn() => 1000);
        check($d->once() === ($result === 'unexpected' ? 'unknown' : $result) &&
            $q->state()['items'][0]['status'] === match ($result) { 'disabled', 'target_changed' => 'held', 'accepted' => 'accepted', default => 'pending' }, 'delivery result ' . $result);
    }

    [$q, $s, $dir] = fixture(); $q->enqueue($target, event()); $job = $q->claim(1000);
    $before = file_get_contents($dir . '/state.json');
    check($q->claim(999) === null && file_get_contents($dir . '/state.json') === $before, 'clock reversal cannot claim');
    rejects(fn() => $q->finish($job['id'], $job['token'], 'accepted', 999), 'clock reversal cannot accept receipt');
    rejects(fn() => $q->finish($job['id'], $job['token'], 'accepted', 1031), 'receipt beyond lease is uncertain');
    // A crashed sender may be old while another delivery advances the global clock.
    $q->enqueue($target, event(1));
    $s->exclusive(function ($s) { $v = $s->read(); $v['last_time'] = 200000; $s->commit($v); });
    check($q->claim(200000)['attempts'] === 2, 'old crashed sending item remains recoverable after days');

    [$q] = fixture(); $q->enqueue($target, event());
    for ($i = 0; $i < 8; $i++) { $j = $q->claim($i === 0 ? 1000 : $j['next_attempt']); }
    check($q->claim($j['next_attempt']) === null && $q->state()['items'][0]['status'] === 'held', 'eight lost workers also exhaust retry budget');

    [$q, $s, $dir] = fixture();
    for ($i = 0; $i < 128; $i++) $q->enqueue($target, event($i));
    check(!$q->enqueue($target, event(128)) && count($q->state()['items']) === 128 && $q->state()['overflow_attempts'] === 1, 'full outbox counts loss and retains unsent records');
    $first = $q->claim(1000); $q->finish($first['id'], $first['token'], 'accepted', 1000);
    check($q->enqueue($target, event(128)) && $q->state()['items'][0]['event']['action_id'] === 'boot:1', 'only accepted records are evicted');
    check(filesize($dir . '/state.json') < 131072, 'full queue fits bounded journal');
    $before = file_get_contents($dir . '/state.json');
    $writes = 0;
    $q = new NotificationOutbox(new StateStore($dir, function (string $p) use (&$writes): void { $writes++; throw new RuntimeException('Ambiguous fsync'); }));
    $sends = 0;
    rejects(fn() => (new NotificationDelivery($q, function () use (&$sends) { $sends++; return 'accepted'; }, fn() => 1000))->once(), 'ambiguous claim commit aborts sender');
    check($writes === 1 && $sends === 0, 'failed persistence never reaches transport');

    [$q, $s] = fixture();
    rejects(fn() => $q->enqueue($target, event() + ['password' => 'SECRET']), 'extra event fields rejected');
    rejects(fn() => $q->enqueue('recipient@example.invalid', event()), 'raw recipient rejected as destination binding');
    $q->enqueue($target, event()); $j = $q->claim(1000);
    $message = NotificationMessage::render($j);
    check(str_contains($message['body'], 'completed reboot has not been established') && !str_contains($message['body'], 'boot:0'), 'unconfirmed reboot is truthful and omits internal action id');
    check(NotificationMessage::render($j)['message_id'] === $message['message_id'], 'retry message id is stable');
    $s->exclusive(function ($s) { $v = $s->read(); $v['items'][0]['status'] = 'accepted'; $s->commit($v); });
    rejects(fn() => $q->state(), 'contradictory acceptance is rejected');
    $config = ['enabled' => true, 'booting' => false, 'hostname' => 'firewall', 'domain' => 'example.invalid',
        'smtp' => ['ipaddress' => 'smtp.example.invalid', 'port' => '465', 'ssl' => '',
            'username' => 'fixture-user', 'password' => 'SECRET-FIXTURE', 'notifyemailaddress' => 'admin@example.invalid']];
    [$q] = fixture(); $q->enqueue(NotificationSmtp::target($config), event()); $j = $q->claim(1000);
    $calls = 0; $return = true; $seen = null;
    $sender = new NotificationSmtp(function () use (&$config) { return $config; },
        function ($params, $to, $headers, $body) use (&$calls, &$return, &$seen) { $calls++; $seen = [$params, $to, $headers, $body]; return $return; });
    check($sender->send($j) === 'accepted' && $calls === 1 && $seen[0]['password'] === 'SECRET-FIXTURE' &&
        $seen[0]['socket_options']['ssl']['verify_peer'] === true && $seen[0]['host'] === 'ssl://smtp.example.invalid', 'native settings retained in transport with TLS verification');
    check($seen[2]['Message-ID'] === NotificationMessage::render($j)['message_id'] &&
        !str_contains(json_encode($q->state()), 'SECRET-FIXTURE') && !str_contains(json_encode($q->state()), 'admin@'), 'credential and address stay out of durable queue');
    foreach ([false, null, 'error', new stdClass()] as $return) check($sender->send($j) === 'unknown', 'only explicit transport true proves acceptance');
    $return = true; $config['smtp']['password'] = 'ROTATED-FIXTURE';
    check($sender->send($j) === 'accepted', 'password rotation preserves destination binding');
    $beforeCalls = $calls;
    $config['smtp']['notifyemailaddress'] = 'different@example.invalid';
    check($sender->send($j) === 'target_changed' && $calls === $beforeCalls, 'changed recipient never reaches transport');
    $config['smtp']['notifyemailaddress'] = 'admin@example.invalid'; $config['enabled'] = false;
    check($sender->send($j) === 'disabled' && $calls === $beforeCalls, 'package opt-out inhibits sending');
    $config['enabled'] = true; $config['smtp']['disable'] = '';
    check($sender->send($j) === 'disabled' && $calls === $beforeCalls, 'native SMTP disable inhibits sending');
    unset($config['smtp']['disable']); $config['booting'] = true;
    check($sender->send($j) === 'unknown' && $calls === $beforeCalls, 'booting is retryable uncertainty');
    $config['booting'] = false;
    foreach (['notifyemailaddress' => "admin@example.invalid\r\nBcc: leak@example.invalid", 'ipaddress' => 'file:///tmp/mail', 'port' => 0] as $key => $value) {
        $bad = $config; $bad['smtp'][$key] = $value;
        rejects(fn() => NotificationSmtp::target($bad), 'invalid SMTP setting rejected: ' . $key);
    }
    $config['smtp']['sslvalidate'] = 'disabled';
    check($sender->send($j) === 'target_changed', 'TLS policy change invalidates queued target');
    echo "PASS: {$checks} durable notification checks; injected senders only, no mail sent.\n";
} finally {
    foreach ($directories as $dir) { foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir); }
}
