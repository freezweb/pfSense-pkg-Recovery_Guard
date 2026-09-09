<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Private worker-only read. Never return this projection over a subprocess pipe. */
final class NativeMailSettings
{
    public static function read(): array
    {
        require_once '/etc/inc/xmlparse.inc';
        $path = '/cf/conf/config.xml';
        clearstatcache(true, $path); $before = stat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 1 || $before['size'] > 8388608) throw new \RuntimeException('Invalid native configuration');
        $hash = hash_file('sha256', $path);
        $config = parse_xml_config($path, ['pfsense']);
        clearstatcache(true, $path); $after = stat($path);
        if (!is_array($config) || $after === false || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] ||
            $before['size'] !== $after['size'] || $hash !== hash_file('sha256', $path)) throw new \RuntimeException('Configuration changed during read');
        return self::project($config, file_exists('/var/run/booting') || file_exists('/var/run/pkg.dirty') || file_exists('/var/run/pfSense-upgrade.pid'));
    }

    public static function project(array $config, bool $booting): array
    {
        $settings = $config['installedpackages']['recoveryguard']['settings'] ?? [];
        if (!is_array($settings)) throw new \RuntimeException('Invalid package settings');
        foreach (['enabled', 'notifications'] as $key) if (isset($settings[$key]) && !in_array($settings[$key], ['', 'on', 'yes', true], true)) throw new \RuntimeException('Invalid notification flag');
        return ['enabled' => isset($settings['enabled'], $settings['notifications']), 'booting' => $booting,
            'smtp' => $config['notifications']['smtp'] ?? [], 'hostname' => $config['system']['hostname'] ?? '', 'domain' => $config['system']['domain'] ?? ''];
    }
}
