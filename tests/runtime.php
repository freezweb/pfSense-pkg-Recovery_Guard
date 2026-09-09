<?php
declare(strict_types=1);
foreach (['RecoveryPolicy', 'StateStore', 'ActionCoordinator', 'Configuration', 'NetworkProbe', 'EndpointBaseline', 'RuntimeSupervisor', 'LogRateProbe'] as $class) require __DIR__ . '/../src/' . $class . '.php';
use RecoveryGuard\{RecoveryPolicy, StateStore, RuntimeSupervisor, NetworkProbe, LogRateProbe};
$checks = 0; $directories = [];
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function fixture(): array {
    global $directories;
    $directory = sys_get_temp_dir() . '/recovery-guard-runtime-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700); $directories[] = $directory;
    $store = new StateStore($directory, PHP_OS_FAMILY === 'Windows' ? static function () {} : null);
    $store->exclusive(fn($s) => $s->provision(RecoveryPolicy::provision()));
    $f = new stdClass();
    $f->time = 1000; $f->mono = 1000000000000; $f->healthy = true; $f->calls = 0; $f->actions = [];
    $f->readHook = null; $f->probeHook = null; $f->evidenceHook = null; $f->log = false;
    $f->snapshot = ['boot_id' => 'fixture-boot', 'uptime' => 1000,
        'settings' => ['enabled' => 'on', 'mode' => 'recover', 'interface' => 'lan', 'peers' => "192.0.2.2\n192.0.2.3"],
        'interfaces' => ['lan' => ['enable' => '', 'if' => 'em1', 'ipaddr' => '192.0.2.1', 'subnet' => '24']],
        'vlans' => [], 'virtual_ips' => [], 'interlocks' => array_fill_keys(['maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down'], false)];
    $network = new NetworkProbe(function ($argv) use ($f): array {
        $f->calls++;
        if ($f->probeHook) ($f->probeHook)($f, $argv);
        $text = match ($argv[0]) {
            '/sbin/ifconfig' => 'em1: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST>' . "\n status: " . ($f->healthy ? 'active' : 'no carrier') . "\n",
            '/sbin/route' => "interface: em1\nflags: <UP,HOST,DONE>\n",
            default => '1 packets transmitted, ' . ($f->healthy ? '1' : '0') . " packets received, 0.0% packet loss\n",
        };
        return ['status' => 'exited', 'exit_code' => $argv[0] === '/sbin/ping' && !$f->healthy ? 2 : 0, 'stdout' => $text, 'stderr' => ''];
    });
    $runtime = new RuntimeSupervisor(new RecoveryPolicy(), $store,
        function () use ($f) { if ($f->readHook) ($f->readHook)($f); return $f->snapshot; },
        fn() => ['ok' => $f->healthy], $network, fn() => $f->log,
        function () use ($f) { if ($f->evidenceHook) ($f->evidenceHook)($f); },
        function ($p) use ($f) { $f->actions[] = $p['kind']; return ['id' => $p['id'], 'outcome' => 'completed']; },
        fn() => $f->time, fn() => $f->mono);
    return [$f, $runtime, $store];
}
function advance(stdClass $f, int $seconds = 15): void { $f->time += $seconds; $f->mono += $seconds * 1000000000; $f->snapshot['uptime'] += $seconds; }
function qualify(stdClass $f, RuntimeSupervisor $runtime): void { for ($i = 0; $i < 3; $i++) { $runtime->cycle(); advance($f); } }

