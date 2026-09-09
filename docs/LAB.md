# Isolated integration lab

Status on 2026-09-09: a separate official pfSense CE 2.9.0-RELEASE VM now complements the FreeBSD platform laboratory. Its native port build, package lifecycle and authenticated HTTP GUI checks pass. Active integration and development-channel coverage remain in progress. The sections below retain the earlier platform evidence separately.

## Provisioned baseline

- Official `FreeBSD-15.0-RELEASE-amd64-BASIC-CLOUDINIT-zfs.qcow2.xz` image.
- SHA256 verified before decompression: `7cd43f502df575c76e5b39d0fc164272c40b99facdba9c59387f619dca321c5a`.
- Two vCPUs, 2 GiB RAM, a dedicated 16 GiB virtual disk and serial console; automatic host-boot startup disabled.
- No production bridge attached. QEMU user networking supplied outbound access during provisioning; it was then switched to `restrict=on` before process fault tests. The only explicit forwarding rule is SSH on host loopback, reached through the authenticated hypervisor SSH connection.
- A dedicated SSH key, protected runtime storage and verified vault backup. No production credentials or firewall configuration are copied into the guest. Private addresses, host identities and access instructions are kept outside this public repository.
- No production VM was stopped, reconfigured or rebooted.

FreeBSD's BASIC-CLOUDINIT image runs first-boot security updates before normal service availability. The update to 15.0-RELEASE-p13 completed successfully and requested its own reboot. PHP 8.3.33 and its filter, POSIX and PCNTL extensions were installed from the signed official FreeBSD package repository. FreeBSD 15.0 is a platform test baseline, not a substitute for required current pfSense development-version tests.

An isolated guest cannot resolve reverse DNS through the normal external resolver. Set the lab SSH server's `UseDNS=no` while retaining key-only authentication. This restored the forwarded SSH connection without relaxing `restrict=on`. A separate 30-second default-route wait during boot was observed; use the serial console to distinguish a progressing boot from a failed guest, and do not restart solely because a short SSH connection attempt times out.

## Verified results

- 76 policy/journal integration checks with native file and directory fsync, 10 FastCGI loopback transport checks and 42 network-contract checks passed.
- Eight native process checks passed: literal arguments, stderr/exit status, output cap, timeout, orphaned grandchild, TERM-resistant child, detached descendant and a killed timeout wrapper.
- The killed-wrapper case returned `cleanup_unknown`. The finite fixture then exited; descendant cleanup was checked through POSIX process existence, not inferred solely from an exit code.
- A real operator-issued guest reboot changed the native boot identity and preserved the exact durable journal SHA256 and action budgets. A fresh coordinator retained the prior reboot reservation; a subsequent synthetic fault timeline could not reserve a second reboot.
- No service-repair or automatic-reboot OS executor is claimed by these tests. Fault observations and action receipts in the journal test are synthetic; the guest reboot between prepare and verify is real.

## Reproducing the guarded tests

Use an isolated FreeBSD guest with the above dependencies and source checkout. The marker file must be created by the lab provisioning procedure only after establishing isolation; neither marker is a security boundary.

```console
php tests/integration.php
php tests/fastcgi.php
php tests/network.php
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/process-lab.php
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/reboot-lab.php prepare
# Reboot this isolated guest through its management connection, then reconnect.
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/reboot-lab.php verify
```

The reboot fixture refuses to overwrite an existing test directory. It keeps its durable evidence under `/root/recovery-guard-reboot-check`; use the optional new directory argument for a separate test run. Verification can be repeated within the retained budget window without changing that evidence; an expired budget needs a separate new test run.

## Next verification steps

1. Extend process checks to resource exhaustion, filesystem faults and simultaneous supervisor processes; validate repair-controller completion separately from intentionally persistent service daemons.
2. Add actual pfSense development and supported CE guests, native package lifecycle, service-recovery and reboot tests. Validate GUI transitions and safe defaults.

The existing Netgate account now works in the in-app browser. An existing fulfilled AMD64 ISO installer order was reopened using its fresh emailed access link, without a new order or account. Its Download Now action is blocked by the browser, and a direct request returned HTTP 404. No matching local download was found. The user has been asked to download it through their browser and provide the local path. No browser barrier or CAPTCHA was bypassed, and no unofficial image was substituted.

A subsequent ordinary shop attempt reaches a new free AMD64 ISO checkout. Current private billing details are now verified from current owner records. Explicit confirmation for transmitting those details and accepting the checkout's purchase/evaluation/license terms is pending. No new order has been placed and no installer obtained through this attempt.

