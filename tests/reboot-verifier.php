<?php
declare(strict_types=1);
foreach (['Configuration', 'NetworkProbe', 'LogRateProbe', 'RebootVerifier'] as $n) require __DIR__ . '/../src/' . $n . '.php';
use RecoveryGuard\{NetworkProbe, LogRateProbe, RebootVerifier};
$checks = 0; $files = [];
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function blocked(Closure $fn, string $label): void { try { $fn(); } catch (Throwable) { check(true, $label); return; } check(false, $label); }
function fixture(): array {
    global $files;
    $f = (object)['mono' => 0, 'wallShift' => 0, 'php' => false, 'link' => false, 'peer' => false,
        'storm' => true, 'logCalls' => 0, 'networkCalls' => [], 'phpCalls' => 0, 'reads' => 0, 'hook' => null];
    $f->snapshot = ['boot_id' => 'fixture-boot', 'settings' => ['enabled' => 'on', 'mode' => 'recover', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3"],
        'interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']], 'vlans' => [], 'virtual_ips' => [],
        'interlocks' => array_fill_keys(['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'], false)];
    $f->file = tempnam(sys_get_temp_dir(), 'recovery-guard-reverify-'); $files[] = $f->file;
    $f->line = "Sep  9 01:00:00 fixture check_reload_status[7]: Could not connect to /var/run/php-fpm.socket\n";
    file_put_contents($f->file, str_repeat($f->line, 200)); // Historical records must never prove the current storm.
    $f->reader = new LogRateProbe($f->file);
    $network = new NetworkProbe(function ($argv) use ($f): array {
        $f->networkCalls[] = $argv; if ($f->hook) ($f->hook)('network', $f, $argv);
        if ($argv[0] === '/sbin/ifconfig') return ['status' => 'exited', 'exit_code' => 0, 'stderr' => '',
            'stdout' => "em1: flags=8843<UP,BROADCAST,RUNNING>\n status: " . ($f->link === null ? 'unknown' : ($f->link ? 'active' : 'no carrier')) . "\n"];
        if ($argv[0] === '/sbin/route') return ['status' => 'exited', 'exit_code' => 0, 'stderr' => '', 'stdout' => "interface: em1\nflags: <UP,HOST,DONE>\n"];
        return ['status' => $f->peer === null ? 'timeout' : 'exited', 'exit_code' => $f->peer ? 0 : 2, 'stderr' => '',
            'stdout' => '1 packets transmitted, ' . ($f->peer ? '1' : '0') . " packets received, 100.0% packet loss\n"];
    });
    $v = new RebootVerifier(function () use ($f) { $f->reads++; if ($f->hook) ($f->hook)('snapshot', $f, []); return $f->snapshot; },
        function () use ($f) { $f->phpCalls++; if ($f->hook) ($f->hook)('php', $f, []); return ['ok' => $f->php]; }, $network,
        function ($context, $time) use ($f) { $f->logCalls++; if ($f->logCalls % 2 === 0 && $f->storm) file_put_contents($f->file, str_repeat($f->line, 60), FILE_APPEND); return $f->reader->check($context, $time); },
        fn() => 1000 + intdiv($f->mono, 1000000000) + $f->wallShift, fn() => $f->mono,
        function () use ($f) { $f->mono += 1000000000; });
    return [$f, $v];
}
try {
    [$f, $v] = fixture(); $r = $v->check();
    check($r['php_ok'] === false && $r['local_reachable'] === false && $r['critical_link_up'] === false && $f->phpCalls === 2, 'combined current failure reconfirmed with final PHP transaction');
    $pings = array_values(array_filter($f->networkCalls, fn($a) => $a[0] === '/sbin/ping'));
    check(count($pings) === 2 && array_map(fn($a) => end($a), $pings) === ['192.0.2.2', '192.0.2.3'] && in_array('-r', $pings[0], true), 'only exact configured peers are probed directly');
    foreach ([true, null] as $value) {
        [$f, $v] = fixture(); $f->php = $value; $r = $v->check();
        check($r['php_ok'] === $value && $f->networkCalls === [], 'recovered or unknown PHP cancels further collection');
        [$f, $v] = fixture(); $f->peer = $value; $r = $v->check();
        check($r['local_reachable'] === $value, 'recovered or unknown LAN cancels reboot decision');
    }
    [$f, $v] = fixture(); $f->link = null; $r = $v->check();
    check($r['critical_link_up'] === null && count($f->networkCalls) === 1, 'unknown link stops collection');
    [$f, $v] = fixture(); $f->link = true; $r = $v->check();
    check($r['log_storm'] === true && $f->mono >= 5000000000 && $f->logCalls === 2, 'active link requires freshly observed log storm');
    [$f, $v] = fixture(); $f->link = true; $f->storm = false; $r = $v->check();
    check($r['log_storm'] === false, 'historical storm is not accepted as a current failure');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'php' && $f->phpCalls === 2) $f->php = true; };
    check($v->check()['php_ok'] === true, 'late PHP recovery cancels after network collection');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'snapshot' && $f->reads === 2) $f->snapshot['settings']['peers'] = "192.0.2.4\n192.0.2.5"; };
    blocked(fn() => $v->check(), 'changed topology after collection inhibits');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'snapshot' && $f->reads === 2) $f->snapshot['interlocks']['upgrade'] = true; };
    blocked(fn() => $v->check(), 'late maintenance inhibits');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'network' && count($f->networkCalls) > 1) $f->link = true; };
    check($v->check()['critical_link_up'] === null, 'link transition during peer checks inhibits');
    [$f, $v] = fixture(); $f->snapshot['interlocks']['ha_configured'] = true; $v->check();
    check($f->phpCalls === 0 && $f->networkCalls === [], 'initial interlock prevents collection');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'php') $f->mono += 26000000000; };
    blocked(fn() => $v->check(), 'collection overrun inhibits');
    [$f, $v] = fixture(); $f->hook = function ($event, $f) { if ($event === 'php') $f->wallShift += 10; };
    blocked(fn() => $v->check(), 'wall clock discontinuity inhibits');
    echo "PASS: {$checks} final reboot fault-verification checks; synthetic network and local log fixtures.\n";
} finally {
    // Reader descriptors close at process exit; defer Windows unlink failures on retained handles.
    foreach ($files as $file) if (is_file($file)) @unlink($file);
}
