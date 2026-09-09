<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Finite diagnostic commands only. Never use this runner to start a daemon. */
final class ProbeProcess
{
    public function run(array $argv, float $timeout = 2.0, int $limit = 65536): array
    {
        if (!array_is_list($argv) || count($argv) < 1 || count($argv) > 32 ||
            !is_string($argv[0]) || !str_starts_with($argv[0], '/') ||
            !is_finite($timeout) || $timeout < 0.1 || $timeout > 10 || $limit < 256 || $limit > 65536) {
            throw new \InvalidArgumentException('Invalid diagnostic command bounds');
        }
        foreach ($argv as $arg) {
            if (!is_string($arg) || strlen($arg) > 1024 || str_contains($arg, "\0")) {
                throw new \InvalidArgumentException('Invalid diagnostic argument');
            }
        }
        $start = hrtime(true);
        $result = ['status' => 'unavailable', 'exit_code' => null, 'stdout' => '', 'stderr' => '',
            'duration_ms' => 0];
        if (PHP_OS_FAMILY !== 'BSD' || PHP_OS !== 'FreeBSD' || !is_executable('/usr/bin/timeout')) {
            return $result;
        }
        // No shell, inherited secrets or unbounded output files.
        // FreeBSD timeout owns the descendant reaper. Do not use --foreground.
        $command = ['/usr/bin/timeout', '-k', '0.5', sprintf('%.3F', $timeout), ...$argv];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/',
            ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin',
                'LC_ALL' => 'C', 'LANG' => 'C', 'HOME' => '/']);
        if (!is_resource($process)) return $result;
        $overflow = false;
        $remaining = $limit;
        $uncertain = false;
        $exitCode = null;
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        try {
            while (true) {
                // Drain both streams even after the storage cap, avoiding pipe deadlock.
                foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $key) {
                    $chunk = fread($pipes[$fd], 8192);
                    if ($chunk === false) { $uncertain = true; continue; }
                    $kept = min(strlen($chunk), $remaining);
                    $result[$key] .= substr($chunk, 0, $kept);
                    $remaining -= $kept;
                    if ($kept < strlen($chunk)) $overflow = true;
                }
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $exitCode ??= $state['exitcode'] >= 0 ? $state['exitcode'] : null;
                    if (feof($pipes[1]) && feof($pipes[2])) break;
                }
                if ((hrtime(true) - $start) / 1e9 > $timeout + 1.5) {
                    // Timeout/reaper failure is unknown, never an action-completion receipt.
                    $uncertain = true;
                    break;
                }
                usleep(1000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                $uncertain = true;
                // TERM asks timeout to propagate termination; do not kill it first.
                proc_terminate($process, 15);
                $cleanupDeadline = hrtime(true) + 1000000000;
                while (proc_get_status($process)['running'] && hrtime(true) < $cleanupDeadline) usleep(10000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) fclose($pipe);
            $closed = proc_close($process);
            if ($exitCode === null && $closed >= 0) $exitCode = $closed;
        }
        $result['exit_code'] = $exitCode;
        $result['duration_ms'] = (int) ((hrtime(true) - $start) / 1000000);
        $result['status'] = $uncertain ? 'cleanup_unknown' : ($overflow ? 'output_limit' :
            ($exitCode === 124 ? 'timeout' : ($exitCode === null ? 'unavailable' : 'exited')));
        return $result;
    }
}
