<?php
/* SPDX-License-Identifier: BSD-2-Clause */
##|+PRIV
##|*IDENT=page-services-recoveryguard
##|*NAME=Services: Recovery Guard
##|*DESCR=Allow access to Recovery Guard configuration.
##|*MATCH=services_recovery_guard.php*
##|-PRIV
require('guiconfig.inc');
require_once('/usr/local/pkg/recovery_guard.inc');
if (isset($_GET['download']) && !$_POST) {
    header('Cache-Control: no-store');
    try {
        $bytes = json_encode(['version' => 2, 'records' => recovery_guard_diagnostics()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="recovery-guard-diagnostics.json"');
        echo $bytes;
    } catch (Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo gettext('Recovery Guard diagnostics are unavailable.');
    }
    exit;
}
$input_errors = [];
$pconfig = config_get_path('installedpackages/recoveryguard/settings', []);
if ($_POST) {
    $pconfig = ['version' => '1', 'mode' => 'monitor',
        'interface' => $_POST['interface'] ?? '', 'peers' => $_POST['peers'] ?? ''];
    if (isset($_POST['maintenance'])) $pconfig['maintenance'] = 'on';
    if (isset($_POST['enabled'])) $pconfig['enabled'] = 'on';
    if (isset($_POST['notifications'])) $pconfig['notifications'] = 'on';
    try {
        recovery_guard_save_settings($pconfig);
        recovery_guard_service_apply();
        header('Location: services_recovery_guard.php?saved=1');
        exit;
    } catch (InvalidArgumentException | RuntimeException $error) {
        $input_errors[] = gettext($error->getMessage());
    }
}
$pgtitle = [gettext('Services'), gettext('Recovery Guard')];
include('head.inc');
print_info_box(gettext('Development preview: monitoring only. Service repair and automatic reboot are not available in this build.'), 'warning');
if ($input_errors) print_input_errors($input_errors);
if (isset($_GET['saved'])) print_info_box(gettext('Recovery Guard configuration saved.'), 'success');
$form = new Form('Save configuration');
$section = new Form_Section('Local network');
$section->addInput(new Form_Checkbox('enabled', 'Enable monitoring',
    'Observe PHP-FPM and the configured LAN', isset($pconfig['enabled'])))
    ->setHelp('Observations are recorded as status changes in the system log. This preview never repairs services or reboots.');
$section->addInput(new Form_Select('interface', 'LAN interface',
    is_string($pconfig['interface'] ?? null) ? $pconfig['interface'] : '', recovery_guard_interface_choices()))
    ->setHelp('Choose a static IPv4 LAN without an upstream gateway. VLAN parents are resolved from the firewall configuration.');
$section->addInput(new Form_Textarea('peers', 'Local peers',
    is_string($pconfig['peers'] ?? null) ? $pconfig['peers'] : ''))
    ->setHelp('Enter two to eight distinct, normally available hosts on this LAN, one numeric IPv4 address per line. Firewall and broadcast addresses are excluded.');
$section->addInput(new Form_Checkbox('maintenance', 'Maintenance',
    'Suspend recovery during planned maintenance', isset($pconfig['maintenance'])))
    ->setHelp('Pauses fault observation and clears pending failure confirmation.');
$section->addInput(new Form_Checkbox('notifications', 'Email notifications',
    'Report new recovery proposals and outcomes using system SMTP settings', isset($pconfig['notifications'])))
    ->setHelp('Requires monitoring and SMTP settings under System > Advanced > Notifications. The first successful check establishes a history baseline. Delivery attempts are limited; SMTP acceptance does not prove inbox delivery.');
$form->add($section);
print($form);
try {
    $mail = recovery_guard_notification_status();
    echo '<p>' . htmlspecialchars(sprintf(gettext('Notifications: %d pending, %d awaiting a receipt, %d accepted by SMTP, %d held; %d queue overflow attempts.'),
        $mail['pending'], $mail['sending'], $mail['accepted'], $mail['held'], $mail['overflow_attempts']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
} catch (Throwable) {
    print_info_box(gettext('Notification status is unavailable or currently in use. Monitoring does not depend on mail delivery.'), 'warning');
}
?>
<div class="panel panel-default">
  <div class="panel-heading"><h2 class="panel-title"><?=gettext('Recent diagnostic records')?></h2></div>
  <div class="panel-body">
    <p><?=gettext('The latest 64 confirmed action proposals are retained. Monitoring-only proposals never execute a repair. No recorded outcome means completion is unconfirmed; records are not replayed.')?></p>
<?php
try {
    $records = array_reverse(recovery_guard_diagnostics());
    if (!$records) {
        echo '<p>' . gettext('No action proposals have been recorded.') . '</p>';
    } else {
        $results = ['pending' => gettext('No outcome recorded'), 'handoff_pending' => gettext('Reboot handed off; completion unconfirmed'), 'mode_inhibited' => gettext('Monitoring only'),
            'interlock_inhibited' => gettext('Inhibited by maintenance or configuration'), 'completed' => gettext('Completed'), 'boot_observed' => gettext('New boot observed; cause and service recovery unconfirmed'),
            'failed' => gettext('Failed'), 'timeout_cleaned' => gettext('Timed out; process cleanup verified'), 'unknown' => gettext('Completion unknown')];
        $tri = static fn($v) => $v === null ? gettext('Unknown') : ($v ? gettext('Yes') : gettext('No'));
        echo '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>';
        foreach (['Time', 'Proposal', 'Outcome', 'PHP responding', 'Link up', 'LAN reachable', 'Log storm'] as $label) echo '<th>' . gettext($label) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($records as $record) {
            $s = $record['sample'];
            $cells = [date('Y-m-d H:i:s T', $s['time']), $record['kind'] === 'reboot' ? gettext('Reboot') : gettext('Repair PHP-FPM'),
                $results[$record['result']] . (isset($record['boot_observation']) ? ' (' . date('Y-m-d H:i:s T', $record['boot_observation']['time']) . ')' : ''), $tri($s['php_ok']), $tri($s['critical_link_up']), $tri($s['local_reachable']), $tri($s['log_storm'])];
            echo '<tr>';
            foreach ($cells as $cell) echo '<td>' . htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<a class="btn btn-default" href="services_recovery_guard.php?download=1">' . gettext('Download diagnostics') . '</a>';
} catch (Throwable) {
    print_info_box(gettext('Diagnostic history is unavailable or currently in use. Existing records have not been reset.'), 'warning');
}
?>
  </div>
</div>
<?php
include('foot.inc');
