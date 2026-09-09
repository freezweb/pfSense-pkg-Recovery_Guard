<?php
/* SPDX-License-Identifier: BSD-2-Clause */
// Package page comments alone are not loaded by pfSense's runtime privilege registry.
global $priv_list;
$priv_list['page-services-recoveryguard'] = [
    'name' => 'WebCfg - Services: Recovery Guard',
    'descr' => 'Allow access to Recovery Guard configuration and diagnostics.',
    'match' => ['services_recovery_guard.php*'],
];
