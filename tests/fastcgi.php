<?php
declare(strict_types=1);
require __DIR__ . '/../src/FastCgiProbe.php';
use RecoveryGuard\FastCgiProbe;
$checks = 0;
foreach (['success' => true, 'fragmented' => true, 'stderr' => true, 'wrong_nonce' => false,
    'wrong_id' => false, 'truncated' => false, 'oversize' => false, 'application_error' => false,
    'timeout' => false] as $mode => $expected) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fastcgi-server.php', $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start loopback fixture');
    try {
        stream_set_timeout($pipes[1], 5);
        $address = trim((string) fgets($pipes[1]));
        if (!preg_match('/\A127\.0\.0\.1:[0-9]+\z/D', $address)) throw new RuntimeException('Invalid fixture endpoint');
        $probe = new FastCgiProbe(static fn(float $timeout) =>
            stream_socket_client('tcp://' . $address, $errno, $error, $timeout));
        $timeout = $mode === 'timeout' ? 0.2 : 2.0;
        $result = $probe->check('/fixture/health.php', $timeout);
        if ($result['ok'] !== $expected) throw new RuntimeException('Failed: ' . $mode . ' ' . json_encode($result));
        if ($result['duration_ms'] > ($timeout * 1000) + 250) throw new RuntimeException('Probe exceeded wall-time bound');
        $checks++;
    } finally {
        foreach ($pipes as $pipe) fclose($pipe);
        if (proc_get_status($process)['running']) proc_terminate($process);
        proc_close($process);
    }
}
$probe = new FastCgiProbe(static function () { throw new RuntimeException('socket_unavailable', 13); });
if ($probe->check()['ok'] !== null) throw new RuntimeException('Permission error must be unknown');
$checks++;
echo "PASS: {$checks} FastCGI transport checks; loopback fixtures only.\n";
