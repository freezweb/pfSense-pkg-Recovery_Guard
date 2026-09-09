# Native development port

The canonical PHP library lives in `src`. Run `php tools/stage-port.php` once to create a complete `build/ports/sysutils/pfSense-pkg-Recovery_Guard` source port, including libraries, native adapter/page/XML, license and sorted install manifest. Pass an unused destination directory to create another staging tree; the tool refuses to overwrite an existing destination.

The template is a monitor-only preview. It registers a native service and can start monitoring when explicitly enabled with valid peers. Both the save adapter and daemon reject repair/recovery modes. Package resync stops the service, initializes a new journal or validates the existing one, then starts the enabled monitor. Deinstallation stops the service and retains its budget, diagnostic and handoff journals. Missing or invalid existing state is never silently reset. These hooks still require actual pfSense lifecycle validation.

Saving settings uses pfSense's native config API and writes only `installedpackages/recoveryguard/settings`. The element must not be named `config`: pfSense treats that name as a list, changing the shape during XML serialization. Native `write_config` still performs its normal system-wide save/backup/synchronization behavior. A reported write failure restores the current request's package settings; this is not a claim of rollback after a partially persisted native write. A later service-apply failure is reported separately; already saved settings remain saved.

The menu points to `services_recovery_guard.php`, with the native page privilege declaration and `guiconfig.inc` authentication/CSRF path. Actual browser rendering, privilege enforcement, CSRF behavior and package registration still require an isolated pfSense guest. Synthetic adapter tests do not establish these properties.

`tests/configuration.php` tests unsafe topologies and peers, VLAN parent chains, direct-route gating and a route change during a failed ping. `tests/native-config.php` uses an explicit native API test double to check package-scoped saves, rejection before writes, failed-save restoration and the staged install manifest. Neither test modifies a real firewall config.

Do not install this development preview on a production firewall. The release needs a validated daemon, service lifecycle, durable budgets, actual pfSense install/upgrade/deinstall and the current development-version test matrix. Port registration scripts invoke native `/etc/rc.packages`; successful packaging on plain FreeBSD is not proof those scripts work on pfSense.

Sources: [Netgate package development](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html), [native Service Watchdog port](https://github.com/pfsense/FreeBSD-ports/tree/devel/sysutils/pfSense-pkg-Service_Watchdog), [native config implementation](https://github.com/pfsense/pfsense/blob/master/src/etc/inc/config.lib.inc).

## Reproduce the laboratory build

Use an isolated FreeBSD guest with `pkg`, a C compiler, PHP CLI and its filter, pcntl, posix and XML extensions, and the pfSense `devel` ports framework. With these dependencies already installed, the framework's `Mk`, `Templates`, `Tools` and `ports-mgmt/pkg` directories were sufficient, exported from the exact revision in [VALIDATION.md](VALIDATION.md). Export with `git -c core.autocrlf=false archive` when preparing the framework on Windows. Stage the port into a separate unused directory, then copy it into the framework's `sysutils` directory and run from that port directory:

```sh
make PORTSDIR=/path/to/pfsense-ports BATCH=yes stage
make PORTSDIR=/path/to/pfsense-ports BATCH=yes check-plist stage-qa package
pkg info -F work/pkg/pfSense-pkg-Recovery_Guard-0.1.0.a1_6.pkg
```

These commands build and inspect the package without installing it or executing its pfSense registration scripts. They passed on FreeBSD 15.0-p13; the resulting ABI is not a claim of compatibility with another FreeBSD or pfSense release.

Run `php tests/native-xml.php /path/to/pfsense/xmlparse.inc` after staging to exercise original native XML serialization and parsing on a synthetic configuration. This complements the config API test double; it found a real object/list mismatch that the double could not detect. The tracked CI template pins the parser revision for reproducibility; no upstream parser source is shipped with the package.
