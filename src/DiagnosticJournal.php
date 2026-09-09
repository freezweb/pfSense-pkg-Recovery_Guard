<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Bounded action evidence; no raw configuration, addresses, credentials or log text. */
final class DiagnosticJournal
{
    private const LIMIT = 64;
    private const RESULTS = ['pending', 'handoff_pending', 'mode_inhibited', 'interlock_inhibited', 'completed', 'failed', 'timeout_cleaned', 'unknown', 'boot_observed'];
    public function __construct(private StateStore $store) {}
    public static function emptyState(): array { return ['version' => 2, 'records' => []]; }

    public static function initialize(string $directory): void
    {
        $store = new StateStore($directory);
        if (!file_exists($directory) && !is_link($directory)) {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create evidence directory');
            $parent = fopen(dirname($directory), 'r');
            try { if (!$parent || !fsync($parent)) throw new \RuntimeException('Cannot persist evidence directory'); }
            finally { if (is_resource($parent)) fclose($parent); }
            $store->exclusive(fn($s) => $s->provision(self::emptyState()));
        }
        (new self($store))->records(); // Existing damaged evidence is not silently erased.
    }

    public function capture(array $proposal, array $sample, string $mode): void
    {
        $record = ['id' => $proposal['id'] ?? null, 'kind' => $proposal['kind'] ?? null,
            'mode' => $mode, 'sample' => [], 'result' => 'pending'];
        foreach (['time', 'uptime', 'boot_id', 'context_id', 'maintenance', 'upgrade',
            'php_ok', 'critical_link_up', 'local_reachable', 'log_storm'] as $key) {
            $record['sample'][$key] = $sample[$key] ?? null;
        }
        if (!self::validRecord($record)) throw new \InvalidArgumentException('Invalid diagnostic evidence');
        $this->store->exclusive(function ($s) use ($record): void {
            $state = $s->read(); self::validate($state);
            foreach ($state['records'] as $existing) {
                if ($existing['id'] === $record['id'] && $existing['kind'] === $record['kind']) {
                    $existing['result'] = 'pending'; unset($existing['boot_observation']);
                    if ($existing !== $record) throw new \RuntimeException('Conflicting action evidence');
                    return;
                }
            }
            $state['records'][] = $record;
            $state['records'] = array_slice($state['records'], -self::LIMIT);
            $s->commit($state);
        });
    }

    public function outcome(array $proposal, string $result): void
    {
        if (in_array($result, ['pending', 'boot_observed'], true) || !in_array($result, self::RESULTS, true)) throw new \InvalidArgumentException('Invalid diagnostic outcome');
        $this->store->exclusive(function ($s) use ($proposal, $result): void {
            $state = $s->read(); self::validate($state);
            foreach ($state['records'] as &$record) {
                if ($record['id'] === ($proposal['id'] ?? null) && $record['kind'] === ($proposal['kind'] ?? null)) {
                    if ($record['result'] === $result) return;
                    if ($record['result'] !== 'pending') throw new \RuntimeException('Action result already recorded');
                    $record['result'] = $result;
                    $s->commit($state);
                    return;
                }
            }
            throw new \RuntimeException('Action evidence missing');
        });
    }
    public function records(): array
    {
        return $this->store->exclusive(function ($s): array {
            $state = $s->read(); self::validate($state); return $state['records'];
        });
    }

