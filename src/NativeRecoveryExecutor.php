<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Joins native repair/handoff adapters after the coordinator's durable reservation/evidence. */
final class NativeRecoveryExecutor
{
    public function __construct(private \Closure $snapshot, private \Closure $php, private \Closure $upgradeLease,
        private \Closure $repair, private \Closure $dispatch, private \Closure $wall, private \Closure $monotonic) {}

    public function execute(array $proposal, array $sample): array
    {
        if (!in_array($proposal['kind'] ?? null, ['repair_php_fpm', 'reboot'], true) || !is_string($proposal['id'] ?? null) ||
            !preg_match('/\A[a-zA-Z0-9_.:-]{1,130}\z/D', $proposal['id'])) throw new \InvalidArgumentException('Invalid recovery proposal');
        $started = false;
        try {
            $wall = ($this->wall)(); $mono = ($this->monotonic)();
            $fresh = function () use ($proposal, $sample, $wall, $mono): bool {
                $s = ($this->snapshot)();
                $c = Configuration::compile($s['settings'], $s['interfaces'], $s['vlans'], $s['virtual_ips']);
                $now = ($this->wall)(); $ticks = ($this->monotonic)();
                if (!is_int($wall) || !is_int($mono) || !is_int($now) || !is_int($ticks) || $ticks < $mono ||
                    $ticks - $mono > 15000000000 || abs(($now - $wall) - ($ticks - $mono) / 1e9) > 5 ||
                    !is_int($sample['time'] ?? null) || $now < $sample['time'] || $now - $sample['time'] > 30 ||
                    $proposal['id'] !== ($sample['boot_id'] ?? '') . ':' . $sample['time']) return false;
                if (!$c['enabled'] || $c['maintenance'] || !in_array($c['mode'], ['repair', 'recover'], true) ||
                    ($proposal['kind'] === 'reboot' && $c['mode'] !== 'recover') || $s['boot_id'] !== ($sample['boot_id'] ?? null) ||
                    hash('sha256', json_encode([$s['boot_id'], $c], JSON_THROW_ON_ERROR)) !== ($sample['context_id'] ?? null)) return false;
                foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) if (($s['interlocks'][$key] ?? null) !== false) return false;
                return true;
            };
            $operation = function (?\Closure $assertLease = null) use ($fresh, $proposal, &$started): array {
                $health = ($this->php)();
                if (!is_array($health) || ($health['ok'] ?? null) !== false || !$fresh()) return ['id' => $proposal['id'], 'outcome' => 'interlock_inhibited'];
                if ($assertLease !== null) $assertLease();
                $started = true;
                return $proposal['kind'] === 'repair_php_fpm' ? ($this->repair)($proposal) : ($this->dispatch)($proposal);
            };
            // Reboot only dispatches here. Its independent worker reacquires the native lease
            // after supervisor retirement and performs full PHP/LAN reconfirmation.
            if ($proposal['kind'] === 'reboot') return $fresh() ? $operation() : ['id' => $proposal['id'], 'outcome' => 'interlock_inhibited'];
            return ($this->upgradeLease)($fresh, $operation);
        } catch (\Throwable $error) {
            if ($started) throw $error;
            return ['id' => $proposal['id'], 'outcome' => 'interlock_inhibited'];
        }
    }
}
