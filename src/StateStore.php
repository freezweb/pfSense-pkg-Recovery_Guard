<?php
declare(strict_types=1);

namespace RecoveryGuard;

/** Single-writer journal. An unreadable or unprovisioned journal never means zero usage. */
final class StateStore
{
    private $lock = null;
    private \Closure $syncDirectory;

    public function __construct(private string $directory, ?\Closure $syncDirectory = null)
    {
        // Injection is for durability fault tests, not a runtime configuration setting.
        $this->syncDirectory = $syncDirectory ?? static function (string $directory): void {
            $handle = @fopen($directory, 'r');
            if ($handle === false) throw new \RuntimeException('Cannot open journal directory for synchronization');
            try {
                if (!@fsync($handle)) throw new \RuntimeException('Cannot synchronize journal directory');
            } finally { fclose($handle); }
        };
    }

    public function exclusive(\Closure $operation): mixed
    {
        if ($this->lock !== null) throw new \RuntimeException('Nested journal transaction');
        $this->checkDirectory();
        $path = $this->directory . '/writer.lock';
        if (is_link($path)) throw new \RuntimeException('Journal lock is a symlink');
        $handle = @fopen($path, 'c+b');
        if ($handle === false) throw new \RuntimeException('Cannot open journal lock');
        // chmod on every sample can itself write metadata even with no journal commit.
        // The private directory protects a newly created lock until this first chmod.
        if (PHP_OS_FAMILY !== 'Windows') {
            $stat = fstat($handle);
            if ($stat === false || (($stat['mode'] & 0777) !== 0600 && !chmod($path, 0600))) {
                fclose($handle);
                throw new \RuntimeException('Cannot protect journal lock');
            }
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another recovery operation owns the journal');
        }
        $this->lock = $handle;
        try { return $operation($this); }
        finally { $this->lock = null; flock($handle, LOCK_UN); fclose($handle); }
    }

    public function provision(array $state): void
    {
        $this->requireLock();
        if (file_exists($this->directory . '/initialized.json') ||
            file_exists($this->directory . '/state.json') ||
            is_link($this->directory . '/state.json') ||
            is_link($this->directory . '/initialized.json')) {
            throw new \RuntimeException('Journal already initialized; refusing to erase its budget');
        }
        // The sentinel survives an interrupted first commit and prevents silent reset.
        $this->atomicWrite('initialized.json', ['format' => 1]);
        $this->commit($state);
    }

    public function read(): array
    {
        $this->requireLock();
        if ($this->readObject('initialized.json') !== ['format' => 1]) {
            throw new \RuntimeException('Invalid journal initialization marker');
        }
        return $this->readObject('state.json');
    }

    public function commit(array $state): void
    {
        $this->requireLock();
        if ($this->readObject('initialized.json') !== ['format' => 1]) {
            throw new \RuntimeException('Journal must be explicitly provisioned');
        }
        $this->atomicWrite('state.json', $state);
    }

    private function atomicWrite(string $name, array $data): void
    {
        $path = $this->directory . '/' . $name;
        if (is_link($path)) throw new \RuntimeException('Journal destination is a symlink');
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($bytes) > 524288) throw new \RuntimeException('Journal exceeds size limit');
        $temporary = $this->directory . '/.pending-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw new \RuntimeException('Cannot create temporary journal');
        try {
            if (!chmod($temporary, 0600)) throw new \RuntimeException('Cannot protect journal permissions');
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('Incomplete journal write');
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) throw new \RuntimeException('Cannot synchronize journal data');
            fclose($handle); $handle = null;
            if (!rename($temporary, $path)) throw new \RuntimeException('Cannot atomically replace journal');
            ($this->syncDirectory)($this->directory);
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (file_exists($temporary)) unlink($temporary);
        }
    }

    private function readObject(string $name): array
    {
        $path = $this->directory . '/' . $name;
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || filesize($path) > 524288) {
            throw new \RuntimeException('Missing or unsafe journal object: ' . $name);
        }
        $bytes = @file_get_contents($path, false, null, 0, 524289);
        if ($bytes === false || strlen($bytes) > 524288) throw new \RuntimeException('Cannot read bounded journal');
        $object = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($object)) throw new \RuntimeException('Journal is not an object');
        return $object;
    }

    private function checkDirectory(): void
    {
        clearstatcache(true, $this->directory);
        if (is_link($this->directory) || !is_dir($this->directory)) {
            throw new \RuntimeException('Journal directory must exist and must not be a symlink');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $stat = stat($this->directory);
            if (($stat['mode'] & 0077) !== 0) throw new \RuntimeException('Journal directory must be private');
            if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('Journal directory must be owned by the supervisor user');
            }
        }
    }

    private function requireLock(): void
    {
        if ($this->lock === null) throw new \RuntimeException('Journal operation requires exclusive ownership');
    }
}
