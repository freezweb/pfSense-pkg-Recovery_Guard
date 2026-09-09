<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Compile only the package's settings and the necessary native topology, never secrets. */
final class Configuration
{
    public static function compile(array $settings, array $interfaces, array $vlans = [], array $virtualIps = []): array
    {
        if (isset($settings['version']) && $settings['version'] !== '1') throw new \InvalidArgumentException('Unsupported configuration version');
        $enabled = self::flag($settings, 'enabled');
        $maintenance = self::flag($settings, 'maintenance');
        $mode = $settings['mode'] ?? 'monitor';
        if (!in_array($mode, ['monitor', 'repair', 'recover'], true)) throw new \InvalidArgumentException('Invalid operating mode');
        $logical = $settings['interface'] ?? '';
        $text = $settings['peers'] ?? '';
        if (!is_string($logical) || !is_string($text) || strlen($text) > 512) throw new \InvalidArgumentException('Invalid local network settings');
        $peers = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($peers) > 8 || count(array_unique($peers)) !== count($peers)) throw new \InvalidArgumentException('Specify at most eight distinct peers');
        $result = ['enabled' => $enabled, 'maintenance' => $maintenance, 'mode' => $mode,
            'interface' => $logical, 'device' => null, 'link_device' => null, 'source' => null, 'prefix' => null, 'peers' => $peers];
        if (!$enabled && $logical === '' && $peers === []) return $result;
        if (!preg_match('/\A(?:lan|opt[0-9]+)\z/D', $logical) || !isset($interfaces[$logical]) || !is_array($interfaces[$logical])) {
            throw new \InvalidArgumentException('Select an assigned LAN interface');
        }
        $lan = $interfaces[$logical];
        if (!array_key_exists('enable', $lan) || !empty($lan['gateway'])) throw new \InvalidArgumentException('The selected interface must be enabled and must not have an upstream gateway');
        $source = $lan['ipaddr'] ?? '';
        if (!self::unicast($source)) throw new \InvalidArgumentException('A static IPv4 LAN address is required');
        $prefix = $lan['subnet'] ?? '';
        if (!is_string($prefix) || !preg_match('/\A(?:[1-9]|[12][0-9]|30)\z/D', $prefix)) throw new \InvalidArgumentException('Invalid LAN prefix');
        $prefix = (int) $prefix;
        $device = $lan['if'] ?? '';
        self::device($device);
        $link = $device; $visited = [];
        while (true) {
            if (isset($visited[$link]) || count($visited) >= 8) throw new \InvalidArgumentException('Cyclic or excessive VLAN parent chain');
            $visited[$link] = true; $parents = [];
            foreach ($vlans as $vlan) if (is_array($vlan) && ($vlan['vlanif'] ?? null) === $link) $parents[] = $vlan['if'] ?? '';
            if (count($parents) > 1) throw new \InvalidArgumentException('Ambiguous VLAN parent');
            if ($parents === []) break;
            self::device($parents[0]); $link = $parents[0];
        }
        $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
        $network = self::number($source) & $mask;
        $broadcast = $network | (0xffffffff ^ $mask);
        if (self::number($source) === $network || self::number($source) === $broadcast) throw new \InvalidArgumentException('LAN source must be a host address');
        $selfAddresses = [$source];
        foreach ($interfaces as $interface) if (is_array($interface) && self::unicast($interface['ipaddr'] ?? null)) $selfAddresses[] = $interface['ipaddr'];
        foreach ($virtualIps as $vip) if (is_array($vip) && self::unicast($vip['subnet'] ?? null)) $selfAddresses[] = $vip['subnet'];
        foreach ($peers as $peer) {
            if (!self::unicast($peer)) throw new \InvalidArgumentException('Peers must be numeric unicast IPv4 addresses');
            $number = self::number($peer);
            if (($number & $mask) !== $network || $number === $network || $number === $broadcast || in_array($peer, $selfAddresses, true)) {
                throw new \InvalidArgumentException('Each peer must be a different host on the selected LAN, excluding network, broadcast and firewall addresses');
            }
        }
        if ($enabled && count($peers) < 2) throw new \InvalidArgumentException('At least two local peers are required before enabling monitoring');
        return array_replace($result, ['device' => $device, 'link_device' => $link, 'source' => $source, 'prefix' => $prefix]);
    }

    public static function native(array $compiled): array
    {
        $native = ['version' => '1', 'mode' => $compiled['mode'], 'interface' => $compiled['interface'], 'peers' => implode("\n", $compiled['peers'])];
        foreach (['enabled', 'maintenance'] as $flag) if ($compiled[$flag]) $native[$flag] = 'on';
        return $native;
    }

    private static function flag(array $settings, string $key): bool
    {
        if (!array_key_exists($key, $settings)) return false;
        if (!in_array($settings[$key], ['', 'on', 'yes', true], true)) throw new \InvalidArgumentException('Invalid ' . $key . ' flag');
        return true;
    }

    private static function unicast(mixed $value): bool
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $first = (int) explode('.', $value)[0];
        return $first > 0 && $first !== 127 && $first < 224;
    }

    private static function number(string $ip): int { return unpack('N', inet_pton($ip))[1]; }
    private static function device(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/\A[a-z][a-z0-9_.-]{0,14}\z/D', $value)) throw new \InvalidArgumentException('Invalid native interface name');
    }
}
