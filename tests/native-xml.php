<?php
declare(strict_types=1);
// Supply the actual pfSense xmlparse.inc; it is not vendored or replaced by a parser mock.
$parser = $argv[1] ?? '/etc/inc/xmlparse.inc';
$port = $argv[2] ?? dirname(__DIR__) . '/build/ports/sysutils/pfSense-pkg-Recovery_Guard';
if (!is_file($parser) || !extension_loaded('xml')) throw new RuntimeException('Native pfSense parser and PHP XML extension required');
require $parser;
set_include_path(__DIR__ . '/fixtures/native' . PATH_SEPARATOR . get_include_path());
require $port . '/files/usr/local/pkg/recovery_guard.inc';
define('LOG_PREFIX_CONFIG', 'fixture');
function localize_text(string $text, mixed ...$arguments): string { return $arguments ? vsprintf($text, $arguments) : $text; }
function logger(mixed ...$arguments): void { throw new RuntimeException('Native XML parser reported an error'); }
$fixture_config = ['interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']],
    'system' => ['hostname' => 'fixture'], 'installedpackages' => ['otherpackage' => ['keep' => 'untouched']]];
$fixture_writes = 0; $fixture_write_result = 'success';
$settings = ['interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3", 'maintenance' => 'on'];
$path = tempnam(sys_get_temp_dir(), 'recovery-guard-xml-');
$checks = 0;
try {
    recovery_guard_save_settings($settings);
    file_put_contents($path, dump_xml_config($fixture_config, 'pfsense'));
    $roundTrip = parse_xml_config($path, ['pfsense']);
    if ($roundTrip !== $fixture_config) throw new RuntimeException('Native XML save/read changes the configuration shape');
    $checks++;
    $fixture_config = $roundTrip;
    $compiled = recovery_guard_compile_settings(config_get_path('installedpackages/recoveryguard/settings', []));
    if ($compiled['peers'] !== ['192.0.2.2', '192.0.2.3'] || !$compiled['maintenance'] || $compiled['enabled']) throw new RuntimeException('Reloaded package settings differ');
    $checks++;
    unset($settings['maintenance']);
    recovery_guard_save_settings($settings);
    file_put_contents($path, dump_xml_config($fixture_config, 'pfsense'));
    $fixture_config = parse_xml_config($path, ['pfsense']);
    if (array_key_exists('maintenance', config_get_path('installedpackages/recoveryguard/settings', [])) ||
        config_get_path('system/hostname') !== 'fixture' || config_get_path('installedpackages/otherpackage/keep') !== 'untouched') throw new RuntimeException('Second save lost native values');
    $checks++;
    $settings['enabled'] = 'on';
    recovery_guard_save_settings($settings);
    file_put_contents($path, dump_xml_config($fixture_config, 'pfsense'));
    $fixture_config = parse_xml_config($path, ['pfsense']);
    $compiled = recovery_guard_compile_settings(config_get_path('installedpackages/recoveryguard/settings', []));
    if (!$compiled['enabled'] || $compiled['mode'] !== 'monitor' || $compiled['maintenance']) throw new RuntimeException('Enabled monitor did not survive native XML');
    $checks++;
    $fixture_config['system']['domain'] = 'example.invalid';
    $fixture_config['notifications']['smtp'] = ['ipaddress' => 'smtp.example.invalid', 'notifyemailaddress' => 'admin@example.invalid'];
    $settings['notifications'] = 'on'; recovery_guard_save_settings($settings);
    file_put_contents($path, dump_xml_config($fixture_config, 'pfsense'));
    $fixture_config = parse_xml_config($path, ['pfsense']);
    if (!recovery_guard_compile_settings(config_get_path('installedpackages/recoveryguard/settings', []))['notifications']) throw new RuntimeException('Notification opt-in did not survive native XML');
    $checks++;
    echo "PASS: {$checks} original pfSense XML round-trip checks; fixture config only.\n";
} finally { unlink($path); }
