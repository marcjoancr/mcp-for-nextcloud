# Mail C0 native capability and lifecycle evidence

Status: C0 characterization/gates implemented on top of A/D; final checks recorded with the delivery. No message-mutation or send tool is added.
Baseline: MCP 2.7.0, prior commit `8ded3dd5`, A `90fe762d`, D `f59d5fc1`.
Native fixture: Nextcloud35.0.2 (internal35.0.2.2), MariaDB10.11, Mail5.12.5, pinned Dovecot2.4.5. Archive/image integrity is checked by the existing native runner. Other runtime pairs remain unverified for these new capability claims.
Plan: [C0 in the completion plan](../plans/2026-11-07-mail-completion-plan.md).

## Delivered boundary

`mcp/lib/Modules/Mail/Integration/MailCapabilities.php` provides `forUser(string $uid): array` and `requireSupported(string $uid, string $capability): void`. It reads only per-user Mail availability and normalized installed versions; it never creates IMAP/SMTP clients, performs lifecycle experiments, stages writes or grants permissions. Unsupported, unavailable and unverified paths fail before any effect. The existing 23-tool catalog, grants, A/D flows and availability rules remain intact.

This is evidence about native paths, not authorization. A future caller still needs current tool/connection permission, owned account/resources, a fresh effect proof and journal admission. Native version matching alone never admits the pending mutation paths. Unknown versions fail closed for these paths while ordinary A/D discovery remains under its existing policy.

## Capability matrix

| Capability | Characterized35.0.2 / Mail5.12.5 | Consequence |
| --- | --- | --- |
| Parent/owned alias on a local unsent draft | Verified with native sync hold | A's local guarantee remains. |
| Reply parent after native IMAP draft flush | Unsupported | The generated IMAP draft omits In-Reply-To and References. |
| Selected alias after native IMAP draft flush | Unsupported | Native saveLocalDraft builds From from the account identity. |
| Native UI edit preserves MCP sync hold | Unsupported | Native draft update releases failed=true, allowing later IMAP conversion. |
| Generic raw-client mutation callback | Unsupported | Native store can authenticate/reselect without accepting the expected UID generation. |
| Message flags, move, labels | Blocked | A generation-safe execution/cache boundary must be verified first. Native method existence is insufficient. |
| Folder create/rename/subscription and refresh | Unverified for MCP mutations | Native sync side effects, changed cached identities and complete wall-clock budgets need separate proof. |
| SMTP submission, scheduling and enqueue | Disabled | User instruction; independent of native version. D authentication-only probes remain allowed. |

On other pairs the new paths report unverified (or unavailable when Mail is off), never implicitly supported. The matrix is not a claim that all admitted Mail>=5 installations share these behaviors.

## Native experiments and protected risks

`mcp/tests/Integration/Mail/MailLifecycleActor.php` is reachable only through the disposable fixture's Mail actor, never through production tools. `McpMailLifecycleTest.php` drives it alongside real MCP A composition.

1. Create an approved reply draft with an owned alias through the actual HTTP MCP writer. Inspect the local parent, alias and failed=true hold. Reopen/update through the native DraftsController, then exercise **only native local-draft-to-IMAP serialization**. Its misleadingly named DraftsService.sendMessage uses MailTransmission.saveLocalDraft and a NULL MIME transport, not SMTP. Fetch actual IMAP envelope/header data with a control Subject/From assertion, inspect missing parent headers/account sender and remove only the generated fixture draft/folder.
2. Select a uniquely named fixture mailbox, capture its UID generation and first message, log out, recreate only that generated mailbox, and append new content with the same UID/Message-ID. The same native object can select the new generation; native MessageMapper.addFlag -> store can also reauthenticate/reselect after logout. A second message with the same Message-ID shows why that header cannot authorize a target or prove move correspondence. C0 refuses mutation admission instead of pretending the raw-client callback is safe.
3. Compare low-level native flag storage with the supported high-level event path against an owned fixture message. A raw mapper operation does not itself provide the complete native cache/event contract. Record the observed cache behavior and restore the original flag through native APIs. The normal MailManager path creates its own client, so it cannot simply inherit an earlier external generation check.

All experiments compare native outgoing-message identities and the authentication-only SMTP command capture before/after. They dispatch no outbox worker, SMTP envelope/DATA or MDN. Mailbox/message cleanup is limited to these generated disposable test artifacts; no production cleanup policy or Files behavior is changed.

## Why MailMutationSession is not manufactured

The proposed `run(..., Closure $effect)` would hand an unrestricted native client to its callback. A SELECT/UIDVALIDITY check at callback entry cannot guard later internal login/reselection, a different MailManager client, or cache/event work done after the command. The native APIs inspected expose no caller-supplied expected generation at that mutation boundary. Socket/read timeouts also do not prove a complete callback wall-clock budget.

The approved plan explicitly says to avoid unused mutation wrappers and refuse unsupported boundaries. Accordingly C0 does not introduce a placeholder runner that appears usable, nor a new transport, protected-method reflection, copied credentials or SQL patches. C1/C3 depend on a supported guarded execution contract or a separately reviewed revision that avoids exposing a raw client. This is a safety gate result, not a claim that every conceivable native integration is impossible.

## Sources and verification

Read from the exact pinned official Mail archive, and exercised in the native fixture:

- `lib/IMAP/HordeImapClient.php`, `lib/IMAP/IMAPClientFactory.php`.
- Bundled `vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/{Base,Socket}.php`: public openMailbox/store and connection/selection behavior.
- `lib/IMAP/MessageMapper.php`: addFlag/removeFlag/move.
- `lib/Service/MailManager.php`: own-client flag/move flows and native event dispatch.
- `lib/Service/DraftsService.php`, `lib/Service/MailTransmission.php`, `lib/Controller/DraftsController.php`: hold release and local-to-IMAP conversion.

No additional production native symbol is used by the C0 metadata service, so there is no reason to regenerate or broaden static API surfaces merely to change fixture files. Existing generator/pins, read-generation tests and A/D native fixtures remain the verification base.

C0 test/operational evidence is kept with the delivery's execution log and committed tests. Recharacterize before changing a supported-pair claim or enabling a blocked capability. Sending remains outside the current completion plan.

## Proposed follow-up (2026-11-08)

The [safe mutation guard design](2026-11-08-mail-mutation-guard-design.md) and [plan](../plans/2026-11-08-mail-mutation-guard-plan.md) replace the unimplemented raw-client callback proposal with closed operations and identify native dispatch, COPYUID generation and cache-fence dependencies. They do not change this executed matrix: C1/C3 remain blocked, and sending remains disabled by user instruction.

The [C1 delivery record](2026-11-08-mail-c1-guard-evidence.md) subsequently adds a separately fingerprinted native flag boundary. It does not make stock Mail or the generic raw callback safe, and does not unlock moves or sending.
