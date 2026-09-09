<?php
declare(strict_types=1);
namespace RecoveryGuard;

final class ServiceState
{
    public static function initialize(string $directory): void
    {
        $store = new StateStore($directory);
        if (!file_exists($directory) && !is_link($directory)) {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot provision state directory');
            $parent = fopen(dirname($directory), 'r');
            try { if (!$parent || !fsync($parent)) throw new \RuntimeException('Cannot persist state directory'); }
            finally { if (is_resource($parent)) fclose($parent); }
            $store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
        }
        if (!(new RecoveryPolicy())->acceptsState($store->exclusive(fn($s) => $s->read()))) throw new \RuntimeException('Invalid existing budget');
    }
}