Sources: [official VM image directory and checksums](https://download.freebsd.org/releases/VM-IMAGES/15.0-RELEASE/amd64/Latest/), [FreeBSD BASIC-CLOUDINIT build configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/release/tools/basic-cloudinit.conf), [native nuageinit configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/libexec/nuageinit/nuageinit.7), [QEMU user-network restrictions](https://www.qemu.org/docs/master/system/invocation.html), [Netgate installer](https://shop.netgate.com/products/netgate-installer).

## Service lifecycle fixture

On the isolated FreeBSD guest only, with the existing `/root/RECOVERY_GUARD_ISOLATED_LAB` marker and PHP CLI/filter/pcntl/posix/XML extensions:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/service-lab.php
```

The test retains a unique evidence directory under `/root/recovery-guard-service-*`. It uses the shipped rc script with a private PID file and synthetic entrypoint; actual ServiceLoop, ServiceState and LogWorker classes are exercised. Its cleanup stops the fixture daemon and worker. It does not install the package, write a pfSense configuration or execute firewall actions. Twenty-four checks pass; see the separate validation record for build hashes. Do not substitute this result for actual pfSense install, upgrade, deinstall or native daemon testing.

## Repair controller and private FPM test

Run only in the isolated FreeBSD guest with the existing marker and PHP-FPM binary:

```sh
cc -std=c11 -Wall -Wextra -Werror -O2 -DRECOVERY_GUARD_LAB native/repair-controller.c -o /root/repair-controller-lab
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/repair-controller-lab.php /root/repair-controller-lab
```

The lab binary accepts synthetic commands. The production port never defines `RECOVERY_GUARD_LAB`, and its helper only accepts the fixed native repair operation. The test starts one real private PHP-FPM instance on a Unix socket and stops it afterwards. Its isolated root pool permits access to the private fixture tree; it is not a proposed production FPM configuration. Sixteen checks pass. Process-failure fixtures self-expire after eight seconds when deliberately testing a killed controller. Retained evidence is under `/root/recovery-guard-repair-*`.

## Reboot handoff tests

Non-rebooting coverage in the isolated FreeBSD guest:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/handoff-lab.php
```

The following test **reboots the guest automatically**. Use only the designated isolated disposable VM, never a production firewall. `prepare` builds a synthetic fault timeline, persists real reservation/evidence/intent records, starts a separate worker and retires the fixture supervisor. The worker invokes FreeBSD shutdown. Reconnect after the guest boots, then use `verify`:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/handoff-reboot-lab.php prepare /root/recovery-guard-handoff-reboot-example
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/handoff-reboot-lab.php verify /root/recovery-guard-handoff-reboot-example
```

Always choose a new fixture path for preparation. Verification reads the retained evidence, confirms a changed native boot identity and exact budget/claimed-intent hashes, and checks that a consumed intent cannot replay. It does not request another reboot. The harness uses a test-specific FreeBSD action, not pfSense system cleanup, and its measurements/maintenance flags are fixtures. Source does not contain the laboratory's private access configuration.

## Private SMTP and TLS fixtures

With the ignored, pinned PEAR fixture described in VALIDATION.md and OpenSSL enabled in the isolated guest:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/smtp-lab.php /root/pear-mail-fixture
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/smtp-tls-lab.php /root/pear-mail-fixture
```

The first suite checks anonymous SMTP acceptance, rejection and message preservation. The second generates short-lived private certificates, binds only ephemeral loopback listeners and launches deadline-bounded clients with a fixture-only trust file. It tests real implicit TLS/STARTTLS and PLAIN/LOGIN authentication, with nine success/failure cases. Server evidence contains only protocol-state booleans. Neither suite uses external recipients, real account credentials or changes to system trust. The lab marker is a guard against accidental execution, not a security boundary. Full native pfSense SMTP configuration/entrypoint testing remains separate.

## Actual storage failure fixture

As root in the designated isolated FreeBSD guest only:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/storage-lab.php
```

The test mounts a new 8 MiB tmpfs under a newly created private root directory. It exhausts only that filesystem and separately remounts it read-only. It never fills or remounts the guest root filesystem, accesses a physical disk or invokes a real repair/reboot/mail sender. Cleanup unmounts its exact mount path, including after failed assertions.

Twenty-three checks cover failed budget reservations, unchanged prior journals, reconfirmation after storage recovery, a full diagnostic store after a successful budget reservation, conservative cooldown without reboot escalation, and mail claim inhibition/recovery. The first fill attempt exposed a fixture issue: failure to allocate a large block can leave enough pages for a small journal. The final fixture exhausts progressively smaller allocations too. Retained results distinguish failure to create a temporary journal from failure to open the writer lock on a read-only filesystem. Tmpfs tests do not establish power-loss durability or behavior during an uninterruptible physical I/O hang; native fsync and injected ambiguous-commit tests remain separate evidence.

## Official pfSense installation media and new native guest

The fresh, user-approved free Netgate installer order is complete. A direct HTTPS download from its normal order endpoint on the laboratory host produced the official `netgate-installer-v1.2-RELEASE-amd64.iso.gz`. The compressed SHA256 is `184514fe7df0d339362c1e33fa051c464577a450528759b343ade894c7c57955`, matching Netgate's published checksum list; `gzip -t` also passes. The previous browser-download handoff is resolved without changing browser settings or using unofficial installation media.

A separate temporary VM uses a dedicated unprivileged QEMU account and two user-mode networks, with no production bridge. Host egress rules scoped to that account reject private/reserved IPv4 destinations and IPv6, allowing public HTTP/HTTPS/DNS/NTP and established replies. Public HTTPS and rejection of private destinations were checked under that UID. Host IP forwarding remains disabled and the VM has no automatic boot. Management forwards bind only host loopback; the installer SSH key was verified against its serial-console fingerprint. Before starting after a host reboot, its egress rules must be restored and verified.

The authentic installer supports its native serial console and CA-verified local installer API. Installation on the VM's verified new disk completed. Booting from disk and native package queries confirm CE 2.9.0-RELEASE, FreeBSD 16.0-CURRENT and PHP 8.5.7. The installed SSH fingerprint was checked through serial before use, and the dedicated GUI credential is stored in the authorized vault. This stable installation is distinct from current development-channel validation. The older FreeBSD fixture VM and all production guests remain unchanged.

Source: [Netgate installer checksums](https://www.netgate.com/hubfs/pfSense-plus-installer-checksums.txt). Private order links, host routes, identities and provisioning details are kept outside this repository.

## Real pfSense package lifecycle

With the native package already installed on the isolated guest, enable monitor mode, maintenance on and notifications off. Initialize a private synthetic repair/reboot reservation in its journal through StateStore; the test deliberately requires nonempty budgets so preservation cannot pass trivially. Then run:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/pfsense-package-lab.php /absolute/path/to/candidate.pkg
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/upgrade-lease-lab.php
```

The first test replaces, removes and reinstalls the actual package. It verifies daemon processes, native menu/service registration, retained settings, exact journal hashes and installed-file integrity. Each pkg output is also checked for native hook failures that pkg may report without a failing exit status. Private logs remain under a unique /root/recovery-guard-package-* directory. This is native package-hook coverage, not an official repository GUI upgrade test.

The second test uses the installed native upgrade wrapper with a private payload and lock path. Omit the wrapper argument on pfSense: its filename in the test command line correctly triggers the conservative process interlock. No real upgrade runs.

The native service registration uses explicit startcmd/stopcmd and an empty rcfile because pfSense unlinks rcfile before custom deinstallation. The script is owned by pkg-plist and must remain until pkg removes its files. Native stop/start and after-sync behavior were checked after this fix.

## Real native PHP-FPM recovery

The following test disrupts the isolated pfSense guest's actual PHP-FPM. Keep SSH and serial recovery routes available and retain the lab network containment. The installed package must stay enabled in monitor mode with maintenance on and notifications off:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php -d display_errors=stderr tests/pfsense-repair-lab.php stop-and-repair-native-fpm
```

The test verifies the FPM master PID, stops it gracefully and samples a real failed FastCGI transaction for the default 120-second confirmation interval. It uses a separate durable budget/evidence store and an in-memory repair-mode configuration; network and log inputs are synthetic healthy observations. Actual boot identity and lifecycle interlocks remain native. The original installed fixed-command controller executes pfSense's unmodified PHP-FPM restart script. A failed test attempts to restore the laboratory service and records that fallback separately. Installed package configuration and its existing budgets must remain unchanged.

Native reboot cleanup can now be exercised explicitly on the installed isolated pfSense guest:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 RECOVERY_GUARD_PFSENSE_CLEANUP=1 php tests/handoff-reboot-lab.php prepare /root/recovery-guard-handoff-reboot-native-example
# Wait for the guest to boot and native boot/package activity to settle.
RECOVERY_GUARD_ISOLATED_LAB=1 RECOVERY_GUARD_PFSENSE_CLEANUP=1 php tests/handoff-reboot-lab.php verify /root/recovery-guard-handoff-reboot-native-example
```

This variant retires the installed monitor through its actual rc stop, uses the shared native supervisor lease and native upgrade lease, and calls system_reboot_sync in the independent worker. It retains the installed monitor configuration and budget and checks them after boot. Fault inputs and the private coordinator timeline are synthetic. It tests native cleanup and one-use persistence, not the shipped daemon's complete autonomous failing-peer sequence. If preparation fails after the monitor was retired, inspect its evidence before restoring the monitor through the native service control.

For actual restricted-user authentication and privilege checks:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/pfsense-gui-privilege-lab.php
```

The test creates a temporary GUI-only identity with a process-local random password, verifies settings/export denial, grants only the package page privilege, verifies access and removes the identity in finally. It does not create an OS/shell account or retain its password. The real pfSense TLS certificate, matching hostname and explicit CA trust remain enforced. No real administrator credential is required for this root-run laboratory fixture.

The stronger joined collector/runtime repair test uses every native collector and real configured endpoint replies:

```sh
RECOVERY_GUARD_ISOLATED_LAB=1 php tests/pfsense-runtime-lab.php stop-and-repair-native-fpm
```

It requires settled boot grace and initially healthy PHP/link/peers, runs three healthy qualification cycles, then stops the actual FPM master and allows the full RuntimeSupervisor to confirm and repair the fault using real clocks. Only its in-memory recovery settings and private budget differ from the installed monitor. The native log worker is closed and the lab PHP service restored even after test failure. The test never requests a reboot.
