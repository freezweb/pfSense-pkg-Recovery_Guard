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

## Native snapshot and original XML parser

On 2026-09-09, inspection of the original pfSense XML parser found that `config` is a globally reserved list element. The earlier configuration adapter's associative object could not survive native serialization. A regression test first failed against the previous staged preview. Changing the package branch to `installedpackages/recoveryguard/settings` fixes that round trip. Three original-parser checks now pass both on Windows with pfSense master parser revision `9363ac5b8651a1c7a333180425ce7719070f95f9` and on pfSense CE `2.8.1-RELEASE` / PHP `8.3.19` using its installed parser (SHA256 `a3a3585454803ace95926795eb6084d07e474ef56fc13e6960cc0a53eee3116f`). These tests serialize only a temporary fixture; the live firewall config is not written.

The new bounded native snapshot worker executes successfully on the same pfSense appliance in 62 ms. It reports the configured interface set, disabled package state and clear observed lifecycle interlocks. Hashes and modification times of both config.xml and config.cache remain identical before/after the helper. The initial passive run also exposed PID 0 in FreeBSD's process list; the parser now accepts this normal kernel record and the synthetic fixture includes it. This passive result does not prove interlock exclusion during a real upgrade, repair, HA transition or shutdown.

Windows and isolated FreeBSD 15.0-p13 pass 23 projection/interlock checks, including removal of credential fields, native process/marker gates, HA and malformed observations. The isolated guest additionally passes 31 joined-runtime, 76 integration and nine staged config-adapter/manifest checks. Source archive SHA256: `92e1f87bc03829828ba6dee2494d94f76f9c8125a9242307b5aab6e56aba9531`.

The 17-file preview revision `0.1.0.a1_2` passes `stage`, `check-plist`, `stage-qa` and `package` against the same official ports framework. Package SHA256: `b162f1355e5f1099e351e6f958f76d1bc4abfc97facdbd4b485aaf28a67e669f`. It remains a laboratory build; no active daemon or real action executor is installed by this preview.

## Monitor service and dependency-bearing port

On 2026-09-09, isolated FreeBSD 15.0-p13 / PHP 8.3.33 passes 24 new service/worker checks. These execute the shipped rc script with only its PID path and daemon entrypoint replaced by private fixtures, and exercise the real ServiceLoop, ServiceState and LogWorker implementations. Disabled start, start/status, duplicate start, direct duplicate lease exclusion, graceful stop, restart, PID cleanup and unchanged action-budget bytes pass. Missing state fails closed. Log-worker cases include mismatched nonce, timeout, stderr and oversized output, followed by successful worker replacement; the timed-out fixture process is confirmed gone.

This is an actual FreeBSD rc/daemon/process test, not a native pfSense entrypoint or package-install test. The fixture does not execute any firewall repair or reboot. Evidence directory in the isolated guest: `/root/recovery-guard-service-e3bcf7856344`. The same final source passes 76 integration, 31 runtime, 23 snapshot, 46 configuration and ten staged config/manifest checks. Four original-pfSense-parser XML checks pass on Windows, now including enabled monitor persistence. PHP syntax checks pass throughout source, tests, tools and native PHP templates.

The 23-file preview `0.1.0.a1_3` passes `stage`, `check-plist`, `stage-qa` and `package` against the previously recorded pfSense ports framework. Its package manifest declares PHP CLI and filter, pcntl, posix and XML extensions, all at 8.3.33 in this lab. The rc script has executable mode 0555 and passes native `sh -n`. Package SHA256: `aa41f1efc0682b597300bbf78a2c160f485b7ab843a006b22e5d8c98f6a22f7b`. Source archive SHA256: `488927a5a4b25a4d3ed3eeedd96baae835c53a0a5030bad0a1f63e8b1630c948`.

The package now contains a monitor-only native daemon, first-install journal initialization and service hooks. Actual pfSense GUI, configuration apply, install/upgrade/deinstall and native daemon operation remain unverified. Real repair/reboot executors and durable evidence/notifications are not implemented. No production setting or service changed during this phase.

## Repair controller and real private PHP-FPM

