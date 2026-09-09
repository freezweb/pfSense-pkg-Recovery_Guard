<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD laboratory required');
$vendor = $argv[1] ?? '';
if (!is_file($vendor . '/Mail.php') || !is_file($vendor . '/SOURCES.txt')) throw new RuntimeException('Pinned private PEAR fixture required');
set_include_path($vendor . PATH_SEPARATOR . get_include_path());
foreach (['NotificationOutbox', 'NotificationMessage', 'NotificationSmtp'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{NotificationSmtp, NotificationMessage};
umask(0077); $checks = 0;
$dir = '/root/recovery-guard-smtp-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
foreach (['accept', 'reject'] as $case) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException('Private listener failed');
    $port = (int) explode(':', stream_socket_get_name($listener, false))[1];
    $pid = pcntl_fork();
    if ($pid === 0) {
        $socket = stream_socket_accept($listener, 5); fclose($listener);
        if (!$socket) exit(2); stream_set_timeout($socket, 3); fwrite($socket, "220 fixture ESMTP\r\n");
        $data = false; $body = '';
        while (($line = fgets($socket, 8192)) !== false) {
            if ($data) {
                if ($line === ".\r\n") { $data = false; file_put_contents($dir . '/message.txt', $body); fwrite($socket, "250 accepted\r\n"); }
                else $body .= $line;
            } elseif (str_starts_with($line, 'EHLO ') || str_starts_with($line, 'HELO ')) fwrite($socket, "250 fixture\r\n");
            elseif (str_starts_with($line, 'MAIL FROM:')) fwrite($socket, "250 sender\r\n");
            elseif (str_starts_with($line, 'RCPT TO:')) fwrite($socket, $case === 'reject' ? "550 rejected\r\n" : "250 recipient\r\n");
            elseif ($line === "DATA\r\n") { $data = true; fwrite($socket, "354 continue\r\n"); }
            elseif ($line === "RSET\r\n") fwrite($socket, "250 reset\r\n");
            elseif ($line === "QUIT\r\n") { fwrite($socket, "221 bye\r\n"); break; }
            else exit(3);
        }
        fclose($socket); exit(0);
    }
    fclose($listener);
    $settings = ['enabled' => true, 'booting' => false, 'hostname' => 'fixture', 'domain' => 'example.invalid',
        'smtp' => ['ipaddress' => '127.0.0.1', 'port' => $port, 'notifyemailaddress' => 'fixture@example.invalid']];
    $job = ['id' => str_repeat('a', 64), 'target' => NotificationSmtp::target($settings),
        'event' => ['action_id' => 'fixture:1000', 'kind' => 'reboot', 'mode' => 'recover', 'result' => 'handoff_pending', 'time' => 1000]];
    try {
        $result = (new NotificationSmtp(fn() => $settings))->send($job);
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || $result !== ($case === 'accept' ? 'accepted' : 'unknown')) throw new RuntimeException('Actual PEAR SMTP result mismatch: ' . $case);
        $checks++;
        if ($case === 'accept') {
            $bytes = file_get_contents($dir . '/message.txt');
            if (!str_contains($bytes, NotificationMessage::render($job)['message_id']) || !str_contains($bytes, 'completed reboot has not been established')) throw new RuntimeException('Message content changed in transport');
            $checks++;
        }
    } finally { if (posix_kill($pid, 0)) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); } }
}
echo "PASS: {$checks} actual PEAR SMTP checks against loopback-only fixture; no external delivery.\nEvidence directory: {$dir}\n";
