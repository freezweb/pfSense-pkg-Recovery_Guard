# Notification delivery

The preview ships tested notification components, but does not yet initialize or fill an outbox, expose a notification opt-in, or start a sender worker. No notification is sent by the preview daemon. Automatic recovery remains disabled.

## Durable attempts

`NotificationOutbox` uses the same atomic, private, fsynced `StateStore` mechanism as the action journal, in a separate store. A job contains a destination digest and a fixed event schema, never SMTP credentials, recipient addresses, raw configuration or logs. The SMTP adapter obtains native settings only at sending time. A changed recipient, sender, relay, authentication identity or TLS policy holds the old job rather than silently retargeting it. Password rotation does not change a destination binding.

The outbox retains at most 128 records. When full, it removes one oldest accepted record. If none is available, it retains every pending/held record, increments an overflow counter and refuses the new event. Deduplication covers retained records only. Producers must expose overflow and delivery faults; mail capacity must not independently authorize or prevent a firewall recovery operation.

Before calling a transport, a sender durably increments the attempt count and reserves a retry deadline. Backoffs are 60, 300, 900, 3600, 7200, 14400, 21600 and 43200 seconds. At most eight attempts are allowed, including workers lost before a receipt. Each attempt has a new one-use token and a 30-second receipt lease. A stale worker cannot overwrite a newer attempt. Clock reversal inhibits claims and receipts. Ordinary idle polling and duplicate enqueue do not rewrite the journal.

Eight unsuccessful or uncertain attempts leave a held record for diagnosis. Disabling notifications or changing the target also holds the record. There is currently no automatic replay of held records and no user-facing retry control.

## Honest results

PEAR Mail's explicit `true` return is recorded as SMTP relay acceptance. A null, error object, exception or late receipt is uncertain. Neither the native temporary queue nor a generic null return from pfSense's notification wrapper is sufficient evidence of acceptance. Relay acceptance does not prove delivery to the recipient's inbox.

SMTP cannot guarantee exactly-once delivery: a relay may accept DATA immediately before a worker dies or loses its receipt. A retry can therefore duplicate a message. Each retained event has a stable Message-ID, which aids correlation but is not a recipient-side deduplication guarantee. A reboot handoff notification explicitly says that completion has not yet been established.

The adapter follows native relay, authentication and TLS verification settings. It accepts up to sixteen comma-separated plain email addresses; display-name address syntax is not supported. It never copies SMTP errors into the journal because those may expose account or recipient data. A fixed ten-second socket timeout bounds an individual socket wait, not the entire SMTP transaction.

## Required integration

Before enabling delivery, implement and validate the explicit opt-in, separate outbox initialization, event production, status/overflow visibility and an independently supervised worker with a total deadline below the receipt lease. Network calls must never delay fault sampling or hold an action-budget lock. Re-read native configuration for each attempt and during service changes. Test timeout termination and process cleanup with a private SMTP fixture, then validate native settings and package lifecycle on an isolated pfSense guest. Reconcile reboot outcomes separately; a handoff receipt is not a boot-completion receipt.

References: [pfSense native notification source](https://github.com/pfsense/pfsense/blob/9363ac5b8651a1c7a333180425ce7719070f95f9/src/etc/inc/notices.inc), [PEAR Mail SMTP implementation](https://github.com/pear/Mail/blob/master/Mail/smtp.php).
