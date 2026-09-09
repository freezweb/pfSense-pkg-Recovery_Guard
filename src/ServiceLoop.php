<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Single supervisor ownership and interruptible scheduling, independent of PHP-FPM. */
final class ServiceLoop
{
    private $lease = null;
    public function __construct(private string $directory) {}

    public function run(\Closure $cycle, \Closure $stopping, \Closure $report): void
    {
        if (!is_dir($this->directory) || is_link($this->directory)) throw new \RuntimeException('Missing private runtime directory');
        $stat = stat($this->directory);
        if (($stat['mode'] & 0077) !== 0 || $stat['uid'] !== posix_geteuid()) throw new \RuntimeException('Unsafe runtime directory');
        $path = $this->directory . '/supervisor.lock';
        if (is_link($path)) throw new \RuntimeException('Unsafe supervisor lease');
        $this->lease = fopen($path, 'c+b');
        if ($this->lease === false) throw new \RuntimeException('Cannot open supervisor lease');
        try {
            if (!flock($this->lease, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Supervisor already running');
            if (!ftruncate($this->lease, 0) || fwrite($this->lease, (string) getmypid() . "\n") === false) throw new \RuntimeException('Cannot record supervisor identity');
            fflush($this->lease);
            $previous = null;
            while (!$stopping()) {
                $start = hrtime(true);
                $result = $cycle();
                $status = ['reason' => $result['reason'] ?? 'cycle_unavailable', 'execution' => $result['execution'] ?? 'none'];
                foreach ($status as $value) if (!is_string($value) || !preg_match('/\A[a-z_]{1,64}\z/D', $value)) throw new \RuntimeException('Invalid service status');
                if ($status !== $previous) { $report($status); $previous = $status; }
                // Never busy-loop or try to catch up after a slow cycle.
                $next = max($start + 15000000000, hrtime(true) + 1000000000);
                while (!$stopping() && hrtime(true) < $next) usleep(100000);
            }
        } finally {
            flock($this->lease, LOCK_UN); fclose($this->lease); $this->lease = null;
            // Never unlink a lock inode: a waiting successor may already have opened it.
        }
    }
}
