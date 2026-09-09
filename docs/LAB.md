# Isolated integration lab

Status on 2026-09-09: the dedicated FreeBSD VM has completed first-boot updates, native process tests and a real reboot-persistence test. SSH host identity was checked against the serial console. This validates platform libraries; actual pfSense service-recovery and package-lifecycle tests remain open.

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
