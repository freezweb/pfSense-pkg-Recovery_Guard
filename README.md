# Recovery Guard for pfSense

Recovery Guard is an independent recovery supervisor under development for pfSense. It detects an unresponsive PHP-FPM service and corroborating local network failures, attempts a bounded service repair, and reserves a reboot only as a last resort.

**Development status:** tested libraries and a package design, not yet an installable pfSense package. No operating-system restart executor or active daemon is shipped. Integration tests use fake executors. Official pfSense repository inclusion remains a separate, uncompleted goal.

## Implemented

- RecoveryPolicy: sustained failure confirmation, separate repair/reboot budgets, boot grace, maintenance/upgrade inhibition, unknown-probe handling and explicit repair receipts.
- StateStore: exclusive action ownership, bounded JSON, atomic replacement, file and directory synchronization, restrictive permissions and protection against silently resetting a lost journal.
- ActionCoordinator: durable reservation before execution, evidence before execution and fresh action interlocks both before and after evidence capture. Active actions are inhibited for configured HA systems until a supported HA strategy has been validated.
- FastCgiProbe: a real PHP-FPM transaction over its local Unix socket. An unpredictable challenge prevents a stale response from passing. The health script is outside the web document root and needs no administrator password or public endpoint.

Default policy: confirm PHP failure for two minutes, then propose one service repair. Escalate only after ten minutes of continuous combined failure and at least three minutes after a confirmed repair attempt. Reserve at most one reboot per 24 hours and one service repair per 15 minutes. A failed WAN ping, an unplugged link or a GUI-only failure with working LAN is not sufficient for a reboot.

Reservations are conservative: ambiguous writes and mode-inhibited proposals may consume their cooldown, but are never reported as executed actions. Mode changes reconfirm the fault and retain budgets. A missing or corrupt journal inhibits action; explicit provisioning is not automatically repeated during upgrades or process restarts.

## Tests

PHP CLI 8.1 or newer:

~~~console
php tests/integration.php
php tests/fastcgi.php
php examples/replay-outage.php
~~~

The integration suite includes the policy suite. Coverage includes concurrent writer exclusion, repair/reboot reservation ordering, corrupted and missing state, ambiguous action commits, mode changes, fresh maintenance interlocks, malformed/fragmented FastCGI responses, output limits and timeouts.

On Windows, only directory-fsync is simulated. The journal tests have also run on pfSense CE 2.8.1 / PHP 8.3.19 with native file and directory fsync. Actual system restarts and package lifecycle tests require an isolated lab and are not yet proven.

The optional passive check tests/live-probe.php invokes the included challenge through the existing local PHP-FPM socket. It does not install a web route or alter services.

The example is a simulation with assumed continuous measurements and a simulated repair receipt. It does not establish how quickly a historical incident would have recovered.

## Package direction

Planned native port: sysutils/pfSense-pkg-Recovery_Guard, menu Services > Recovery Guard. The supervisor will run separately from PHP-FPM and use native pfSense repair routines. No core-file patches, cloud account, UniFi dependency or network scanning are planned.

Remaining work includes bounded platform execution and collectors, durable notification delivery, native configuration and GUI, lifecycle hooks, build manifests, isolated failure/reboot tests and current pfSense development-version validation. See [acceptance tracking](docs/ACCEPTANCE.md).

A completely frozen kernel cannot run a local supervisor. A supported, separately tested hardware watchdog or independent management controller is required for that class of failure. Merely finding the FreeBSD watchdog interface does not prove hardware-reset support.

## References

- [Netgate package development and submission](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html)
- [Native package layout](https://docs.netgate.com/pfsense/en/latest/development/package-directories.html)
- [Existing Service Watchdog source](https://github.com/pfsense/FreeBSD-ports/blob/devel/sysutils/pfSense-pkg-Service_Watchdog/files/usr/local/pkg/servicewatchdog.inc)
- [Hardware watchdog support](https://docs.netgate.com/pfsense/en/latest/config/advanced-misc.html#watchdog)
- [PHP-FPM retry log-storm report, issue 13252](https://redmine.pfsense.org/issues/13252)

This is an independent project, not an official Netgate package. A repository or pull request is not proof of availability in the official package manager.
