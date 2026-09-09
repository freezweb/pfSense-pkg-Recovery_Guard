<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB') ||
    !function_exists('pcntl_fork') || !function_exists('posix_setsid')) exit(2);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['ignore_term', 'detached', 'kill_wrapper'], true)) exit(2);
if ($mode === 'detached') {
    $child = pcntl_fork();
    if ($child < 0) exit(3);
    if ($child > 0) exit(0);
    if (posix_setsid() < 0) exit(3);
}
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
echo getmypid() . "\n";
flush();
if ($mode === 'kill_wrapper') {
    $parent = posix_getppid();
    // Refuse to kill a calling shell if this fixture is accidentally invoked directly.
    $p = proc_open(['/bin/ps', '-p', (string) $parent, '-o', 'comm='],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) exit(3);
    $name = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    if (proc_close($p) !== 0 || basename($name) !== 'timeout') exit(3);
    if (!posix_kill($parent, SIGKILL)) exit(3);
}
// Even if the runner fails, the fixture never becomes a permanent background process.
$end = hrtime(true) + ($mode === 'kill_wrapper' ? 3000000000 : 6000000000);
while (hrtime(true) < $end) usleep(10000);
