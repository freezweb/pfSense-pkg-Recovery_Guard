<?php
declare(strict_types=1);
require __DIR__ . '/../src/FastCgiProbe.php';
$probe = new RecoveryGuard\FastCgiProbe();
$result = $probe->check(realpath(__DIR__ . '/../src/health.php'));
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
exit($result['ok'] === true ? 0 : 1);
