# Runtime integration

`RuntimeSupervisor::cycle()` joins native-shaped configuration snapshots, the PHP challenge adapter, real network parsing/local-route checks, peer qualification, a log observation and the durable action coordinator. The platform snapshot and action functions are injected; no installed daemon starts these cycles yet. The current package UI still rejects activation.

Snapshot adapters must provide fresh settings, interfaces, VLANs, VIPs, native boot identity, uptime and explicit tri-state interlocks. Every required interlock must be known false. Maintenance, upgrades, any configured HA, another repair or shutdown stop collection and clear volatile confirmation. Configuration is compiled on every snapshot, and its effective hash plus boot identity defines the measurement context. Changing this context resets the peer baseline and policy episode while preserving durable budgets.

A second snapshot follows collection. Immediately before actions, the coordinator checks the same context again, including after evidence capture. Disable, mode, peer, interface or boot changes inhibit stale proposals. Wall-clock and monotonic-clock divergence greater than five seconds resets confirmation. The existing journal retains conservative action reservations.

The observation phase has a 25-second cooperative deadline checked between each bounded operation. It stops remaining collectors after an overrun and never calls the policy with a partially timed-out cycle. It cannot preempt a blocking adapter: native snapshot/log access must run behind suitable bounded adapters, and a functioning kernel is still required. Service-start adapters must not reuse the finite diagnostic descendant reaper. A future daemon must provide lifecycle locking, scheduling, status reporting and signal handling in addition to this cycle.

## Bounded retry-log sampling

`LogRateProbe` reads new bytes from the regular native system log through a retained descriptor, at most 64 KiB per observation. The first observation skips existing contents. It counts only RFC3164-shaped `check_reload_status` records with the exact PHP-FPM socket failure message described in [Netgate issue 13252](https://redmine.pfsense.org/issues/13252). Embedded quotations from other programs and unrelated interface errors do not count. Alternate log formats are not yet supported.

A storm requires at least ten matching records per elapsed second, with an interval from five to 45 seconds. A bounded sample can prove this lower bound even when additional bytes were skipped. Otherwise, truncated coverage is unknown, never evidence of a quiet log. Context changes, long gaps, truncation, inaccessible files and symlinks invalidate measurements. Rotation reads the previously open file's new bytes, then opens the replacement at EOF; replacement history is not retroactively counted. There are no log-reader state writes or exported raw log lines.

The policy still requires a failed PHP transaction and corroborating local reachability/link conditions. A log storm alone never permits a reboot. A complete native runtime must validate log layout, interlock semantics, privilege boundaries, timing under load and durability across real package lifecycle operations.
