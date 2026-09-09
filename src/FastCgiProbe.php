<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Real PHP-FPM transaction, without nginx, HTTP exposure or an administrator credential. */
final class FastCgiProbe
{
    private \Closure $connect;
    public function __construct(?\Closure $connect = null)
    {
        $this->connect = $connect ?? static function (float $timeout) {
            $stream = @stream_socket_client('unix:///var/run/php-fpm.socket', $errno, $error, $timeout);
            if ($stream === false) {
                // Permission/unknown transport errors must not masquerade as a failed service.
                throw new \RuntimeException('socket_unavailable', $errno);
            }
            return $stream;
        };
    }

    public function check(string $script = '/usr/local/pkg/recovery_guard/health.php', float $timeout = 2.0): array
    {
        if (!str_starts_with($script, '/') || str_contains($script, "\0") ||
            $timeout <= 0 || $timeout > 10) throw new \InvalidArgumentException('Invalid probe bounds');
        $started = hrtime(true); $deadline = $started + (int) ($timeout * 1e9); $stream = null;
        $nonce = bin2hex(random_bytes(16));
        $answer = static fn(?bool $ok, string $reason): array =>
            ['ok' => $ok, 'reason' => $reason, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)];
        try {
            $stream = ($this->connect)($timeout);
            if (!is_resource($stream)) return $answer(null, 'invalid_transport');
            stream_set_blocking($stream, false);
            $params = '';
            foreach (['SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/recovery_guard_health.php',
                'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/recovery_guard_health.php',
                'SERVER_PROTOCOL' => 'HTTP/1.1', 'GATEWAY_INTERFACE' => 'CGI/1.1',
                'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1',
                'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80',
                'RECOVERY_GUARD_NONCE' => $nonce] as $name => $value) {
                $params .= $this->length(strlen($name)) . $this->length(strlen($value)) . $name . $value;
            }
            $request = $this->record(1, pack('nCxxxxx', 1, 0)) . $this->record(4, $params) .
                $this->record(4, '') . $this->record(5, '');
            $offset = 0;
            while ($offset < strlen($request)) {
                $this->wait($stream, $deadline, true);
                $written = fwrite($stream, substr($request, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('short_write');
                $offset += $written;
            }
            $stdout = ''; $readBytes = 0;
            while (true) {
                $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $this->read($stream, 8, $deadline));
                if ($header['version'] !== 1 || $header['id'] !== 1) throw new \RuntimeException('invalid_record');
                $readBytes += 8 + $header['length'] + $header['padding'];
                if ($readBytes > 65536) throw new \RuntimeException('response_limit');
                $body = $this->read($stream, $header['length'], $deadline);
                $this->read($stream, $header['padding'], $deadline);
                if ($header['type'] === 6) $stdout .= $body;
                elseif ($header['type'] === 3) {
                    if (strlen($body) !== 8) throw new \RuntimeException('invalid_end_record');
                    $end = unpack('Napplication/Cprotocol', substr($body, 0, 5));
                    if ($end['application'] !== 0 || $end['protocol'] !== 0) return $answer(false, 'request_failed');
                    $parts = preg_split('/\r?\n\r?\n/', $stdout, 2);
                    return $answer(count($parts) === 2 && hash_equals('recovery-guard:' . $nonce, $parts[1]), 'response_checked');
                } elseif ($header['type'] !== 7) throw new \RuntimeException('unexpected_record');
            }
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'socket_unavailable') {
                return $answer(in_array($error->getCode(), [2, 61, 111], true) ? false : null, 'socket_unavailable');
            }
            return $answer(false, in_array($error->getMessage(), ['timeout', 'response_limit'], true) ?
                $error->getMessage() : 'protocol_failure');
        } finally { if (is_resource($stream)) fclose($stream); }
    }

    private function wait($stream, int $deadline, bool $write = false): void
    {
        $left = $deadline - hrtime(true);
        if ($left <= 0) throw new \RuntimeException('timeout');
        $read = $write ? [] : [$stream]; $writes = $write ? [$stream] : []; $except = [];
        $ready = @stream_select($read, $writes, $except, intdiv($left, 1000000000), intdiv($left % 1000000000, 1000));
        if ($ready === false) throw new \RuntimeException('select_failed');
        if ($ready === 0) throw new \RuntimeException('timeout');
    }
    private function read($stream, int $length, int $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->wait($stream, $deadline);
            $part = fread($stream, $length - strlen($bytes));
            if ($part === false || ($part === '' && feof($stream))) throw new \RuntimeException('truncated_response');
            $bytes .= $part;
        }
        return $bytes;
    }
    private function length(int $length): string { return $length < 128 ? chr($length) : pack('N', $length | 0x80000000); }
    private function record(int $type, string $body): string { return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body; }
}
