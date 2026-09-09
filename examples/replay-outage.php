<?php
declare(strict_types=1);
require __DIR__ . '/../src/RecoveryPolicy.php';
use RecoveryGuard\RecoveryPolicy;

// Hypothetical continuous observations, informed by the incident; NOT measured samples.
date_default_timezone_set('Europe/Berlin');
$start = strtotime('2026-09-09 04:06:00');
$policy = new RecoveryPolicy(); $state = RecoveryPolicy::provision();
echo "SIMULATION ONLY: assumed continuous failure, simulated repair receipt.\n";
for ($time = $start; $time <= $start + 900; $time += 30) {
    $result = $policy->step($state, [
        'time' => $time, 'boot_id' => 'simulated-outage-boot', 'uptime' => 604800,
        'php_ok' => false, 'critical_link_up' => false, 'local_reachable' => false,
        'log_storm' => true, 'maintenance' => false, 'upgrade' => false,
    ]);
    $state = $result['state'];
    if ($result['proposal']) {
        echo date('H:i:s', $time) . ' WOULD PROPOSE ' . $result['proposal']['kind'] . "\n";
        if ($result['proposal']['kind'] === 'repair_php_fpm') {
            $state = $policy->acknowledgeRepair($state, $result['proposal']['id'], $time);
        }
    }
}
echo "No service restarted; no reboot requested from the operating system.\n";
