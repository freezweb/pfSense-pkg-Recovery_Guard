<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** The command is code-owned; never accept it from package settings. */
final class RepairProcess
{
    public function __construct(private array $command = ['/usr/local/libexec/recovery-guard-repair', 'repair']) {}

    public function run(): array
    {
        $unknown = ['outcome' => 'cleanup_unknown', 'controller_exit' => -1];
        if (PHP_OS !== 'FreeBSD') return $unknown;
        $process = @proc_open($this->command, [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin', 'LC_ALL' => 'C']);
        if (!is_resource($process)) return $unknown;
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $out = ''; $error = ''; $exit = null; $complete = false;
        $deadline = hrtime(true) + 35000000000;
        try {
            while (hrtime(true) < $deadline) {
                $a = fread($pipes[1], 1024); $b = fread($pipes[2], 1024);
                if ($a === false || $b === false) break;
                $out .= $a; $error .= $b;
                if (strlen($out) > 256 || $error !== '') break;
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $exit ??= $state['exitcode'] >= 0 ? $state['exitcode'] : null;
                    if (feof($pipes[1]) && feof($pipes[2])) { $complete = true; break; }
                }
                usleep(10000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                // Let the owning controller clean its descendants before any last-resort kill.
                proc_terminate($process, 15);
                $end = hrtime(true) + 3500000000;
                while (proc_get_status($process)['running'] && hrtime(true) < $end) usleep(10000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) fclose($pipe);
            $closed = proc_close($process);
            if ($exit === null && $closed >= 0) $exit = $closed;
        }
        if (!$complete || $error !== '' || strlen($out) > 256) return $unknown;
        try { $result = json_decode($out, true, 4, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return $unknown; }
        if (!is_array($result) || count($result) !== 2 || !is_string($result['outcome'] ?? null) ||
            !is_int($result['controller_exit'] ?? null) || $result['controller_exit'] < -1 || $result['controller_exit'] > 255) return $unknown;
        $expected = ['controller_exited' => 0, 'failed_cleaned' => 70, 'cancelled_cleaned' => 70, 'timeout_cleaned' => 124];
        if (($expected[$result['outcome']] ?? null) !== $exit || !isset($expected[$result['outcome']]) ||
            ($result['outcome'] === 'controller_exited' && $result['controller_exit'] !== 0)) return $unknown;
        return $result;
    }
}
