<?php
declare(strict_types=1);
namespace RecoveryGuard;

final class NotificationMessage
{
    public static function render(array $job): array
    {
        $e = $job['event'] ?? null;
        if (!is_array($e) || !NotificationOutbox::validEvent($e) || !is_string($job['id'] ?? null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $job['id'])) throw new \InvalidArgumentException('Invalid notification message');
        $descriptions = ['proposed' => 'An action was proposed; execution is not confirmed.',
            'mode_inhibited' => 'The operating mode inhibited the proposed action.',
            'interlock_inhibited' => 'Current safety or health checks inhibited the action.',
            'completed' => 'The action executor reported completion.', 'failed' => 'The action attempt failed.',
            'timeout_cleaned' => 'The action timed out; process cleanup was verified.',
            'handoff_pending' => 'A reboot was handed off; a completed reboot has not been established.',
            'boot_observed' => 'A new boot was observed after a claimed reboot request. This does not establish the cause of the boot or recovery of services.',
            'unknown' => 'Action completion could not be established.'];
        return ['subject' => 'Recovery Guard: ' . $e['result'], 'message_id' => '<recovery-guard-' . $job['id'] . '@localhost>',
            'body' => "Recovery Guard\n" . $descriptions[$e['result']] . "\nAction: " . $e['kind'] . "\nMode: " . $e['mode'] .
                "\nEvent time (UTC): " . gmdate('Y-m-d H:i:s', $e['time']) . "\nReference: " . $job['id'] . "\n"];
    }
}
