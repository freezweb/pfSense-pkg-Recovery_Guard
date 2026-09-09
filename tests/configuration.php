<?php
declare(strict_types=1);
require __DIR__ . '/../src/Configuration.php';
require __DIR__ . '/../src/NetworkProbe.php';
use RecoveryGuard\Configuration;
use RecoveryGuard\NetworkProbe;
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
$interfaces = ['lan' => ['enable' => '', 'if' => 'em1.20', 'ipaddr' => '192.0.2.1', 'subnet' => '24'],
    'wan' => ['enable' => '', 'if' => 'em0', 'ipaddr' => '203.0.113.1', 'subnet' => '24', 'gateway' => 'WAN_GW'],
    'opt1' => ['enable' => '', 'if' => 'em2', 'ipaddr' => '192.0.2.253', 'subnet' => '24']];
$vlans = [['vlanif' => 'em1.20', 'if' => 'em1']];
$vips = [['subnet' => '192.0.2.254']];
$settings = ['version' => '1', 'enabled' => 'on', 'mode' => 'recover', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3"];
$compile = fn($s) => Configuration::compile($s, $interfaces, $vlans, $vips);
$result = $compile($settings);
check($result['device'] === 'em1.20' && $result['link_device'] === 'em1', 'VLAN probe and parent link stay distinct');
check($result['source'] === '192.0.2.1' && $result['peers'] === ['192.0.2.2', '192.0.2.3'], 'compiled local peers');
check($compile(Configuration::native($result)) === $result, 'native round trip');
check(Configuration::compile([], [])['enabled'] === false, 'unconfigured defaults disabled');
check($compile(array_replace($settings, ['maintenance' => '']))['maintenance'] === true, 'native empty maintenance element');
$bad = [ ['version' => '2'], ['enabled' => 'false'], ['mode' => 'unknown'], ['interface' => 'wan'],
    ['interface' => 'opt999'], ['interface' => ['lan']], ['peers' => ['192.0.2.2']], ['peers' => '192.0.2.2'],
    ['peers' => "192.0.2.2\n192.0.2.2"], ['peers' => str_repeat('x', 513)],
    ['peers' => implode("\n", array_map(fn($n) => '192.0.2.' . $n, range(2, 10)))]];
foreach (['192.0.2.0', '192.0.2.255', '192.0.2.1', '192.0.2.253', '192.0.2.254', '203.0.113.2',
    'example.test', '127.0.0.1', '224.0.0.1', '::1'] as $peer) $bad[] = ['peers' => $peer . "\n192.0.2.3"];
foreach ($bad as $change) {
    try { $compile(array_replace($settings, $change)); throw new RuntimeException('Accepted invalid settings'); }
    catch (InvalidArgumentException) { $checks++; }
}
foreach ([['ipaddr' => 'dhcp'], ['ipaddr' => '192.0.2.0'], ['ipaddr' => '192.0.2.255'], ['subnet' => '32'], ['gateway' => 'another-router'], ['if' => 'em1; id']] as $change) {
    $changed = $interfaces; $changed['lan'] = array_replace($changed['lan'], $change);
    try { Configuration::compile($settings, $changed, $vlans); throw new RuntimeException('Accepted unsafe LAN'); }
    catch (InvalidArgumentException) { $checks++; }
}
try { $changed = $interfaces; unset($changed['lan']['enable']); Configuration::compile($settings, $changed); throw new RuntimeException('Disabled LAN accepted'); }
catch (InvalidArgumentException) { $checks++; }
foreach ([array_merge($vlans, $vlans), [['vlanif' => 'em1.20', 'if' => 'em1.20']]] as $chain) {
    try { Configuration::compile($settings, $interfaces, $chain); throw new RuntimeException('Bad VLAN chain accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
$route = ['status' => 'exited', 'exit_code' => 0, 'stdout' => "  interface: em1.20\n  flags: <UP,HOST,DONE,LLINFO>\n", 'stderr' => ''];
$calls = [];
$probe = new NetworkProbe(function ($argv) use (&$route, &$calls): array {
    $calls[] = $argv;
    return $argv[0] === '/sbin/route' ? $route : ['status' => 'exited', 'exit_code' => 2,
        'stdout' => "1 packets transmitted, 0 packets received, 100.0% packet loss\n", 'stderr' => ''];
});
check($probe->localEndpoint('192.0.2.2', '192.0.2.1', 'em1.20') === false, 'direct LAN no reply is a negative observation');
check(count($calls) === 3 && $calls[0] === ['/sbin/route', '-n', 'get', '-inet', '192.0.2.2'] && $calls[2] === $calls[0], 'route is checked before and after one ping');
check(in_array('-r', $calls[1], true) && array_slice($calls[1], -3) === ['-S', '192.0.2.1', '192.0.2.2'], 'local ping bypasses gateways and binds the LAN source');
$before = $route; $after = $route;
$after['stdout'] = "interface: em0\nflags: <UP,GATEWAY>\n";
$sequence = [$before, ['status' => 'exited', 'exit_code' => 2,
    'stdout' => "1 packets transmitted, 0 packets received, 100.0% packet loss\n", 'stderr' => ''], $after];
$moving = new NetworkProbe(function () use (&$sequence) { return array_shift($sequence); });
check($moving->localEndpoint('192.0.2.2', '192.0.2.1', 'em1.20') === null && $sequence === [], 'route change during a failed ping inhibits the observation');
foreach (["interface: em0\nflags: <UP,DONE>\n", "interface: em1.20\nflags: <UP,GATEWAY,DONE>\n",
    "interface: em1.20\nflags: <UP,REJECT>\n", "interface: em1.20\nflags: <UP,BLACKHOLE>\n",
    "interface: em1.20\nflags: <DONE>\n", "interface: em1.20\ninterface: em1.20\nflags: <UP>\n", 'unrecognized'] as $text) {
    $route['stdout'] = $text; $calls = [];
    check($probe->localEndpoint('192.0.2.2', '192.0.2.1', 'em1.20') === null && count($calls) === 1, 'uncertain/nonlocal route suppresses ping');
}
echo "PASS: {$checks} configuration and local-route checks; synthetic topology only.\n";
