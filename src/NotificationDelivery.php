<?php
declare(strict_types=1);
namespace RecoveryGuard;

final class NotificationDelivery
{
    public function __construct(private NotificationOutbox $outbox, private \Closure $sender, private \Closure $clock) {}
    public function once(): string
    {
        $job = $this->outbox->claim(($this->clock)());
        if ($job === null) return 'idle';
        try { $result = ($this->sender)($job); }
        catch (\Throwable) { $result = 'unknown'; }
        if (!in_array($result, ['accepted', 'rejected', 'disabled', 'target_changed'], true)) $result = 'unknown';
        $this->outbox->finish($job['id'], $job['token'], $result, ($this->clock)());
        return $result;
    }
}
