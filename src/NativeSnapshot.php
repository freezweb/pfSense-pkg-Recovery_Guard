<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Project native configuration and bounded process observations into runtime inputs. */
final class NativeSnapshot
{
    public static function read(ProbeProcess $runner): array
    {
        $result = $runner->run(['/usr/local/bin/php', __DIR__ . '/native-snapshot.php'], 10.0, 65536);
        if ($result['status'] !== 'exited' || $result['exit_code'] !== 0 || $result['stderr'] !== '') throw new \RuntimeException('Native snapshot worker unavailable');
        $snapshot = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new \RuntimeException('Invalid native worker response');
        return $snapshot;
    }

    public static function project(array $config, string $bootTime, string $processes, array $markers, int $now): array
    {
        if (!preg_match('/\A\{ sec = ([0-9]+), usec = ([0-9]+) \}/', trim($bootTime), $boot) ||
            (int) $boot[1] <= 0 || (int) $boot[1] > $now || (int) $boot[2] > 999999 || strlen($processes) > 65536) {
            throw new \RuntimeException('Unusable native boot/process observation');
        }
        if ($processes === '' || !str_ends_with($processes, "\n")) throw new \RuntimeException('Incomplete process observation');
        $busy = ['upgrade' => false, 'other_repair' => false, 'shutting_down' => false];
        foreach (explode("\n", rtrim($processes, "\n")) as $line) {
            if (!preg_match('/\A\s*(?:0|[1-9][0-9]*)\s+\S/', $line)) throw new \RuntimeException('Unrecognized process record');
            foreach (['upgrade' => '(?:pfSense-upgrade|pkg|pkg-static)',
                'other_repair' => '(?:rc\.php-fpm_restart|rc\.restart_webgui|rc\.php_ini_setup)',
                'shutting_down' => '(?:rc\.reboot|rc\.halt|rc\.shutdown|shutdown|reboot|halt)'] as $kind => $names) {
                if (preg_match('~(?:\A|[\s/])' . $names . '(?:\s|\z)~', $line)) $busy[$kind] = true;
            }
        }
        foreach (['booting', 'pkg_dirty', 'upgrade_pid'] as $key) if (!is_bool($markers[$key] ?? null)) throw new \RuntimeException('Unknown lifecycle marker');
        $interfaces = [];
        if (!is_array($config['interfaces'] ?? null) || $config['interfaces'] === []) throw new \RuntimeException('Missing native interfaces');
        foreach ($config['interfaces'] as $name => $interface) {
            if (!is_string($name) || !is_array($interface)) throw new \RuntimeException('Invalid native interface');
            $interfaces[$name] = array_intersect_key($interface, array_flip(['enable', 'if', 'ipaddr', 'subnet', 'gateway']));
        }
        $settings = $config['installedpackages']['recoveryguard']['settings'] ?? [];
        if (!is_array($settings)) throw new \RuntimeException('Invalid package settings');
        $settings = array_intersect_key($settings, array_flip(['version', 'enabled', 'maintenance', 'mode', 'interface', 'peers']));
        $vlans = self::records($config, 'vlans', 'vlan', ['vlanif', 'if']);
        $vips = self::records($config, 'virtualip', 'vip', ['mode', 'subnet']);
        $ha = false;
        foreach ($vips as $vip) if (($vip['mode'] ?? null) === 'carp') $ha = true;
        $sync = $config['hasync'] ?? [];
        if (!is_array($sync)) throw new \RuntimeException('Invalid HA configuration');
        foreach (['pfsyncenabled', 'synchronizetoip', 'pfsyncpeerip', 'pfsyncinterface'] as $key) {
            if (array_key_exists($key, $sync) && $sync[$key] !== false && $sync[$key] !== null &&
                ($key === 'pfsyncenabled' || $sync[$key] !== '')) $ha = true;
        }
        // Compile once here to fail closed before this data leaves the helper.
        Configuration::compile($settings, $interfaces, $vlans, $vips);
        return ['settings' => $settings, 'interfaces' => $interfaces, 'vlans' => $vlans, 'virtual_ips' => $vips,
            'boot_id' => 'boot:' . $boot[1] . ':' . $boot[2], 'uptime' => $now - (int) $boot[1],
            'interlocks' => ['maintenance' => $markers['booting'], 'upgrade' => $busy['upgrade'] || $markers['pkg_dirty'] || $markers['upgrade_pid'],
                'ha_configured' => $ha, 'other_repair' => $busy['other_repair'], 'shutting_down' => $busy['shutting_down']]];
    }

    private static function records(array $config, string $section, string $list, array $fields): array
    {
        $records = $config[$section][$list] ?? [];
        if (!is_array($records) || !array_is_list($records)) throw new \RuntimeException('Invalid topology list');
        return array_map(static function ($record) use ($fields): array {
            if (!is_array($record)) throw new \RuntimeException('Invalid topology record');
            return array_intersect_key($record, array_flip($fields));
        }, $records);
    }
}
