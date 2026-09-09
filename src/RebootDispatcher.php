<?php
declare(strict_types=1);
namespace RecoveryGuard;

final class RebootDispatcher
{
    public function __construct(private RebootHandoff $handoff, private ?\Closure $launch = null) {}
    public function execute(array $proposal): array
    {
        $token = $this->handoff->prepare($proposal);
        if ($this->launch !== null) ($this->launch)($token);
        else $this->nativeLaunch($token);
        return ['id' => $proposal['id'], 'outcome' => 'handoff_pending'];
    }
    private function nativeLaunch(string $token): void
    {
        if (PHP_OS !== 'FreeBSD') throw new \RuntimeException('Native reboot dispatcher unavailable');
        // Never wrap a persistent worker in the finite diagnostic descendant reaper.
        $p = proc_open(['/usr/sbin/daemon', '-f', '-p', '/var/run/recovery_guard_reboot.pid',
            '/usr/local/bin/php', __DIR__ . '/reboot-worker.php', $token],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, '/', ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) throw new \RuntimeException('Cannot dispatch reboot worker');
        $exit = null; $end = hrtime(true) + 2000000000;
        do {
            $status = proc_get_status($p);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            usleep(10000);
        } while (hrtime(true) < $end);
        if ($status['running']) {
            proc_terminate($p, 15);
            $end = hrtime(true) + 500000000;
            while (proc_get_status($p)['running'] && hrtime(true) < $end) usleep(10000);
            if (proc_get_status($p)['running']) proc_terminate($p, 9);
        }
        proc_close($p);
        if ($exit !== 0) throw new \RuntimeException('Reboot worker dispatch unconfirmed');
    }
}
