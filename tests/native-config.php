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
if ($fixture_writes !== 1 || config_get_path('installedpackages/recoveryguard/config/mode') !== 'monitor' ||
    config_get_path('system/hostname') !== 'unchanged' || config_get_path('installedpackages/otherpackage/keep') !== 'unchanged') throw new RuntimeException('Native save modified unrelated settings');
$checks++;
$saved = $fixture_config;
foreach ([['enabled' => 'on'], ['mode' => 'recover'], ['interface' => 'wan']] as $change) {
    $rejected = false;
    try { recovery_guard_save_settings(array_replace($settings, $change)); }
    catch (RuntimeException | InvalidArgumentException) { $rejected = true; }
    if (!$rejected || $fixture_config !== $saved || $fixture_writes !== 1) throw new RuntimeException('Rejected configuration was persisted');
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
$actual = [];
$prefix = $port . '/files/usr/local/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $actual[] = str_replace('\\', '/', substr($file->getPathname(), strlen($prefix)));
sort($actual);
if ($actual !== $plist || count($plist) !== count(array_unique($plist))) throw new RuntimeException('Install manifest differs from staged files');
$checks++;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($port, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), "\r\n")) throw new RuntimeException('Port contains CRLF text');
}
$checks++;
echo "PASS: {$checks} staged native-adapter/manifest checks; config API test double, no firewall writes.\n";
