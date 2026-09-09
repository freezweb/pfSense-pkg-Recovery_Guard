<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** One finite SMTP child at a time. The native timeout survives a lost supervisor. */
final class NotificationWorker
{
    private $process = null;
    private array $pipes = [];
    private string $output = '';
    private bool $invalid = false;
    private int $started = 0;
    public function __construct(private string $php = '/usr/local/bin/php', private ?string $script = null, private float $timeout = 22.0)
    {
        if ($timeout < 0.1 || $timeout > 22.0 || !is_finite($timeout)) throw new \InvalidArgumentException('Invalid notification timeout');
    }
    public function __destruct() { $this->close(); }

    /** Poll and schedule without waiting for SMTP or returning any raw child output. */
    public function tick(): string
    {
        if (PHP_OS !== 'FreeBSD') return 'unavailable';
        $result = 'running';
        if (is_resource($this->process)) {
            $chunk = fread($this->pipes[1], 1024); $error = fread($this->pipes[2], 1024);
            if ($chunk === false || $error === false || $error !== '') $this->invalid = true;
            $this->output .= $chunk === false ? '' : $chunk;
            if (strlen($this->output) > 512) { $this->invalid = true; $this->output = ''; }
            $state = proc_get_status($this->process);
            if ($state['running']) {
                if (hrtime(true) - $this->started <= (int) (($this->timeout + 1.5) * 1e9)) return $result;
                $this->close();
                return 'unknown';
            }
            $result = 'unknown';
            if (!$this->invalid && $state['exitcode'] === 0 && feof($this->pipes[1]) && feof($this->pipes[2])) {
                $value = json_decode($this->output, true);
                if (is_array($value) && array_keys($value) === ['result'] && in_array($value['result'], ['idle', 'disabled', 'accepted', 'unknown', 'target_changed', 'rejected'], true)) $result = $value['result'];
            }
            foreach ($this->pipes as $pipe) fclose($pipe);
            proc_close($this->process); $this->process = null; $this->pipes = [];
        }
        $this->output = ''; $this->invalid = false;
        $this->process = @proc_open(['/usr/bin/timeout', '-k', '0.5', sprintf('%.3F', $this->timeout), $this->php, $this->script ?? __DIR__ . '/notification-worker.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, '/',
            ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin', 'LC_ALL' => 'C', 'LANG' => 'C']);
        if (!is_resource($this->process)) return 'unavailable';
        $this->started = hrtime(true);
        foreach ($this->pipes as $pipe) stream_set_blocking($pipe, false);
        return $result;
    }
    public function close(): void
    {
        if (!is_resource($this->process)) return;
        if (proc_get_status($this->process)['running']) proc_terminate($this->process, 15);
        $deadline = hrtime(true) + 1000000000;
        while (proc_get_status($this->process)['running'] && hrtime(true) < $deadline) usleep(10000);
        if (proc_get_status($this->process)['running']) proc_terminate($this->process, 9);
        foreach ($this->pipes as $pipe) fclose($pipe);
        proc_close($this->process); $this->process = null; $this->pipes = [];
    }
}
