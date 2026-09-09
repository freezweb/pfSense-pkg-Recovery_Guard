<?php
declare(strict_types=1);
require __DIR__ . '/../src/ProbeProcess.php';
require __DIR__ . '/../src/NetworkProbe.php';
require __DIR__ . '/../src/EndpointBaseline.php';
use RecoveryGuard\ProbeProcess;
use RecoveryGuard\NetworkProbe;
use RecoveryGuard\EndpointBaseline;

$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    $checks++;
}
$transport = ['status' => 'exited', 'exit_code' => 0, 'stdout' => '', 'stderr' => ''];
$calls = [];
$probe = new NetworkProbe(static function (...$args) use (&$transport, &$calls) { $calls[] = $args; return $transport; });
$transport['stdout'] = "em0: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500\n\tstatus: active\n";
check($probe->link('em0') === true, 'active link');
check($calls[0] === [['/sbin/ifconfig', 'em0'], 2.0, 16384], 'read-only explicit interface');
$transport['stdout'] = str_replace('active', 'no carrier', $transport['stdout']);
check($probe->link('em0') === false, 'no carrier');
$transport['stdout'] = str_replace('UP,', '', $transport['stdout']);
check($probe->link('em0') === null, 'administratively down is unknown');
$transport['stdout'] = "em1: flags=8843<UP>\n\tstatus: active\n";
check($probe->link('em0') === null, 'wrong interface');
$transport['stdout'] = "em0: flags=8843<UP>\n\tstatus: active\n\tstatus: no carrier\n";
check($probe->link('em0') === null, 'ambiguous status');
$transport['stdout'] = "1 packets transmitted, 1 packets received, 0.0% packet loss\n";
check($probe->endpoint('192.0.2.2', '192.0.2.1') === true, 'received ping');
check(array_slice($calls[count($calls)-1][0], -3) === ['-S', '192.0.2.1', '192.0.2.2'], 'explicit source');
$transport['exit_code'] = 2;
$transport['stdout'] = "1 packets transmitted, 0 packets received, 100.0% packet loss\n";
check($probe->endpoint('192.0.2.2', '192.0.2.1') === false, 'sent without reply');
$transport['stderr'] = 'ping: sendto: Network is unreachable';
check($probe->endpoint('192.0.2.2', '192.0.2.1') === null, 'routing error is unknown');
$transport['stderr'] = '';
foreach (['timeout', 'unavailable', 'output_limit', 'cleanup_unknown'] as $status) {
    $transport['status'] = $status;
    check($probe->endpoint('192.0.2.2', '192.0.2.1') === null, $status);
}
foreach (['-a', 'em0 up', "em0\n", 'em0;id', str_repeat('a', 16)] as $name) {
    try { $probe->link($name); throw new RuntimeException('accepted invalid interface'); }
    catch (InvalidArgumentException) { $checks++; }
}
foreach (['example.test', '127.0.0.1', '224.0.0.1', '0.0.0.0', '192.0.2.1', '192.0.2.2;id'] as $target) {
    try { $probe->endpoint($target, '192.0.2.1'); throw new RuntimeException('accepted invalid target'); }
    catch (InvalidArgumentException) { $checks++; }
}
$baseline = new EndpointBaseline();
$bad = ['peer_a' => false, 'peer_b' => false];
$good = ['peer_a' => true, 'peer_b' => true];
check($baseline->observe('boot-config', 0, $bad) === null, 'never-healthy peers');
foreach ([15, 30, 45] as $t) check($baseline->observe('boot-config', $t, $good) === true, 'baseline replies');
check($baseline->observe('boot-config', 60, $bad) === false, 'qualified peers failed');
check($baseline->observe('boot-config', 75, ['peer_a' => false, 'peer_b' => null]) === null, 'one unknown peer');
check($baseline->observe('boot-config', 90, ['peer_a' => false, 'peer_b' => true]) === true, 'one healthy peer');
check($baseline->observe('boot-config', 150, $bad) === null, 'gap resets qualification');
foreach ([165, 180, 195] as $t) $baseline->observe('boot-config', $t, $good);
check($baseline->observe('new-boot-config', 210, $bad) === null, 'boot/config reset');
foreach ([225, 240, 255] as $t) $baseline->observe('new-boot-config', $t, $good);
check($baseline->observe('new-boot-config', 255, $bad) === null, 'duplicate time resets');
foreach ([270, 285, 300] as $t) $baseline->observe('new-boot-config', $t, $good);
check($baseline->observe('new-boot-config', 315, ['peer_a' => false, 'peer_c' => false]) === null, 'peer replacement resets');
$runner = new ProbeProcess();
foreach ([[[], 2.0], [['relative'], 2.0], [['/bin/echo', "bad\0arg"], 2.0], [['/bin/echo'], INF],
    [['/bin/echo'], 0.0], [['/bin/echo'], 11.0]] as [$argv, $timeout]) {
    try { $runner->run($argv, $timeout); throw new RuntimeException('accepted invalid command'); }
    catch (InvalidArgumentException) { $checks++; }
}
if (PHP_OS !== 'FreeBSD') check($runner->run(['/bin/echo'])['status'] === 'unavailable', 'unsupported OS fails closed');
echo "PASS: {$checks} network collector, baseline and command-contract checks; synthetic data only.\n";
