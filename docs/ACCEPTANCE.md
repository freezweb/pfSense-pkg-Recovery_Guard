# Acceptance evidence and remaining work

The goal is availability in the official pfSense package repository. It remains open: no Netgate pull request, acceptance or official package availability is claimed. The shipped package remains a monitor-only development preview.

## Current native evidence, 2026-09-09

A separate isolated VM now runs the officially installed pfSense CE 2.9.0-RELEASE, FreeBSD 16.0-CURRENT and PHP 8.5.7. The approved installer download is resolved and its SHA256 verified. No production guest was stopped or reconfigured.

- 414 component checks passed on the native guest, including original config.xml parser round trips. Eight native process, ten notification-worker, seventeen private-FPM repair-controller and sixteen native upgrade-wrapper lease checks also passed. Fixture actions and native actions are distinguished in [VALIDATION.md](VALIDATION.md).
- The 42-file port builds for the actual FreeBSD 16 ABI and PHP 8.5 dependency set. Compile, stage, check-plist, stage-qa and package pass. The FreeBSD 15 binary was not forced onto pfSense.
- Actual package hooks exposed and then verified a service-registration fix: pfSense previously removed a pkg-owned rc script before custom deinstallation. Explicit native start/stop commands preserve package ownership. Nineteen native lifecycle checks now pass for replacement, removal and reinstall, including exact retained budget hashes and rejection of hidden hook errors even when pkg returns success. Native registered stop/start and after-sync controls also pass.
- Native HTTPS authentication, CSRF rejection with unchanged config.xml, authenticated settings save, monitor startup and authenticated diagnostics export pass. TLS uses the real certificate, matching hostname and explicit CA trust; no verification bypass. Eight native restricted-user authentication/privilege checks now pass after adding the runtime privilege definition; visual browser rendering remains untested.
- Existing platform evidence includes real isolated FreeBSD reboot persistence, 23 full/read-only filesystem checks, nine SMTP encryption/authentication cases and three anonymous SMTP cases. These are supporting evidence, not pfSense native cleanup or notification configuration integration.

The complete RuntimeSupervisor also passes a real native PHP fault/repair with actual link, configured peer replies, log worker and real clocks; only the harness settings/private ledger differ from the installed monitor. A separate carrier-loss probe returned unknown for native ping send errors, so this does not close the full combined-failure reboot gate.

The policy, atomic durable budgets, bounded collectors, diagnostic retention, repair controller, native upgrade lease, one-use reboot handoff and default-off notifications are implemented. See [PLATFORM.md](PLATFORM.md), [RUNTIME.md](RUNTIME.md) and the chronological [validation record](VALIDATION.md).

## Required before release

1. Finish the full autonomous native runtime/fault sequence, real failed-peer reconfirmation and upgrade collisions. Real PHP-FPM fault/repair with a two-minute confirmation interval and a synthetic-fault native cleanup reboot harness now pass, including post-boot budget preservation. Keep the monitor-only gate until the supported active path is established.
2. Complete native notification configuration/worker TLS and authentication integration, native diagnostic retention and visual UI review. Validate HA/CARP inhibition and total runtime I/O, including metadata and notification/evidence writes. IPv6 peer probes are not implemented.
3. Complete resource-exhaustion, concurrent-supervisor and supported-version fault/lifecycle coverage. Private tmpfs failures do not establish physical I/O hang or power-loss durability. Production remains limited to passive probes and pure tests.
4. Test the latest pfSense development target as Netgate requires, as well as isolated CE 2.8.1 compatibility. Rebuild for each actual ABI/PHP dependency set.
5. Review the final source and support artifacts for unsafe defaults, secrets, private topology and personal data. Publish only this isolated repository.
6. Submit the complete port to pfsense/FreeBSD-ports, address maintainer review and verify actual official package availability. Acceptance is Netgate's decision.

## Target versions and external constraints

| Target | Verified evidence | Remaining |
| --- | --- | --- |
| pfSense CE 2.9.0-RELEASE / FreeBSD 16 / PHP 8.5.7 | Official isolated install, component/process tests, native port build, real package lifecycle and authenticated HTTP GUI checks | Full autonomous recovery sequence and native release matrix |
| pfSense development master | Remote HEAD rechecked: 9363ac5b8651a1c7a333180425ce7719070f95f9; version file says 2.9.0-DEVELOPMENT | Actual development-channel installation and runtime coverage; stable 2.9 is not relabeled as development |
| pfSense CE 2.8.1 / PHP 8.3.19 | Passive native probes and pure tests on existing appliance | Isolated lifecycle/failure matrix |
| FreeBSD 15.0-p13 / PHP 8.3.33 | Platform/process/TLS/storage/reboot and port-build evidence | Supporting lab only |
| Windows PHP 8.5.10 | 411 component and 69 syntax checks | Platform inputs and directory fsync are fixtures |

The ports devel HEAD remains a621624266b19a7f48b1f94a60821d2c2fc6ee4c. The official development guide names a FreeBSD 16.0-CURRENT builder. The native 2.9 build uses PHP 8.5 explicitly; generic ports defaults must not override pfSense dependencies.

The GitHub OAuth credential lacks workflow scope. The prepared ci/github-actions-tests.yml remains an inactive template; no hosted CI run is claimed. Direct native test execution and source publication do not depend on this permission. Private order, billing, credentials and laboratory routes remain outside this repository.

Sources: [Netgate package development](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html), [port layout](https://docs.netgate.com/pfsense/en/latest/development/package-directories.html), [installation media](https://docs.netgate.com/pfsense/en/latest/install/download-installer-image.html).
