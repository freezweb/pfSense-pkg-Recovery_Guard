<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Called only after the coordinator has reserved, recorded and rechecked an action. */
final class RepairExecutor
{
    public function __construct(private \Closure $run, private \Closure $health) {}

    public function execute(array $proposal): array
    {
        if (($proposal['kind'] ?? null) !== 'repair_php_fpm' || !is_string($proposal['id'] ?? null) ||
            !preg_match('/\A[a-zA-Z0-9_.:-]{1,130}\z/D', $proposal['id'])) throw new \InvalidArgumentException('Invalid repair proposal');
        $result = ($this->run)();
        $kind = is_array($result) ? ($result['outcome'] ?? null) : null;
        // Cancellation and uncertain cleanup cannot authorize subsequent reboot escalation.
        if (!in_array($kind, ['controller_exited', 'failed_cleaned', 'timeout_cleaned'], true)) {
            throw new \RuntimeException('Repair completion is unknown or cancelled');
        }
        $outcome = $kind === 'timeout_cleaned' ? 'timeout_cleaned' : 'failed';
        if ($kind === 'controller_exited') {
            // A successful script exit alone is not a functioning PHP service.
            $outcome = ($this->health)() === true ? 'completed' : 'failed';
        }
        return ['id' => $proposal['id'], 'outcome' => $outcome];
    }
}
