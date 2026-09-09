<?php
declare(strict_types=1);
require __DIR__ . '/../src/ProbeProcess.php';
use RecoveryGuard\ProbeProcess;
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1') {
    fwrite(STDERR, "Requires FreeBSD in an isolated lab and RECOVERY_GUARD_ISOLATED_LAB=1.\n");
    exit(2);
}
$runner = new ProbeProcess();
$checks = 0;
foreach ([
    ['literal shell metacharacters', ['/bin/echo', '$(false); *'], 2.0, 1024, 'exited', 0, "$(false); *\n"],
    ['stderr', [PHP_BINARY, '-r', 'fwrite(STDERR,"fixture error"); exit(2);'], 2.0, 1024, 'exited', 2, ''],
    ['output cap', [PHP_BINARY, '-r', 'echo str_repeat("x", 200000);'], 2.0, 1024, 'output_limit', 0, null],
    ['timeout', ['/bin/sleep', '20'], 0.2, 1024, 'timeout', 124, ''],
    // A dead direct child must not hide a still-running grandchild.
    ['orphan descendant', ['/bin/sh', '-c', '/bin/sleep 20 & echo $!; exit 0'], 0.2, 1024, 'timeout', 124, null],
] as [$name, $command, $timeout, $limit, $status, $exitCode, $stdout]) {
    $result = $runner->run($command, $timeout, $limit);
    if ($result['status'] !== $status || $result['exit_code'] !== $exitCode ||
        strlen($result['stdout']) + strlen($result['stderr']) > $limit ||
        $result['duration_ms'] > ($timeout + 3.0) * 1000 ||
        ($stdout !== null && $result['stdout'] !== $stdout)) throw new RuntimeException('Failed: ' . $name);
    if ($name === 'orphan descendant') {
        if (!function_exists('posix_kill')) throw new RuntimeException('Cannot verify descendant cleanup without POSIX');
        $pid = trim($result['stdout']);
        if (!ctype_digit($pid) || (int) $pid < 2) throw new RuntimeException('Invalid descendant fixture PID');
        usleep(100000);
        if (posix_kill((int) $pid, 0)) throw new RuntimeException('Descendant remains after timeout');
    }
    $checks++;
}
echo "PASS: {$checks} native process lab checks; no service or system restarts.\n";
