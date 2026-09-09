<?php
declare(strict_types=1);
require __DIR__ . '/../src/ProbeProcess.php';
require __DIR__ . '/../src/NetworkProbe.php';
use RecoveryGuard\ProbeProcess;
use RecoveryGuard\NetworkProbe;
if (PHP_OS !== 'FreeBSD') { fwrite(STDERR, "Requires FreeBSD; no simulation.\n"); exit(2); }
$runner = new ProbeProcess();
$result = $runner->run(['/sbin/sysctl', '-n', 'kern.ostype']);
if ($result['status'] !== 'exited' || $result['exit_code'] !== 0 || trim($result['stdout']) !== 'FreeBSD') {
    throw new RuntimeException('Native diagnostic command did not succeed: ' . $result['status']);
}
echo "PASS: native sysctl probe, duration_ms={$result['duration_ms']}, output bounded.\n";
if (isset($argv[1])) {
    $probe = new NetworkProbe($runner->run(...));
    $link = $probe->link($argv[1]);
    echo 'PASS: explicit-interface passive observation=' . json_encode($link) . "\n";
    if ($link === null) exit(3);
}
