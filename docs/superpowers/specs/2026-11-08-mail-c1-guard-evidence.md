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

Final verification after the review corrections:

| Gate | Result |
| --- | --- |
| Full `composer --working-dir=mcp check` | Passed: 9,965 PHP tests / 89,900 assertions; 837 browser tests; both static scopes; documentation and production package. |
| Custom native dependency, NC35.0.2 / MariaDB10.11.19 / Mail5.12.5 / Dovecot2.4.5 | Passed: 16 tests / 406 assertions. Includes both flags true/false through HTTP, remote/cache durability, no-send capture, two DB actors, generation/cache races, NO/lost acknowledgement and permanent-flag faults. |
| Stock Mail regression, same native platform | Passed: 12 executed tests / 326 assertions; 4 guard-only tests intentionally skipped. Existing A/D/C0 paths remain available; the stock mutation boundary remains blocked. |
| Focused guard/journal/tool/architecture cases | Passed: 42 tests / 2,663 assertions. |

The native boundary manifest digest is `7676d541b440b989e2e961fa3904a45de048d803ca1dcf78c2f4902c286320a3`. The reproducible overlay archive is generated at `mcp/build/native-mail-guard/mail-guard-overlay.tar.gz`, SHA256 `809b3df9bb0c82bc3ecd20584f14861b2a648fefe1d102eaeefb1670b34098ee`. Its source files must match the committed `NativeGuardManifest::DATA`; the build tool also emits the matching PHP manifest. The full native artifact is derived from the original pinned archive and the seven overlay files, not an invented upstream version.

Independent read-only review of `c6b99fed..0110d53d` identified three issues. Each was reproduced before correction and fixed in one pass: the dispatched request now retains the exact approved snapshot; native guards require the chosen system flag in PERMANENTFLAGS at admission and every guarded command/readback; and the retired-path scanner exempts only the two exact native fingerprint data strings in this generated manifest. Other files and retired MCP loaders remain rejected. The permanent-flag tests also withdraw support during the session and prove zero STORE commands. The complete suite above passed after these fixes; no second independent review was substituted for those regression tests.

The permanent/session distinction is defined by [RFC3501 sections 2.3.2 and 7.1](https://www.rfc-editor.org/rfc/rfc3501). Missing permanent support or a wildcard alone is refused for these system flags, even if a server could otherwise accept a session-only STORE. This is a conservative unsupported path, never fabricated durable success.

A source audit of the pinned native `lib/AppInfo/Application.php` and `SpamReportListener`, `HamReportListener`, `MoveJunkListener` confirms that their email-report/move paths require `$junk`/`$notjunk`. The new closed primitive accepts only `seen`/`flagged`, so it cannot enter those paths. This is also a reason not to broaden the primitive into arbitrary labels as part of C1.

## Architectural choices and operational limits

- The native dependency is a source overlay in this project; no other registered repository or shared Nextcloud was modified. Installation of this custom build remains a separate deployment action.
- Native cache admission uses short row locks on immutable message/mailbox IDs and sync-token revalidation, without timestamp lease acquisition or IMAP inside a DB transaction. The cache commits before one guarded native event; uncertain event completion is never redispatched automatically.
- The shared journal uses token/phase plus whole-receipt CAS, with no schema expansion. The optional native typed fence adapter wraps a DB-only implementation; parent launcher and child writer remain separate from D's authentication runner.
- Worker expiry is reported uncertain after its 25-second admission window without releasing the claim. Once bytes may have begun, interruption never proves cancellation or authorizes another write.
- The builder streams bounded fixed source members; it does not expand the complete release into the shared temporary filesystem. A prior full-archive expansion hit ENOSPC and was replaced without cleaning unrelated files.
- Qualification covers the exact native boundary above. Broader platform/database/server combinations, production installation and upstream acceptance are not claimed. Installed PHP/listeners are trusted; this feature is not a containment boundary for a compromised host.

## Remaining gates

- C3 remains blocked independently of C1: the public native result still lacks command-bound destination UIDVALIDITY, and MOVE/destination-cache guarantees have not been implemented or admitted.
- General C7 recovery cannot release uncertain remote effects or redispatch uncertain events. The status extension is useful evidence, not a general repair tool.
- C2 labels, C4 identity/composition parity, C5 folder changes, C6 refresh and the remaining C7 workflow retain their existing plan gates. This delivery does not infer readiness from the flags boundary.
- The native dependency is a local custom artifact. Publishing, upstream contribution, production installation and broader native-pair qualification require their own requested work. No SMTP submission, enqueue/schedule/MDN path, held-draft flush or cron activation is introduced.
