<?php
declare(strict_types=1);
// Loopback-only adversarial test fixture. Never installed as part of the package.
$mode = $argv[1] ?? 'success';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
echo stream_socket_get_name($server, false) . "\n"; flush();
$client = stream_socket_accept($server, 5);
if (!$client) exit(2);
stream_set_timeout($client, 5);
function take($stream, int $length): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $part = fread($stream, $length - strlen($bytes));
        if ($part === false || $part === '') exit(3);
        $bytes .= $part;
    }
    return $bytes;
}
$params = '';
for ($count = 0; $count < 10; $count++) {
    $h = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', take($client, 8));
    $body = take($client, $h['length']); take($client, $h['padding']);
    if ($h['type'] === 4) $params .= $body;
    if ($h['type'] === 5 && $h['length'] === 0) break;
}
preg_match('/RECOVERY_GUARD_NONCE([a-f0-9]{32})/', $params, $match);
$nonce = $match[1] ?? 'missing';
function frame(int $type, string $body, int $padding = 0, int $id = 1): string {
    return pack('CCnnCC', 1, $type, $id, strlen($body), $padding, 0) . $body . str_repeat("\0", $padding);
}
if ($mode === 'timeout') { usleep(800000); fclose($client); fclose($server); exit; }
if ($mode === 'wrong_nonce') $nonce = str_repeat('0', 32);
$content = "Content-Type: text/plain\r\n\r\nrecovery-guard:" . $nonce;
$end = frame(3, pack('NCxxx', $mode === 'application_error' ? 1 : 0, 0));
$reply = frame(6, substr($content, 0, 25), 3) . frame(6, substr($content, 25)) . $end;
if ($mode === 'wrong_id') $reply = frame(6, $content, 0, 2) . $end;
if ($mode === 'truncated') $reply = substr(frame(6, $content), 0, 10);
if ($mode === 'oversize') $reply = frame(6, str_repeat('a', 65000)) . frame(6, str_repeat('b', 1000)) . $end;
if ($mode === 'stderr') $reply = frame(7, 'a harmless simulated diagnostic') . $reply;
if ($mode === 'fragmented') {
    foreach (str_split($reply, 3) as $part) { fwrite($client, $part); usleep(1000); }
} else @fwrite($client, $reply);
fclose($client); fclose($server);
