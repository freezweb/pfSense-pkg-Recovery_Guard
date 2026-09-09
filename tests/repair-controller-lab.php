<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD lab required');
$binary = $argv[1] ?? '';
require __DIR__ . '/../src/RepairProcess.php';
require __DIR__ . '/../src/RepairExecutor.php';
require __DIR__ . '/../src/FastCgiProbe.php';
require __DIR__ . '/../src/Configuration.php';
require __DIR__ . '/../src/NativeUpgradeLease.php';
require __DIR__ . '/../src/NativeRecoveryExecutor.php';
if (!is_executable($binary)) throw new RuntimeException('Separately compiled lab controller required');
umask(0077);
$dir = '/root/recovery-guard-repair-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
$fixture = <<<'PHP'
<?php
pcntl_async_signals(true);
$mode = $argv[1]; $file = $argv[2];
$child = pcntl_fork();
if ($child === 0) {
    posix_setsid();
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents($file, (string)getmypid());
    // Self-expiry also contains a deliberately killed controller fixture.
    $end = microtime(true) + 8;
    while (microtime(true) < $end) usleep(10000);
    exit(0);
}
while (!is_file($file)) usleep(1000);
if ($mode === 'success') exit(0);
if ($mode === 'failure') exit(7);
if ($mode === 'killed') posix_kill(posix_getppid(), SIGKILL);
pcntl_signal(SIGTERM, SIG_IGN);
sleep(8);
PHP;
file_put_contents($dir . '/fixture.php', $fixture);
$checks = 0;
foreach (['success', 'failure', 'timeout', 'cancel', 'killed'] as $mode) {
    $pidPath = $dir . '/' . $mode . '.pid';
    $start = microtime(true);
    $process = proc_open([$binary, '500', PHP_BINARY, $dir . '/fixture.php', $mode, $pidPath],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture');
    if ($mode === 'cancel') {
        $end = microtime(true) + 2;
        while (!is_file($pidPath) && microtime(true) < $end) { clearstatcache(); usleep(1000); }
        proc_terminate($process, SIGTERM);
    }
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $result = json_decode($out, true);
    $pid = (int)file_get_contents($pidPath);
    if ($pid < 2) throw new RuntimeException('Fixture PID unavailable');
    if ($mode === 'success') {
        if ($exit !== 0 || ($result['outcome'] ?? null) !== 'controller_exited' ||
            microtime(true) - $start > 2 || !posix_kill($pid, 0)) throw new RuntimeException('Service did not survive successful controller exit');
        posix_kill($pid, SIGKILL);
    } elseif ($mode === 'killed') {
        if ($exit === 0 || $result !== null) throw new RuntimeException('Killed controller forged a receipt');
        // Its process tree is now uncertain, not a clean timeout or completed repair.
    } else {
        $expected = ['failure' => 'failed_cleaned', 'timeout' => 'timeout_cleaned', 'cancel' => 'cancelled_cleaned'][$mode];
        if ($exit !== ($mode === 'timeout' ? 124 : 70) || ($result['outcome'] ?? null) !== $expected ||
            microtime(true) - $start > 4 || posix_kill($pid, 0) || $error !== '') throw new RuntimeException('Incorrect cleanup: ' . $mode);
    }
    $checks++;
    if ($mode === 'killed') {
        $end = microtime(true) + 10;
        while (posix_kill($pid, 0) && microtime(true) < $end) usleep(10000);
        if (posix_kill($pid, 0)) throw new RuntimeException('Killed-wrapper fixture failed to expire');
    }
}
// Killing the PHP owner must ask the surviving controller to clean its tree.
$ownerPidPath = $dir . '/owner-death.pid';
$ownerResult = $dir . '/owner-result.json';
$ownerCode = '<?php $p=proc_open(' . var_export([$binary, '5000', PHP_BINARY, $dir . '/fixture.php', 'timeout', $ownerPidPath], true) .
    ',' . var_export([0 => ['file', '/dev/null', 'r'], 1 => ['file', $ownerResult, 'w'], 2 => ['file', '/dev/null', 'w']], true) .
    ',$pipes); $end=microtime(true)+2; while(!is_file(' . var_export($ownerPidPath, true) .
    ') && microtime(true)<$end) {clearstatcache(); usleep(1000);} posix_kill(getmypid(),SIGKILL);';
file_put_contents($dir . '/owner.php', $ownerCode);
$owner = proc_open([PHP_BINARY, $dir . '/owner.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $unused);
if (proc_close($owner) === 0) throw new RuntimeException('Owner-death fixture did not terminate');
$end = microtime(true) + 4;
do {
    $result = is_file($ownerResult) ? json_decode(file_get_contents($ownerResult), true) : null;
    if (is_array($result)) break;
    usleep(10000);
} while (microtime(true) < $end);
$pid = is_file($ownerPidPath) ? (int)file_get_contents($ownerPidPath) : 0;
if (($result['outcome'] ?? null) !== 'cancelled_cleaned' || $pid < 2 || posix_kill($pid, 0)) throw new RuntimeException('Owner death did not clean the repair tree');
$checks++;
$proposal = ['kind' => 'repair_php_fpm', 'id' => 'lab:1'];
foreach ([['success', true, 'completed'], ['success', false, 'failed'],
    ['success', null, 'failed'], ['failure', true, 'failed'], ['timeout', false, 'timeout_cleaned']] as $index => [$mode, $health, $expected]) {
    $pidPath = $dir . '/adapter-' . $index . '.pid';
    $runner = new \RecoveryGuard\RepairProcess([$binary, '500', PHP_BINARY, $dir . '/fixture.php', $mode, $pidPath]);
    $executor = new \RecoveryGuard\RepairExecutor($runner->run(...), fn() => $health);
    $receipt = $executor->execute($proposal);
    if ($receipt !== ['id' => 'lab:1', 'outcome' => $expected]) throw new RuntimeException('Invalid joined repair receipt');
    $pid = (int)file_get_contents($pidPath);
    if ($mode === 'success' && $pid > 1) posix_kill($pid, SIGKILL);
    $checks++;
}
foreach (['cleanup_unknown', 'cancelled_cleaned', 'unavailable'] as $kind) {
    $executor = new \RecoveryGuard\RepairExecutor(fn() => ['outcome' => $kind], fn() => true);
    $rejected = false;
    try { $executor->execute($proposal); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Uncertain execution authorized escalation');
    $checks++;
}
// A real PHP-FPM daemon on a private Unix socket, with no network listener.
if (!is_executable('/usr/local/sbin/php-fpm')) throw new RuntimeException('Lab PHP-FPM binary required');
$socket = $dir . '/fpm.socket'; $fpmPidPath = $dir . '/fpm.pid';
$configuration = "[global]\npid = {$fpmPidPath}\nerror_log = {$dir}/fpm.log\ndaemonize = yes\n" .
    "[recovery_guard_lab]\nuser = root\ngroup = wheel\nlisten = {$socket}\nlisten.mode = 0600\n" .
    "pm = static\npm.max_children = 1\n";
file_put_contents($dir . '/fpm.conf', $configuration);
$probe = new \RecoveryGuard\FastCgiProbe(static function (float $timeout) use ($socket) {
    $s = @stream_socket_client('unix://' . $socket, $errno, $error, $timeout);
    if ($s === false) throw new RuntimeException('socket_unavailable', $errno);
    return $s;
});
$health = fn() => $probe->check(realpath(__DIR__ . '/../src/health.php'))['ok'];
if ($health() !== false) throw new RuntimeException('Absent private FPM was not detected');
$checks++;
try {
    $runner = new \RecoveryGuard\RepairProcess([$binary, '2000', '/usr/local/sbin/php-fpm', '-R', '-D', '-y', $dir . '/fpm.conf']);
    $executor = new \RecoveryGuard\RepairExecutor($runner->run(...), $health);
    $snapshot = ['settings' => ['enabled' => 'on', 'mode' => 'repair', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3"],
        'interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']], 'vlans' => [], 'virtual_ips' => [],
        'boot_id' => 'lab:boot', 'interlocks' => ['maintenance' => false, 'upgrade' => false, 'ha_configured' => false, 'other_repair' => false, 'shutting_down' => false]];
    $now = time(); $sample = ['time' => $now, 'boot_id' => 'lab:boot', 'context_id' => hash('sha256', json_encode(['lab:boot',
        \RecoveryGuard\Configuration::compile($snapshot['settings'], $snapshot['interfaces'])], JSON_THROW_ON_ERROR))];
    $proposal = ['kind' => 'repair_php_fpm', 'id' => 'lab:boot:' . $now];
    $lease = new \RecoveryGuard\NativeUpgradeLease($dir . '/upgrade.lock');
    $native = new \RecoveryGuard\NativeRecoveryExecutor(fn() => $snapshot, fn() => ['ok' => $health()], $lease->exclusive(...),
        $executor->execute(...), fn() => throw new LogicException('No reboot in repair fixture'), time(...), fn() => hrtime(true));
    $receipt = $native->execute($proposal, $sample);
    $fpmPid = (int)file_get_contents($fpmPidPath);
    if ($receipt['outcome'] !== 'completed' || $fpmPid < 2 || !posix_kill($fpmPid, 0) || $health() !== true) {
        throw new RuntimeException('Real FPM did not remain healthy after controller exit');
    }
    $checks++;
    if ($native->execute($proposal, $sample)['outcome'] !== 'interlock_inhibited' || (int) file_get_contents($fpmPidPath) !== $fpmPid) throw new RuntimeException('Healthy private FPM was restarted again');
    $checks++;
} finally {
    if (is_file($fpmPidPath)) {
        $fpmPid = (int)file_get_contents($fpmPidPath);
        if ($fpmPid > 1) {
            posix_kill($fpmPid, SIGQUIT);
            $end = microtime(true) + 5;
            while (posix_kill($fpmPid, 0) && microtime(true) < $end) usleep(10000);
            if (posix_kill($fpmPid, 0)) throw new RuntimeException('Private FPM did not stop');
        }
    }
}
echo "PASS: {$checks} native repair-controller checks; fixtures and private PHP-FPM, no pfSense service changes.\nEvidence: {$dir}\n";
