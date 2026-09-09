<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('memory_limit', '64M');
umask(0077);
$log = null;
$notification = null;
$exitCode = 0;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS !== 'FreeBSD' || !function_exists('pcntl_async_signals') ||
        !function_exists('posix_geteuid') || posix_geteuid() !== 0) throw new RuntimeException('Native root CLI required');
    foreach (['RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'Configuration', 'ProbeProcess', 'NativeSnapshot',
        'NetworkProbe', 'EndpointBaseline', 'FastCgiProbe', 'LogWorker', 'RuntimeSupervisor', 'ServiceLoop', 'ServiceState', 'DiagnosticJournal', 'RebootHandoff', 'NotificationOutbox', 'NotificationWorker', 'NativeUpgradeLease', 'NativeRecoveryExecutor', 'RepairProcess', 'RepairExecutor', 'RebootDispatcher'] as $name) require_once __DIR__ . '/' . $name . '.php';
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
        // Release gate: native pfSense lifecycle validation is required before arming recovery.
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
    $notification = $compiled['notifications'] ? new \RecoveryGuard\NotificationWorker() : null;
    $mailStatus = null;
    $reconciled = false;
    $handoff = new \RecoveryGuard\RebootHandoff($store, new \RecoveryGuard\StateStore($directory . '/handoff'), $diagnostics, time(...));
    $fpm = new \RecoveryGuard\FastCgiProbe();
    $repairProcess = new \RecoveryGuard\RepairProcess();
    $repair = new \RecoveryGuard\RepairExecutor($repairProcess->run(...), fn() => $fpm->check()['ok']);
    $upgrade = new \RecoveryGuard\NativeUpgradeLease();
    $dispatcher = new \RecoveryGuard\RebootDispatcher($handoff);
    $executor = new \RecoveryGuard\NativeRecoveryExecutor($snapshot, $fpm->check(...), $upgrade->exclusive(...),
        $repair->execute(...), $dispatcher->execute(...), time(...), hrtimeNanoseconds(...));
    $runtime = new \RecoveryGuard\RuntimeSupervisor(new \RecoveryGuard\RecoveryPolicy(), $store, $snapshot,
        fn() => $fpm->check(), new \RecoveryGuard\NetworkProbe($runner->run(...)), $log->check(...),
        $diagnostics->capture(...),
        $executor->execute(...), time(...), hrtimeNanoseconds(...), $diagnostics->outcome(...));
    openlog('recovery_guard', LOG_PID, LOG_DAEMON);
    syslog(LOG_NOTICE, 'Monitor service started');
    (new \RecoveryGuard\ServiceLoop($runtimeDir))->run(static function () use ($runtime, $notification, $log, &$stopping, &$mailStatus, &$reconciled, $handoff, $initial): array {
        if (!$reconciled) {
            try {
                if (!preg_match('/\Aboot:([0-9]+):[0-9]+\z/D', $initial['boot_id'], $boot)) throw new RuntimeException('Native boot identity unavailable');
                if ($handoff->reconcile(['boot_id' => $initial['boot_id'], 'time' => (int) $boot[1] + $initial['uptime'],
                    'uptime' => $initial['uptime'], 'monotonic_ns' => hrtime(true)])) syslog(LOG_NOTICE, 'New boot observed after a claimed reboot request; cause and service recovery are unconfirmed');
            } catch (Throwable) { syslog(LOG_WARNING, 'Reboot outcome could not be reconciled; no intent was replayed'); }
            $reconciled = true;
        }
        $status = $runtime->cycle();
        if (($status['execution'] ?? null) === 'handoff_pending') {
            if ($notification !== null) $notification->close();
            $log->close(); $stopping = true; return $status;
        }
        if ($notification !== null) {
            try { $newStatus = $notification->tick(); } catch (Throwable) { $newStatus = 'unavailable'; }
            if ($newStatus !== 'running' && $newStatus !== $mailStatus) { syslog(LOG_NOTICE, 'Notification worker: ' . $newStatus); $mailStatus = $newStatus; }
        }
        return $status;
    },
        static function () use (&$stopping): bool { return $stopping; },
        static function (array $status): void { syslog(LOG_NOTICE, $status['reason'] . ': ' . $status['execution']); });
    syslog(LOG_NOTICE, 'Monitor service stopped');
} catch (Throwable) {
    fwrite(STDERR, "Recovery Guard service unavailable; check configuration, dependencies and persistent journal.\n");
    $exitCode = 1;
} finally { if ($notification !== null) $notification->close(); if ($log !== null) $log->close(); }

exit($exitCode);

function hrtimeNanoseconds(): int { return hrtime(true); }
