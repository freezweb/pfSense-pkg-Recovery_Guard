<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD laboratory required');
$source = $argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('Pinned native upgrade wrapper required');
foreach (['NativeUpgradeLease', 'ProbeProcess', 'Configuration', 'NativeSnapshot'] as $n) require __DIR__ . '/../src/' . $n . '.php';
use RecoveryGuard\{NativeUpgradeLease, ProbeProcess, NativeSnapshot};
umask(0077); $checks = 0;
$dir = '/root/recovery-guard-upgrade-lease-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
mkdir($dir . '/sbin', 0700); mkdir($dir . '/libexec', 0700);
$path = $dir . '/native.lock'; $wrapper = $dir . '/sbin/pfSense-upgrade'; $ran = $dir . '/ran';
$native = str_replace("\r\n", "\n", file_get_contents($source));
$needle = 'lockfile="/tmp/$(basename $0).lock"';
if (substr_count($native, $needle) !== 1) throw new RuntimeException('Native lock contract changed');
file_put_contents($wrapper, str_replace($needle, 'lockfile="' . $path . '"', $native)); chmod($wrapper, 0700);
file_put_contents($dir . '/libexec/pfSense-upgrade', '#!/bin/sh' . "\n" . 'touch ' . escapeshellarg($ran) . "\nsleep 0.4\n"); chmod($dir . '/libexec/pfSense-upgrade', 0700);
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function blocked(Closure $operation, string $name): void { $threw = false; try { $operation(); } catch (RuntimeException) { $threw = true; } check($threw, $name); }
function process(array $args): array { $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); return [$p, $pipes]; }
function finish(array $p): int { foreach ($p[1] as $pipe) { stream_get_contents($pipe); fclose($pipe); } return proc_close($p[0]); }
function until(Closure $test): bool { $end = microtime(true) + 3; do { clearstatcache(); if ($test()) return true; usleep(10000); } while (microtime(true) < $end); return false; }
function upgradeClear(): bool {
    $r = (new ProbeProcess())->run(['/bin/ps', 'axww', '-o', 'pid=', '-o', 'command=']);
    if ($r['status'] !== 'exited' || $r['exit_code'] !== 0) return false;
    $s = NativeSnapshot::project(['interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']]],
        '{ sec = 1000, usec = 0 }', $r['stdout'], ['booting' => false, 'pkg_dirty' => false, 'upgrade_pid' => false], time());
    return $s['interlocks']['upgrade'] === false;
}
$lease = new NativeUpgradeLease($path);
check($lease->exclusive(upgradeClear(...), fn() => 42) === 42, 'free native lease reaches action');
$bytes = file_get_contents($path);
$lease->exclusive(upgradeClear(...), function () use ($wrapper, $ran): void {
    check(finish(process(['/bin/sh', $wrapper, '-T', '2'])) === 75 && !file_exists($ran), 'held recovery lease excludes original native upgrade wrapper');
});
check(file_get_contents($path) === $bytes, 'recovery does not rewrite native lock contents');
$p = process(['/bin/sh', $wrapper]);
check(until(fn() => is_file($ran)), 'native upgrade fixture starts');
blocked(fn() => $lease->exclusive(upgradeClear(...), fn() => throw new LogicException('Must not execute')), 'running native upgrade excludes recovery');
check(finish($p) === 0, 'original native wrapper finishes');
check($lease->exclusive(upgradeClear(...), fn() => 43) === 43, 'recovery handles native lock removal and subsequent creation');
blocked(fn() => $lease->exclusive(fn() => false, fn() => throw new LogicException('Must not execute')), 'fresh upgrade process observation inhibits action');
blocked(fn() => $lease->exclusive(function () use ($path): bool { unlink($path); file_put_contents($path, 'replacement'); return true; }, fn() => throw new LogicException('Must not execute')), 'unlink and replacement during fresh check cannot retain authority');
check(file_get_contents($path) === 'replacement', 'failure does not unlink replacement native lock');
blocked(fn() => $lease->assertCurrent(), 'lease cannot be asserted outside ownership');
// Ensure exec children cannot retain the upgrade lock after their PHP owner releases it.
$child = null;
$lease->exclusive(upgradeClear(...), function () use (&$child): void { $child = process(['/bin/sleep', '0.5']); usleep(30000); });
check((new NativeUpgradeLease($path))->exclusive(upgradeClear(...), fn() => true), 'close-on-exec prevents persistent child lock inheritance');
finish($child);
unlink($path); symlink($ran, $path);
blocked(fn() => $lease->exclusive(fn() => true, fn() => throw new LogicException('Must not execute')), 'symlink cannot become native upgrade lease');
unlink($path); file_put_contents($path, 'unsafe'); chmod($path, 0666);
blocked(fn() => $lease->exclusive(fn() => true, fn() => throw new LogicException('Must not execute')), 'world-writable native lock is refused');
chmod($path, 0600);
// Reproduce the native wrapper's second unlink after lockf has removed the first inode.
unlink($ran);
$epilogue = $dir . '/epilogue'; $release = $dir . '/release';
$wait = 'touch ' . escapeshellarg($epilogue) . "\nwhile [ ! -f " . escapeshellarg($release) . " ]; do sleep 0.1; done\n";
$instrumented = str_replace("\t[ -f \"\${lockfile}\" ]", $wait . "\t[ -f \"\${lockfile}\" ]", str_replace($needle, 'lockfile="' . $path . '"', $native));
file_put_contents($wrapper, $instrumented);
$p = process(['/bin/sh', $wrapper]);
try {
    check(until(fn() => is_file($epilogue)), 'native wrapper pauses in actual unlink epilogue');
    blocked(fn() => $lease->exclusive(upgradeClear(...), fn() => throw new LogicException('Must not execute')), 'real wrapper epilogue remains visible to native process interlock');
} finally { touch($release); finish($p); }
echo "PASS: {$checks} native upgrade-lease checks; original wrapper with private payload/lock, no real upgrades or firewall actions.\nEvidence directory: {$dir}\n";
