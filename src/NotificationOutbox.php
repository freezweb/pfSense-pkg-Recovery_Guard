<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Durable, bounded delivery attempts. SMTP acceptance cannot provide exactly-once delivery. */
final class NotificationOutbox
{
    private const MAX = 128;
    private const DELAYS = [60, 300, 900, 3600, 7200, 14400, 21600, 43200];
    public function __construct(private StateStore $store) {}
    public static function emptyState(): array { return ['version' => 2, 'last_time' => 0, 'overflow_attempts' => 0, 'observed' => null, 'items' => []]; }

    public static function initialize(string $directory): void
    {
        $store = new StateStore($directory);
        if (!file_exists($directory) && !is_link($directory)) {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create notification directory');
            $parent = fopen(dirname($directory), 'r');
            try { if (!$parent || !fsync($parent)) throw new \RuntimeException('Cannot persist notification directory'); }
            finally { if (is_resource($parent)) fclose($parent); }
            $store->exclusive(fn($s) => $s->provision(self::emptyState()));
        }
        (new self($store))->state();
    }

    /** Atomically ingest changes to the rolling diagnostic snapshot. First call establishes a baseline. */
    public function synchronize(string $target, array $events): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $target) || !array_is_list($events) || count($events) > 64) throw new \InvalidArgumentException('Invalid notification snapshot');
        $current = [];
        foreach ($events as $event) {
            if (!is_array($event) || !self::validEvent($event)) throw new \InvalidArgumentException('Invalid snapshot event');
            $key = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            if (isset($current[$key])) throw new \InvalidArgumentException('Duplicate snapshot event');
            $current[$key] = $event;
        }
        $this->store->exclusive(function ($s) use ($target, $current): void {
            $state = $s->read(); self::validate($state); $before = $state;
            if ($state['observed'] === null) $state['observed'] = array_keys($current);
            else {
                $observed = [];
                foreach ($current as $key => $event) {
                    if (in_array($key, $state['observed'], true) || self::append($state, $target, $event)) $observed[] = $key;
                }
                $state['observed'] = $observed;
            }
            if ($state !== $before) $s->commit($state);
        });
    }

    /** False means capacity was exhausted; pending records were retained and overflow counted. */
    public function enqueue(string $target, array $event): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $target) || !self::validEvent($event)) throw new \InvalidArgumentException('Invalid notification event');
        return $this->store->exclusive(function ($s) use ($target, $event): bool {
            $state = $s->read(); self::validate($state);
            $before = $state; $result = self::append($state, $target, $event);
            if ($state !== $before) $s->commit($state);
            return $result;
        });
    }

    private static function append(array &$state, string $target, array $event): bool
    {
            $id = hash('sha256', json_encode([$target, $event], JSON_THROW_ON_ERROR));
            foreach ($state['items'] as $item) if ($item['id'] === $id) return true;
            if (count($state['items']) === self::MAX) {
                foreach ($state['items'] as $index => $item) if ($item['status'] === 'accepted') {
                    array_splice($state['items'], $index, 1); break;
                }
            }
            if (count($state['items']) === self::MAX) {
                if ($state['overflow_attempts'] < PHP_INT_MAX) $state['overflow_attempts']++;
                return false;
            }
            $state['items'][] = ['id' => $id, 'target' => $target, 'event' => $event, 'status' => 'pending',
                'attempts' => 0, 'next_attempt' => $event['time'], 'token' => null, 'lease_until' => null, 'last_result' => null];
            return true;
    }

    /** Commit the attempt and its retry deadline before any network operation. */
    public function claim(int $now): ?array
    {
        if ($now < 0 || $now > PHP_INT_MAX - 86400) throw new \InvalidArgumentException('Invalid notification clock');
        return $this->store->exclusive(function ($s) use ($now): ?array {
            $state = $s->read(); self::validate($state);
            if ($now < $state['last_time']) return null;
            $changed = false;
            foreach ($state['items'] as &$item) {
                if (!in_array($item['status'], ['pending', 'sending'], true) || $item['next_attempt'] > $now ||
                    ($item['lease_until'] !== null && $item['lease_until'] > $now)) continue;
                if ($item['attempts'] >= count(self::DELAYS)) {
                    $item['status'] = 'held'; $item['token'] = $item['lease_until'] = null;
                    $item['last_result'] = 'unknown'; $changed = true; continue;
                }
                $item['next_attempt'] = $now + self::DELAYS[$item['attempts']];
                $item['attempts']++; $item['status'] = 'sending';
                $item['token'] = bin2hex(random_bytes(16)); $item['lease_until'] = $now + 30;
                $state['last_time'] = $now; $s->commit($state); return $item;
            }
            unset($item);
            if ($changed) { $state['last_time'] = $now; $s->commit($state); }
            return null;
        });
    }

    public function finish(string $id, string $token, string $result, int $now): void
    {
        if (!in_array($result, ['accepted', 'rejected', 'unknown', 'disabled', 'target_changed'], true) || $now < 0) throw new \InvalidArgumentException('Invalid delivery result');
        $this->store->exclusive(function ($s) use ($id, $token, $result, $now): void {
            $state = $s->read(); self::validate($state);
            if ($now < $state['last_time']) throw new \RuntimeException('Delivery clock reversed');
            foreach ($state['items'] as &$item) {
                if ($item['id'] !== $id) continue;
                if ($item['status'] !== 'sending' || !is_string($item['token']) || !hash_equals($item['token'], $token) || $now > $item['lease_until']) throw new \RuntimeException('Stale delivery receipt');
                $item['status'] = $result === 'accepted' ? 'accepted' :
                    (in_array($result, ['disabled', 'target_changed'], true) || $item['attempts'] >= count(self::DELAYS) ? 'held' : 'pending');
                $item['last_result'] = $result; $item['token'] = $item['lease_until'] = null;
                $state['last_time'] = $now; $s->commit($state); return;
            }
            throw new \RuntimeException('Notification not found');
        });
    }
    public function state(): array
    {
        return $this->store->exclusive(function ($s): array { $v = $s->read(); self::validate($v); return $v; });
    }
    public static function validEvent(array $e): bool
    {
        return array_keys($e) === ['action_id', 'kind', 'mode', 'result', 'time'] &&
            is_string($e['action_id']) && preg_match('/\A[a-zA-Z0-9_.:-]{1,130}\z/D', $e['action_id']) === 1 &&
            in_array($e['kind'], ['repair_php_fpm', 'reboot'], true) && in_array($e['mode'], ['monitor', 'repair', 'recover'], true) &&
            in_array($e['result'], ['proposed', 'mode_inhibited', 'interlock_inhibited', 'completed', 'failed', 'timeout_cleaned', 'handoff_pending', 'unknown'], true) &&
            is_int($e['time']) && $e['time'] >= 0;
    }
    private static function validate(array $s): void
    {
        if (array_keys($s) !== ['version', 'last_time', 'overflow_attempts', 'observed', 'items'] || $s['version'] !== 2 ||
            !is_int($s['last_time']) || $s['last_time'] < 0 || !is_int($s['overflow_attempts']) || $s['overflow_attempts'] < 0 ||
            !is_array($s['items']) || !array_is_list($s['items']) || count($s['items']) > self::MAX) throw new \RuntimeException('Invalid notification outbox');
        if ($s['observed'] !== null) {
            if (!is_array($s['observed']) || !array_is_list($s['observed']) || count($s['observed']) > 64 || count(array_unique($s['observed'])) !== count($s['observed'])) throw new \RuntimeException('Invalid notification checkpoint');
            foreach ($s['observed'] as $key) if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D', $key)) throw new \RuntimeException('Invalid observed event');
        }
        $seen = [];
        foreach ($s['items'] as $i) {
            if (!is_array($i) || array_keys($i) !== ['id', 'target', 'event', 'status', 'attempts', 'next_attempt', 'token', 'lease_until', 'last_result'] ||
                !is_string($i['id']) || !is_string($i['target']) || !preg_match('/\A[a-f0-9]{64}\z/D', $i['target']) ||
                !is_array($i['event']) || !self::validEvent($i['event']) ||
                $i['id'] !== hash('sha256', json_encode([$i['target'], $i['event']], JSON_THROW_ON_ERROR)) || isset($seen[$i['id']]) ||
                !in_array($i['status'], ['pending', 'sending', 'accepted', 'held'], true) ||
                !is_int($i['attempts']) || $i['attempts'] < 0 || $i['attempts'] > count(self::DELAYS) ||
                !is_int($i['next_attempt']) || $i['next_attempt'] < 0 ||
                !in_array($i['last_result'], [null, 'accepted', 'rejected', 'unknown', 'disabled', 'target_changed'], true)) throw new \RuntimeException('Invalid notification item');
            if ($i['status'] === 'sending') {
                if ($i['attempts'] < 1 || !is_string($i['token']) || !preg_match('/\A[a-f0-9]{32}\z/D', $i['token']) ||
                    !is_int($i['lease_until']) || $i['lease_until'] < 0 || $i['lease_until'] > $i['next_attempt']) throw new \RuntimeException('Invalid delivery lease');
            } elseif ($i['token'] !== null || $i['lease_until'] !== null) throw new \RuntimeException('Unexpected delivery lease');
            if (($i['status'] === 'accepted') !== ($i['last_result'] === 'accepted') ||
                ($i['attempts'] === 0 && ($i['status'] !== 'pending' || $i['last_result'] !== null || $i['next_attempt'] !== $i['event']['time'])) ||
                ($i['status'] === 'held' && ($i['attempts'] === 0 || $i['last_result'] === null)) ||
                ($i['status'] === 'pending' && $i['attempts'] >= count(self::DELAYS)) ||
                (in_array($i['last_result'], ['disabled', 'target_changed'], true) && $i['status'] !== 'held')) throw new \RuntimeException('Inconsistent delivery receipt');
            $seen[$i['id']] = true;
        }
    }
}