    /** Only used with a validated, durably claimed intent while the caller owns handoff/budget locks. */
    public function observeBoot(array $intent, array $observation): bool
    {
        return $this->store->exclusive(function ($s) use ($intent, $observation): bool {
            $state = $s->read(); self::validate($state);
            foreach ($state['records'] as &$r) {
                if ($r['kind'] !== 'reboot' || $r['id'] !== $intent['id']) continue;
                if ($r['result'] === 'boot_observed') return false;
                if ($r['mode'] !== 'recover' || !in_array($r['result'], ['pending', 'handoff_pending', 'unknown'], true) ||
                    $r['sample']['boot_id'] !== $intent['boot_id'] || $r['sample']['context_id'] !== $intent['context_id'] ||
                    $r['sample']['time'] !== $intent['time'] || !is_int($intent['claimed_at']) ||
                    !is_int($observation['monotonic_ns'] ?? null) || $observation['monotonic_ns'] >= $intent['monotonic_ns']) return false;
                $observation['claimed_at'] = $intent['claimed_at']; $observation['previous_result'] = $r['result'];
                $updated = $r; $updated['result'] = 'boot_observed'; $updated['boot_observation'] = $observation;
                if (!self::validRecord($updated)) return false;
                $r = $updated; $state['version'] = 2; $s->commit($state); return true;
            }
            return false;
        });
    }
    private static function validate(array $state): void
    {
        if (array_keys($state) !== ['version', 'records'] || !in_array($state['version'], [1, 2], true) || !is_array($state['records']) ||
            !array_is_list($state['records']) || count($state['records']) > self::LIMIT) throw new \RuntimeException('Invalid evidence journal');
        $seen = [];
        foreach ($state['records'] as $r) {
            if (!is_array($r) || !self::validRecord($r) || ($state['version'] === 1 && $r['result'] === 'boot_observed')) throw new \RuntimeException('Invalid evidence record');
            $key = $r['kind'] . ':' . $r['id'];
            if (isset($seen[$key])) throw new \RuntimeException('Duplicate action evidence');
            $seen[$key] = true;
        }
    }
    private static function validRecord(array $r): bool
    {
        $keys = ['id', 'kind', 'mode', 'sample', 'result'];
        if (($r['result'] ?? null) === 'boot_observed') $keys[] = 'boot_observation';
        if (array_keys($r) !== $keys ||
            !is_string($r['id']) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,130}\z/D', $r['id']) ||
            !in_array($r['kind'], ['repair_php_fpm', 'reboot'], true) ||
            !in_array($r['mode'], ['monitor', 'repair', 'recover'], true) ||
            !in_array($r['result'], self::RESULTS, true) || !is_array($r['sample'])) return false;
        $s = $r['sample'];
        if (array_keys($s) !== ['time', 'uptime', 'boot_id', 'context_id', 'maintenance', 'upgrade', 'php_ok', 'critical_link_up', 'local_reachable', 'log_storm']) return false;
        foreach (['time', 'uptime'] as $k) if (!is_int($s[$k]) || $s[$k] < 0) return false;
        if (!is_string($s['boot_id']) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/D', $s['boot_id']) ||
            ($s['context_id'] !== null && (!is_string($s['context_id']) || !preg_match('/\A[a-f0-9]{64}\z/D', $s['context_id'])))) return false;
        foreach (['maintenance', 'upgrade'] as $k) if (!is_bool($s[$k])) return false;
        foreach (['php_ok', 'critical_link_up', 'local_reachable', 'log_storm'] as $k) if (!in_array($s[$k], [true, false, null], true)) return false;
        if ($r['result'] === 'boot_observed') {
            $o = $r['boot_observation'];
            if ($r['kind'] !== 'reboot' || $r['mode'] !== 'recover' || !is_array($o) ||
                array_keys($o) !== ['boot_id', 'time', 'uptime', 'monotonic_ns', 'claimed_at', 'previous_result'] ||
                !is_string($o['boot_id']) || !preg_match('/\Aboot:([1-9][0-9]{0,11}):([0-9]{1,6})\z/D', $o['boot_id'], $boot) ||
                !preg_match('/\Aboot:([1-9][0-9]{0,11}):([0-9]{1,6})\z/D', $s['boot_id'], $old) || $o['boot_id'] === $s['boot_id'] ||
                !in_array($o['previous_result'], ['pending', 'handoff_pending', 'unknown'], true)) return false;
            foreach (['time', 'uptime', 'monotonic_ns', 'claimed_at'] as $k) if (!is_int($o[$k]) || $o[$k] < 0) return false;
            if ($o['claimed_at'] < $s['time'] || $o['claimed_at'] - $s['time'] > 60 ||
                (int) $boot[1] < $o['claimed_at'] || (int) $boot[1] <= (int) $old[1] || $o['time'] < (int) $boot[1] ||
                $o['uptime'] !== $o['time'] - (int) $boot[1] || $o['uptime'] >= $s['uptime']) return false;
        }
        return true;
    }
}
