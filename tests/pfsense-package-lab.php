<?php
declare(strict_types=1);

// This intentionally installs/removes the real package, on an isolated pfSense guest only.
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB') || !is_file('/etc/inc/pkg-utils.inc') ||
    posix_geteuid() !== 0) throw new RuntimeException('Isolated native pfSense lab required');
require_once('/etc/inc/xmlparse.inc');
umask(0077);
$package = realpath($argv[1] ?? '');
if ($package === false || !is_file($package)) throw new RuntimeException('Built package file required');
$dir = '/root/recovery-guard-package-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create evidence directory');
$pkg = '/usr/local/sbin/pkg';
$name = 'pfSense-pkg-Recovery_Guard';
$budget = '/cf/conf/recovery_guard/state.json';
$pidfile = '/var/run/recovery_guard.pid';
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function command(array $argv, string $phase): string {
    global $dir;
    // Do not use a descendant reaper: package hooks intentionally start a persistent monitor.
    $path = $dir . '/' . $phase . '.log';
    $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $path, 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot launch package operation');
    $exit = proc_close($p);
    $text = file_get_contents($path);
    if ($exit !== 0 || preg_match('/PHP ERROR|Fatal error|(?:DEINSTALL|INSTALL) script failed|missing file/i', $text)) {
        throw new RuntimeException('Package operation failed: ' . $phase . '; inspect ' . $path);
    }
    return $text;
}
function config(): array { return parse_xml_config('/cf/conf/config.xml', 'pfsense'); }
function monitorPid(): int {
    global $pidfile;
    clearstatcache();
    return is_file($pidfile) ? (int) file_get_contents($pidfile) : 0;
}
function registrations(string $kind, string $name): int {
    $items = config()['installedpackages'][$kind] ?? [];
    return count(array_filter($items, static fn($item) => ($item['name'] ?? null) === $name));
}
function privilegeRegistered(string $phase): bool {
    return trim(command([PHP_BINARY, '-r', 'require_once("config.inc"); require_once("priv.inc"); echo isset($priv_list["page-services-recoveryguard"]) ? "present" : "absent";'], $phase)) === 'present';
}

$metadata = trim(command([$pkg, 'query', '-F', $package, '%n|%o'], 'metadata'));
check($metadata === "$name|sysutils/$name", 'Unexpected candidate package identity');
$settings = config()['installedpackages']['recoveryguard']['settings'] ?? [];
check(isset($settings['enabled'], $settings['maintenance']) && !isset($settings['notifications']) &&
    ($settings['mode'] ?? null) === 'monitor', 'Prepare enabled, maintenance-only monitoring without mail first');
$state = json_decode(file_get_contents($budget), true, 512, JSON_THROW_ON_ERROR);
check(!empty($state['reboot_times']) && !empty($state['repair_times']),
    'Seed synthetic prior-action budgets before running the lifecycle test');
$hash = hash_file('sha256', $budget);
$before = monitorPid();
check($before > 1 && posix_kill($before, 0), 'Initial monitor is not running');

command([$pkg, 'add', '-f', $package], 'replace');
$after = monitorPid();
check($after > 1 && $after !== $before && posix_kill($after, 0) && !posix_kill($before, 0),
    'Package replacement did not replace the monitor process');
check(hash_file('sha256', $budget) === $hash, 'Replacement changed reserved budget');
check(registrations('service', 'recovery_guard') === 1 && registrations('menu', 'Recovery Guard') === 1,
    'Native service/menu registration is missing or duplicated');
check(privilegeRegistered('replace-privilege'), 'Package privilege missing after replacement');

command([$pkg, 'delete', '-y', $name], 'remove');
check(!posix_kill($after, 0), 'Monitor survived package removal');
check(!is_file('/usr/local/www/services_recovery_guard.php') &&
    !is_file('/usr/local/etc/rc.d/recovery_guard.sh') && !is_file('/usr/local/pkg/priv/recovery_guard.priv.inc'), 'Package files survived removal');
check(!privilegeRegistered('remove-privilege'), 'Package privilege survived removal');
check(hash_file('sha256', $budget) === $hash, 'Removal changed reserved budget');
check(registrations('service', 'recovery_guard') === 0 && registrations('menu', 'Recovery Guard') === 0,
    'Native registrations survived removal');

command([$pkg, 'add', $package], 'reinstall');
$pid = monitorPid();
check($pid > 1 && posix_kill($pid, 0), 'Reinstallation did not restart the enabled monitor');
check(hash_file('sha256', $budget) === $hash, 'Reinstallation reset reserved budget');
check((config()['installedpackages']['recoveryguard']['settings'] ?? []) === $settings,
    'Package settings changed across removal and reinstallation');
check(registrations('service', 'recovery_guard') === 1 && registrations('menu', 'Recovery Guard') === 1,
    'Reinstallation produced incorrect native registrations');
check(privilegeRegistered('reinstall-privilege'), 'Package privilege missing after reinstall');
command([$pkg, 'check', '-s', $name], 'integrity');
check(true, 'Installed package integrity');
echo "PASS: {$checks} native package replacement/removal/reinstallation checks; synthetic budgets, no recovery actions.\nEvidence: {$dir}\n";
