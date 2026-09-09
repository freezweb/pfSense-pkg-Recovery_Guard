<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('memory_limit', '128M');
umask(0077);
$lock = null;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS !== 'FreeBSD' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 ||
        !is_file('/etc/inc/xmlparse.inc') || !is_file('/etc/version')) throw new RuntimeException('Native root CLI required');
    foreach (['StateStore', 'DiagnosticJournal', 'NotificationOutbox', 'NotificationDelivery', 'NotificationMessage',
        'NotificationSmtp', 'NativeMailSettings', 'NotificationCycle'] as $name) require_once __DIR__ . '/' . $name . '.php';
    $runtime = '/var/run/recovery_guard';
    $stat = lstat($runtime);
    if ($stat === false || ($stat['mode'] & 0170777) !== 0040700 || $stat['uid'] !== 0 || is_link($runtime . '/notification.lock')) throw new RuntimeException('Unsafe notification runtime');
    $lock = fopen($runtime . '/notification.lock', 'c+b');
    if (!$lock || !chmod($runtime . '/notification.lock', 0600) || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Notification worker unavailable');
    $directory = '/cf/conf/recovery_guard';
    $outbox = new \RecoveryGuard\NotificationOutbox(new \RecoveryGuard\StateStore($directory . '/notifications'));
    $smtp = new \RecoveryGuard\NotificationSmtp(\RecoveryGuard\NativeMailSettings::read(...));
    $cycle = new \RecoveryGuard\NotificationCycle($outbox,
        new \RecoveryGuard\DiagnosticJournal(new \RecoveryGuard\StateStore($directory . '/diagnostics')),
        \RecoveryGuard\NativeMailSettings::read(...), $smtp->send(...), time(...));
    echo json_encode(['result' => $cycle->once()], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    // No native XML, PEAR error, recipient, credential or exception text leaves this process.
    echo "{\"result\":\"unknown\"}\n";
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
