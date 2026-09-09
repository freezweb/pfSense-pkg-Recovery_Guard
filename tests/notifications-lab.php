<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD laboratory required');
require __DIR__ . '/../src/NotificationWorker.php';
use RecoveryGuard\NotificationWorker;
umask(0077);
$dir = '/root/recovery-guard-notification-worker-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function until(Closure $test): bool { $end = microtime(true) + 4; do { clearstatcache(); if ($test()) return true; usleep(20000); } while (microtime(true) < $end); return false; }
$pidFile = $dir . '/child.pid';
$base = '<?php file_put_contents(' . var_export($pidFile, true) . ',(string)getmypid()); ';
file_put_contents($dir . '/hang.php', $base . 'pcntl_async_signals(true); pcntl_signal(SIGTERM,SIG_IGN); sleep(30);');
file_put_contents($dir . '/ok.php', $base . 'echo "{\"result\":\"accepted\"}\n";');
file_put_contents($dir . '/bad.php', $base . 'fwrite(STDERR,"SECRET-FIXTURE"); echo str_repeat("x",2048);');
$worker = null;
try {
    $worker = new NotificationWorker(PHP_BINARY, $dir . '/hang.php', 0.3);
    $start = microtime(true); $worker->tick();
    check(microtime(true) - $start < 0.2, 'starting slow sender does not block fault sampling');
    check(until(fn() => is_file($pidFile)), 'child started'); $pid = (int) file_get_contents($pidFile);
    check(until(fn() => !posix_kill($pid, 0)), 'native deadline kills TERM-resistant child without polling supervisor');
    check($worker->tick() === 'unknown', 'timeout cannot become SMTP acceptance'); $worker->close();
    unlink($pidFile);
    $worker = new NotificationWorker(PHP_BINARY, $dir . '/ok.php', 1);
    $worker->tick();
    check(until(function () use ($worker): bool { return $worker->tick() === 'accepted'; }), 'completed child yields explicit acceptance');
    $worker->close();
    $worker = new NotificationWorker(PHP_BINARY, $dir . '/bad.php', 1);
    $worker->tick();
    check(until(function () use ($worker): bool { return $worker->tick() === 'unknown'; }), 'oversized or stderr response remains unknown');
    $worker->close();
    unlink($pidFile);
    $worker = new NotificationWorker(PHP_BINARY, $dir . '/hang.php', 22);
    $worker->tick(); check(until(fn() => is_file($pidFile)), 'stop fixture started'); $pid = (int) file_get_contents($pidFile);
    $start = microtime(true); $worker->close();
    check(microtime(true) - $start < 1.5 && !posix_kill($pid, 0), 'service stop propagates termination through native reaper');

    unlink($pidFile);
    $owner = pcntl_fork();
    if ($owner === 0) {
        $w = new NotificationWorker(PHP_BINARY, $dir . '/hang.php', 0.4); $w->tick();
        if (!until(fn() => is_file($pidFile))) exit(2);
        posix_kill(getmypid(), SIGKILL);
        exit(3);
    }
    pcntl_waitpid($owner, $status);
    check(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'owner loss fixture is an actual SIGKILL');
    $pid = (int) file_get_contents($pidFile);
    check(until(fn() => !posix_kill($pid, 0)), 'sender deadline survives killed supervisor');
    echo "PASS: {$checks} native notification-worker checks; isolated process fixtures, no mail sent.\nEvidence directory: {$dir}\n";
} finally { if ($worker !== null) $worker->close(); }
