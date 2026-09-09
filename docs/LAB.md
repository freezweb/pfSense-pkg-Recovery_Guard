# Isolated integration lab

Status on 2026-09-09: a dedicated FreeBSD VM has been created and is completing first-boot updates. The guest is running; the update process has been observed through its serial console. Guest SSH login, test execution and reboot persistence are not yet verified. A running VM alone is not integration-test evidence.

## Provisioned baseline

- Official `FreeBSD-15.0-RELEASE-amd64-BASIC-CLOUDINIT-zfs.qcow2.xz` image.
- SHA256 verified before decompression: `7cd43f502df575c76e5b39d0fc164272c40b99facdba9c59387f619dca321c5a`.
- Two vCPUs, 2 GiB RAM, a dedicated 16 GiB virtual disk and serial console; automatic host-boot startup disabled.
- No production bridge attached. QEMU user networking provides outbound access during provisioning and an SSH forwarding listener bound only to host loopback. Before fault tests, restrict outbound guest networking as well.
- A dedicated SSH key, protected runtime storage and verified vault backup. No production credentials or firewall configuration are copied into the guest. Private addresses, host identities and access instructions are kept outside this public repository.
- No production VM was stopped, reconfigured or rebooted.

FreeBSD's BASIC-CLOUDINIT image runs first-boot security updates before normal service availability. The observed update targets 15.0-RELEASE-p13. Do not interrupt a verified active update or recreate the VM just because SSH is not ready yet. FreeBSD 15.0 is a platform test baseline, not a substitute for the required current pfSense development-version tests.

## Next verification steps

1. Verify guest identity and SSH host key through the trusted hypervisor route; confirm successful first-boot completion.
2. Install the required PHP CLI extensions from the official FreeBSD package repository, transfer the exact candidate source and record its identity.
3. Run policy, journal, transport, baseline and `RECOVERY_GUARD_ISOLATED_LAB=1 php tests/process-lab.php` suites. The environment marker alone is not network isolation.
4. Exercise detached descendants, signal-resistant children and interrupted command wrappers in addition to the prepared basic process cases. Verify cleanup before accepting any repair completion receipt.
5. Reserve actions, reboot the guest and verify the persistent budget through a new supervisor instance. Exercise simultaneous supervisors and storage failures.
6. Add the actual pfSense development and supported CE guests, native package lifecycle, service-recovery and reboot tests. Validate GUI transitions and safe defaults.

The current Netgate installer acquisition is waiting at the existing account's regular browser login because another extension UI blocks browser automation. No CAPTCHA bypass, account reset or alternate unofficial installer source has been used.

Sources: [official VM image directory and checksums](https://download.freebsd.org/releases/VM-IMAGES/15.0-RELEASE/amd64/Latest/), [FreeBSD BASIC-CLOUDINIT build configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/release/tools/basic-cloudinit.conf), [native nuageinit configuration](https://github.com/freebsd/freebsd-src/blob/releng/15.0/libexec/nuageinit/nuageinit.7), [Netgate installer](https://shop.netgate.com/products/netgate-installer).
