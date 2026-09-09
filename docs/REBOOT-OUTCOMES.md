# Observing a boot after a recovery request

Handing an instruction to a reboot worker is not evidence that the machine restarted. Likewise, finding a new boot later does not prove that Recovery Guard caused it. The diagnostic outcome `boot_observed` means only that a new boot was observed after a durably claimed reboot request. Service health remains determined by the independent probes and their newly established baseline.

At startup, before ordinary policy sampling can prune old reservations, the daemon attempts reconciliation once. This runs under the supervisor lease and uses the existing budget, intent and diagnostic locks in the same order as the reboot handoff. No executor is called. The budget and intent are read only; only the diagnostic record can change.

The observation requires all of the following:

- A validated handoff journal containing a consumed claim and a matching retained reboot reservation.
- A matching recovery-mode diagnostic record with the same proposal ID, boot identity, configuration context and event time. Its previous outcome must be pending, handed off or unknown.
- A different, later native boot timestamp that is no earlier than the claim. The claim must have occurred within the handoff's original 60-second window.
- An observed wall time and uptime consistent with the new boot timestamp, with uptime lower than the old diagnostic sample.
- A nonnegative monotonic counter lower than the counter persisted with the original intent. A changed wall-clock-derived boot timestamp alone cannot qualify.

The stored observation contains the new boot identity, observation time, uptime, monotonic counter, claim time and original outcome. It contains no handoff token, credentials or addresses. The JSON export uses diagnostic schema version 2. Existing version-1 history can be read unchanged; adding an observation advances the journal version without resetting records. Ordinary executor receipts cannot create this outcome.

A repeated startup preserves the first observation byte for byte. If the diagnostic commit is ambiguous, both the action budget and consumed intent remain unchanged. A subsequent retry can recognize an already persisted observation. There is never automatic intent replay.

Uncertain evidence leaves the previous outcome unchanged. This includes a service-only restart, clock inconsistencies, an unclaimed intent, missing/pruned reservation, changed evidence, damaged storage, and startup so late that the new monotonic counter or uptime has already overtaken the old value. These conservative conditions can miss a real reboot; they must not be described as proof that no reboot occurred. VM state restoration and unsupported clock behavior require platform-specific validation.

If notifications were previously initialized, the new diagnostic outcome produces one event using the observation time. Its message explicitly leaves reboot cause and service recovery unconfirmed. On first-ever notification activation, the existing history remains a baseline, including any earlier boot observations.

The implementation still requires validation through pfSense's full native cleanup and startup path. Current automated actions remain disabled in the preview. [PHP's monotonic timer contract](https://www.php.net/manual/en/function.hrtime.php) describes why wall-clock changes alone are not sufficient evidence for this check.
