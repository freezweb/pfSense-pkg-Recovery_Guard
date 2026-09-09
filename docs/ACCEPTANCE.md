# Acceptance evidence and remaining work

The goal is availability in the official pfSense package repository, not merely a submitted pull request. It remains open.

## Verified implementation

- Independent, side-effect-free recovery policy; sustained failure confirmation, reboot and repair budgets, boot grace, tri-state probes, maintenance and upgrade suppression.
- Atomic journal with a separate writer lock, bounded JSON, restrictive permissions, file fsync, rename and parent-directory fsync. An initialization sentinel prevents a missing journal from being silently replaced with an empty action budget.
- Action coordination: reservation before execution, evidence capture before execution, fresh interlocks checked again after capture, explicit executor receipts, conservative treatment of ambiguous commits and action completion.
- Monitor, service-repair and recovery modes. Mode changes reconfirm faults while retaining conservative reservations. Mode-inhibited proposals can temporarily consume their cooldown; they are never reported as executed actions.
- Local FastCGI challenge through the PHP-FPM Unix socket. The challenge script resides outside the web document root. No administrator credentials, nginx dependency or public HTTP route are required.
- Local PHP 8.3 tests and pfSense CE 2.8.1 / PHP 8.3.19 execution with native directory fsync. Real PHP-FPM challenge succeeds; all restart executors used in integration tests are fakes.

## Required before release

1. Implement bounded platform command execution, interface/local-endpoint collectors, known-healthy baselines and bounded error-rate sampling.
2. Implement the native daemon, real service/reboot adapters, evidence rotation and durable notification queue. Avoid per-sample flash writes by separating volatile observations from persistently reserved action budgets without weakening crash safety.
3. Integrate native config.xml storage, GUI, service registration, package install/upgrade/deinstall and explicit state initialization. Preserve budgets through all normal lifecycle changes. Validate HA/CARP behavior; current coordinator suppresses all active actions when HA is configured.
4. Set up an isolated VM lab. Test command descendants, timeouts, filesystem failures, concurrent supervisors, reboot persistence, service recovery, package lifecycle and supported versions. A production appliance is only used for passive probes and pure tests.
5. Test the current pfSense development version, as required by Netgate, in addition to CE 2.8.1 compatibility. Build a staged FreeBSD port and verify package manifests, permissions and dependency resolution.
6. Review source and generated support artifacts for secrets, private topology, personal data and unsafe defaults. Publish only this isolated repository, never its parent workspace.
7. Publish the generic source, submit the complete port to `pfsense/FreeBSD-ports`, address maintainer review and verify actual official package availability.

## External constraints

Netgate decides whether to accept a package. No PR has been submitted yet. The Netgate installer is distributed through the free store checkout and requires an account; installation media and current development-channel access still need to be established for the lab. Existing infrastructure access has been verified read-only; no production guest has been stopped or modified.

The current GitHub OAuth credential lacks the workflow scope. Therefore the prepared GitHub Actions definition is tracked as `ci/github-actions-tests.yml`, not installed as an active workflow. Source publication and direct test execution do not depend on this permission. Do not claim hosted CI has run until a real workflow run has been verified.

Sources: [Netgate package development](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html), [port layout](https://docs.netgate.com/pfsense/en/latest/development/package-directories.html), [installation media](https://docs.netgate.com/pfsense/en/latest/install/download-installer-image.html).
