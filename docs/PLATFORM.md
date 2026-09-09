# Platform adapter boundaries

`ProbeProcess` is for finite read-only diagnostic commands, not service starts or reboot executors. `NetworkProbe` constructs `ifconfig <interface>`, explicit route lookups and source-bound numeric IPv4 pings. It never discovers peers or scans a subnet. `Configuration` validates static LAN addresses, native VLAN parent mapping, same-subnet peers and exclusion of all supplied firewall/VIP addresses, network and broadcast. `localEndpoint` requires the expected interface and a direct UP route without GATEWAY/REJECT/BLACKHOLE both before and after a `ping -r` transaction. The direct-send option bypasses gateways. Route errors and changes suppress observations. These checks reduce routing ambiguity but are not an atomic snapshot of network configuration.

The low-level `endpoint` method remains available for command-contract tests; use the validated `localEndpoint` path for recovery decisions. Bridge, LAGG, QinQ and other virtual-device health semantics remain unvalidated. An unrecognized link status stays unknown. Runtime integration must also invalidate the baseline when relevant topology changes.

The runner uses an argument array, a fixed minimal environment, no stdin, no shell and no output files. It drains stdout and stderr with a combined storage cap. FreeBSD `timeout` supplies the primary deadline and descendant reaper; TERM is followed by KILL after 0.5 seconds. The PHP observer has an additional deadline and returns `cleanup_unknown` if normal completion cannot be established. Timeout, excessive output, permissions, route errors and malformed responses cannot become a negative network observation.

These limits assume a functioning kernel and process scheduler. `proc_close`, process creation and kernel I/O cannot provide a hardware-level guarantee during a kernel freeze. Timeout and descendant paths pass the isolated FreeBSD 15.0-p13 lab suite; supported pfSense guests still require their own execution evidence. Do not represent this diagnostic adapter as a repair receipt.

## Why service repair needs a separate adapter

FreeBSD timeout normally acquires a descendant reaper and waits for descendants. A successful service start intentionally leaves a daemon running. Applying this finite-command wrapper to a service-start routine can therefore time out and kill the newly started service. The eventual repair adapter must track the repair controller separately from the service it starts, check actual post-repair health and classify uncertain completion conservatively. It must not infer success solely from exit status 0 or timeout exit status 124.

FreeBSD 14.1 and 15.0 timeout implementations differ in their termination/reaping paths. An exit code alone is not a portable proof that every descendant has disappeared. The lab harness checks direct timeout, an orphaned grandchild, a detached descendant, ignored TERM signals and a killed timeout wrapper. The last case must return unknown cleanup and never a completion receipt. Resource exhaustion and pfSense-version coverage remain release tests.

## Peer qualification

`EndpointBaseline` requires two to eight explicitly named peers, with three healthy observations spanning at least 30 seconds for every peer before aggregate failure is possible. One reply proves connectivity; an unknown observation suppresses aggregate failure. A supervisor restart, changed context (boot plus relevant configuration), changed peer set, duplicate/backward observation time or gap over 45 seconds clears qualification. The state is intentionally volatile and adds no flash writes.

The caller must construct the context from real boot/configuration identity and supply a monotonic observation clock. This library is not yet connected to the live recovery coordinator. Endpoint selection must avoid devices routinely sleeping or powered down together. A healthy baseline is additional corroboration, not proof that the firewall caused a later outage.

## Validation status, 2026-09-09

- Windows PHP 8.3: 43 collector, baseline and argument-contract checks passed. Unsupported platforms return unavailable rather than attempting a substitute command.
- pfSense CE 2.8.1 / PHP 8.3.19: 42 corresponding checks passed (the non-FreeBSD guard case does not apply).
- Native read-only command path: `kern.ostype` returned FreeBSD in 2 ms; one explicitly selected physical link returned active.
- Isolated FreeBSD 15.0-p13 / PHP 8.3.33: eight native process cases passed, including output caps, timeouts, detached and signal-resistant descendants and an interrupted wrapper. The same guest passes 76 integration, 10 FastCGI and 42 network-contract checks. `tests/process-lab.php` requires both the lab file and environment marker; these are operator guards, not an isolation mechanism.
- No endpoint packet was sent during the passive platform verification. No production service was restarted and no active recovery setting was installed.

Sources: [FreeBSD 15 timeout manual](https://github.com/freebsd/freebsd-src/blob/releng/15.0/bin/timeout/timeout.1), [FreeBSD 15 implementation](https://github.com/freebsd/freebsd-src/blob/releng/15.0/bin/timeout/timeout.c), [FreeBSD 14.1 implementation](https://github.com/freebsd/freebsd-src/blob/releng/14.1/bin/timeout/timeout.c), [FreeBSD ping manual](https://github.com/freebsd/freebsd-src/blob/releng/15.0/sbin/ping/ping.8).

## Native repair controller

`native/repair-controller.c` owns a FreeBSD descendant reaper and waits only for the direct repair controller during normal execution. The production binary requires root and accepts exactly `repair`, mapped to `/etc/rc.php-fpm_restart` with a 30-second limit. It never accepts a command from package settings. A separately compiled laboratory binary accepts fixture commands; that binary is not packaged. Descriptors above stderr are closed before forking so persistent service children cannot retain the supervisor's journal or lease descriptors. Controller output is discarded; only a fixed, bounded outcome is returned.

Controller exit zero releases reaper ownership without waiting for, or killing, new daemons. `RepairExecutor` then requires a fresh PHP transaction for a healthy result. An unhealthy transaction is a failed completed attempt, not a successful repair. Nonzero controller exit, timeout or cancellation triggers descendant termination: TERM, then KILL, with a three-second cleanup deadline and a kernel ownership/status check. Only verified empty descendant state yields a cleaned result. `RepairProcess` uses a separate 35-second observer deadline and validates the helper result against its process exit status; it never uses the diagnostic timeout reaper.

Unknown cleanup and cancellation cannot produce a repair receipt for reboot escalation. An externally SIGKILLed controller cannot certify descendant cleanup; the laboratory test deliberately verifies the absent receipt, with self-expiring fixtures. Kernel-uninterruptible I/O and scheduling failure remain outside software deadline guarantees. A dedicated test kills the PHP owner and verifies that native parent-death signalling causes the controller to clean its remaining tree.

The shipped monitor daemon does not invoke these adapters. Existing coordinator reservation, evidence and fresh interlock requirements must remain ahead of execution when active modes are wired. The observed native PHP-FPM restart script has no common recovery mutex and also restarts `check_reload_status`, regenerates PHP configuration and removes the XMLRPC lock. HA inhibition therefore remains required. A process snapshot is not atomic exclusion against a maintenance action starting immediately afterwards; supported native lifecycle coordination and collision tests remain release requirements.

The native reboot path also needs a separate design: `system_reboot_cleanup()` calls `stop_packages()`, which would stop this supervisor itself. A synchronous nested reboot call could deadlock or terminate its own action worker. Reboot handoff must preserve the durable reservation and evidence across supervisor shutdown and must be validated in a pfSense guest before activation.

Source inspected: [native PHP-FPM restart](https://github.com/pfsense/pfsense/blob/9363ac5b8651a1c7a333180425ce7719070f95f9/src/etc/rc.php-fpm_restart), [native system cleanup](https://github.com/pfsense/pfsense/blob/9363ac5b8651a1c7a333180425ce7719070f95f9/src/etc/inc/system.inc), [FreeBSD process ownership](https://github.com/freebsd/freebsd-src/blob/releng/15.0/lib/libsys/procctl.2).
