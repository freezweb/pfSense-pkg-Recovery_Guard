<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Coordinates a policy with a durable journal and bounded platform adapters. */
final class ActionCoordinator
{
    private ?array $observations = null;
    private ?array $journal = null;

    public function __construct(
        private RecoveryPolicy $policy,
        private StateStore $store,
        private \Closure $freshInterlocks,
        private \Closure $captureEvidence,
        private \Closure $executor,
        private \Closure $clock,
        private ?\Closure $recordOutcome = null,
    ) {}

    /** Invalidate a measurement episode without erasing durable action budgets. */
    public function resetObservations(): void
    {
        $this->observations = $this->journal = null;
    }

    public function tick(array $sample, string $mode): array
    {
        if (!in_array($mode, ['monitor', 'repair', 'recover'], true)) {
            throw new \InvalidArgumentException('Unknown operating mode');
        }
        $captured = null;
        try {
            return $this->store->exclusive(function (StateStore $store) use ($sample, $mode, &$captured): array {
                // Re-read under the action lock even when observations are cached. Another
                // supervisor may have reserved an action or the journal may have disappeared.
                $disk = $store->read();
                if (!$this->policy->acceptsState($disk)) throw new \RuntimeException('Invalid recovery journal');
                if ($this->journal !== $disk || $this->observations === null) {
                    $state = $disk;
                    // Process restart or a competing writer requires fresh fault confirmation.
                    // Persistent action budgets survive; old episode progress is not replayed.
                    $state['episode'] = null;
                } else {
                    $state = $this->observations;
                }
                $this->journal = $disk;
                $modeChanged = $state['operating_mode'] !== $mode;
                if ($modeChanged) {
                    // Reconfirm faults after mode changes; retain all conservative action budgets.
                    $state['episode'] = null;
                    $state['operating_mode'] = $mode;
                }
                $result = $this->policy->step($state, $sample);
                $proposal = $result['proposal'];
                $this->observations = $result['state'];
                if ($modeChanged || $proposal !== null) $this->persist($store, $result['state']);
                $result['execution'] = 'none';
                if ($proposal === null) return $result;
                // Disabled actions consume their reservation conservatively, without fake receipts.
                if ($mode === 'monitor' || ($proposal['kind'] === 'reboot' && $mode !== 'recover')) {
                    ($this->captureEvidence)($proposal, $sample, $mode);
                    $captured = $proposal;
                    $this->record($proposal, 'mode_inhibited'); $captured = null;
                    $result['execution'] = 'mode_inhibited';
                    return $result;
                }
                $checks = ($this->freshInterlocks)();
                if (!$this->clearInterlocks($checks, $sample)) {
                    $result['execution'] = 'interlock_inhibited';
                    return $result;
                }
                // Capture failure blocks the action; it never skips straight to a reboot.
                ($this->captureEvidence)($proposal, $sample, $mode);
                $captured = $proposal;
                if (!$this->clearInterlocks(($this->freshInterlocks)(), $sample)) {
                    $this->record($proposal, 'interlock_inhibited'); $captured = null;
                    $result['execution'] = 'interlock_inhibited';
                    return $result;
                }
                $receipt = ($this->executor)($proposal, $sample);
                if (!is_array($receipt) || ($receipt['id'] ?? null) !== $proposal['id'] ||
                    (!in_array($receipt['outcome'] ?? null, ['completed', 'failed', 'timeout_cleaned', 'interlock_inhibited'], true) &&
                        !(($receipt['outcome'] ?? null) === 'handoff_pending' && $proposal['kind'] === 'reboot'))) {
                    throw new \RuntimeException('Executor did not return a verifiable completion receipt');
                }
                $result['execution'] = $receipt['outcome'];
                $this->record($proposal, $receipt['outcome']); $captured = null;
                $result['executes_actions'] = !in_array($receipt['outcome'], ['handoff_pending', 'interlock_inhibited'], true);
                if ($proposal['kind'] === 'repair_php_fpm' && $receipt['outcome'] !== 'interlock_inhibited') {
                    $now = ($this->clock)();
                    if (!is_int($now)) throw new \RuntimeException('Invalid completion clock');
                    $result['state'] = $this->policy->acknowledgeRepair($result['state'], $proposal['id'], $now);
                    $this->persist($store, $result['state']);
                }
                return $result;
            });
        } catch (\Throwable $error) {
            if ($captured !== null) {
                try { $this->record($captured, 'unknown'); } catch (\Throwable) {}
            }
            // Includes uncertain rename/fsync and executor receipts. Never reuse cached
            // fault confirmation across a failed transaction.
            $this->observations = $this->journal = null;
            throw $error;
        }
    }

    private function record(array $proposal, string $result): void
    {
        if ($this->recordOutcome !== null) ($this->recordOutcome)($proposal, $result);
    }

    private function persist(StateStore $store, array $state): void
    {
        $store->commit($state);
        $this->journal = $this->observations = $state;
    }

    private function clearInterlocks(mixed $checks, array $sample): bool
    {
        if (!is_array($checks)) return false;
        foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) {
            // HA integration starts conservatively: active recovery is disabled for any CARP setup.
            if (!array_key_exists($key, $checks) || $checks[$key] !== false) return false;
        }
        if (($checks['boot_id'] ?? null) !== ($sample['boot_id'] ?? null)) return false;
        if (isset($sample['context_id']) && ($checks['context_id'] ?? null) !== $sample['context_id']) return false;
        $now = ($this->clock)();
        return is_int($now) && $now >= $sample['time'] && $now - $sample['time'] <= 30;
    }
}
