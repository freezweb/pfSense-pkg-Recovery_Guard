<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Volatile bounded reader for the native check_reload_status socket retry storm. */
final class LogRateProbe
{
    private $handle = null;
    private ?string $context = null;
    private ?int $time = null;
    private int $offset = 0;
    private string $partial = '';
    private const LIMIT = 65536;

    public function __construct(private string $path = '/var/log/system.log') {}
    public function __destruct() { $this->close(); }

    public function check(string $context, int $time): ?bool
    {
        if ($context === '' || strlen($context) > 256 || $time < 0) throw new \InvalidArgumentException('Invalid log context');
        clearstatcache(true, $this->path);
        $current = @lstat($this->path);
        if (!$this->regular($current)) { $this->close(); return null; }
        if ($this->context !== $context || $this->time === null || $time <= $this->time || $time - $this->time > 45 || !is_resource($this->handle)) {
            $this->close();
            $this->openAtEnd($current, $context, $time);
            return null;
        }
        $elapsed = $time - $this->time;
        if ($elapsed < 5) return null;
        $old = fstat($this->handle);
        if (!$this->regular($old) || $old['size'] < $this->offset) {
            $this->close(); $this->openAtEnd($current, $context, $time); return null;
        }
        $bytes = $old['size'] - $this->offset;
        if (fseek($this->handle, $this->offset) !== 0) { $this->close(); return null; }
        $data = $bytes === 0 ? '' : fread($this->handle, min($bytes, self::LIMIT));
        if ($data === false || strlen($data) !== min($bytes, self::LIMIT)) { $this->close(); return null; }
        $text = $this->partial . $data;
        $end = strrpos($text, "\n");
        $complete = $end === false ? '' : substr($text, 0, $end + 1);
        $this->partial = $end === false ? $text : substr($text, $end + 1);
        if (strlen($this->partial) > 4096) { $this->close(); return null; }
        // A strict program/message match prevents unrelated interface logs counting as PHP failures.
        $count = preg_match_all('/^[A-Z][a-z]{2} +[0-9]{1,2} [0-9]{2}:[0-9]{2}:[0-9]{2} [^\s]+ check_reload_status\[[0-9]+\]: Could not connect to \/var\/run\/php-fpm\.socket\r?$/m', $complete);
        $overflow = $bytes > self::LIMIT;
        $this->offset = $old['size']; $this->time = $time;
        if ($overflow) $this->partial = ''; // Never join a fragment across skipped bytes.
        $rotated = $current['dev'] !== $old['dev'] || $current['ino'] !== $old['ino'];
        if ($rotated) {
            // The open descriptor covers only new bytes since the preceding observation.
            // Start the replacement at EOF; its pre-existing contents are not fresh evidence.
            $this->close(); $this->openAtEnd($current, $context, $time);
        }
        if ($count >= 10 * $elapsed) return true; // Lower bound suffices even if unread bytes were skipped.
        return ($overflow || $rotated) ? null : false;
    }

    private function regular(mixed $stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && is_int($stat['size']) && $stat['size'] >= 0;
    }
    private function openAtEnd(array $expected, string $context, int $time): void
    {
        $handle = @fopen($this->path, 'rb');
        if (!is_resource($handle)) return;
        $actual = fstat($handle);
        if (!$this->regular($actual) || $actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']) { fclose($handle); return; }
        $this->handle = $handle; $this->offset = $actual['size']; $this->context = $context; $this->time = $time;
    }
    private function close(): void
    {
        if (is_resource($this->handle)) fclose($this->handle);
        $this->handle = null; $this->context = null; $this->time = null; $this->offset = 0; $this->partial = '';
    }
}
