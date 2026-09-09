<?php
declare(strict_types=1);
$port = $argv[1] ?? dirname(__DIR__) . '/build/ports/sysutils/pfSense-pkg-Recovery_Guard';
set_include_path(__DIR__ . '/fixtures/native' . PATH_SEPARATOR . get_include_path());
require $port . '/files/usr/local/pkg/recovery_guard.inc';
$fixture_config = ['interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']],
    'system' => ['hostname' => 'unchanged'], 'installedpackages' => ['otherpackage' => ['keep' => 'unchanged']]];
$fixture_writes = 0; $fixture_write_result = 'success'; $checks = 0;
$settings = ['interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3", 'maintenance' => 'on'];
recovery_guard_save_settings($settings);
if ($fixture_writes !== 1 || config_get_path('installedpackages/recoveryguard/settings/mode') !== 'monitor' ||
    config_get_path('system/hostname') !== 'unchanged' || config_get_path('installedpackages/otherpackage/keep') !== 'unchanged') throw new RuntimeException('Native save modified unrelated settings');
$checks++;
// Notification opt-in validates native settings but never contacts a relay when saving.
$fixture_config['system']['domain'] = 'example.invalid';
$fixture_config['notifications']['smtp'] = ['ipaddress' => 'smtp.example.invalid', 'notifyemailaddress' => 'admin@example.invalid'];
recovery_guard_save_settings(array_replace($settings, ['enabled' => 'on', 'notifications' => 'on']));
if (!recovery_guard_compile_settings(config_get_path('installedpackages/recoveryguard/settings', []))['notifications']) throw new RuntimeException('Notification opt-in was not saved');
$checks++;
$fixture_config['notifications']['smtp']['disable'] = '';
$savedMail = $fixture_config; $writesMail = $fixture_writes; $rejected = false;
try { recovery_guard_save_settings(array_replace($settings, ['notifications' => 'on'])); } catch (InvalidArgumentException) { $rejected = true; }
if (!$rejected || $fixture_config !== $savedMail || $fixture_writes !== $writesMail) throw new RuntimeException('Disabled SMTP accepted notification opt-in');
$checks++;
unset($fixture_config['notifications']['smtp']['disable']);
recovery_guard_save_settings($settings);
if (isset(config_get_path('installedpackages/recoveryguard/settings', [])['notifications'])) throw new RuntimeException('Notification opt-out was not saved');
$checks++;
$saved = $fixture_config;
$savedWrites = $fixture_writes;
foreach ([['mode' => 'repair'], ['mode' => 'recover'], ['interface' => 'wan']] as $change) {
    $rejected = false;
    try { recovery_guard_save_settings(array_replace($settings, $change)); }
    catch (RuntimeException | InvalidArgumentException) { $rejected = true; }
    if (!$rejected || $fixture_config !== $saved || $fixture_writes !== $savedWrites) throw new RuntimeException('Rejected configuration was persisted');
    $checks++;
}
foreach ([false, -1, 'throw'] as $failure) {
    $fixture_write_result = $failure;
    try { recovery_guard_save_settings(array_replace($settings, ['peers' => "192.0.2.4\n192.0.2.5"])); throw new LogicException('Save failure ignored'); }
    catch (RuntimeException) {}
    if ($fixture_config !== $saved) throw new RuntimeException('Failed save did not restore request state');
    $checks++;
}
$plist = file($port . '/pkg-plist', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$actual = ['libexec/recovery-guard-repair']; // Built from the canonical C source, not a prebuilt binary.
if (!is_file($port . '/files/repair-controller.c') || !str_contains(file_get_contents($port . '/Makefile'), '${CC} ${CFLAGS}')) throw new RuntimeException('Native controller build input missing');
$prefix = $port . '/files/usr/local/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $actual[] = str_replace('\\', '/', substr($file->getPathname(), strlen($prefix)));
sort($actual);
if ($actual !== $plist || count($plist) !== count(array_unique($plist))) throw new RuntimeException('Install manifest differs from staged files');
$checks++;
$fixture_write_result = 'success';
recovery_guard_save_settings(array_replace($settings, ['enabled' => 'on']));
if (!recovery_guard_compile_settings(config_get_path('installedpackages/recoveryguard/settings', []))['enabled']) throw new RuntimeException('Monitor activation was not saved');
$checks++;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($port, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), "\r\n")) throw new RuntimeException('Port contains CRLF text');
}
$checks++;
echo "PASS: {$checks} staged native-adapter/manifest checks; config API test double, no firewall writes.\n";
