<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Pure decision engine. No shell commands, network calls or filesystem writes. */
final class RecoveryPolicy
{
    public const DEFAULTS = [
        'boot_grace' => 600, 'sample_gap' => 45, 'repair_after' => 120,
        'reboot_after' => 600, 'repair_grace' => 180,
        'healthy_after' => 120, 'minimum_samples' => 3,
        'reboot_window' => 86400, 'max_reboots' => 1,
        'repair_window' => 900, 'max_repairs' => 1,
    ];
    private array $config;

    public function __construct(array $config = [])
    {
        if (array_diff_key($config, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown policy setting');
        }
        $this->config = array_replace(self::DEFAULTS, $config);
        foreach ($this->config as $value) {
            if (!is_int($value) || $value < 1) {
                throw new \InvalidArgumentException('Policy values must be positive integers');
            }
        }
        if ($this->config['reboot_after'] <= $this->config['repair_after']) {
            throw new \InvalidArgumentException('Reboot must follow the repair threshold');
        }
    }

    /** Only provision a fresh ledger explicitly; never replace a lost one silently. */
    public static function provision(): array
    {
        return ['version' => 3, 'operating_mode' => null, 'boot_id' => null, 'last_time' => null,
            'reboot_times' => [], 'repair_times' => [], 'episode' => null];
    }

    public function step(?array $state, array $sample): array
    {
        $result = fn(array $s, string $reason, ?array $proposal = null): array =>
            ['state' => $s, 'reason' => $reason, 'proposal' => $proposal,
             'executes_actions' => false];
        if (!$this->validState($state)) {
            return $result($state ?? [], 'invalid_or_missing_ledger');
        }
        if (!$this->validSample($sample)) {
            $state['episode'] = null;
            return $result($state, 'invalid_sample');
        }
        $now = $sample['time'];
        if (($state['last_time'] !== null && $now <= $state['last_time']) ||
            array_filter(array_merge($state['reboot_times'], $state['repair_times']), fn($time) => $time > $now)) {
            $state['episode'] = null;
            return $result($state, 'clock_or_duplicate_sample');
        }
        $newBoot = $state['boot_id'] !== $sample['boot_id'];
        $gap = $state['last_time'] === null ? 0 : $now - $state['last_time'];
        $state['boot_id'] = $sample['boot_id'];
        $state['last_time'] = $now;
        if ($newBoot || $gap > $this->config['sample_gap']) {
            $state['episode'] = null;
        }
        // Never inherit a failure interval through maintenance, a boot or unknown probes.
        if ($sample['maintenance'] || $sample['upgrade'] ||
            $sample['uptime'] < $this->config['boot_grace']) {
            $state['episode'] = null;
            return $result($state, 'inhibited');
        }
        foreach (['php_ok', 'critical_link_up', 'local_reachable', 'log_storm'] as $key) {
            if ($sample[$key] === null) {
                $state['episode'] = null;
                return $result($state, 'unknown_probe');
            }
        }
        if ($sample['php_ok']) {
            if ($state['episode'] !== null) {
                $episode = &$state['episode'];
                $episode['healthy_since'] ??= $now;
                if ($now - $episode['healthy_since'] >= $this->config['healthy_after']) {
                    $state['episode'] = null;
                    return $result($state, 'recovered');
                }
                return $result($state, 'recovery_confirmation');
            }
            return $result($state, $sample['critical_link_up'] ? 'healthy' : 'link_only_failure');
        }
        // PHP failure alone permits one repair proposal, never a full reboot.
        $state['episode'] ??= [
            'since' => $now, 'samples' => 0, 'healthy_since' => null,
            'severe_since' => null, 'repair_request' => null,
            'repair_completed' => null, 'reboot_request' => null,
        ];
        $episode = &$state['episode'];
        if ($episode['healthy_since'] !== null) {
            // Brief recovery cancels the duration accumulated before it.
            $episode['since'] = $now;
            $episode['samples'] = 0;
            $episode['severe_since'] = null;
        }
        $episode['healthy_since'] = null;
        $episode['samples']++;
        $severe = !$sample['local_reachable'] &&
            (!$sample['critical_link_up'] || $sample['log_storm']);
        $episode['severe_since'] = $severe ? ($episode['severe_since'] ?? $now) : null;
        if ($episode['reboot_request'] !== null) {
            return $result($state, 'reboot_already_requested');
        }
        if ($now - $episode['since'] < $this->config['repair_after'] ||
            $episode['samples'] < $this->config['minimum_samples']) {
            return $result($state, 'confirming_failure');
        }
        if ($episode['repair_request'] === null) {
            $recentRepairs = array_filter($state['repair_times'],
                fn($time) => $time > $now - $this->config['repair_window']);
            if (count($recentRepairs) >= $this->config['max_repairs']) {
                return $result($state, 'repair_budget_exhausted');
            }
            $state['repair_times'] = array_values($recentRepairs);
            $state['repair_times'][] = $now;
            $episode['repair_request'] = ['id' => $sample['boot_id'] . ':' . $now, 'time' => $now];
            return $result($state, 'repair_required', [
                'kind' => 'repair_php_fpm', 'id' => $episode['repair_request']['id'],
                'capture_evidence_first' => true,
            ]);
        }
        // A proposal is not proof that a repair actually ran.
        if ($episode['repair_completed'] === null) {
            return $result($state, 'awaiting_repair_receipt');
        }
        if (!$severe || $now - $episode['severe_since'] < $this->config['reboot_after'] ||
            $now - $episode['repair_completed'] < $this->config['repair_grace']) {
            return $result($state, 'awaiting_recovery_or_corroboration');
        }
        $recent = array_filter($state['reboot_times'],
            fn($time) => $time > $now - $this->config['reboot_window']);
        if (count($recent) >= $this->config['max_reboots']) {
            return $result($state, 'reboot_budget_exhausted');
        }
        // Reserve BEFORE an executor could act. Must be durably committed by the caller.
        $state['reboot_times'] = array_values($recent);
        $state['reboot_times'][] = $now;
        $episode['reboot_request'] = $sample['boot_id'] . ':' . $now;
        return $result($state, 'persistent_combined_failure', [
            'kind' => 'reboot', 'id' => $episode['reboot_request'],
            'capture_evidence_first' => true, 'persist_reservation_first' => true,
        ]);
    }

    public function acknowledgeRepair(array $state, string $id, int $time): array
    {
        if (!$this->validState($state) || $state['episode'] === null ||
            ($state['episode']['repair_request']['id'] ?? null) !== $id ||
            $time < $state['episode']['repair_request']['time'] ||
            $time < $state['last_time'] || $state['episode']['repair_completed'] !== null) {
            throw new \InvalidArgumentException('Receipt must match a current pending repair');
        }
        $state['episode']['repair_completed'] = $time;
        $state['last_time'] = $time;
        return $state;
    }

    public function acceptsState(?array $state): bool
    {
        return $this->validState($state);
    }

    private function validSample(array $s): bool
    {
        foreach (['time', 'uptime'] as $key) {
            if (!isset($s[$key]) || !is_int($s[$key]) || $s[$key] < 0) return false;
        }
        if (!isset($s['boot_id']) || !is_string($s['boot_id']) ||
            !preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/D', $s['boot_id'])) return false;
        foreach (['maintenance', 'upgrade'] as $key) {
            if (!isset($s[$key]) || !is_bool($s[$key])) return false;
        }
        foreach (['php_ok', 'critical_link_up', 'local_reachable', 'log_storm'] as $key) {
            if (!array_key_exists($key, $s) || (!is_bool($s[$key]) && $s[$key] !== null)) return false;
        }
        return true;
    }

    private function validState(?array $s): bool
    {
        if ($s === null || ($s['version'] ?? null) !== 3 ||
            !array_key_exists('operating_mode', $s) ||
            !in_array($s['operating_mode'], [null, 'monitor', 'repair', 'recover'], true) ||
            !array_key_exists('episode', $s) || !array_key_exists('boot_id', $s) ||
            !array_key_exists('last_time', $s) || !isset($s['reboot_times']) ||
            !is_array($s['reboot_times']) || !isset($s['repair_times']) ||
            !is_array($s['repair_times'])) return false;
        if ($s['boot_id'] !== null && !is_string($s['boot_id'])) return false;
        if ($s['last_time'] !== null && (!is_int($s['last_time']) || $s['last_time'] < 0)) return false;
        foreach (array_merge($s['reboot_times'], $s['repair_times']) as $time) {
            if (!is_int($time) || $time < 0) return false;
            if ($s['last_time'] === null || $time > $s['last_time']) return false;
        }
        if ($s['episode'] === null) return true;
        $e = $s['episode'];
        if (!is_array($e)) return false;
        foreach (['since', 'samples'] as $key) {
            if (!isset($e[$key]) || !is_int($e[$key]) || $e[$key] < 0) return false;
        }
        foreach (['healthy_since', 'severe_since', 'repair_completed'] as $key) {
            if (!array_key_exists($key, $e) ||
                ($e[$key] !== null && (!is_int($e[$key]) || $e[$key] < 0))) return false;
        }
        if (!array_key_exists('repair_request', $e) || !array_key_exists('reboot_request', $e)) return false;
        if ($e['reboot_request'] !== null && !is_string($e['reboot_request'])) return false;
        if ($e['repair_request'] !== null && (!is_array($e['repair_request']) ||
            !isset($e['repair_request']['id'], $e['repair_request']['time']) ||
            !is_string($e['repair_request']['id']) || !is_int($e['repair_request']['time']))) return false;
        if ($s['last_time'] === null || $e['since'] > $s['last_time']) return false;
        foreach (['healthy_since', 'severe_since', 'repair_completed'] as $key) {
            if ($e[$key] !== null && $e[$key] > $s['last_time']) return false;
            if ($key !== 'repair_completed' && $e[$key] !== null && $e[$key] < $e['since']) return false;
        }
        if ($e['repair_request'] !== null &&
            ($e['repair_request']['time'] < 0 || $e['repair_request']['time'] > $s['last_time'] ||
             !in_array($e['repair_request']['time'], $s['repair_times'], true))) return false;
        if ($e['repair_completed'] !== null && ($e['repair_request'] === null ||
            $e['repair_completed'] < $e['repair_request']['time'])) return false;
        if ($e['reboot_request'] !== null && ($e['repair_completed'] === null || $s['reboot_times'] === [])) return false;
        return true;
    }
}
