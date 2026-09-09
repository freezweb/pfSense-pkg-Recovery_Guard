<?php
declare(strict_types=1);
require __DIR__ . '/../src/Configuration.php';
require __DIR__ . '/../src/NativeSnapshot.php';
use RecoveryGuard\NativeSnapshot;
$config = ['interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24', 'password' => 'secret-omitted']],
    'installedpackages' => ['recoveryguard' => ['settings' => ['mode' => 'monitor', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3", 'token' => 'secret-omitted']]],
    'system' => ['user' => [['password' => 'secret-omitted']]], 'hasync' => ['password' => 'secret-omitted']];
$markers = ['booting' => false, 'pkg_dirty' => false, 'upgrade_pid' => false];
$boot = "{ sec = 1000, usec = 123 } Thu Jan  1 00:16:40 1970\n";
$processes = "  0 [kernel]\n  1 /sbin/init\n 123 /usr/local/bin/php /usr/local/pkg/recovery_guard/native-snapshot.php\n";
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$result = NativeSnapshot::project($config, $boot, $processes, $markers, 2000);
check($result['boot_id'] === 'boot:1000:123' && $result['uptime'] === 1000, 'native boot identity and uptime');
check(!str_contains(json_encode($result), 'secret-omitted'), 'projection excludes system, interface, package and HA credentials');
check(!in_array(true, $result['interlocks'], true), 'ordinary snapshot has clear lifecycle gates');
foreach (['upgrade' => ['/usr/local/sbin/pfSense-upgrade -y', '/usr/local/sbin/pkg-static upgrade', '/usr/local/sbin/pkg info'],
    'other_repair' => ['/bin/sh /etc/rc.php-fpm_restart', '/usr/local/bin/php /etc/rc.restart_webgui', '/etc/rc.php_ini_setup'],
    'shutting_down' => ['/bin/sh /etc/rc.reboot', '/sbin/reboot', '/sbin/shutdown -r now']] as $gate => $commands) {
    foreach ($commands as $command) check(NativeSnapshot::project($config, $boot, $processes . ' 456 ' . $command . "\n", $markers, 2000)['interlocks'][$gate], 'native process gate: ' . $command);
}
foreach (array_keys($markers) as $key) {
    $changed = array_replace($markers, [$key => true]);
    check(in_array(true, NativeSnapshot::project($config, $boot, $processes, $changed, 2000)['interlocks'], true), 'native lifecycle marker: ' . $key);
}
foreach ([['hasync' => ['pfsyncenabled' => '']], ['hasync' => ['synchronizetoip' => '192.0.2.9']],
    ['virtualip' => ['vip' => [['mode' => 'carp', 'subnet' => '192.0.2.254']]]]] as $change) {
    check(NativeSnapshot::project(array_replace($config, $change), $boot, $processes, $markers, 2000)['interlocks']['ha_configured'], 'native HA inhibition');
}
foreach ([fn() => NativeSnapshot::project($config, 'unreadable', $processes, $markers, 2000),
    fn() => NativeSnapshot::project($config, $boot, '', $markers, 2000),
    fn() => NativeSnapshot::project($config, $boot, "truncated process output", $markers, 2000),
    fn() => NativeSnapshot::project($config, $boot, $processes, [], 2000),
    fn() => NativeSnapshot::project($config, $boot, $processes, $markers, 999)] as $bad) {
    $rejected = false; try { $bad(); } catch (RuntimeException) { $rejected = true; }
    check($rejected, 'uncertain native data is rejected');
}
echo "PASS: {$checks} native snapshot projection and interlock checks; synthetic data only.\n";
