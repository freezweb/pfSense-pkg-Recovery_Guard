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

The current Netgate installer acquisition is waiting at the existing account's regular browser login because another extension UI blocks browser automation. No CAPTCHA bypass, account reset or alternate unofficial installer source has been used.

Sources: [official VM image directory and checksums](https://download.freebsd.org/releases/VM-IMAGES/15.0-RELEASE/amd64/Latest/), [FreeBSD BASIC-CLOUDINIT build configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/release/tools/basic-cloudinit.conf), [native nuageinit configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/libexec/nuageinit/nuageinit.7), [QEMU user-network restrictions](https://www.qemu.org/docs/master/system/invocation.html), [Netgate installer](https://shop.netgate.com/products/netgate-installer).
