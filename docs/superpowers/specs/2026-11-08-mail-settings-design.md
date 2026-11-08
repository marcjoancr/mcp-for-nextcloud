# Mail accounts, signatures and connection settings

Status: Planning extension requested on 2026-11-08; not implemented.
Parent design: [Mail completion](2026-11-07-mail-completion-design.md).
Implementation baseline: MCP 2.7.0 plus the reviewed Release A changes; start from that implementation line, not this older documentation checkout.
Native reference: nextcloud/mail v5.12.5. Support for other pinned pairs needs separate capability evidence.

## Intent and decisions

Complete the owner's Mail configuration workflow: inspect settings, edit signatures and preferences, add or remove an account, configure IMAP/SMTP, and inspect write outcomes before repeating an action.

The user chose to reuse the native Mail assistant for credentials. Passwords, OAuth consent and tokens stay in native Mail. MCP schemas, arguments, previews, logs, operation records and URLs never accept or return those values. No custom credential form, password vault or parallel account authentication stack is added.

The selected approach is a native-service adapter for ordinary settings plus a native-UI handoff for account setup and connection/authentication changes. A fully MCP-owned setup API was considered and rejected because it would put credential handling into the model/tool path. Returning an untracked Mail link alone was rejected because it would not provide observable completion or protection against blind repeats.

This is Release D of the master plan. It depends on Release A's journal and authorization boundaries, not on Release B sending or Release C mailbox mutations, and may be implemented before either of those releases. The current request updates planning only.

## Verified implementation facts

- `AccountService.find(uid, id)` resolves owned accounts; `updateSignature(id, uid, signature)` updates account signatures. `AliasesService.find/updateSignature` provides the corresponding alias boundary.
- `AccountService.save/update` persists native account entities and invalidates caches. Settings changes must patch an already loaded owned entity and preserve unrelated fields.
- `AccountsController.patchAccount` implements editor mode, signature placement, subscription display, special folders and other account preferences. These controller-side checks are not automatically inherited by calling services.
- `AccountsController.create` checks `ALLOW_NEW_MAIL_ACCOUNTS` and validates IMAP/SMTP hosts through `IRemoteHostValidator` before calling `SetupService.createNewAccount`.
- `AccountForm.vue` is used both by `Setup.vue` for account creation and by `AccountSettings.vue` for server configuration. The latter hides the server form for provisioned and delegated accounts. It supports password and provider OAuth workflows.
- `mail.page.setup` is the verified server route `/setup`, relative to the Mail app; the frontend router also declares it. There is no verified account-settings deep link in that router. For an existing account, link to Mail and give precise account/settings navigation instructions until a supported deep link is proven; do not invent URL parameters or inject code.
- `EditorSettings.vue` uses native `plaintext` and `html` editor modes. `AccountForm.vue` exposes `ssl`, `tls` and `none` connection modes; MCP proposals require `ssl` or `tls` and never silently disable certificate validation or downgrade an existing configuration.
- `AccountService.testAccountConnection` uses the IMAP client only. It is not evidence of SMTP connectivity. Native `SetupService.testConnectivity` covers IMAP and SMTP but is protected; an adapter must not call it through reflection or treat it as a public API.
- `AccountsController.update` routes through `SetupService.createNewAccount`; reuse of that path for direct MCP patching is not assumed safe for omitted settings or credentials. Connection changes are completed in the native account form.

Primary implementation references:

