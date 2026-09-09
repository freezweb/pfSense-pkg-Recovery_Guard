<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('memory_limit', '64M');
umask(0077);
$log = null;
$exitCode = 0;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS !== 'FreeBSD' || !function_exists('pcntl_async_signals') ||
        !function_exists('posix_geteuid') || posix_geteuid() !== 0) throw new RuntimeException('Native root CLI required');
    foreach (['RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'Configuration', 'ProbeProcess', 'NativeSnapshot',
        'NetworkProbe', 'EndpointBaseline', 'FastCgiProbe', 'LogWorker', 'RuntimeSupervisor', 'ServiceLoop', 'ServiceState', 'DiagnosticJournal'] as $name) require_once __DIR__ . '/' . $name . '.php';
    $runner = new \RecoveryGuard\ProbeProcess();
    $command = $argv[1] ?? 'run';
    $directory = '/cf/conf/recovery_guard';
    $store = new \RecoveryGuard\StateStore($directory);
    if ($command === 'init') {
        if (!is_dir('/cf/conf') || !is_file('/etc/inc/xmlparse.inc')) throw new RuntimeException('Native config directory required');
        \RecoveryGuard\ServiceState::initialize($directory);
        exit(0);
    }
    if (!in_array($command, ['enabled', 'run'], true)) throw new RuntimeException('Unknown service command');
    $stopping = false;
    $snapshot = static function () use ($runner, &$stopping): array {
        $data = \RecoveryGuard\NativeSnapshot::read($runner);
        // The preview has no real executors: never silently accept an armed configuration.
        if (($data['settings']['mode'] ?? 'monitor') !== 'monitor') throw new RuntimeException('Active recovery unavailable');
        if ($stopping) $data['interlocks']['shutting_down'] = true;
        return $data;
    };
    $initial = $snapshot();
    $compiled = \RecoveryGuard\Configuration::compile($initial['settings'], $initial['interfaces'], $initial['vlans'], $initial['virtual_ips']);
    if (!$compiled['enabled']) exit($command === 'enabled' ? 2 : 0);
    if (!(new \RecoveryGuard\RecoveryPolicy())->acceptsState($store->exclusive(fn($s) => $s->read()))) throw new RuntimeException('Invalid service journal');
    $diagnostics = new \RecoveryGuard\DiagnosticJournal(new \RecoveryGuard\StateStore($directory . '/diagnostics'));
    $diagnostics->records();
    if ($command === 'enabled') exit(0);
    $runtimeDir = '/var/run/recovery_guard';
    if (!file_exists($runtimeDir) && !mkdir($runtimeDir, 0700)) throw new RuntimeException('Cannot create runtime directory');
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
    pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });
    $log = new \RecoveryGuard\LogWorker();
    $fpm = new \RecoveryGuard\FastCgiProbe();
    $runtime = new \RecoveryGuard\RuntimeSupervisor(new \RecoveryGuard\RecoveryPolicy(), $store, $snapshot,
        fn() => $fpm->check(), new \RecoveryGuard\NetworkProbe($runner->run(...)), $log->check(...),
        $diagnostics->capture(...),
        static function (): never { throw new RuntimeException('Action adapter unavailable'); }, time(...), hrtimeNanoseconds(...), $diagnostics->outcome(...));
    openlog('recovery_guard', LOG_PID, LOG_DAEMON);
    syslog(LOG_NOTICE, 'Monitor service started');
    (new \RecoveryGuard\ServiceLoop($runtimeDir))->run($runtime->cycle(...),
        static function () use (&$stopping): bool { return $stopping; },
        static function (array $status): void { syslog(LOG_NOTICE, $status['reason'] . ': ' . $status['execution']); });
    syslog(LOG_NOTICE, 'Monitor service stopped');
} catch (Throwable) {
    fwrite(STDERR, "Recovery Guard service unavailable; check configuration, dependencies and persistent journal.\n");
    $exitCode = 1;
} finally { if ($log !== null) $log->close(); }

exit($exitCode);

function hrtimeNanoseconds(): int { return hrtime(true); }
