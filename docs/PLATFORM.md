# Platform adapter boundaries

`ProbeProcess` is for finite read-only diagnostic commands, not service starts or reboot executors. `NetworkProbe` currently constructs only `ifconfig <interface>` and one source-bound numeric IPv4 ping. It never discovers peers or scans a subnet. The native configuration adapter must still verify that both source and target belong to the intended local network and that the route cannot leave through a WAN. Source binding alone does not establish route locality. Directed-broadcast and other local firewall-address checks also belong to that adapter; do not wire arbitrary GUI addresses directly to escalation.

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
