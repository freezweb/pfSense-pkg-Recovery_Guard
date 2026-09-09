<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Bounded action evidence; no raw configuration, addresses, credentials or log text. */
final class DiagnosticJournal
{
    private const LIMIT = 64;
    private const RESULTS = ['pending', 'handoff_pending', 'mode_inhibited', 'interlock_inhibited', 'completed', 'failed', 'timeout_cleaned', 'unknown'];
    public function __construct(private StateStore $store) {}
    public static function emptyState(): array { return ['version' => 1, 'records' => []]; }

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
                    $existing['result'] = 'pending';
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
        if ($result === 'pending' || !in_array($result, self::RESULTS, true)) throw new \InvalidArgumentException('Invalid diagnostic outcome');
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
    private static function validate(array $state): void
    {
        if (array_keys($state) !== ['version', 'records'] || $state['version'] !== 1 || !is_array($state['records']) ||
            !array_is_list($state['records']) || count($state['records']) > self::LIMIT) throw new \RuntimeException('Invalid evidence journal');
        $seen = [];
        foreach ($state['records'] as $r) {
            if (!is_array($r) || !self::validRecord($r)) throw new \RuntimeException('Invalid evidence record');
            $key = $r['kind'] . ':' . $r['id'];
            if (isset($seen[$key])) throw new \RuntimeException('Duplicate action evidence');
            $seen[$key] = true;
        }
    }
    private static function validRecord(array $r): bool
    {
        if (array_keys($r) !== ['id', 'kind', 'mode', 'sample', 'result'] ||
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
        return true;
    }
}