- [AccountService](https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/AccountService.php)
- [AliasesService](https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/AliasesService.php)
- [AccountsController](https://github.com/nextcloud/mail/blob/v5.12.5/lib/Controller/AccountsController.php)
- [SetupService](https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/SetupService.php)
- [Native routes](https://github.com/nextcloud/mail/blob/v5.12.5/appinfo/routes.php)
- [Frontend router](https://github.com/nextcloud/mail/blob/v5.12.5/src/router.js)
- [Setup view](https://github.com/nextcloud/mail/blob/v5.12.5/src/views/Setup.vue)
- [Account form](https://github.com/nextcloud/mail/blob/v5.12.5/src/components/AccountForm.vue)
- [Account settings](https://github.com/nextcloud/mail/blob/v5.12.5/src/components/AccountSettings.vue)
- [Editor settings](https://github.com/nextcloud/mail/blob/v5.12.5/src/components/EditorSettings.vue)

## Capabilities and independent permissions

All names below are proposed. Every new tool needs an explicit, denied-by-default assignment and current connection selection. Existing `mail.read`, draft, send and status grants do not grant configuration access.

| Proposed tool | Permission | Effect |
| --- | --- | --- |
| `mail_read_account_settings` | `read_settings` | Read allowlisted preferences, native editability and an opaque revision. |
| `mail_write_account_settings` | `write_settings` | Confirmed patch of allowlisted preferences; preserve omitted fields. |
| `mail_read_signature` | `read_signatures` | Read the selected account or owned alias signature. |
| `mail_write_signature` | `write_signatures` | Confirmed replacement or explicit removal of that signature. |
| `mail_read_connection` | `read_connection` | Read non-secret IMAP/SMTP host, port, TLS mode, username, auth-method label and native editability. |
| `mail_configure_connection` | `configure_connection` | Approve non-secret desired changes and return the native account-form handoff. No direct transport mutation. |
| `mail_test_connection` | `test_connection` | Explicit, bounded IMAP and/or SMTP authentication/connectivity probe; no message is sent. |
| `mail_add_account` | `create_accounts` | Approve account intent and return the native setup assistant. Native Mail creates the account. |
| `mail_complete_account_setup` | `complete_setup` | Confirm a specific owned native result and durably bind it to a pending workflow; does not create/update a native account. |
| `mail_delete_account` | `delete_accounts` | Confirmed removal of an owned native account after its local effects are proven and shown. |
| Existing `mail_get_operation` / `mail_list_operations` | Existing `read_operations` | Read minimal receipts, additionally checking current original-action permission. |

`mail_complete_account_setup` additionally requires the original workflow tool to remain allowed and selected, plus `read_connection` to review the actual profile. It cannot attach a result to a foreign user/connection or to an expired workflow. Reading a receipt after completion still requires its original action; a completion receipt also checks access to its source workflow.

D1 preferences are deliberately bounded: `editor_mode` (`plaintext` or `html`), `signature_above_quote` and `show_subscribed_only`. Account name/email and IMAP/SMTP identity changes go through the native account form. Future folder/retention/preferences keys require explicit capability additions and owned-folder checks; this is not a generic arbitrary native-config setter.

Signatures use `account_id` and optional `alias_id`, with a maximum of 20,000 characters; `signature: null` explicitly removes one, and omission never means removal. The full proposed signature is escaped in the confirmation renderer; HTML is untrusted content, not executable MCP UI. Account-wide signature placement remains in account preferences, not in an alias-only field. Native signature application remains Mail's responsibility: prove when composition applies it and avoid appending a second copy in MCP.

Connection proposals accept only non-secret `account_id`, host/port/TLS/username fields and an `auth_method` label (`password` or `xoauth2`). Host and username limits are 255 and 500 characters respectively, ports 1..65535, and header/control characters are refused. Partial proposals preserve omitted values for review; no raw native account serialization is returned. Native provisioned/delegated settings and the administrator's account-creation policy are enforced before a handoff is offered and before result binding.

## Native assistant and observable completion

1. A preview reads owned, redacted state and shows the requested effect. It performs no native writes, network authentication, browser navigation or credential staging.
2. Confirmation repeats the server-generated `operation_id`, `plan_state` and unchanged effect. Reserve before an MCP write or persistent handoff. Store only bounded IDs, keyed digests, workflow kind and expiry.
3. For direct preference/signature/delete actions, write once via native services, read back and finish the normal journal receipt. Repeats return that receipt.
4. For account setup or connection changes, return a same-origin Mail link and `state: awaiting_user`, `native_state: unverified`. Generate URLs through Nextcloud's URL generator, with no passwords, tokens, workflow IDs or unsupported prefills in query strings. The user explicitly opens native Mail and completes its own form/consent. Opening a link is not proof of a write.
5. The user supplies the resulting `account_id` to `mail_complete_account_setup`. Its preview reads the exact owned account and shows its non-secret observed configuration beside the requested one. For creation, the ID must not be in the pre-handoff owned-ID set. For reconfiguration, it must be the originally approved account. Never guess by email, subject or timestamps; concurrent/duplicate accounts require explicit selection and are never auto-merged/deleted.
6. Completion confirmation binds that account's current secret-free revision and the source workflow's immutable intent; repeat it after any revision change only with a new plan. Supplied requested fields must match; discoveries in unspecified fields require the full observed configuration to be reviewed. Different requested values require a new workflow instead of silently changing the original effect. The observed profile must also satisfy the supported TLS/host policy, even when discovery supplied initially unspecified fields.
7. Only a proven observed result completes the workflow: `state: succeeded`, `native_state: observed_in_mail`, `source: native_ui`, minimal `account_id`. This proves the reviewed native configuration exists, not that MCP created exactly one account or that authentication/delivery succeeded. Credential-only/OAuth completion needs an observable native authorization signal or an explicit connection probe under its separate permission; without evidence it remains unverified.
8. Status lookup/listing is read-only: it never opens the assistant, binds a result, probes a server, retries account creation, refreshes OAuth or sends a message. Pending/ambiguous external work remains `awaiting_user`/unverified, or becomes `uncertain` when an admitted effect may have occurred without a verifiable result.

The initial approval ID expires after 15 minutes as in A. An admitted native-UI workflow may wait for the user for 24 hours and is not treated as a stale running worker. Waiting releases the MCP mutation claim; confirmation of result binding obtains a new short-lived claim and rechecks both workflow and native revision. After 24 hours its workflow becomes expired/unverified: never interpret that as failure of the native account creation, reopen it automatically, undo native changes or delete a resulting account. Status may report expiry from the timestamp without mutating the row. Any new attempt starts with inspection and an explicitly new approved intent.

MCP deduplicates its own write/admission/completion operations. Native Mail's form remains independently usable and can submit from multiple tabs; this design does not claim global exactly-once account creation or impose MCP permissions on independent native UI activity.

## Architecture and journal extension

- `Mail/Settings`: strict redacted projections, revisions and allowlisted preference patches.
- `Mail/Signatures`: account/alias signature ownership and complete confirmation rendering.
- `Mail/Accounts`: native-assistant handoff, setup-result binding and owner-scoped account removal.
- `Mail/Connection`: redacted connection profiles and explicitly requested native-client probes.
- `Mail/Integration`: lazy native account/alias/client adapters, route generation and per-capability evidence. Reuse native host validation, provider restrictions and administrative policies; do not call controllers internally.
- `Mcp/Operations`: reuse A's signing, scoping and non-replay reservation. Extend target identities beyond draft/path to `account:<id>` and actor-scoped new-account intent. Add an admitted `awaiting_user` workflow distinct from a running native writer.
- Keep pending UI intent in a bounded MCP-owned workflow record linked to the original receipt: kind, minimal before-account IDs, fixed target ID if any, keyed requested-effect digest and expiry. Never store native account objects, signature bodies, passwords, tokens or cleartext connection profiles there. Completion repeats non-secret intent to verify its digest; the current observed profile is bound by its fresh plan proof.
- Extend public receipt references with `account_id`; internal workflow IDs/before-ID sets are never download or authentication handles. Reuse `mail_get_operation` and `mail_list_operations`; do not add a competing status store or silently reuse mail sending states for setup.
- Normal receipt metadata follows A's 30-day compaction/non-replay key policy. Expiring a UI workflow never triggers native cleanup. Account removal never removes uncertain write history or turns an old operation ID into permission to act on a recreated account.

## Connection probes and removal boundaries

A probe is an explicit diagnostic operation, separately allowed and selected; it never runs as part of status lookup or a settings preview. Apply native `IRemoteHostValidator`, provisioned-account rules and administrative policies before network access. Use native IMAP/SMTP client factories with normal TLS/certificate checks. Never issue SMTP `MAIL FROM`, `RCPT TO` or `DATA`, or issue a message-send/outbox call. Return separate IMAP/SMTP `ok`, `failed`, `unsupported` or `unknown` results with sanitized error categories, not raw server transcripts or authentication payloads. Never infer SMTP success from the IMAP-only native helper. Proposal budget: 10 seconds per protocol, 20 seconds total and 5 probes per user/account per minute. Prove native timeout enforcement; return unsupported rather than implement a parallel transport if it cannot be bounded. Probes are not replayed by status or by repeated invocation using the same operation ID.

Account removal must show the concrete native local impact on identities, cached mail, local drafts and background work as established by the compatibility tests. It never calls remote IMAP deletion/expunge or deletes Files sources. Native references owned by ongoing/uncertain operations block automatic cleanup; uncertain history remains readable under the existing authorization contract. If native account removal cannot preserve required data or the impact cannot be shown accurately, keep the tool disabled and direct the owner to native account settings.

## Required acceptance evidence

- Disabled Mail, denied grants, changed connection selection, foreign/delegated accounts and provisioned profiles are refused appropriately; ordinary reads and draft grants never authorize settings.
- Patch one preference or signature without changing the other preferences, alias, security requirements or encrypted auth state. Alias/account mismatches are refused and HTML is displayed as text in approval.
- No secret is accepted by a schema, copied into a plan/journal/URL or returned by a native serialization/error path. Test password, OAuth and provider-error sentinels.
- Opening/cancelling/repeating an assistant link never creates a second MCP-driven account or claims success. Multiple independently created native accounts are not guessed or auto-deleted.
- Explicit result binding checks account ID, actor/connection, intent digest, expiry, current original permission and a fresh native revision; lookup performs zero native writes/probes.
- IMAP success plus SMTP failure reports two distinct results and sends zero messages; strict budgets and host policy apply to both.
- An account deletion preview reflects measured native impact; completion/timeout never guesses that native removal failed and never re-creates an account.
- Run the pinned native UI/account/probe tests and API compatibility fixtures, then the repository check before exposing D. No production accounts, credentials or external test delivery.
