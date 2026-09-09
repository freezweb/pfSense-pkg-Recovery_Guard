# Validation record

Date: 2026-09-09. Initial implementation evidence below refers to commit `f83dba4f576298ca0fccda09ce2d73e09ef2840d`; subsequent changes have separate entries below.

| Environment | Result | Scope |
| --- | --- | --- |
| Windows, PHP CLI 8.3.32 | 68 policy/integration checks passed | Directory synchronization injected; other journal operations real; fake action executors |
| Windows, PHP CLI 8.3.32 | 10 FastCGI checks passed | Loopback test servers; valid, malformed, oversized, truncated and delayed responses |
| pfSense CE 2.8.1, PHP CLI 8.3.19 | 68 policy/integration checks passed | Native file and directory fsync; fake action executors |
| pfSense CE 2.8.1, PHP CLI 8.3.19 | 10 FastCGI checks passed | Same loopback adversarial transport fixtures |
| pfSense CE 2.8.1, live PHP-FPM Unix socket | Challenge matched, exit 0 | Passive real service transaction with script outside the web document root |

The integration total includes 38 policy checks. It is not 68 plus 38. All PHP source, test and example files also passed syntax checks.

This initial evidence does **not** demonstrate actual service repair, real reboot persistence, package lifecycle, current pfSense development-version compatibility, hardware-watchdog operation or official repository acceptance. Later platform reboot evidence is recorded below; the remaining requirements are tracked in [ACCEPTANCE.md](ACCEPTANCE.md).

No production service was restarted and no active recovery supervisor was installed during this validation. No hosted GitHub Actions run has been performed; its workflow currently exists only as a template because the publishing credential cannot write active workflows.

## Volatile observations and durable budgets

The expanded integration suite passes 76 total checks on Windows PHP 8.3.32 and pfSense CE 2.8.1 / PHP 8.3.19. This includes the original 38 policy checks and eight new checks for write frequency, process restart, cached-state corruption detection and competing observers. The Windows directory sync remains simulated; pfSense executes native file and directory fsync.

The counted directory-sync adapter observes one mode commit across 100 healthy samples, no further writes for unconfirmed faults, and exactly two further durable commits for a repair reservation and its receipt. This measures journal commits, not every physical write made by the OS. The writer lock is also no longer chmod'ed on every tick when its permissions are already correct.

Every tick still reads the durable journal under the action lock. A different on-disk state invalidates the in-memory episode; new processes never replay historical escalation progress. Existing reservation-before-execution, ambiguous-commit and maintenance-race checks continue to pass. All action executors in these tests remain fakes.

The unchanged local FastCGI suite (10 checks) and network suite (43 Windows checks) also pass.

## Native process and real reboot evidence

The isolated FreeBSD 15.0-RELEASE-p13 guest, with PHP 8.3.33 from the official package repository, passes 76 integration, 10 FastCGI and 42 network-contract checks. The tested implementation is `c6da7905b7f20d9b56f5c43ca6e081f10bde69cd`. Eight expanded process fixtures also pass: literal argument handling, stderr/nonzero exit, bounded output, direct timeout, an orphan grandchild, ignored TERM, detached descendant and killed timeout wrapper. The interrupted wrapper correctly returns `cleanup_unknown`; fixtures terminate without leaving permanent background processes.

`tests/reboot-lab.php prepare` creates actual durable reservations using a synthetic historical fault timeline and fake action receipts. An operator-issued real guest reboot then occurs separately. `verify` confirms a changed native boot identity, byte-for-byte journal preservation by SHA256, budget retention through a fresh coordinator and inhibition of a second reboot in a synthetic subsequent severe-failure timeline. Verification does not rewrite the stored evidence.

This proves the platform journal survives a real FreeBSD guest reboot. It does not prove an automatic pfSense reboot executor, actual service repair, the package lifecycle or supported pfSense versions. See [lab reproduction and remaining coverage](LAB.md).

## Native configuration and first development package

On 2026-09-09, Windows PHP 8.3.32 and isolated FreeBSD 15.0-p13 / PHP 8.3.33 pass 46 configuration/local-route checks and nine staged adapter/manifest checks. The latter use a native config API test double; no real firewall configuration is written. The FreeBSD run also passes 76 integration, 42 network and 10 FastCGI checks. Syntax checks cover PHP libraries, tests, tools, page and include files; both package XML files parse successfully. Synthetic routing tests include a route change during a failed direct ping.

The complete preview port builds against `pfsense/FreeBSD-ports` devel framework commit `a621624266b19a7f48b1f94a60821d2c2fc6ee4c` in that guest. `make stage`, `check-plist`, `stage-qa` and `package` pass. The produced `pfSense-pkg-Recovery_Guard-0.1.0.a1.pkg` has SHA256 `956d597243ad6b1e4856598e38a456b78f152f49b7d209f61bc2a20861784499` and reports ABI `FreeBSD:15:amd64`. Native shell scripts pass `sh -n`; the GUI, include and challenge files have mode 0644; the metadata version placeholder is substituted. The generated manifest covers 13 application files plus the port framework's license metadata.

An initial build exposed CRLF line endings in a Windows-generated framework archive. The framework was re-exported with Git `core.autocrlf=false`; the project now sets LF attributes and normalizes text during port staging, with a regression check. The final lab source archive SHA256 is `be3ff3de266fbf12c9ed9ef2755b225c4cf74608d6bfd0e1f0652d838970c466` (source/test/template/tool directories and license).

The binary remains a local laboratory artifact. It has not been installed, published as a release, submitted to Netgate or made available in an official repository. Native GUI rendering, authentication/CSRF, config persistence, package registration and install/upgrade/deinstall require an actual pfSense guest. The preview cannot start monitoring or execute recovery actions.

## Joined runtime and bounded log collection

On 2026-09-09, `tests/runtime.php` passes 27 checks on Windows PHP 8.3.32 and 31 on isolated FreeBSD 15.0-p13 / PHP 8.3.33. The four additional FreeBSD cases exercise descriptor retention across real file rotation and symlink rejection. Other cases join configuration, actual network-output parsers, peer baseline and the real journal with synthetic platform data and fake actions: sustained combined failure produces exactly one repair and one reboot; disabled/unknown/HA/maintenance/update/repair/shutdown interlocks stop collection; topology changes during probes or evidence capture inhibit action; deadlines and clock changes erase volatile confirmation. Log tests cover old records, exact thresholds, bounded lower bounds, partial coverage, truncation and unrelated/embedded messages.

The same guest passes 76 integration, 46 configuration, 42 network and nine staged native-adapter/manifest checks. The source archive SHA256 is `9ae0fe9ba3a4939b6e7e68d754a08c621716083e19b7b8a2a0a9bdbb92061221`. Preview revision `0.1.0.a1_1` builds against the same pfSense devel framework: `stage`, `check-plist`, `stage-qa` and `package` pass, with 15 application files. Package SHA256: `8aff888fc9f7926302ab0ab56f0fcfeb63f45287ae5d304125ab70dfdb7e4faa`.

This is runtime-cycle and file-reader evidence, not an installed daemon or real pfSense repair. Native snapshot/interlock adapters, daemon lifecycle, actual executors and notifications remain incomplete. See [runtime boundaries](RUNTIME.md).