On 2026-09-09, both production and lab variants of the C repair controller compile with `-std=c11 -Wall -Wextra -Werror -O2` on isolated FreeBSD 15.0-p13. Sixteen joined tests pass: daemon survival after controller success, cleanup after controller failure, timeout and TERM, cleanup after the PHP owner dies, absent receipt after controller SIGKILL, real PHP RepairProcess/RepairExecutor receipt mapping, rejection of unknown/cancelled outcomes, and an actual private PHP-FPM start followed by two successful nonce transactions. The private PHP-FPM instance uses only a Unix socket and is stopped by test cleanup. Evidence directory: `/root/recovery-guard-repair-212b31b4c5e1`.

The 26-file development port `0.1.0.a1_4` passes native compile, `stage`, `check-plist`, `stage-qa` and `package`. The installed helper is a stripped FreeBSD ELF executable with mode 0555, not setuid. Its production command parser rejects a non-allowlisted argument without invoking repair. Package SHA256: `d43a440684cd57852c81b57222df40183a46e6ff87fa40d13d992635a18efb7e`. Ten staged native-adapter/manifest checks pass locally and in the guest.

This demonstrates real service-daemon preservation and functional probing on plain FreeBSD. It does not establish the entire pfSense restart routine, upgrade exclusion, native reboot handoff or supported pfSense package lifecycle. Automatic repair/reboot remains disabled in the shipped daemon and editor. No production service was changed.

## Durable diagnostic journal and monitor integration

On 2026-09-09, fifteen new diagnostic/coordinator checks pass on Windows PHP 8.3.32 (directory fsync injected) and isolated FreeBSD 15.0-p13 / PHP 8.3.33 (native fsync). The cases cover field whitelisting, duplicate/conflicting evidence, immutable outcomes, bounded 64-record retention, schema corruption, fresh-reader persistence, monitor-only records, ordering before fake execution, ambiguous capture and outcome commits, and unknown executor results. The unchanged 76 integration checks and 27 Windows/31 FreeBSD runtime checks also pass.

The updated native service suite passes 24 cases after initialization was extended to validate/provision the separate evidence journal; retained fixture path `/root/recovery-guard-service-c4b1c30dcf25`. Ten native-adapter/manifest and four original-XML-parser checks pass locally. PHP syntax checks pass throughout the source and native templates. The UI history and authenticated export have been implemented but not browser-validated on pfSense.

The 27-file port `0.1.0.a1_5` passes native compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `9e14d011ebb422a7e55dac530b5d2e9e1a8c151eb68867a0db423d858cf56236`. Source archive SHA256: `261d38bd3ff52f448f590f3d3e08da2c86ff904e30691ba2ad7c80a0a7becca9`.

The Netgate order page remains accessible, but the ordinary download still produced no local installer file in this phase. Real pfSense installation/upgrade/deinstallation, GUI authorization, automatic repair and reboot handoff remain outstanding. No production setting or service changed.

## Durable reboot handoff and automatic isolated guest reboot

On 2026-09-09, 25 handoff checks pass in isolated FreeBSD 15.0-p13 / PHP 8.3.33. They exercise the actual coordinator, journal locks, evidence, dispatcher receipt and supervisor lease with fake reboot callbacks. Coverage includes one-use token/claim behavior, all lifecycle gates, disabled/changed mode, changed boot/configuration, missing evidence, changed reservation, wall and monotonic expiry/reversal, ambiguous claim persistence, maintenance changing after claim, two competing forked workers and SIGKILL after claim. Retained evidence: `/root/recovery-guard-handoff-c858fabbe7e3`.

Two real isolated guest reboot trials also pass. The final trial uses the implementation with both wall-clock and monotonic deadlines. The fixture supervisor persists real reservations/evidence, launches an independent worker and releases the shared lease. The worker claims the intent and invokes the laboratory's FreeBSD shutdown action. After reconnection, verification establishes a different native boot identity, byte-identical budget and claimed-intent hashes, and refusal to replay the consumed token. Final evidence: `/root/recovery-guard-handoff-reboot-20260909b`. The first SSH observation during each reboot timed out; later verification succeeded without an additional host reset or re-dispatch.

