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
$input_errors = [];
$pconfig = config_get_path('installedpackages/recoveryguard/settings', []);
if ($_POST) {
    $pconfig = ['version' => '1', 'mode' => 'monitor',
        'interface' => $_POST['interface'] ?? '', 'peers' => $_POST['peers'] ?? ''];
    if (isset($_POST['maintenance'])) $pconfig['maintenance'] = 'on';
    if (isset($_POST['enabled'])) $pconfig['enabled'] = 'on';
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
$form->add($section);
print($form);
include('foot.inc');
