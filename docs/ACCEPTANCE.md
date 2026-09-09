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
- Isolated FreeBSD 15.0-p13 / PHP 8.3.33 VM: integration, transport and network suites pass; eight native process cases pass, including detached/TERM-resistant descendants and interrupted timeout-wrapper handling. A real guest reboot changed boot identity while preserving the exact journal hash and durable reboot budget. Fresh-supervisor and synthetic post-reboot policy checks passed. This is platform-library evidence, not actual pfSense service recovery or package acceptance.

## Required before release

Native configuration development now includes a validated compiler, package-scoped save adapter, preview page, XML metadata, registration scripts and a complete port staging tool. The preview supplies an independent monitor daemon and native service hooks; repair and recovery remain disabled. Its rc lifecycle, exclusive lease, persistent-budget initialization and bounded log-worker protocol pass 24 isolated FreeBSD checks with a fixture entrypoint. The dependency-bearing port passes `stage`, `check-plist`, `stage-qa` and `package`. This is not installation/GUI/lifecycle validation on pfSense. See [port scope](PORT.md) and [build evidence](VALIDATION.md).

1. Complete platform diagnostics: extend native process checks to supported pfSense guests and wire the joined RuntimeSupervisor/configuration/collector/baseline path to verified native snapshot and interlock adapters. Bounded socket-retry log sampling is implemented and tested, but alternate log formats and native integration remain. IPv6 endpoint probes are not implemented.
2. Implement real service/reboot adapters, evidence rotation and durable notification queue. Validate the monitor daemon with actual pfSense inputs and verify total runtime I/O, including filesystem metadata and eventual notification/evidence writes.
3. Validate native config.xml storage and GUI on pfSense, including the implemented service registration, install/upgrade/deinstall hooks and explicit first initialization. Preserve budgets through all normal lifecycle changes. Validate HA/CARP behavior; current coordinator suppresses all active actions when HA is configured.
4. Extend the established isolated FreeBSD VM lab to actual pfSense guests. Complete storage-failure, concurrent-supervisor, service-recovery, package-lifecycle and supported-version tests. A production appliance is only used for passive probes and pure tests.
5. Test the current pfSense development version, as required by Netgate, in addition to CE 2.8.1 compatibility. Repeat the successful preview port build for the complete package in supported pfSense build environments, including runtime dependencies and permissions.
6. Review source and generated support artifacts for secrets, private topology, personal data and unsafe defaults. Publish only this isolated repository, never its parent workspace.
7. Publish the generic source, submit the complete port to `pfsense/FreeBSD-ports`, address maintainer review and verify actual official package availability.

## External constraints

Netgate decides whether to accept a package. No PR has been submitted yet. The existing account and a fulfilled installer order are now accessible in the in-app browser. The order's download is blocked by the browser and returned HTTP 404 on a direct request; the user has been asked to supply the downloaded file's local path. Installation media and current development-channel access remain to be established. The dedicated FreeBSD VM has completed its official first-boot updates, native process checks and a real reboot-persistence test. No production guest has been stopped or reconfigured. See [lab tracking](LAB.md).

The current GitHub OAuth credential lacks the workflow scope. Therefore the prepared GitHub Actions definition is tracked as `ci/github-actions-tests.yml`, not installed as an active workflow. Source publication and direct test execution do not depend on this permission. Do not claim hosted CI has run until a real workflow run has been verified.

Sources: [Netgate package development](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html), [port layout](https://docs.netgate.com/pfsense/en/latest/development/package-directories.html), [installation media](https://docs.netgate.com/pfsense/en/latest/install/download-installer-image.html).