try {
    [$f, $runtime, $store] = fixture(); qualify($f, $runtime);
    $f->healthy = false;
    for ($i = 0; $i < 60; $i++) { $runtime->cycle(); advance($f); }
    check($f->actions === ['repair_php_fpm', 'reboot'], 'joined collectors, baseline and real journal coordinate bounded fake recovery');
    check(count($store->exclusive(fn($s) => $s->read())['reboot_times']) === 1, 'joined runtime preserves reboot reservation');

    foreach (['disabled', 'maintenance', 'upgrade', 'ha_configured', 'other_repair', 'shutting_down', 'unknown'] as $gate) {
        [$f, $runtime] = fixture();
        if ($gate === 'disabled') unset($f->snapshot['settings']['enabled']);
        elseif ($gate === 'unknown') unset($f->snapshot['interlocks']['upgrade']);
        else $f->snapshot['interlocks'][$gate] = true;
        $result = $runtime->cycle();
        check($result['sample'] === null && $f->calls === 0 && $f->actions === [], 'early inhibition: ' . $gate);
    }
    [$f, $runtime] = fixture(); qualify($f, $runtime); $f->healthy = false;
    for ($i = 0; $i < 8; $i++) { $runtime->cycle(); advance($f); }
    $f->snapshot['settings']['peers'] = "192.0.2.4\n192.0.2.5";
    $r = $runtime->cycle();
    check($r['reason'] === 'unknown_probe' && $f->actions === [], 'new peers cannot inherit healthy baseline or pending repair');

    [$f, $runtime] = fixture();
    $f->probeHook = function ($f) { $f->snapshot['settings']['maintenance'] = 'on'; };
    check($runtime->cycle()['reason'] === 'snapshot_changed' && $f->actions === [], 'configuration changes inside a cycle inhibit');
    [$f, $runtime] = fixture();
    $f->probeHook = function ($f) { $f->mono += 26000000000; $f->time += 26; };
    check($runtime->cycle()['reason'] === 'cycle_unavailable' && $f->calls === 1, 'cycle deadline stops remaining probes');
    [$f, $runtime] = fixture(); $runtime->cycle(); $f->time += 3600; $f->mono += 15000000000;
    check($runtime->cycle()['reason'] === 'clock_changed', 'forward wall-clock jump cannot mature confirmation');
    [$f, $runtime] = fixture(); $runtime->cycle();
    check($runtime->cycle()['reason'] === 'clock_changed', 'duplicate cycle time rejected');
    [$f, $runtime] = fixture(); qualify($f, $runtime); $f->healthy = false;
    $f->evidenceHook = function ($f) { $f->snapshot['settings']['peers'] = "192.0.2.4\n192.0.2.5"; };
    for ($i = 0; $i < 9; $i++) { $r = $runtime->cycle(); advance($f); }
    check($r['execution'] === 'interlock_inhibited' && $f->actions === [], 'fresh context checked again after evidence capture');
    [$f, $runtime] = fixture(); $f->readHook = function () { throw new RuntimeException('secret fixture content'); };
    check(!str_contains(json_encode($runtime->cycle()), 'secret'), 'adapter exception contents are not runtime output');

    $dir = end($directories); $path = $dir . '/system.log';
    $line = "Sep  9 00:00:00 fixture check_reload_status[123]: Could not connect to /var/run/php-fpm.socket\n";
    file_put_contents($path, str_repeat($line, 200));
    $log = new LogRateProbe($path);
    check($log->check('boot', 100) === null, 'old log contents do not become fresh storm');
    check($log->check('boot', 115) === false, 'known empty fresh interval');
    file_put_contents($path, str_repeat($line, 150), FILE_APPEND);
    check($log->check('boot', 130) === true, 'consecutive socket retry lines count exactly at ten per second');
    file_put_contents($path, str_repeat("fixture mdns: Network is down\n", 200), FILE_APPEND);
    check($log->check('boot', 145) === false, 'unrelated interface errors do not corroborate PHP storm');
    file_put_contents($path, str_repeat($line, 2000), FILE_APPEND);
    check($log->check('boot', 160) === true, 'bounded read lower bound detects storm without reading all bytes');
    file_put_contents($path, str_repeat("unrelated\n", 10000), FILE_APPEND);
    check($log->check('boot', 175) === null, 'truncated non-storm sample remains unknown');
    check($log->check('new-boot', 190) === null, 'new context discards log interval');
    check($log->check('new-boot', 250) === null, 'long gap discards interval');
    file_put_contents($path, 'short');
    check($log->check('new-boot', 265) === null, 'copytruncate resets observation');
    unset($log);
    file_put_contents($path, ''); $log = new LogRateProbe($path); $log->check('boot', 100);
    file_put_contents($path, str_repeat('Sep  9 00:00:00 fixture mdns[12]: quoted ' . $line, 200), FILE_APPEND);
    check($log->check('boot', 115) === false, 'embedded messages from another program cannot count as native retries');
    unset($log);
    if (PHP_OS === 'FreeBSD') {
        file_put_contents($path, ''); $log = new LogRateProbe($path); $log->check('boot', 100);
        file_put_contents($path, str_repeat($line, 150), FILE_APPEND);
        rename($path, $path . '.0'); file_put_contents($path, str_repeat($line, 500));
        check($log->check('boot', 115) === true, 'rotation retains a lower bound from the previous open descriptor');
        check($log->check('boot', 130) === false, 'replacement log historical contents are skipped');
        rename($path, $path . '.1'); file_put_contents($path, '');
        check($log->check('boot', 145) === null, 'rotation without enough observed retries is unknown');
        unset($log);
        symlink($path, $dir . '/log-link');
        $log = new LogRateProbe($dir . '/log-link');
        check($log->check('boot', 100) === null, 'symlink log is refused');
        unset($log); unlink($dir . '/log-link');
    }
    $log = new LogRateProbe($dir . '/absent');
    check($log->check('boot', 100) === null, 'missing log is unknown');
    unset($log);
    echo "PASS: {$checks} joined runtime and bounded log checks; synthetic platform and fake actions.\n";
} finally {
    unset($log);
    foreach ($directories as $directory) {
        foreach (glob($directory . '/*') as $file) if (is_file($file)) unlink($file);
        rmdir($directory);
    }
}
