<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** A bounded, one-use handoff; persisted intents are never replayed at service startup. */
final class RebootHandoff
{
    public function __construct(private StateStore $budget, private StateStore $intents,
        private DiagnosticJournal $evidence, private \Closure $clock, private ?\Closure $monotonic = null) {}
    public static function emptyState(): array { return ['version' => 1, 'intent' => null]; }
    public static function initialize(string $directory): void
    {
        $s = new StateStore($directory);
        if (!file_exists($directory) && !is_link($directory)) {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create handoff directory');
            $parent = fopen(dirname($directory), 'r');
            try { if (!$parent || !fsync($parent)) throw new \RuntimeException('Cannot persist handoff directory'); }
            finally { if (is_resource($parent)) fclose($parent); }
            $s->exclusive(fn($s) => $s->provision(self::emptyState()));
        }
        $s->exclusive(fn($s) => self::validate($s->read()));
    }

    /** Caller already owns the budget lock through ActionCoordinator. */
    public function prepare(array $proposal): string
    {
        $budget = $this->budget->read();
        $record = $this->evidenceFor($proposal, $budget);
        $now = $this->now();
        $this->freshTime($record['sample']['time'], $now);
        return $this->intents->exclusive(function ($s) use ($record): string {
            $state = $s->read(); self::validate($state);
            if ($state['intent'] !== null && $state['intent']['id'] === $record['id']) {
                if ($state['intent']['claimed_at'] !== null) throw new \RuntimeException('Reboot intent already consumed');
                return $state['intent']['token'];
            }
            $sample = $record['sample'];
            $intent = ['token' => bin2hex(random_bytes(16)), 'id' => $record['id'], 'boot_id' => $sample['boot_id'],
                'context_id' => $sample['context_id'], 'time' => $sample['time'], 'monotonic_ns' => $this->monotonicTime(), 'claimed_at' => null];
            $s->commit(['version' => 1, 'intent' => $intent]);
            return $intent['token'];
        });
    }

