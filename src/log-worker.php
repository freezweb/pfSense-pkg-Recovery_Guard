<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0');
ini_set('memory_limit', '32M');
require __DIR__ . '/LogRateProbe.php';
$probe = new \RecoveryGuard\LogRateProbe();
while (($line = fgets(STDIN, 1025)) !== false) {
    try {
        if (strlen($line) > 1024 || !str_ends_with($line, "\n")) exit(1);
        $request = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !is_string($request['id'] ?? null) || !preg_match('/\A[a-f0-9]{16}\z/D', $request['id']) ||
            !is_string($request['context'] ?? null) || !is_int($request['time'] ?? null)) exit(1);
        $value = $probe->check($request['context'], $request['time']);
        $response = json_encode(['id' => $request['id'], 'value' => $value], JSON_THROW_ON_ERROR) . "\n";
        if (fwrite(STDOUT, $response) !== strlen($response) || !fflush(STDOUT)) exit(1);
    } catch (Throwable) { exit(1); }
}
