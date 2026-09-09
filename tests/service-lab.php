<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD laboratory required');
foreach (['RecoveryPolicy', 'StateStore', 'ServiceState', 'LogWorker'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, ServiceState, LogWorker};
umask(0077);
$dir = '/root/recovery-guard-service-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function command(array $argv): array {
    $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($p), $out, $error];
}
function until(Closure $test): bool { $end = microtime(true) + 5; do { clearstatcache(); if ($test()) return true; usleep(50000); } while (microtime(true) < $end); return false; }
ServiceState::initialize($dir . '/state');
$store = new StateStore($dir . '/state');
$store->exclusive(function ($s) { $state = $s->read(); $state['last_time'] = time(); $state['repair_times'] = [$state['last_time'] - 100]; $s->commit($state); });
$hash = hash_file('sha256', $dir . '/state/state.json');
ServiceState::initialize($dir . '/state');
check(hash_file('sha256', $dir . '/state/state.json') === $hash, 'reinstall validates existing budget without rewriting it');
mkdir($dir . '/runtime', 0700);
$root = realpath(__DIR__ . '/..');
$fixture = '<?php ' . "\n" . 'umask(0077); $dir=' . var_export($dir, true) . '; $root=' . var_export($root, true) . ';' . <<<'PHP'

if (($argv[1] ?? '') === 'enabled') exit(is_file($dir . '/enabled') ? 0 : 2);
require $root . '/src/ServiceLoop.php';
$stop = false; pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
try {
 (new \RecoveryGuard\ServiceLoop($dir . '/runtime'))->run(
  function () use ($dir) { file_put_contents($dir . '/ready', 'ready'); return ['reason' => 'healthy', 'execution' => 'none']; },
  function () use (&$stop) { return $stop; }, function () {});
} catch (Throwable) { exit(1); }
PHP;
file_put_contents($dir . '/fixture.php', $fixture);
$rcSource = file_get_contents($root . '/ports/sysutils/pfSense-pkg-Recovery_Guard/files/usr/local/etc/rc.d/recovery_guard.sh');
$rcSource = str_replace(["\r\n", '/var/run/recovery_guard.pid', '/usr/local/pkg/recovery_guard/daemon.php'], ["\n", $dir . '/service.pid', $dir . '/fixture.php'], $rcSource);
file_put_contents($dir . '/service.sh', $rcSource);
$rc = fn($action) => command(['/bin/sh', $dir . '/service.sh', $action]);
try {
    check($rc('start')[0] === 0 && !file_exists($dir . '/service.pid'), 'disabled rc start creates no daemon');
    file_put_contents($dir . '/enabled', '1');
    check($rc('start')[0] === 0 && until(fn() => is_file($dir . '/ready')), 'rc start runs the real service loop');
    $pid = trim(file_get_contents($dir . '/service.pid'));
    check($rc('status')[0] === 0, 'native rc status identifies the PHP child');
    $rc('start');
    check(trim(file_get_contents($dir . '/service.pid')) === $pid, 'duplicate rc start retains the same process');
    check(command([PHP_BINARY, $dir . '/fixture.php', 'run'])[0] === 1, 'direct duplicate launch cannot acquire supervisor lease');
    check($rc('stop')[0] === 0 && until(fn() => !file_exists($dir . '/service.pid')), 'TERM stops the loop and removes the daemon PID file');
    check($rc('restart')[0] === 0 && until(fn() => is_file($dir . '/service.pid')), 'rc restart after stop succeeds');
    check($rc('stop')[0] === 0, 'restarted process stops normally');
    check(hash_file('sha256', $dir . '/state/state.json') === $hash, 'service lifecycle leaves the durable budget unchanged');

    $workerScript = '<?php file_put_contents(' . var_export($dir . '/worker.pid', true) . ',(string)getmypid());' . <<<'PHP'
while (($line=fgets(STDIN))!==false) {
 $r=json_decode($line,true);
 if($r['time']===102) { sleep(4); exit(0); }
 if($r['time']===103) { fwrite(STDERR,'fixture failure'); exit(1); }
 if($r['time']===104) { echo str_repeat('x',1024); exit(0); }
 echo json_encode(['id'=>$r['time']===101?'bad':$r['id'],'value'=>false])."\n";
 fflush(STDOUT);
}
PHP;
    file_put_contents($dir . '/worker.php', $workerScript);
    $worker = new LogWorker(PHP_BINARY, $dir . '/worker.php');
    check($worker->check('test', 100) === false, 'worker returns correlated tri-state response');
    foreach ([101, 102, 103, 104] as $time) {
        $start = microtime(true);
        check($worker->check('test', $time) === null && microtime(true) - $start < 3.5, 'malformed/slow/error worker is bounded and unknown');
        $deadPid = (int) file_get_contents($dir . '/worker.pid');
        check(!posix_kill($deadPid, 0), 'failed worker has exited');
        check($worker->check('test', 100) === false, 'fresh worker starts after failure');
    }
    $worker->close();
    unlink($dir . '/state/state.json'); $rejected = false;
    try { ServiceState::initialize($dir . '/state'); } catch (RuntimeException) { $rejected = true; }
    check($rejected && !file_exists($dir . '/state/state.json'), 'reinstall cannot reset a missing journal');
    echo "PASS: {$checks} native service/worker checks; isolated fixture entrypoint, no firewall actions.\n";
    echo "Evidence directory: {$dir}\n";
} finally { if (isset($worker)) $worker->close(); $rc('stop'); }
