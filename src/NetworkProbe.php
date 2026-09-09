<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Read-only, explicit-target collectors. Unknown results never become failures. */
final class NetworkProbe
{
    public function __construct(private \Closure $run) {}

    public function link(string $interface): ?bool
    {
        if (!preg_match('/\A[a-z][a-z0-9_.-]{0,14}\z/D', $interface)) {
            throw new \InvalidArgumentException('Invalid interface name');
        }
        $result = ($this->run)(['/sbin/ifconfig', $interface], 2.0, 16384);
        if (!$this->validResult($result) || $result['exit_code'] !== 0 || $result['stderr'] !== '') return null;
        $text = $result['stdout'];
        if (!preg_match('/\A' . preg_quote($interface, '/') . ': flags=[0-9a-f]+<([^>]*)>/i', $text, $flags)) return null;
        // Administratively disabled interfaces must not trigger automated recovery.
        if (!in_array('UP', explode(',', $flags[1]), true)) return null;
        if (preg_match_all('/^\s*status: (active|no carrier)\s*$/m', $text, $status) !== 1) return null;
        return $status[1][0] === 'active';
    }

    public function endpoint(string $target, string $source): ?bool
    {
        foreach ([$target, $source] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Explicit IPv4 addresses required');
            }
            $first = (int) explode('.', $ip)[0];
            if ($first === 0 || $first === 127 || $first >= 224) {
                throw new \InvalidArgumentException('Unicast addresses required');
            }
        }
        if ($target === $source) throw new \InvalidArgumentException('Endpoint must be a different host');
        // Bind the configured source. The config adapter must separately verify LAN routing.
        $result = ($this->run)(['/sbin/ping', '-4', '-n', '-q', '-c', '1', '-W', '1000',
            '-t', '2', '-S', $source, $target], 3.0, 4096);
        if (!$this->validResult($result)) return null;
        if (!preg_match('/^1 packets transmitted, ([01]) packets received,/m', $result['stdout'], $m)) return null;
        if ($m[1] === '1' && $result['exit_code'] === 0) return true;
        if ($m[1] === '0' && $result['exit_code'] === 2 && $result['stderr'] === '') return false;
        return null;
    }

    private function validResult(mixed $result): bool
    {
        return is_array($result) && ($result['status'] ?? null) === 'exited' &&
            is_int($result['exit_code'] ?? null) && is_string($result['stdout'] ?? null) &&
            is_string($result['stderr'] ?? null) && strlen($result['stdout']) + strlen($result['stderr']) <= 65536;
    }
}
