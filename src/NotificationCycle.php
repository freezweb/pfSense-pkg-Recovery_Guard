<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Runs only in the bounded child; no SMTP calls or outbox locks in the fault supervisor. */
final class NotificationCycle
{
    public function __construct(private NotificationOutbox $outbox, private DiagnosticJournal $diagnostics,
        private \Closure $settings, private \Closure $sender, private \Closure $clock) {}
    public function once(): string
    {
        $settings = ($this->settings)();
        if (($settings['enabled'] ?? false) !== true || isset($settings['smtp']['disable'])) return 'disabled';
        if (($settings['booting'] ?? null) !== false) return 'unknown';
        $events = [];
        foreach ($this->diagnostics->records() as $r) $events[] = ['action_id' => $r['id'], 'kind' => $r['kind'], 'mode' => $r['mode'],
            'result' => $r['result'] === 'pending' ? 'proposed' : $r['result'], 'time' => $r['boot_observation']['time'] ?? $r['sample']['time']];
        $this->outbox->synchronize(NotificationSmtp::target($settings), $events);
        return (new NotificationDelivery($this->outbox, $this->sender, $this->clock))->once();
    }
}
