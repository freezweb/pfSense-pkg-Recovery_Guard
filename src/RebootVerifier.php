<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Reconfirm current faults using the same configured peers as the reserved episode. */
final class RebootVerifier
{
    public function __construct(private \Closure $snapshot, private \Closure $php,
        private NetworkProbe $network, private \Closure $log, private \Closure $wall,
        private \Closure $monotonic, private ?\Closure $pause = null) {}

    public function check(): array
    {
        $start = $this->mono(); $wall = $this->time();
        [$config, $checks] = $this->read();
        $checks += ['php_ok' => null, 'local_reachable' => null, 'critical_link_up' => null, 'log_storm' => null];
        if (!$this->clear($checks)) return $checks;
        $php = ($this->php)(); $this->deadline($start, $wall);
        $checks['php_ok'] = is_array($php) && in_array($php['ok'] ?? null, [true, false], true) ? $php['ok'] : null;
        if ($checks['php_ok'] !== false) return $checks;
        $checks['critical_link_up'] = $this->network->link($config['link_device']); $this->deadline($start, $wall);
        if ($checks['critical_link_up'] === null) return $checks;
        // Seed a fresh interval only when a live link needs log-storm corroboration.
        $logStart = null;
        if ($checks['critical_link_up'] === true) {
            ($this->log)($checks['context_id'], $this->time());
            $logStart = $this->mono(); $this->deadline($start, $wall);
        }
        foreach ($config['peers'] as $peer) {
            $reply = $this->network->localEndpoint($peer, $config['source'], $config['device']);
            $this->deadline($start, $wall);
            // A reply or uncertainty cancels the destructive decision immediately.
            if ($reply !== false) { $checks['local_reachable'] = $reply; return $checks; }
        }
        $checks['local_reachable'] = false;
        $link = $this->network->link($config['link_device']); $this->deadline($start, $wall);
        if ($link !== $checks['critical_link_up']) { $checks['critical_link_up'] = null; return $checks; }
        if ($link === true) {
            while ($this->mono() - $logStart < 5000000000) {
                if ($this->pause !== null) ($this->pause)(); else usleep(100000);
                $this->deadline($start, $wall);
            }
            $storm = ($this->log)($checks['context_id'], $this->time());
            $checks['log_storm'] = is_bool($storm) ? $storm : null;
            $this->deadline($start, $wall);
        }
        [, $after] = $this->read(); $this->deadline($start, $wall);
        if ($after['context_id'] !== $checks['context_id'] || !$this->clear($after)) throw new \RuntimeException('Reboot verification context changed');
        $php = ($this->php)(); $this->deadline($start, $wall);
        $checks['php_ok'] = is_array($php) && is_bool($php['ok'] ?? null) ? $php['ok'] : null;
        // The original monitor already qualified these exact peers. A changed context is rejected
        // by the handoff; this one-shot verifier cannot create a new peer baseline or reservation.
        return $after + array_intersect_key($checks, array_flip(['php_ok', 'local_reachable', 'critical_link_up', 'log_storm']));
    }
    private function read(): array
    {
        $s = ($this->snapshot)();
        if (!is_array($s) || !is_string($s['boot_id'] ?? null) || !is_array($s['interlocks'] ?? null)) throw new \RuntimeException('Invalid reboot snapshot');
        $c = Configuration::compile($s['settings'], $s['interfaces'], $s['vlans'], $s['virtual_ips']);
        $checks = ['enabled' => $c['enabled'], 'mode' => $c['mode'], 'boot_id' => $s['boot_id'],
            'context_id' => hash('sha256', json_encode([$s['boot_id'], $c], JSON_THROW_ON_ERROR))];
        foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) {
            $checks[$key] = is_bool($s['interlocks'][$key] ?? null) ? $s['interlocks'][$key] : null;
        }
        if ($c['maintenance']) $checks['maintenance'] = true;
        return [$c, $checks];
    }
    private function clear(array $checks): bool
    {
        if ($checks['enabled'] !== true || $checks['mode'] !== 'recover') return false;
        foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) if ($checks[$key] !== false) return false;
        return true;
    }
    private function mono(): int
    {
        $n = ($this->monotonic)(); if (!is_int($n) || $n < 0) throw new \RuntimeException('Invalid monotonic clock'); return $n;
    }
    private function time(): int
    {
        $n = ($this->wall)(); if (!is_int($n) || $n < 0) throw new \RuntimeException('Invalid wall clock'); return $n;
    }
    private function deadline(int $start, int $wall): void
    {
        $elapsed = $this->mono() - $start;
        if ($elapsed < 0 || $elapsed > 25000000000 || abs(($this->time() - $wall) - $elapsed / 1e9) > 5) throw new \RuntimeException('Reboot verification deadline or clock changed');
    }
}
