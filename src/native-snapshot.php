<?php
declare(strict_types=1);
// Run only through a bounded diagnostic worker. No config.inc boot/cache/backup path.
ini_set('display_errors', '0');
ini_set('memory_limit', '128M');
set_error_handler(static function (): never { throw new RuntimeException('Native snapshot read failed'); });
$phase = 'platform';
try {
    if (PHP_SAPI !== 'cli' || PHP_OS !== 'FreeBSD' || !is_file('/etc/inc/xmlparse.inc') || !is_file('/etc/version')) {
        throw new RuntimeException('Unsupported native platform');
    }
    require_once __DIR__ . '/Configuration.php';
    require_once __DIR__ . '/NativeSnapshot.php';
    require_once __DIR__ . '/ProbeProcess.php';
    require_once '/etc/inc/xmlparse.inc';
    $phase = 'configuration';
    $path = '/cf/conf/config.xml';
    $before = stat($path);
    if (($before['mode'] & 0170000) !== 0100000 || $before['size'] < 1 || $before['size'] > 8388608) throw new RuntimeException('Invalid config file');
    $hash = hash_file('sha256', $path);
    $phase = 'xml_parse';
    $config = parse_xml_config($path, ['pfsense']);
    $phase = 'configuration_verification';
    clearstatcache(true, $path);
    $after = stat($path);
    if (!is_array($config) || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] ||
        $before['size'] !== $after['size'] || $hash !== hash_file('sha256', $path)) throw new RuntimeException('Configuration changed during read');
    $runner = new \RecoveryGuard\ProbeProcess();
    $read = static function (array $argv, int $limit) use ($runner): string {
        $result = $runner->run($argv, 2.0, $limit);
        if ($result['status'] !== 'exited' || $result['exit_code'] !== 0 || $result['stderr'] !== '') throw new RuntimeException('Native probe unavailable');
        return $result['stdout'];
    };
    $phase = 'boot_identity';
    $boot = $read(['/sbin/sysctl', '-n', 'kern.boottime'], 1024);
    $phase = 'processes';
    $processes = $read(['/bin/ps', 'axww', '-o', 'pid=', '-o', 'command='], 65536);
    $marker = static function (string $path): bool {
        if (!is_dir(dirname($path)) || !is_readable(dirname($path))) throw new RuntimeException('Lifecycle directory unavailable');
        clearstatcache(true, $path);
        return file_exists($path) || is_link($path);
    };
    $phase = 'projection';
    $snapshot = \RecoveryGuard\NativeSnapshot::project($config, $boot, $processes,
        ['booting' => $marker('/var/run/booting'), 'pkg_dirty' => $marker('/var/run/pkg.dirty'),
         'upgrade_pid' => $marker('/var/run/pfSense-upgrade.pid')], time());
    unset($config, $processes);
    echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable) {
    // Parser failures and command arguments may contain secrets: never echo exception text.
    fwrite(STDERR, "Native snapshot unavailable: " . $phase . "\n");
    exit(1);
}