The final source passes 76 integration, 15 diagnostic, 31 runtime, 24 service-lifecycle and ten staged native-adapter checks in the same guest. PHP syntax checks pass locally. Source archive SHA256: `fff0f9507f6436d6fba191b08116bd91d3ee1ec5c96f2fce7ee2fad16a304b63`. The 30-file `0.1.0.a1_6` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`; package SHA256: `3bf37d1770972155e7502905b4313be50d21f940252c2f22011089d16f556537`.

The reboot trials use synthetic failure/lifecycle inputs and FreeBSD shutdown, not pfSense's full native cleanup. The native worker currently rechecks configuration and lifecycle gates; fresh functional fault reconfirmation immediately before reboot, native maintenance collision testing, active-mode integration, reboot-result reconciliation and notifications remain required before release. The shipped monitor still rejects recovery mode. No production service or network setting changed, and no official package submission occurred.

## Fresh functional reboot verification

On 2026-09-09, sixteen RebootVerifier checks pass on Windows PHP 8.3.32 and isolated FreeBSD 15.0-p13 / PHP 8.3.33. They use the actual configuration compiler, NetworkProbe parsers and local LogRateProbe file reader with synthetic command results. Coverage includes exact peer selection/direct routing, recovered or unknown PHP/LAN, unknown or changing link, a freshly observed storm versus historical messages, late PHP recovery, changed configuration/interlocks, deadlines and clock changes. No endpoint traffic is emitted by this suite.

The expanded FreeBSD handoff suite passes 33 checks, including recovered/unknown functional state before claim, PHP or LAN recovering after claim, and fresh log-storm corroboration with an active link. Evidence directory: `/root/recovery-guard-handoff-194a26db250f`. The same guest passes 31 runtime and ten staged native-adapter checks. Fifteen diagnostic and 76 integration checks pass locally. These changes add final verification without changing the previously tested one-use reboot-claim mechanism; no additional real reboot was required in this phase.

The 31-file `0.1.0.a1_7` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `3a5577dc20bcf3ab10d7b567305e9d4642758812e310ba4369c37aea0d883c3b`. Source archive SHA256: `3c58caf135caab13490ffb5b7d5d46f6fe8273a780aa2950d6911d1f70f6164a`.

The native worker is wired to this verifier, but the monitor and UI still reject automatic recovery mode. Actual pfSense cleanup/upgrade collisions, active-mode integration, notifications, outcome reconciliation and supported-version installation tests remain release requirements. No production service or configuration changed.

## Durable notification components

On 2026-09-09, seventy notification checks pass on Windows PHP 8.3 and isolated FreeBSD 15.0-p13 / PHP 8.3.33. They exercise real file persistence, restart/new-reader behavior, stale and expired receipts, clock reversal, lost workers, bounded attempts, overflow retention, ambiguous directory synchronization, sanitized messages, native SMTP parameter projection, password rotation and changed-recipient/TLS inhibition. Senders are injected fixtures; no real email is sent. Windows injects directory fsync, while FreeBSD uses native synchronization. The native SMTP adapter's default PEAR transport and total-worker deadline still need private-server integration tests.

The 35-file `0.1.0.a1_8` port passes native compile, `stage`, `check-plist`, `stage-qa` and `package`, plus ten staged native-adapter/manifest checks. Package SHA256: `54404bf98624872d75af27545a3ba62e4aabfb3f9632f4cade16b233e435a5c3`. Source archive SHA256: `acaa5d25767301beef2988ba8954216cc4b40fac421fc749fc654c2b0a8cf568`. Guest source directory: `/root/recovery-guard-notifications`.

The queue and SMTP adapter are shipped development components, not an enabled notification feature. Separate worker supervision, native opt-in, event production and delivery-status visibility remain required. No production configuration or service changed. No official submission or acceptance occurred.

## Notification service integration

On 2026-09-09, the monitor gains a default-off notification opt-in, a separately initialized outbox, a persistent diagnostic checkpoint, a finite asynchronous sender and native status counts. Eighty-two component checks pass on Windows and FreeBSD, including diagnostic-journal-to-delivery integration, baseline suppression, restart/destination changes, overflow recovery and native opt-in projection. Thirteen native adapter/manifest checks and five round trips through the original pfSense XML parser validate saving and disabling the new flag without sending mail.

In isolated FreeBSD 15.0-p13, ten new worker checks verify nonblocking scheduling, a TERM-resistant child deadline, malformed output, service stop and timeout survival after a real SIGKILL of the supervisor. Evidence: `/root/recovery-guard-notification-worker-2088c20d95cc`. The expanded 27-check service suite validates outbox initialization/preservation and confirms that a corrupt mail queue is retained without rejecting the monitoring budget; evidence `/root/recovery-guard-service-01ea56aea231`. The final source also passes 31 runtime, 46 configuration and 23 snapshot checks. Local syntax checks cover 61 PHP/include files.

Three SMTP checks execute the adapter's actual PEAR transport against an ephemeral listener bound only to 127.0.0.1: accepted DATA, rejected recipient, and transmitted Message-ID/body preservation. Evidence: `/root/recovery-guard-smtp-4c8e37fc844d`. The ignored lab fixture pins public upstream sources: Mail `f2c5cc6a3afe4c1b11953bbf988d53c5bb7699ed`, Net_SMTP `09b699ea1c209698ee560bd3a4d8a72e9ef39323`, Net_Socket `08d28f074e13438cff9d14892b30bf900810229d`, pear-core-minimal `c7b55789d01de0ce090d289b73f1bbd6a2f113b1`. No external message was delivered and no library was installed in a production environment. These plain SMTP tests do not establish authenticated/TLS delivery.

The 39-file `0.1.0.a1_9` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `873e57f406d2dbead7d26ccd002ddfb336abc52dfd729c3a17154bec28e03c60`. Source archive SHA256: `273af92962bfd12d70d6a9cc409c96e603855da35f227022c53f98a09b3a86ec`. Guest source: `/root/recovery-guard-notification-integrated`.

Full pfSense GUI/worker execution, TLS/authentication and native package lifecycle remain required before release. Active repair/reboot remains disabled. No production service/configuration changed and no official package submission occurred.

## Reboot outcome reconciliation

On 2026-09-09, 25 reconciliation checks pass on Windows PHP 8.3 and isolated FreeBSD 15.0-p13 / PHP 8.3.33. They cover matching durable reservations/claims, changed native boot identity, monotonic reset, lower uptime, inconsistent clocks, unclaimed/missing/mismatched intent, legacy diagnostic history, ambiguous commits, idempotence and exactly one new notification for the retained observation. These component checks use synthetic boot inputs and no real reboot. Fifteen diagnostic and 82 notification checks also pass on both platforms; 33 existing native handoff checks pass with fake reboot routines.

A separate real isolated guest reboot then passes the extended handoff verification in `/root/recovery-guard-handoff-reboot-20260909c`. The worker initiates FreeBSD shutdown, SSH reconnects after boot, and the verifier records `boot_observed` from actual native boot identity, wall time, uptime and monotonic time. The action budget and consumed intent remain byte-identical to their pre-reboot hashes. A second verification preserves the first diagnostic observation without rewriting it, and replay of the consumed request is inhibited. The fault samples and repair executor used to obtain the reservation are synthetic; this does not validate pfSense's native cleanup sequence or prove service recovery.

The final source also passes 76 policy/integration, 31 runtime, 27 native service and 13 staged native-adapter/manifest checks. Service evidence: `/root/recovery-guard-service-3ee8a8a97147`. The 39-file `0.1.0.a1_10` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `d9265704d9f2f76bafe3ae8b0920f005ed5eb01342b6b08eb6cc1e7c07345371`. Source archive SHA256: `768e689cde3a6ecb993a4e0cbfa526db4813ab3d734cdd7d7c43a48e319765a8`. Guest source: `/root/recovery-guard-reconciliation`.

Only the isolated FreeBSD laboratory guest rebooted. Production settings and services were unchanged. Native pfSense startup/GUI, active-mode integration, supported-version testing and official submission/acceptance remain outstanding. The Downloads directory still contains no Netgate/pfSense installer media.

## Native upgrade coordination and action adapter integration

On 2026-09-09, `NativeUpgradeLease` passes sixteen checks in isolated FreeBSD 15.0-p13 using the original pfSense-upgrade wrapper from the pinned ports source. The fixture substitutes only a private lock path and harmless payload, with an additional barrier for the unlink-epilogue case. Tests establish mutual exclusion between the real wrapper and PHP lease, safe reacquisition after native unlink, rejection of replaced/unsafe lock inodes, visibility of the real wrapper epilogue through NativeSnapshot's process parser, and close-on-exec behavior. Evidence: `/root/recovery-guard-upgrade-lease-7a954e7a86d8`. No real upgrade runs.

Twenty-eight native executor/coordinator checks pass on Windows and FreeBSD. They cover fresh boot/configuration/mode/health checks, all lifecycle gates, late lease loss, changing clocks and deadlines, uncertain execution, reboot dispatch, and an inhibited repair retaining its reservation without acknowledgement or reboot escalation. The expanded seventeen-case FreeBSD repair suite runs a real private PHP-FPM instance through `NativeRecoveryExecutor`, the real upgrade lease, native C repair controller and health receipt; the resulting daemon remains healthy and a second request leaves it running. Evidence: `/root/recovery-guard-repair-ee4d60781f32`. Configuration inputs and the repair command are laboratory fixtures, not pfSense's full native restart script.

The daemon now constructs the native adapters, passes the original sample to the executor, and closes workers/retires on a handoff receipt. Its monitor-only release gate remains in force, so this wiring cannot arm automatic actions through configuration. The reboot worker also acquires the native upgrade lease and checks inode identity around its claim. The lease's limits, including direct administrator commands and `/tmp` removal during native shutdown, are documented in PLATFORM.md and remain part of the native release audit.

The final guest source passes 76 policy/integration, 31 runtime, 25 reconciliation, 33 handoff, 27 service and 13 staged native-adapter/manifest checks. Local syntax validation covers 66 PHP/include files. The 41-file `0.1.0.a1_11` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `e04981b9da268a24658279a251a2f998087f4ebf107f1cfa701adfefb7a7a321`. Source archive SHA256: `51f8a49ef082b2501bd6ce384a67b8996150ce3dd8d90e6c4aff46d91006ea33`. Guest source: `/root/recovery-guard-native-action-final` (final rebuild adjusts only the UI outcome label).

The authenticated installer order was rechecked and remains open for handoff; no installation medium was obtained in this phase. Native pfSense validation and official submission/acceptance remain outstanding. No production service or setting changed.

## SMTP encryption before authentication

On 2026-09-09, a real loopback SMTP regression fixture demonstrated that the previous adapter sent fixture credentials and DATA when a relay advertised AUTH without STARTTLS. The corrected adapter connects without credentials, explicitly requires implicit TLS or successful STARTTLS, then authenticates on the established connection. Partial authentication configuration is rejected instead of becoming anonymous. The native explicit certificate-validation opt-out is retained, but cannot disable the encryption requirement.

Nine cases pass against actual pinned PEAR libraries in isolated FreeBSD 15.0-p13 / PHP 8.3.33: absent STARTTLS with validation enabled and disabled, implicit TLS with PLAIN, STARTTLS with LOGIN, wrong password, wrong certificate identity, untrusted issuer, explicit validation opt-out and refused STARTTLS. Evidence records booleans for TLS, AUTH, plaintext AUTH and DATA, never protocol credential bytes. Ephemeral certificates and synthetic credentials remain in a private root-only lab directory. Final evidence: `/root/recovery-guard-smtp-tls-e466d805a2f6`. No external mail was sent and no system trust store was changed.

The unchanged anonymous SMTP acceptance/rejection/content suite passes three checks (`/root/recovery-guard-smtp-c72ad23698ce`); notification components pass 84 checks locally and in FreeBSD. Thirteen staged native-adapter/manifest checks and syntax checks for 68 PHP/include files pass. The 41-file `0.1.0.a1_12` port passes compile, `stage`, `check-plist`, `stage-qa` and `package`. Package SHA256: `2ae46b8221900be936f351f144fed89a0ee5cb8f7dc0c9371e7b93e5e5eb7529`. Source archive SHA256: `93c51ac125692bec7f7848d2fec9a501446e2d6546f9d7281412219e04446094`. Guest source: `/root/recovery-guard-smtp-tls-final`.

These tests establish transport behavior with the pinned private PEAR fixture, not the full native pfSense notification entrypoint. Native lifecycle/GUI and supported-development-version tests remain release requirements. The regular Netgate shop flow now reaches a free installer checkout, but current private billing details are pending; no new order or installer download is claimed. Automatic recovery remains disabled, no production setting changed, and no official submission or acceptance occurred.
