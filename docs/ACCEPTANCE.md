# Acceptance evidence and remaining work

The goal is availability in the official pfSense package repository, not merely a submitted pull request. It remains open.

## Verified implementation

- Independent, side-effect-free recovery policy; sustained failure confirmation, reboot and repair budgets, boot grace, tri-state probes, maintenance and upgrade suppression.
- Atomic journal with a separate writer lock, bounded JSON, restrictive permissions, file fsync, rename and parent-directory fsync. An initialization sentinel prevents a missing journal from being silently replaced with an empty action budget.
- Action coordination: reservation before execution, evidence capture before execution, fresh interlocks checked again after capture, explicit executor receipts, conservative treatment of ambiguous commits and action completion.
- Monitor, service-repair and recovery modes. Mode changes reconfirm faults while retaining conservative reservations. Mode-inhibited proposals can temporarily consume their cooldown; they are never reported as executed actions.
- Local FastCGI challenge through the PHP-FPM Unix socket. The challenge script resides outside the web document root. No administrator credentials, nginx dependency or public HTTP route are required.
- Local PHP 8.3 tests and pfSense CE 2.8.1 / PHP 8.3.19 execution with native directory fsync. Real PHP-FPM challenge succeeds; all restart executors used in integration tests are fakes.
- Network collector and volatile peer-baseline tests pass on Windows and pfSense CE 2.8.1. Native bounded-command adapter successfully reads FreeBSD OS type and an explicitly selected active physical interface. These passive successes do not validate timeout or descendant cleanup.
- Volatile coordinator observations avoid per-sample journal commits. Mode transitions, reservations and receipts remain durable; every sample re-reads and validates the journal under its lock. Restart, competing writes and transaction errors discard cached confirmation while preserving budgets. Expanded 76-check integration suite passes on Windows and pfSense CE 2.8.1 with native fsync on the latter.

## Required before release

1. Complete and validate platform diagnostics: exercise the implemented process deadline/output limits in an isolated FreeBSD lab, integrate interface/endpoint collectors and peer baselines, validate local routes against native configuration, and implement bounded error-rate sampling. IPv6 endpoint probes are not implemented.
2. Implement the native daemon, real service/reboot adapters, evidence rotation and durable notification queue. Integrate the implemented volatile-observation/durable-budget separation and verify total runtime I/O, including filesystem metadata and eventual notification/evidence writes.
3. Integrate native config.xml storage, GUI, service registration, package install/upgrade/deinstall and explicit state initialization. Preserve budgets through all normal lifecycle changes. Validate HA/CARP behavior; current coordinator suppresses all active actions when HA is configured.
4. Set up an isolated VM lab. Test command descendants, timeouts, filesystem failures, concurrent supervisors, reboot persistence, service recovery, package lifecycle and supported versions. A production appliance is only used for passive probes and pure tests.
5. Test the current pfSense development version, as required by Netgate, in addition to CE 2.8.1 compatibility. Build a staged FreeBSD port and verify package manifests, permissions and dependency resolution.
6. Review source and generated support artifacts for secrets, private topology, personal data and unsafe defaults. Publish only this isolated repository, never its parent workspace.
7. Publish the generic source, submit the complete port to `pfsense/FreeBSD-ports`, address maintainer review and verify actual official package availability.

## External constraints

Netgate decides whether to accept a package. No PR has been submitted yet. The Netgate installer is distributed through the free store checkout and requires an account. An existing account has been located, but the regular browser login is waiting for a blocking extension UI to be closed; installation media and current development-channel access still need to be established. A dedicated FreeBSD VM has been created from the official checksum-verified image and is completing its first-boot updates. No production guest has been stopped or modified. See [lab tracking](LAB.md).

The current GitHub OAuth credential lacks the workflow scope. Therefore the prepared GitHub Actions definition is tracked as `ci/github-actions-tests.yml`, not installed as an active workflow. Source publication and direct test execution do not depend on this permission. Do not claim hosted CI has run until a real workflow run has been verified.

Sources: [Netgate package development](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html), [port layout](https://docs.netgate.com/pfsense/en/latest/development/package-directories.html), [installation media](https://docs.netgate.com/pfsense/en/latest/install/download-installer-image.html).
