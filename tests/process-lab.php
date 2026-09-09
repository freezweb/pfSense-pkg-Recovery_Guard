<?php
declare(strict_types=1);
require __DIR__ . '/../src/ProbeProcess.php';
use RecoveryGuard\ProbeProcess;
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) {
    fwrite(STDERR, "Requires FreeBSD, /root/RECOVERY_GUARD_ISOLATED_LAB and RECOVERY_GUARD_ISOLATED_LAB=1.\n");
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
    ['TERM resistant', [PHP_BINARY, __DIR__ . '/process-fixture.php', 'ignore_term'], 0.2, 1024, 'timeout', 124, null],
    ['detached descendant', [PHP_BINARY, __DIR__ . '/process-fixture.php', 'detached'], 0.2, 1024, 'timeout', 124, null],
    ['killed wrapper', [PHP_BINARY, __DIR__ . '/process-fixture.php', 'kill_wrapper'], 0.2, 1024, 'cleanup_unknown', null, null],
] as [$name, $command, $timeout, $limit, $status, $exitCode, $stdout]) {
    $result = $runner->run($command, $timeout, $limit);
    if ($result['status'] !== $status || ($exitCode !== null && $result['exit_code'] !== $exitCode) ||
        strlen($result['stdout']) + strlen($result['stderr']) > $limit ||
        $result['duration_ms'] > ($timeout + 3.0) * 1000 ||
        ($stdout !== null && $result['stdout'] !== $stdout)) throw new RuntimeException('Failed: ' . $name .
            ' status=' . $result['status'] . ' exit=' . json_encode($result['exit_code']));
    if (in_array($name, ['orphan descendant', 'TERM resistant', 'detached descendant', 'killed wrapper'], true)) {
        if (!function_exists('posix_kill')) throw new RuntimeException('Cannot verify descendant cleanup without POSIX');
        $pid = trim($result['stdout']);
        if (!preg_match('/\A[0-9]+\z/D', $pid) || (int) $pid < 2) throw new RuntimeException('Invalid descendant fixture PID');
        usleep(100000);
        if ($name === 'killed wrapper') {
            // Unknown cleanup must never masquerade as a completed action. This finite
            // fixture exits by itself, so wait for its cleanup without killing unrelated PIDs.
            $deadline = hrtime(true) + 4000000000;
            while (posix_kill((int) $pid, 0) && hrtime(true) < $deadline) usleep(10000);
        }
        if (posix_kill((int) $pid, 0)) throw new RuntimeException('Descendant remains after timeout');
    }
    $checks++;
}
echo "PASS: {$checks} native process lab checks; no service or system restarts.\n";
