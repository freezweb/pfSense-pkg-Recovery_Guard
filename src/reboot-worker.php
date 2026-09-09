<?php
declare(strict_types=1);
ini_set('display_errors', '0'); ini_set('memory_limit', '64M'); umask(0077);
try {
    if (PHP_SAPI !== 'cli' || PHP_OS !== 'FreeBSD' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 ||
        !is_file('/etc/inc/system.inc')) throw new RuntimeException('Native root worker required');
    foreach (['RecoveryPolicy', 'StateStore', 'DiagnosticJournal', 'RebootHandoff', 'ServiceLoop', 'ProbeProcess', 'Configuration', 'NativeSnapshot'] as $name) require_once __DIR__ . '/' . $name . '.php';
    $root = '/cf/conf/recovery_guard';
    $handoff = new \RecoveryGuard\RebootHandoff(new \RecoveryGuard\StateStore($root), new \RecoveryGuard\StateStore($root . '/handoff'),
        new \RecoveryGuard\DiagnosticJournal(new \RecoveryGuard\StateStore($root . '/diagnostics')), time(...));
    $fresh = static function (): array {
        $s = \RecoveryGuard\NativeSnapshot::read(new \RecoveryGuard\ProbeProcess());
        $c = \RecoveryGuard\Configuration::compile($s['settings'], $s['interfaces'], $s['vlans'], $s['virtual_ips']);
        $checks = $s['interlocks'];
        $checks['maintenance'] = $c['maintenance'] || $checks['maintenance'] !== false;
        return $checks + ['enabled' => $c['enabled'], 'mode' => $c['mode'], 'boot_id' => $s['boot_id'],
            'context_id' => hash('sha256', json_encode([$s['boot_id'], $c], JSON_THROW_ON_ERROR))];
    };
    $reboot = static function (): void {
        require_once('config.inc'); require_once('functions.inc');
        // Native cleanup stops packages, including the already-retired monitor.
        system_reboot_sync();
    };
    $deadline = hrtime(true) + 35000000000;
    do {
        try {
            $handoff->invoke($argv[1] ?? '', new \RecoveryGuard\ServiceLoop('/var/run/recovery_guard'), $fresh, $reboot);
            break;
        } catch (\RecoveryGuard\SupervisorBusy) { usleep(100000); }
    } while (hrtime(true) < $deadline);
    throw new RuntimeException('Supervisor did not retire');
} catch (Throwable) {
    openlog('recovery_guard', LOG_PID, LOG_DAEMON);
    syslog(LOG_ERR, 'Reboot handoff did not establish a completed reboot; no automatic retry.');
    exit(1);
}
