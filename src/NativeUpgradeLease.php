<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Cooperates with the installed pfSense-upgrade wrapper's FreeBSD lockf/flock lock. */
final class NativeUpgradeLease
{
    private $handle = null;
    public function __construct(private string $path = '/tmp/pfSense-upgrade.lock') {}

    /** Fresh must exclude upgrade processes, including a wrapper still removing its old lock. */
    public function exclusive(\Closure $fresh, \Closure $operation): mixed
    {
        if (PHP_OS !== 'FreeBSD' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || $this->handle !== null) throw new \RuntimeException('Native upgrade lease unavailable');
        clearstatcache(true, $this->path);
        if (is_link($this->path) || (file_exists($this->path) && !is_file($this->path))) throw new \RuntimeException('Unsafe upgrade lock');
        $mask = umask(0077);
        try { $handle = @fopen($this->path, 'c+e'); } finally { umask($mask); }
        if ($handle === false) throw new \RuntimeException('Cannot open upgrade lock');
        $this->handle = $handle;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Native upgrade is busy');
            $this->assertCurrent();
            // lockf removes its inode before releasing; the shell wrapper also has an unlink
            // epilogue. Check processes while locked, then confirm this is still the named inode.
            if ($fresh() !== true) throw new \RuntimeException('Upgrade activity inhibits recovery');
            $this->assertCurrent();
            return $operation($this->assertCurrent(...));
        } finally {
            flock($handle, LOCK_UN); fclose($handle); $this->handle = null;
            // Never unlink a shared native lock or modify its existing contents/permissions.
        }
    }

    public function assertCurrent(): void
    {
        if (!is_resource($this->handle)) throw new \RuntimeException('Upgrade lease not held');
        clearstatcache(true, $this->path);
        $named = @lstat($this->path); $held = fstat($this->handle);
        if ($named === false || $held === false || ($held['mode'] & 0170000) !== 0100000 ||
            ($held['mode'] & 0022) !== 0 || $held['uid'] !== 0 || $held['nlink'] !== 1 ||
            $named['dev'] !== $held['dev'] || $named['ino'] !== $held['ino'] ||
            ($named['mode'] & 0170000) !== 0100000) throw new \RuntimeException('Native upgrade lock was replaced or is unsafe');
    }
}
