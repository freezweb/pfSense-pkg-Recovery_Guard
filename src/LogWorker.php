<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Persistent file-reader worker; bounded requests keep file I/O out of the supervisor. */
final class LogWorker
{
    private $process = null;
    private array $pipes = [];
    public function __construct(private string $php = '/usr/local/bin/php', private ?string $script = null) {}
    public function __destruct() { $this->close(); }

    public function check(string $context, int $time): ?bool
    {
        if ($context === '' || strlen($context) > 256 || $time < 0) throw new \InvalidArgumentException('Invalid log request');
        try {
            if (!is_resource($this->process)) $this->start();
            $id = bin2hex(random_bytes(8));
            $request = json_encode(['id' => $id, 'context' => $context, 'time' => $time], JSON_THROW_ON_ERROR) . "\n";
            $deadline = hrtime(true) + 2000000000;
            $offset = 0; $reply = '';
            while (hrtime(true) < $deadline) {
                if ($offset < strlen($request)) {
                    $n = fwrite($this->pipes[0], substr($request, $offset));
                    if ($n === false) throw new \RuntimeException('Log worker write failed');
                    $offset += $n;
                }
                $part = fread($this->pipes[1], 1024);
                $error = fread($this->pipes[2], 1024);
                if ($part === false || $error === false || $error !== '') throw new \RuntimeException('Log worker failed');
                $reply .= $part;
                if (strlen($reply) > 512) throw new \RuntimeException('Oversized log response');
                if (str_contains($reply, "\n")) {
                    if (!str_ends_with($reply, "\n") || substr_count($reply, "\n") !== 1 || $offset !== strlen($request)) throw new \RuntimeException('Invalid log framing');
                    $result = json_decode($reply, true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($result) || ($result['id'] ?? null) !== $id || !array_key_exists('value', $result) ||
                        !in_array($result['value'], [true, false, null], true)) throw new \RuntimeException('Invalid log response');
                    return $result['value'];
                }
                if (!proc_get_status($this->process)['running']) throw new \RuntimeException('Log worker exited');
                usleep(1000);
            }
        } catch (\Throwable) {
            // No raw log lines or worker exception text leave this boundary.
        }
        $this->close();
        return null;
    }

    public function close(): void
    {
        if (!is_resource($this->process)) return;
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $this->pipes = [];
        if (proc_get_status($this->process)['running']) proc_terminate($this->process, 15);
        $deadline = hrtime(true) + 500000000;
        while (proc_get_status($this->process)['running'] && hrtime(true) < $deadline) usleep(10000);
        if (proc_get_status($this->process)['running']) proc_terminate($this->process, 9);
        proc_close($this->process);
        $this->process = null;
    }

    private function start(): void
    {
        $this->process = @proc_open([$this->php, $this->script ?? __DIR__ . '/log-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, '/',
            ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin', 'LC_ALL' => 'C', 'LANG' => 'C']);
        if (!is_resource($this->process)) throw new \RuntimeException('Cannot start log worker');
        foreach ($this->pipes as $pipe) stream_set_blocking($pipe, false);
    }
}
