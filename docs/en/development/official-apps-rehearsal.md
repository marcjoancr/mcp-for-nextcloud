<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Official app transition rehearsal

These rehearsals use synthetic users and separate disposable Nextcloud 35.0.2 / PostgreSQL 16.15 instances. They cover the native updater, stateful replacement of a guarded app with its official archive, and restoration of the matching code, database and data. The deployed Minico Cloud instance is outside the test boundary.

The observations supplied for Minico Cloud identify guarded Forms 5.4.2, guarded Mail 5.12.5.13, Nextcloud 35.0.2, PostgreSQL 16 and Authentik, with protected Mail transmission enabled. They are deployment evidence supplied with the task. The older stock Mail 5.12.5 matrix in the repository covers MariaDB and has a different scope. The first-stage worktree has separate, opt-in official-package modes and the fresh PostgreSQL acceptance below. [Mail compatibility matrix](../reference/tools/mail.md#guarded-and-legacy-acceptance)

## Transition the matched MCP and Forms packages

The Forms guard 5.4.2 sharing and group profiles pin the exact MCP bootstrap and group listener from their qualified historical package. The MCP official-apps candidate changes the bootstrap. The guarded source-companion verifier still admits only a matching historical package. Use [the v2.10.3 release and its companion](https://github.com/marcjoancr/mcp-for-nextcloud/releases/tag/v2.10.3) to reproduce that published pair, and record the actual matched versions present in the copy.

1. Quiesce workers, app requests and background effects. Record the enabled-app restrictions, Forms migration ledger, pending effects and linked exports, native comments, affected shares and activation state. Verify the matched backup of MCP and Forms code, configuration, database with original ownership, private appdata and affected file storage.
2. Inspect the separate Forms comments factory and group observer while the old pair still matches. The Forms app directory replacement does not remove either configuration component. If the comments factory is active, use its [historical installer rollback](../../../native/forms-guard/README.md#global-native-comments-integration), verify core resolves its stock comments manager in a fresh process, then turn over the old PHP workers. If the group observer is active, use its [dedicated historical rollback](../../../native/forms-guard/group-installer/RUNTIME-PLAN.md) with the actual qualified host plan and verify the retired epoch and absent entry. Preserve the archive and activation history. MCP's own group policy listener remains active.
3. In the same maintenance window, install the verified new MCP package and update Forms to the signed official release using the native App Store path. Enable `app.mcp.official-apps.forms` and restore the intended group restrictions. The update and the new bootstrap must both be in place before reopening to traffic. Do not offer the new MCP and the old guarded Forms as a qualified pair.
4. Verify native package integrity, unchanged expected Forms records and file references, stock comments behavior, two distinct user identities, and all-results denial after revoking permission. Validate the OAuth browser consent path if it is part of the planned deployment. Check the stock Forms catalog and that old guarded writes or linked-export workers cannot start.
5. If any gate fails, keep requests and workers quiesced and restore the matching previous code, configuration, database and data. Follow the historical installers' recovery rules for any activation that was retired. A restored configuration entry alone does not reactivate a retired activation token or epoch.

The archive-based rehearsal below exercised Forms data and migration rollback. Installation-dependent comments and group activation claims require evidence from a copy with those components actually enabled.

## Retire guarded Mail on a restore copy

Use [the PostgreSQL retirement script](../../../native/mail-guard/retire-postgresql.sql) with the qualified Mail 5.12.5.13 schema on PostgreSQL 16. This is an explicit operator step before the first App Store update. It is outside MCP requests and ordinary Nextcloud migrations. The archive and native table changes commit in one transaction. The complete matched backup remains the unit of reversal.

1. Keep Mail's guard active while resolving work that is still eligible to execute. Stop new MCP admissions and confirm that guard delivery, snooze and erasure no longer have work in progress. Reconcile Mail's independent native outbox, including records with a future scheduled date. Preserve any terminal uncertain result and prevent replay.
2. Quiesce HTTP requests, cron and queue workers, including old PHP workers and SMTP/IMAP children. Record the current Mail enablement or group restrictions. Set `app.mail.mcp-transmission.enabled=false` and `app.mcp.official-apps.mail=true`, then disable Mail without uninstalling it. Retain this isolation until the postflight checks pass.
3. Verify a matched backup of the PostgreSQL database with ownership, Nextcloud data and private appdata, original app code, runtime configuration and instance encryption keys. Include independent verification of all storage holding protected draft attachments. Keep the digest of the checked backup manifest and pass it to both review and apply. Ensure there is space for the database archive and the rollback set.
4. Run the read-only review using the actual table prefix and database for the restore copy. Use a database operator role authorized to move the guarded tables, separate from the role that owns the live Nextcloud configuration table. Keep the JSON output private. `ready` must be true and `blockers` must be zero. A held draft remains a blocker unless the reviewed choice enables quarantine. The archive keeps the complete native draft, recipients and attachment metadata, access from stock Mail is removed, and the attachment bytes stay under the original private storage. Obtain the owner decision and verify the matched backup before approving that choice. This operation creates no new Mail draft and makes no network request to a mailbox.
5. Repeat the same command with `reviewed_plan` and `backup_digest` set to the checked digests. The script locks the affected source tables, fingerprints the rows and fields that will be archived, and refuses changes to reviewed state or the supplied backup digest. It preserves complete guard tables, their existing internal constraints, and the definitions of detached references to live tables. It archives exact tag links before removing them from the stock view. Legacy tag links remain in place.
6. With Mail still disabled, run the native App Store update in a fresh process and verify the official app tree and installed version. Restore only the original group restrictions when enabling official Mail. Verify owner and foreign-account reads, held-draft invisibility, exact archive counts, the absence of dispatchable outbox rows and the terminal uncertain receipt. Keep MCP transmission disabled.

Use the same quarantine argument for review and apply. Omit it if no protected drafts should move into the archive.

```sh
psql -X -qAt --dbname "$retirement_db" --username "$retirement_db_operator" \
  -v prefix="$table_prefix" \
  -v quarantine_held_drafts=true -v backup_digest="$backup_digest" \
  -f native/mail-guard/retire-postgresql.sql
psql -X -qAt --dbname "$retirement_db" --username "$retirement_db_operator" \
  -v prefix="$table_prefix" \
  -v quarantine_held_drafts=true -v reviewed_plan="$reviewed_plan" \
  -v backup_digest="$backup_digest" -f native/mail-guard/retire-postgresql.sql
```

The archive is `<prefix>mcp_mail_guard_archive`. Its `manifest` records the plan and the backup manifest digest, which the operator verifies against the actual backup. The archive is private database state and can contain draft bodies and attachment metadata. The migration role owns the archive schema and moved tables. The script revokes non-owner schema grants and verifies the native table owner has no schema access. Use a restricted operator role and separate access controls for any backup export. Stock Mail uses the native tables. MCP's official catalog does not expose guarded receipts.

If the connection is interrupted at commit, run the read-only review to check the durable manifest before issuing another apply. An archived result with the same plan and backup is idempotent. If the update or postflight fails, leave Mail and the workers quiesced and restore the verified matched backup. The archive does not implement an in-place downgrade and cannot safely merge later stock changes into the old guarded state.

### Retirement script evidence

The script was executed on a duplicate restored from the guarded Mail snapshot. The review left the canonical database contents unchanged. Synthetic native outbox and queued-delivery rows each blocked retirement. A changed held-draft subject invalidated the prior plan and left the active and archive schemas unchanged.

An approved run moved all nine guard tables, one held draft, one recipient, one attachment record and one exact tag association. The legacy tag association remained live. The uncertain delivery receipt survived with its original state. The held draft and its children disappeared from the live Mail tables. Repeating the approved plan returned the same archive receipt. The operator checks are retained as `retirement-*` files under `mcp/build/official-apps-tmp/mcp-mail-stateful-20261125-e6f4c90a/evidence/`. A fresh PostgreSQL 16 cell reran the final SQL and additionally rejected a changed guard receipt with unchanged state counts, a different backup manifest digest, a pending erasure after its native account was already absent, and the live Nextcloud table owner's custody role. Its `rehearsal.sh`, script hash, review and apply receipts, and `final-result.json` are under `mcp/build/official-apps-tmp/mail-retirement-final-20261125-73e268c0/`.

The signed official package passed its integrity check on the retired database and read the synthetic account. Its automatic-draft and native outbox selectors both returned zero. The restored baseline had the original protected draft and child rows, original Mail migration ledger and table ownership, the nine guard tables and the same uncertain receipt. The archive schema was absent from that restored database.

## Stateful Forms result

Run `mcp-official-app-rehearsal-20261125-a73d91c4` used Nextcloud `35.0.2.2` and PostgreSQL `16.15`, pinned as `nextcloud:35.0.2-apache@sha256:c73d6b4ada5126dc56bbd6357f9c73c9caf99f4a692bd2da4187ea58b9089eec` and `postgres:16.15@sha256:b95b87e93ceb7594715d5c71fb6e09f6fc878851ab537735d617325eb9ba8b1b`. The fixture used an internal Docker network, no published ports, disabled App Store access, and no external mail or callback transport.

The tested sequence was:

1. Install the guarded Forms 5.4.2 overlay and confirm the isolated run label, guarded app version, and expected empty Forms/fence tables.
2. Seed synthetic state using OCS as two synthetic users: one form, one question, one share, and two submissions (one per user). Verify the inserted data through OCS GET endpoints; a successful OCS POST may return an empty body.
3. Snapshot the complete PostgreSQL database, Nextcloud data directory, and guarded Forms code as a matched set.
4. With maintenance enabled and the fixture stopped for the code replacement, replace only the Forms app directory with the hash-verified official 5.4.4 release archive. Run `occ upgrade`, then verify app version, OCS-readable state and database counts.
5. Restore the matching original database dump, Nextcloud data and Forms code. Restore the database with original owners (do not pass `pg_restore --no-owner`); verify `occ status`, the restored Forms version, OCS-readable state, and an identical Forms migration ledger.

The same form, question and share IDs and both submissions survived the upgrade and matched restore. Counts after restore were one fence row, one form, one question, one share and two submissions. Evidence is retained under the ignored path `mcp/build/official-apps-tmp/mcp-official-app-rehearsal-20261125-a73d91c4/`. Key files include `stateful-summary.txt`, `stateful-preflight.txt`, `stateful-official-upgrade.log`, `baseline-state-verification.json`, `official-state-verification.json`, `restored-state-verification.json`, and `stateful-snapshot/` with the matched snapshots, migration ledgers, counts and checksums. The archive SHA-256 is `bc5a343c97d0888c0106ebc8646764176f5ed088d5b9bbf20985a7a481f6a9d9`.

A separate fresh official-app functional acceptance run passed 10 integration tests and 319 assertions on the tested Forms 5.4.4 / Mail 5.13.4 pins. Its JUnit report is `mcp/build/integration/mcp-it-20261125132939-636d6cf6197b/mcp-it/artifacts/junit.xml`; the launcher is `mcp/build/official-apps-tmp/run-current-stock.sh`. The suite can be reproduced on a disposable fresh Nextcloud 35 PostgreSQL copy with Node 24.22.1 using:

```sh
MCP_IT_MAIL_GUARD=0 MCP_IT_MAIL_OFFICIAL=1 MCP_IT_PHPUNIT_ARGS='--filter McpFormsIsolationTest|FormsResponsesTest|McpMailReadTest|McpMailThreadTest|McpMailHttpTest|McpMailOfficialModeTest|FormsOfficialOcsModeTest' composer --working-dir=mcp test:integration -- 35 pgsql fresh --mail
```

This acceptance covers fresh functional behavior. It is separate from the stateful Forms upgrade/restore above and does not verify Mail account, draft, job, or rollback state.

### Harness exception

The guard migration ledger recorded the migration and its fence table existed, but its expected `(id=1, revision=0)` fence row was absent in this fixture before the OCS seed. Writes correctly refused with a missing-fence error. After asserting the exact run label, empty table and guarded Forms version, the rehearsal explicitly inserted the single seed row specified by the migration. This fixture bootstrap exception is recorded in `stateful-preflight.txt`; it is not evidence that the migration path is reliable or that a clean installation should require manual SQL. Future rehearsals must check this row before attempting writes.

The first restore attempt used `pg_restore --no-owner`, which changed object ownership and made the fixture fail its Nextcloud status check. The matched restore without that option preserved the original database owners and passed. This is why restore instructions must retain owner metadata or explicitly restore to the required original role.

## Native App Store updater

The [public App Store catalog](https://apps.nextcloud.com/api/v1/apps.json) listed Forms 5.4.4 for Nextcloud 33–35 and Mail 5.13.4 for Nextcloud 32–35 with PHP 8.1–8.5 on 2026-11-25. Both were stable. Nextcloud offers an update when the compatible catalog version is strictly greater than the installed app version. Mail 5.12.5.13 is already greater than upstream 5.12.5, so that upstream release cannot replace the local build through the updater. [Nextcloud app management](https://docs.nextcloud.com/server/stable/admin_manual/occ_apps.html) · [Nextcloud 35.0.2 installer](https://github.com/nextcloud/server/blob/v35.0.2/lib/private/Installer.php)

Run \`mcp-appstore-rehearsal-20261125-a18dfb37\` used the normal Nextcloud CLI updater and a loopback App Store mirror. The mirror carried the published app certificates, release signatures and matching official archive bytes. Only the two catalog entries and their download URLs were localized. Nextcloud performed its normal signature check. The network was internal and the fixture exposed zero ports.

- \`occ app:update --showonly\` found both releases. \`occ app:update forms\` completed from guarded 5.4.2 to official 5.4.4.
- \`occ app:update mail\` from enabled guarded 5.12.5.13 installed the official tree but failed during the \`AddMissingDefaultTags\` repair. The already-loaded guarded \`MailAccountMapper\` still referenced \`AccountVisibility\` after the installer had replaced the files. Disk held 5.13.4 while the database recorded 5.12.5.13. A fresh \`occ upgrade\` process completed migration and repair. Both installed trees matched the signed archive contents.
- The fresh process reported retained guard columns and indexes in native Mail tables. Keep them for rollback until their change and retention implications have been assessed. A clean code integrity check and a schema difference can coexist.

A second run, `mcp-appstore-disabled-mail-20261125-4d71a60f`, reproduced the two version offers and successfully completed the Mail update as three separate commands. `occ app:disable mail`, `occ app:update mail` and `occ app:enable mail` each exited successfully. The app remained disabled until explicit re-enablement. `occ integrity:check-app` and a complete file-tree comparison passed for both apps. This second cell contained no Mail accounts or held drafts.

The first transition must account for held drafts, pending account erasure and both outboxes before Mail access and background jobs resume. If the app has group restrictions, record them and restore those same restrictions when re-enabling it. Verify the app path, signed contents and recorded database version independently after either a successful upgrade or an interruption. These CLI runs exercised the native App Store installer through a local mirror. The live browser updater was outside the fixture.

The worktree retains these two runs under `mcp/build/official-apps-tmp/`. The run directory contains `run-native-appstore.sh`, native updater output and the tree comparison. The first run also contains `catalog-evidence.txt`, `after-interruption.txt` and `recovery-upgrade.txt`. A new fixture can recreate the mirror using the two matching catalog entries, their original certificates and signatures and the pinned archives served on loopback.

## Stateful Mail result

Run `mcp-mail-stateful-20261125-e6f4c90a` used guarded Mail 5.12.5.13, Nextcloud 35.0.2, PostgreSQL 16.15 and internal Dovecot. The matching backup contained one account, one held draft with one attachment, one guard delivery in `uncertain/not_started`, and the Mail migration ledger. The held draft was created by the qualified draft flow. The uncertainty was reproduced through the guard queue and a single SMTP attempt to a synthetic internal sink that captured DATA and cut the connection before acknowledgment. The sink had no external route.

After hash-verified archive replacement and a new `occ upgrade` process, Nextcloud reported Mail 5.13.4, `needsDbUpgrade=false` and a successful update. Two Mail repair steps nonetheless failed on the unexpected `mcpAuthRevision` attribute. The protected draft and attachment association and the uncertain receipt remained present. The sink recorded no additional connection or DATA after replacement. The official package retained the custom columns in the native Mail tables, so a successful version change with preserved rows was insufficient to restore Mail reads.

Restoring the matched code, database and data brought Mail back to guarded 5.12.5.13. The native account read worked again. The Mail migration ledger, protected draft and attachment association, and uncertain delivery row matched the baseline. The original database owner was preserved and the sink counters stayed at their original synthetic attempt. The stock version continued to refuse MCP's old remote mutations while official mode was active, even though its native account read was broken by the retained schema. The stateful artifacts are under `mcp/build/official-apps-tmp/mcp-mail-stateful-20261125-e6f4c90a/evidence/`.

### Archive guard metadata before native migration

The independent database copy `mcp-mail-stock-schema-20261125-346bcf1d` reproduced both failures by calling official data mappers with the same synthetic account and draft. Reading the account raised `mcpAuthRevision is not a valid attribute`. Reading the draft raised `mcpDraftOnly is not a valid attribute`. Stock mappers load complete rows and Nextcloud's entity hydrator does not recognize the added fields.

In the database copy, a PostgreSQL transaction archived the guard-specific fields by native row identity and removed those columns from the live Mail tables. The account and draft then loaded through stock mappers. The draft still had `failed=true`, both automatic draft and native outbox candidate counts were zero, and the side archive retained the original hold flag. The guard receipt stayed `uncertain/not_started`.

The clean copy `mcp-mail-stock-schema-before-upgrade-20261125-7ac6b903` applied that same archival transaction before `occ upgrade`. All three Mail repair steps ran, and the same account, draft and receipt checks passed. This exercise covered repair and mapper compatibility on the synthetic rows. The matched full backup remains the rollback unit.

A final clone, `mcp-mail-stock-stateful-appstore-matched-key-20261125-4fe708bb`, combined the first-transition sequence with the same synthetic rows and matching Nextcloud encryption settings. It disabled guarded Mail, archived the fields, installed the signed package through the loopback App Store mirror, and enabled official Mail. The native integrity, account and draft probes passed. The preserved protection flag and uncertain state matched the earlier archive probe. An initial test with unrelated instance keys reached re-enablement and failed the HMAC check. The successful run matched the source keys privately and kept them out of test output and artifacts.

The proof used seven custom fields across `mail_accounts`, `mail_mailboxes`, `mail_messages`, `mail_message_tags` and `mail_local_messages`. An implementation must preserve dependent exact-tag associations, resolve account erasure and held-draft visibility before access resumes, and account for future stock changes to rows referenced by the retained guard tables. The `archive-guard-fields.sql` in the run artifacts is a synthetic proof with fixture assertions. It is outside the deployable MCP package.

## State inventory and rollback boundary

A safe rehearsal must identify the data owner for every state family before removing any app or overlay. Nextcloud core owns the database, app configuration and app enablement records, user and group identities, files and appdata, and core comments. App migrations can add native tables and columns to the core database; app uninstall/removal does not imply those records are disposable.

The Forms overlay adds `forms_mcp_fence`, `forms_mcp_operations` and `forms_mcp_effects` native tables; the fence seed used in the rehearsal is defined by [the guard migration](../../../native/forms-guard/src/Migration/Version050402Date20261113000000.php). The wider Forms package has its own migration history. Preserve the complete database, the complete data directory (including private appdata and retained activation/recovery state), and the exact Forms code as one rollback set. Do not drop guard tables, truncate history/effect rows, prune private appdata, or delete Forms data as part of a withdrawal rehearsal. Preserve pending/uncertain effect and notification records with their linked job/outbox state; do not manually replay or infer delivery from a row's presence. Forms response history, comments and shares remain app-owned/core-linked state and must be verified after each transition.

Mail has separately owned native account, cache, local-message/draft, attachment, outbox, schedule, snooze and related migration state; see the [Mail guard deployment and rollback notes](../../../native/mail-guard/README.md). A local protected draft and its owning account need explicit verification with no conversion or send operation. Pending or uncertain delivery jobs must stay unsent and retained with their linked rows. The official Mail 5.13.4 archive used in the stateful rehearsal has SHA-256 `2859a9020e06dd9d515cb7c55f76c7ac183ffdb5761c8ccff6ffb646bbbc925f`.

### Mail holds and background jobs

- **Held drafts.** The guard sets `mcp_draft_only=true` and `failed=true` on protected drafts. Official Mail 5.13.4 excludes a draft marked failed from automatic IMAP conversion at first, but its explicit native conversion does not enforce `mcp_draft_only`. Treat protected drafts as requiring an owner decision before ordinary Mail access is restored. Keep the exact protected rows and attachment bytes in the matched archive. The new private MCP drafts store plain text and cannot preserve native HTML, attachment, alias or cryptographic state. [Guard draft lifecycle](../../../native/mail-guard/README.md#v6-native-draft-identity-and-protected-editor) · [Official local message mapper](https://github.com/nextcloud/mail/blob/v5.13.4/lib/Db/LocalMessageMapper.php) · [Official outbox service](https://github.com/nextcloud/mail/blob/v5.13.4/lib/Service/OutboxService.php)
- **Pending account erasure.** The guard excludes pending erasure accounts from ordinary account discovery and transport creation. Stock Mail lacks the overlay's filter. Resolve any live account linked to a pending erasure marker under the qualified guard, or keep it isolated before restoring access to stock Mail. Preserve the marker and related audit evidence. [Guard account visibility](../../../native/mail-guard/src/Lifecycle/AccountVisibility.php) · [Guard account integration](../../../native/mail-guard/patch-account-visibility.php)
- **Two queues.** The official MCP mode pauses its delivery and snooze workers. Nextcloud Mail's own outbox worker reads due native outgoing rows and runs independently. Reconcile both queues before workers resume. Keep `app.mail.mcp-transmission.enabled` false during replacement and rollback. Retain approved, in-flight and uncertain rows independently of their current ability to dispatch. An SMTP or Sent-copy attempt with no authoritative outcome remains uncertain and requires manual reconciliation, with no automatic replay. [MCP delivery worker](../../../mcp/lib/Modules/Mail/Transmission/DeliverySweep.php) · [Official outbox worker](https://github.com/nextcloud/mail/blob/v5.13.4/lib/BackgroundJob/OutboxWorkerJob.php) · [Guard delivery state](../../../native/mail-guard/src/Transmission/DeliveryState.php)

Start the inventory with read-only counts in the restored copy. Use the detected table prefix and the statements for the apps present in the matched copy. Compare the same row IDs and associations privately across the transition. Preserve the application secrets with the matched backup so the authoritative code can read encrypted artifacts after restore. Keep content, credentials and raw identifiers out of shared reports.

```sql
BEGIN READ ONLY;
SELECT tool_name, state, count(*) FROM oc_mcp_operations
  WHERE left(tool_name, 6) = 'forms_' OR left(tool_name, 5) = 'mail_'
  GROUP BY 1, 2;
SELECT kind, phase, count(*) FROM oc_forms_mcp_effects GROUP BY 1, 2;
SELECT count(*) FROM oc_forms_mcp_operations;
SELECT count(*) FROM oc_mail_local_messages WHERE mcp_draft_only;
SELECT type, failed, (send_at IS NOT NULL) AS scheduled, count(*)
  FROM oc_mail_local_messages GROUP BY 1, 2, 3;
SELECT state, sent_state, count(*) FROM oc_mail_mcp_deliveries GROUP BY 1, 2;
SELECT state, placement_phase, wake_phase, count(*) FROM oc_mail_mcp_snoozes
  GROUP BY 1, 2, 3;
SELECT e.state, count(*) FROM oc_mail_mcp_erasure e
  JOIN oc_mail_mcp_erase_accounts x ON x.erasure_id = e.id
  JOIN oc_mail_accounts a ON a.id = x.account_id GROUP BY 1;
COMMIT;
```

## Forms identity and revocation

An OIDC-backed user has a Nextcloud UID resolved by `user_oidc`. Share recipients and MCP grants use that UID. The Authentik account name can differ from the provisioned UID. Bind both owner and reader to distinct provisioned users, verify the authenticated UID against Nextcloud, then remove results permission while retaining submit permission. A submitter can still read their own response via Forms OCS. The MCP all-results tool must reject that same request after revocation. [Authentik identity mapping for Nextcloud](https://integrations.goauthentik.io/chat-communication-collaboration/nextcloud/)

Use a Nextcloud app password if exercising the Basic-to-OCS branch. A browser authorization yielding a bearer token scoped to MCP exercises the authenticated native reader. Keep their revocations distinct. Removing a Forms share, withdrawing MCP grants or deleting a Nextcloud app password affects a different boundary from ending the upstream Authentik session.

An isolated cell with Authentik 2025.10.4, `user_oidc` 8.11.0, Nextcloud 35.0.2, PostgreSQL 16.15 and official Forms 5.4.1 exercised two signed Authentik tokens obtained by the application's [machine-to-machine grant](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/machine_to_machine/). The issuer and client audience matched, and distinct subjects mapped to distinct Nextcloud UIDs. Bob could initially list his own response, saw both responses after receiving results permission and returned to his own response after revocation. This authenticated path went through the Nextcloud OIDC user backend and official Forms OCS.

In the same cell, Bob's Authentik bearer authenticated the Nextcloud endpoint `GET /ocs/v2.php/core/getapppassword`. The resulting Nextcloud app password authenticated Basic OCS and MCP as Bob's mapped UID. With the selected MCP Forms tools and grants, the all-results call refused submit-only access, returned Alice's response with the explicit results grant, and refused it again when the share lost results permission. The test harness prepared the Basic connection's tool selection. The network requests used the app password issued by Nextcloud. Browser sign-in and the MCP bearer authorization-code path for Authentik users remain separate qualification cases. The redacted trace summary is in `mcp/build/forms-oidc-files/result.json`.

The repository's existing [guard principal fixture](../../../native/forms-guard/tests/oidc-principal-integration.php) inserts and rolls back synthetic `user_oidc` rows to test account identity and stale-cache denial. An issuer-backed sign-in requires separate evidence.

## Direct delegated mail access

The repository pins Dovecot for the disposable IMAP fixture. It does not identify the deployed mailbox providers. Nextcloud Mail documents Google and Microsoft account OAuth as configurable options. Authentik establishes the Nextcloud user's identity. A separate mailbox grant would have its own issuer, audience, consent and revocation. [Fixture pins](../../../mcp/tests/Integration/ci/mail-pins.json) · [Nextcloud Mail administrator guide](https://docs.nextcloud.com/server/stable/admin_manual/groupware/mail.html)

| Candidate | Reads and drafts | Guarantee at a remote write |
| --- | --- | --- |
| Gmail API | `gmail.metadata` covers headers with limits on search. `gmail.readonly` covers bodies and attachments and is a restricted scope. `gmail.compose` covers drafts and sending, so use MCP-owned drafts to keep send authority absent at the provider. | Draft replacement retains the Draft ID and replaces its inner message. The documented update and send endpoints lack a conditional revision parameter. An accepted HTTP request alone cannot prove delivery. [Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes) · [Draft update](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/update) · [Draft send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/send) · [Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors) |
| Microsoft Graph | Delegated `Mail.ReadBasic` covers metadata with exclusions. `Mail.Read` covers content. `Mail.ReadWrite` permits editing and deleting mailbox messages beyond drafts. `Mail.Send` is separate. | The message model exposes `changeKey`. The draft update documentation supplies no conditional write contract. An actual provider rejection on a stale revision is required before relying on it. Send returns `202 Accepted` and remains asynchronous. [Graph permissions](https://learn.microsoft.com/graph/permissions-reference) · [Message update](https://learn.microsoft.com/en-us/graph/api/message-update?view=graph-rest-1.0) · [Message send](https://learn.microsoft.com/en-us/graph/api/message-send?view=graph-rest-1.0) |
| IMAP with delegated OAuth | Use read-only selection and `BODY.PEEK` for reads. Gmail IMAP requires the full-mail scope. Exchange Online separates delegated `IMAP.AccessAsUser.All` and `SMTP.Send`, subject to account and tenant policy. | `UID STORE (UNCHANGEDSINCE ...)` can fence eligible flag mutations when the selected mailbox supports CONDSTORE and reports a usable MODSEQ. That safeguard does not extend to draft replacement, MOVE or SMTP. Keep those operations in the native interface. [Gmail XOAUTH2](https://developers.google.com/workspace/gmail/imap/xoauth2-protocol) · [Exchange OAuth](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth) · [RFC 7162](https://www.rfc-editor.org/rfc/rfc7162.html#section-3.1.3) |

The first stage uses stock Mail reads and MCP-owned drafts without extra provider credentials. The Mail read adapter still calls Nextcloud Mail services. Its compatibility evidence applies to the tested app/server versions. Reuse the stock integration suite when qualifying a newer App Store release. Before any direct integration, confirm the provider for each eligible account and validate an authorization-code grant with per-account ownership, the smallest usable scopes, two synthetic identities, exact account selection and denied reads after revocation. Bind credential storage to the current MCP actor and provider subject, revoke local access immediately, and remove the stored grant on disconnect. Provider token invalidation is separate and can lag. [Google revocation](https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevocation) · [Microsoft Entra revocation](https://learn.microsoft.com/en-us/entra/identity/users/users-revoke-access)

A local approval can bind the reviewed bytes and authorize one attempt. A server-enforced revision condition is required to protect a mutable remote object from concurrent edits. An authoritative provider outcome or a documented idempotency key is required before safely resolving or retrying an ambiguous remote operation. Until those properties are qualified for the exact endpoint, the MCP keeps remote mutations disabled.

## Rehearsal procedure for a restore copy

Use a new uniquely labeled fixture and synthetic accounts. Keep all Docker services on an internal network with no published ports, disable App Store access and mail transports, and avoid credentials or real user data. Fetch exact official archives outside the fixture and verify their published/reviewed hashes before use. Record the image digests, app versions, source overlay/archive hashes, database provider, run label and artifact paths.

Before mutation, assert that every container/network has the exact run label, the network is internal, no ports are published, the guarded app version matches expectations, and the fixture has only the expected synthetic data. Record schema/migration ledgers and counts for all relevant app tables. Create and checksum a matched database + data + app-code snapshot. Keep the snapshot private and do not mix snapshots from different points in time.

Quiesce requests, cron/background workers and app-specific jobs in the copy. Enable maintenance mode. Replace only the target app's code with the verified official archive and run the ordinary Nextcloud migration command. Start a fresh process and verify `occ status`, installed app version, migration ledger, app-specific API reads and table counts. Inspect pending and uncertain jobs without dispatching them. Any state mismatch stops the rehearsal; preserve the failed copy and evidence for diagnosis.

For rollback, keep the copy quiesced and in maintenance mode. Restore the database with its original ownership, restore the matching data directory and exact prior app code, then start a fresh process. Verify core status, app version, migration ledger equality, state counts, permissions and the same API-visible records. Do not delete native tables or data to make an older version start. Retain both the failed-upgrade copy and the matched restored evidence until review is complete.

The evidence applies to the pinned synthetic copies. The Mail upgrade and restore cover one held draft and one uncertain receipt. Pending native sends, account erasure, exact-tag associations and held-draft visibility still require an end-to-end retirement policy before using the procedure with a real stateful copy. Preserve the exact settings and encryption keys with the matched backup to keep encrypted account and operation data readable.
