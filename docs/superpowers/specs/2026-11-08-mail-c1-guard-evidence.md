# Mail C1 Guard — Delivery Evidence

Baseline: MCP 2.7.0 with A `90fe762d`, D `f59d5fc1`, C0 `8c619790`; approved guard plan `c6b99fed`.
Scope: C1 desired-state flags and its native dependency. Sending remains disabled. C3 and the other pending C slices are not declared complete.

## Delivered behavior

`mail_set_message_flag` sets `seen` or `flagged` to an explicit boolean for one owned message/account. It uses the existing preview, plan_state, operation_id and confirmation protocol. `mail.set_flags` is a separate operation/permission; per-tool grants and connection selection remain mandatory, and existing grants/selections acquire no new access. The catalog now has 24 Mail tools. No move/trash/restore/send tool was added.

`MailMutationSession` exposes closed actions, never a generic `run(..., Closure)` or raw client. A native API and source fingerprint gate guard C1 on the tested NC35.0.2/Mail5.12.5 custom build. Stock5.12.5 and unknown pairs/builds fail closed for C1; existing A/D availability remains unchanged. C0's raw-client characterization and local-only reply/alias limitations remain valid.

The [native source overlay](../../../native/mail-guard/README.md) is supplied in this project rather than modifying a different registered checkout. It reuses native credentials/factory, rate limiting, Horde dispatch and native cache/event services. No protected reflection, copied authentication params, custom product transport or MCP writes to native Mail tables are used. No shared installation was changed. This is a custom dependency, not a claimed upstream release.

The native dispatch guard binds a private connection, actual selected mailbox generation/mode and exact UID, permits one STORE, and verifies desired state after a tagged acknowledgement. Server NO, lost acknowledgement, UID disappearance and generation invalidation never become verified success. The cache stage locks immutable mailbox/message rows, rechecks their synchronization tokens and ownership, and commits before the guarded native event. Existing native timestamp sync locks are neither acquired nor unlocked. Cache/event failure after remote acceptance remains unresolved; event replay/reconciliation refuses.

## Durable execution and recovery

The shared journal delegates one signed effect to an isolated writer. Token/phase plus whole-receipt CAS makes the stored evidence authoritative, including against old direct finish/checkpoint paths. No new table/migration or second receipt database is needed. Public receipts expose only allowlisted mutation phase and remote/cache proof; token, descriptor, profile revision and credentials remain private.

The parent has a 20-second process budget and bounded stdin/stdout/stderr handling. The CLI child requires an independent 20-second alarm, so it also expires if its HTTP parent dies. Five mutation admissions per owner/account/minute use the existing slot store under a distinct scope; D's authentication-only runner stays unchanged.

`prepared` can settle failed_before_effect only by fencing out any late worker. After `wire_may_start`, missing proof settles uncertain and retains the account claim. Complete remote evidence survives a later permission/cache failure. Only `completed` can settle succeeded. A timeout cannot recall transmitted bytes; a new operation ID cannot bypass the unresolved account claim. Status/replay do not connect to IMAP, spawn a writer or reconcile a cache. Status and replay retain current original-tool authorization checks.

## Verification

The recorded runs cover the real NC35.0.2/MariaDB10.11.19/Mail5.12.5 custom artifact, native Dovecot2.4.5 and A/D/C0 regression paths. Ordinary unit tests exercise actual journal SQL through SQLite, one-shot permits, signed tickets, process bounds, stale approval, strict flags and grant/catalog contracts. Native protocol fault tests use Mail's real factory/client against a loopback scripted endpoint and record command kinds only, never authentication payloads.

Latest native run: **15 tests / 374 assertions**, including server NO, lost acknowledgement after a simulated remote effect, and unsolicited generation replacement. Each fault returns the appropriate failed-before-effect/uncertain receipt, no fabricated proof and no replayed STORE. Native boundary manifest digest: `e439e27ce5bf3d4585b556c09dc22008a043d6030aa550d924d220a88f62cac4`. Final full-check/review results are recorded after verification. Earlier intermediate evidence: native suite 14 tests/356 assertions; focused guard/journal/tool suite 35 tests/154 assertions; API/licensing/l10n subset 3,124 tests/19,285 assertions. These intermediate results are not substituted for the final gate.

## Remaining gates

- C3 remains blocked independently of C1: the public native result still lacks command-bound destination UIDVALIDITY, and MOVE/destination-cache guarantees have not been implemented or admitted.
- General C7 recovery cannot release uncertain remote effects or redispatch uncertain events. The status extension is useful evidence, not a general repair tool.
- C2 labels, C4 identity/composition parity, C5 folder changes, C6 refresh and the remaining C7 workflow retain their existing plan gates. This delivery does not infer readiness from the flags boundary.
- The native dependency is a local custom artifact. Publishing, upstream contribution, production installation and broader native-pair qualification require their own requested work. No SMTP submission, enqueue/schedule/MDN path, held-draft flush or cron activation is introduced.