    /** Caller supplies the shared supervisor lease, never a PID-file-only check. */
    public function invoke(string $token, ServiceLoop $lease, \Closure $fresh, \Closure $reboot): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) throw new \InvalidArgumentException('Invalid handoff token');
        $lease->exclusive(function () use ($token, $fresh, $reboot): void {
            $this->budget->exclusive(function ($budgetStore) use ($token, $fresh, $reboot): void {
                $this->intents->exclusive(function ($s) use ($budgetStore, $token, $fresh, $reboot): void {
                    $state = $s->read(); self::validate($state); $intent = $state['intent'];
                    if ($intent === null || !hash_equals($intent['token'], $token) || $intent['claimed_at'] !== null) throw new \RuntimeException('No unused matching handoff');
                    $proposal = ['kind' => 'reboot', 'id' => $intent['id']];
                    $record = $this->evidenceFor($proposal, $budgetStore->read());
                    foreach (['boot_id', 'context_id', 'time'] as $key) if ($record['sample'][$key] !== $intent[$key]) throw new \RuntimeException('Handoff evidence changed');
                    $this->checkFresh($intent, $fresh());
                    // Claim must reach durable storage before native cleanup can stop services.
                    $state['intent']['claimed_at'] = $this->now();
                    $s->commit($state);
                    // A change or I/O delay while claiming consumes the attempt but inhibits reboot.
                    $this->checkFresh($intent, $fresh());
                    $reboot();
                    // A returned routine is not proof that the machine rebooted.
                    throw new \RuntimeException('Native reboot returned without a verified new boot');
                });
            });
        });
    }
    public function state(): array
    {
        return $this->intents->exclusive(function ($s) { $state = $s->read(); self::validate($state); return $state; });
    }

    /** Observe a later boot without replaying the intent or changing either action budget. */
    public function reconcile(array $observation): bool
    {
        return $this->budget->exclusive(function ($budget) use ($observation): bool {
            $state = $budget->read();
            if (!(new RecoveryPolicy())->acceptsState($state)) throw new \RuntimeException('Invalid retained action budget');
            return $this->intents->exclusive(function ($s) use ($state, $observation): bool {
                $saved = $s->read(); self::validate($saved); $intent = $saved['intent'];
                if ($intent === null || $intent['claimed_at'] === null || !in_array($intent['time'], $state['reboot_times'], true)) return false;
                return $this->evidence->observeBoot($intent, $observation);
            });
        });
    }
    private function evidenceFor(array $proposal, array $budget): array
    {
        if (!(new RecoveryPolicy())->acceptsState($budget) || $budget['operating_mode'] !== 'recover' ||
            ($proposal['kind'] ?? null) !== 'reboot' || !is_string($proposal['id'] ?? null) ||
            ($budget['episode']['reboot_request'] ?? null) !== $proposal['id'] ||
            $proposal['id'] !== $budget['boot_id'] . ':' . $budget['last_time'] ||
            !in_array($budget['last_time'], $budget['reboot_times'], true)) throw new \RuntimeException('Durable reboot reservation missing');
        foreach ($this->evidence->records() as $record) {
            if ($record['kind'] !== 'reboot' || $record['id'] !== $proposal['id']) continue;
            $sample = $record['sample'];
            if ($record['mode'] !== 'recover' || !in_array($record['result'], ['pending', 'handoff_pending'], true) ||
                $sample['boot_id'] !== $budget['boot_id'] || $sample['time'] !== $budget['last_time'] ||
                !is_string($sample['context_id']) || $sample['maintenance'] !== false || $sample['upgrade'] !== false ||
                $sample['php_ok'] !== false || $sample['local_reachable'] !== false ||
                ($sample['critical_link_up'] !== false && $sample['log_storm'] !== true)) throw new \RuntimeException('Reboot evidence is not actionable');
            return $record;
        }
        throw new \RuntimeException('Reboot evidence missing');
    }
    private function checkFresh(array $intent, mixed $checks): void
    {
        $this->freshTime($intent['time'], $this->now());
        $mono = $this->monotonicTime();
        if ($mono < $intent['monotonic_ns'] || $mono - $intent['monotonic_ns'] > 60000000000) throw new \RuntimeException('Monotonic handoff deadline exceeded');
        if (!is_array($checks) || ($checks['enabled'] ?? null) !== true || ($checks['mode'] ?? null) !== 'recover' ||
            ($checks['boot_id'] ?? null) !== $intent['boot_id'] || ($checks['context_id'] ?? null) !== $intent['context_id']) throw new \RuntimeException('Reboot context changed');
        foreach (['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'] as $key) {
            if (!array_key_exists($key, $checks) || $checks[$key] !== false) throw new \RuntimeException('Reboot interlock inhibits');
        }
        if (($checks['php_ok'] ?? null) !== false || ($checks['local_reachable'] ?? null) !== false ||
            !(($checks['critical_link_up'] ?? null) === false ||
                (($checks['critical_link_up'] ?? null) === true && ($checks['log_storm'] ?? null) === true))) {
            throw new \RuntimeException('Current faults do not justify reboot');
        }
    }
    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \RuntimeException('Invalid handoff clock');
        return $now;
    }
    private function monotonicTime(): int
    {
        $time = $this->monotonic !== null ? ($this->monotonic)() : hrtime(true);
        if (!is_int($time) || $time < 0) throw new \RuntimeException('Invalid monotonic handoff clock');
        return $time;
    }
    private function freshTime(int $time, int $now): void
    {
        if ($now < $time || $now - $time > 60) throw new \RuntimeException('Reboot intent expired or clock reversed');
    }
    private static function validate(array $state): void
    {
        if (array_keys($state) !== ['version', 'intent'] || $state['version'] !== 1) throw new \RuntimeException('Invalid handoff journal');
        $i = $state['intent'];
        if ($i === null) return;
        if (!is_array($i) || array_keys($i) !== ['token', 'id', 'boot_id', 'context_id', 'time', 'monotonic_ns', 'claimed_at'] ||
            !is_string($i['token']) || !preg_match('/\A[a-f0-9]{32}\z/D', $i['token']) ||
            !is_string($i['id']) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,130}\z/D', $i['id']) ||
            !is_string($i['boot_id']) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/D', $i['boot_id']) ||
            !is_string($i['context_id']) || !preg_match('/\A[a-f0-9]{64}\z/D', $i['context_id']) ||
            !is_int($i['time']) || $i['time'] < 0 || !is_int($i['monotonic_ns']) || $i['monotonic_ns'] < 0 || ($i['claimed_at'] !== null &&
                (!is_int($i['claimed_at']) || $i['claimed_at'] < $i['time']))) throw new \RuntimeException('Invalid handoff intent');
    }
}
