<?php
declare(strict_types=1);
require_once('config.inc');
require_once(__DIR__ . '/recovery_guard/Configuration.php');

function recovery_guard_compile_settings(array $settings): array
{
    return \RecoveryGuard\Configuration::compile($settings, config_get_path('interfaces', []),
        config_get_path('vlans/vlan', []), config_get_path('virtualip/vip', []));
}

function recovery_guard_save_settings(array $settings): void
{
    $compiled = recovery_guard_compile_settings($settings);
    if ($compiled['mode'] !== 'monitor') {
        throw new RuntimeException('Active recovery is not available in this development build.');
    }
    if ($compiled['notifications']) {
        require_once(__DIR__ . '/recovery_guard/NotificationSmtp.php');
        $smtp = config_get_path('notifications/smtp', []);
        if (!is_array($smtp) || isset($smtp['disable'])) throw new InvalidArgumentException('Enable and configure system SMTP notifications first.');
        \RecoveryGuard\NotificationSmtp::target(['enabled' => true, 'booting' => false, 'smtp' => $smtp,
            'hostname' => config_get_path('system/hostname', ''), 'domain' => config_get_path('system/domain', '')]);
    }
    // "config" is a native XML list tag; use a non-list element for this object.
    $path = 'installedpackages/recoveryguard/settings';
    $previous = config_get_path($path, null);
    config_set_path($path, \RecoveryGuard\Configuration::native($compiled));
    try {
        if (!is_array(write_config('Services: Recovery Guard: saved configuration'))) {
            throw new RuntimeException('The firewall configuration could not be saved.');
        }
    } catch (Throwable $error) {
        if ($previous === null) config_del_path($path);
        else config_set_path($path, $previous);
        throw $error;
    }
}

function recovery_guard_interface_choices(): array
{
    $choices = ['' => gettext('Select a LAN interface')];
    foreach (config_get_path('interfaces', []) as $name => $interface) {
        if (preg_match('/\A(?:lan|opt[0-9]+)\z/D', $name) && is_array($interface) &&
            array_key_exists('enable', $interface) && empty($interface['gateway'])) {
            $choices[$name] = ($interface['descr'] ?? strtoupper($name)) . ' (' . $name . ')';
        }
    }
    return $choices;
}

function recovery_guard_service_apply(bool $deinstall = false): void
{
    require_once('util.inc');
    // Stop before package replacement/resync; never start a second supervisor after a failed stop.
    if (mwexec('/usr/local/etc/rc.d/recovery_guard.sh stop') !== 0) throw new RuntimeException('Recovery Guard did not stop; service changes were cancelled.');
    if ($deinstall) return; // Preserve the private journal across removal and reinstallation.
    if (mwexec('/usr/bin/timeout -k 1 10 /usr/local/bin/php /usr/local/pkg/recovery_guard/daemon.php init') !== 0) {
        throw new RuntimeException('Recovery Guard journal is unavailable; existing budgets were not reset.');
    }
    // A finite-command descendant reaper must never wrap a persistent service start.
    if (mwexec('/usr/local/etc/rc.d/recovery_guard.sh start') !== 0) throw new RuntimeException('Settings saved, but Recovery Guard could not start.');
}

function recovery_guard_diagnostics(): array
{
    require_once(__DIR__ . '/recovery_guard/StateStore.php');
    require_once(__DIR__ . '/recovery_guard/DiagnosticJournal.php');
    return (new \RecoveryGuard\DiagnosticJournal(new \RecoveryGuard\StateStore('/cf/conf/recovery_guard/diagnostics')))->records();
}

function recovery_guard_notification_status(): array
{
    require_once(__DIR__ . '/recovery_guard/StateStore.php');
    require_once(__DIR__ . '/recovery_guard/NotificationOutbox.php');
    $state = (new \RecoveryGuard\NotificationOutbox(new \RecoveryGuard\StateStore('/cf/conf/recovery_guard/notifications')))->state();
    $counts = ['pending' => 0, 'sending' => 0, 'accepted' => 0, 'held' => 0, 'overflow_attempts' => $state['overflow_attempts']];
    foreach ($state['items'] as $item) $counts[$item['status']]++;
    return $counts;
}
