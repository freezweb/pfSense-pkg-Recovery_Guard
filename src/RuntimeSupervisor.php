<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Independent runtime cycle. Platform access and actions use explicit adapters. */
final class RuntimeSupervisor
{
    private ActionCoordinator $coordinator;
    private EndpointBaseline $baseline;
    private ?string $context = null;
    private ?int $lastWall = null;
    private ?int $lastMono = null;
    private const INTERLOCKS = ['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'];

    public function __construct(
        RecoveryPolicy $policy,
        StateStore $store,
        private \Closure $snapshot,
        private \Closure $phpProbe,
        private NetworkProbe $network,
        private \Closure $logProbe,
        \Closure $captureEvidence,
        \Closure $executor,
        private \Closure $wallClock,
        private \Closure $monotonicClock,
        ?\Closure $recordOutcome = null,
    ) {
        $this->baseline = new EndpointBaseline();
        $this->coordinator = new ActionCoordinator($policy, $store, function (): array {
            $fresh = $this->readSnapshot();
            $checks = $fresh['interlocks'];
            $checks['maintenance'] = !$fresh['config']['enabled'] || $fresh['config']['maintenance'] || $checks['maintenance'] !== false;
            $checks['boot_id'] = $fresh['boot_id'];
            $checks['context_id'] = $fresh['context'];
            return $checks;
        }, $captureEvidence, $executor, $wallClock, $recordOutcome);
    }

    /** One cycle; caller schedules subsequent calls. Exceptions inhibit and erase volatile confirmation. */
    public function cycle(): array
    {
        try {
            $start = $this->clock($this->monotonicClock);
            $wall = $this->clock($this->wallClock);
            if ($this->lastMono !== null && ($start <= $this->lastMono || $wall <= $this->lastWall ||
                abs(($wall - $this->lastWall) - ($start - $this->lastMono) / 1e9) > 5)) {
                $this->lastMono = $start; $this->lastWall = $wall;
                return $this->inhibit('clock_changed');
            }
            $this->lastMono = $start; $this->lastWall = $wall;
            $snapshot = $this->readSnapshot();
            if (!$snapshot['config']['enabled']) return $this->inhibit('disabled');
            if (!$this->clear($snapshot)) return $this->inhibit('interlock_inhibited');
            if ($this->context !== $snapshot['context']) {
                $this->reset();
                $this->context = $snapshot['context'];
            }
            $this->withinDeadline($start);
            $php = ($this->phpProbe)();
            $php = is_array($php) ? $this->triState($php['ok'] ?? null) : null;
            $this->withinDeadline($start);
            $config = $snapshot['config'];
            $link = $this->network->link($config['link_device']);
            $this->withinDeadline($start);
            $peers = [];
            foreach ($config['peers'] as $peer) {
                $peers[$peer] = $this->network->localEndpoint($peer, $config['source'], $config['device']);
                $this->withinDeadline($start);
            }
            $storm = $this->triState(($this->logProbe)($snapshot['context'], intdiv($start, 1000000000)));
            $this->withinDeadline($start);
            // The second native snapshot catches changes while probes were in progress.
            $fresh = $this->readSnapshot();
            $this->withinDeadline($start);
            if ($fresh['context'] !== $snapshot['context'] || !$this->clear($fresh) ||
                !$fresh['config']['enabled'] || $fresh['uptime'] < $snapshot['uptime']) return $this->inhibit('snapshot_changed');
            $end = $this->clock($this->monotonicClock);
            $now = $this->clock($this->wallClock);
            if ($now < $wall || abs(($now - $wall) - ($end - $start) / 1e9) > 5) return $this->inhibit('clock_changed');
            $sample = ['time' => $now, 'uptime' => $fresh['uptime'], 'boot_id' => $snapshot['boot_id'],
                'context_id' => $snapshot['context'], 'maintenance' => false, 'upgrade' => false,
                'php_ok' => $php, 'critical_link_up' => $link, 'log_storm' => $storm,
                'local_reachable' => $this->baseline->observe($snapshot['context'], intdiv($end, 1000000000), $peers)];
            $result = $this->coordinator->tick($sample, $config['mode']);
            // No raw configuration, command output or exception text enters runtime status.
            return ['reason' => $result['reason'], 'execution' => $result['execution'],
                'proposal' => $result['proposal']['kind'] ?? null, 'sample' => $sample];
        } catch (\Throwable) {
            return $this->inhibit('cycle_unavailable');
        }
    }

    private function readSnapshot(): array
    {
        $s = ($this->snapshot)();
        if (!is_array($s) || !is_string($s['boot_id'] ?? null) ||
            !preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/D', $s['boot_id']) ||
            !is_int($s['uptime'] ?? null) || $s['uptime'] < 0) throw new \RuntimeException('Invalid platform snapshot');
        foreach (['settings', 'interfaces', 'vlans', 'virtual_ips', 'interlocks'] as $key) {
            if (!is_array($s[$key] ?? null)) throw new \RuntimeException('Incomplete platform snapshot');
        }
        $checks = [];
        foreach (self::INTERLOCKS as $key) $checks[$key] = $this->triState($s['interlocks'][$key] ?? null);
        $config = Configuration::compile($s['settings'], $s['interfaces'], $s['vlans'], $s['virtual_ips']);
        $context = hash('sha256', json_encode([$s['boot_id'], $config], JSON_THROW_ON_ERROR));
        return ['config' => $config, 'interlocks' => $checks, 'boot_id' => $s['boot_id'],
            'uptime' => $s['uptime'], 'context' => $context];
    }

    private function clear(array $snapshot): bool
    {
        if ($snapshot['config']['maintenance']) return false;
        foreach (self::INTERLOCKS as $key) if ($snapshot['interlocks'][$key] !== false) return false;
        return true;
    }

    private function triState(mixed $value): ?bool { return is_bool($value) ? $value : null; }
    private function clock(\Closure $clock): int
    {
        $time = $clock();
        if (!is_int($time) || $time < 0) throw new \RuntimeException('Invalid runtime clock');
        return $time;
    }
    private function withinDeadline(int $start): void
    {
        $now = $this->clock($this->monotonicClock);
        if ($now < $start || $now - $start > 25000000000) throw new \RuntimeException('Cycle deadline exceeded');
    }
    private function reset(): void
    {
        $this->context = null;
        $this->baseline = new EndpointBaseline();
        $this->coordinator->resetObservations();
    }
    private function inhibit(string $reason): array
    {
        $this->reset();
        return ['reason' => $reason, 'execution' => 'none', 'proposal' => null, 'sample' => null];
    }
}
