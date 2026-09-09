<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Coordinates a policy with a durable journal and bounded platform adapters. */
final class ActionCoordinator
{
    public function __construct(
        private RecoveryPolicy $policy,
        private StateStore $store,
        private \Closure $freshInterlocks,
        private \Closure $captureEvidence,
        private \Closure $executor,
        private \Closure $clock,
    ) {}

    public function tick(array $sample, string $mode): array
    {
        if (!in_array($mode, ['monitor', 'repair', 'recover'], true)) {
            throw new \InvalidArgumentException('Unknown operating mode');
        }
        return $this->store->exclusive(function (StateStore $store) use ($sample, $mode): array {
            $state = $store->read();
            if (!$this->policy->acceptsState($state)) throw new \RuntimeException('Invalid recovery journal');
            if ($state['operating_mode'] !== $mode) {
                // Reconfirm faults after mode changes; retain all conservative action budgets.
                $state['episode'] = null;
                $state['operating_mode'] = $mode;
            }
            $result = $this->policy->step($state, $sample);
            $proposal = $result['proposal'];
            $store->commit($result['state']);
            $result['execution'] = 'none';
            if ($proposal === null) return $result;
            // Disabled actions consume their reservation conservatively, without fake receipts.
            if ($mode === 'monitor' || ($proposal['kind'] === 'reboot' && $mode !== 'recover')) {
                $result['execution'] = 'mode_inhibited';
                return $result;
            }
            $checks = ($this->freshInterlocks)();
            if (!$this->clearInterlocks($checks, $sample)) {
                $result['execution'] = 'interlock_inhibited';
                return $result;
            }
            // Capture failure blocks the action; it never skips straight to a reboot.
            ($this->captureEvidence)($proposal, $sample);
            if (!$this->clearInterlocks(($this->freshInterlocks)(), $sample)) {
                $result['execution'] = 'interlock_inhibited';
                return $result;
            }
            $receipt = ($this->executor)($proposal);
            if (!is_array($receipt) || ($receipt['id'] ?? null) !== $proposal['id'] ||
                !in_array($receipt['outcome'] ?? null, ['completed', 'failed', 'timeout_cleaned'], true)) {
                throw new \RuntimeException('Executor did not return a verifiable completion receipt');
            }
            $result['execution'] = $receipt['outcome'];
            $result['executes_actions'] = true;
            if ($proposal['kind'] === 'repair_php_fpm') {
                $now = ($this->clock)();
                if (!is_int($now)) throw new \RuntimeException('Invalid completion clock');
                $result['state'] = $this->policy->acknowledgeRepair($result['state'], $proposal['id'], $now);
                $store->commit($result['state']);
            }
            return $result;
        });
    }

    private function clearInterlocks(mixed $checks, array $sample): bool
    {
        if (!is_array($checks)) return false;
        foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) {
            // HA integration starts conservatively: active recovery is disabled for any CARP setup.
            if (!array_key_exists($key, $checks) || $checks[$key] !== false) return false;
        }
        if (($checks['boot_id'] ?? null) !== ($sample['boot_id'] ?? null)) return false;
        $now = ($this->clock)();
        return is_int($now) && $now >= $sample['time'] && $now - $sample['time'] <= 30;
    }
}
