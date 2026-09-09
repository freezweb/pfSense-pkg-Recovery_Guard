<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Volatile baseline: restart, boot/config changes and observation gaps requalify peers. */
final class EndpointBaseline
{
    private ?string $context = null;
    private ?int $lastTime = null;
    private array $peers = [];

    public function observe(string $context, int $time, array $observations): ?bool
    {
        if ($context === '' || strlen($context) > 256 || $time < 0 || count($observations) < 2 ||
            count($observations) > 8) throw new \InvalidArgumentException('Invalid peer baseline');
        foreach ($observations as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 128 ||
                !in_array($value, [true, false, null], true)) throw new \InvalidArgumentException('Invalid peer observation');
        }
        ksort($observations);
        if ($this->context !== $context || array_keys($this->peers) !== array_keys($observations) ||
            $this->lastTime === null || $time <= $this->lastTime || $time - $this->lastTime > 45) {
            $this->peers = array_fill_keys(array_keys($observations), ['since' => null, 'samples' => 0, 'qualified' => false]);
            $this->context = $context;
        }
        $this->lastTime = $time;
        foreach ($observations as $key => $value) {
            $peer = &$this->peers[$key];
            if (!$peer['qualified']) {
                if ($value === true) {
                    $peer['since'] ??= $time;
                    $peer['samples']++;
                    $peer['qualified'] = $peer['samples'] >= 3 && $time - $peer['since'] >= 30;
                } else {
                    $peer['since'] = null;
                    $peer['samples'] = 0;
                }
            }
            unset($peer);
        }
        // Any confirmed reply proves local connectivity even during qualification.
        if (in_array(true, $observations, true)) return true;
        if (in_array(null, $observations, true)) return null;
        foreach ($this->peers as $peer) if (!$peer['qualified']) return null;
        return false;
    }
}
