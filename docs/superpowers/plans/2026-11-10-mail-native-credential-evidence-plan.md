# Native Mail Credential Evidence Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline, TDD and one fresh final review. Keep mutable harness records external and preserve the selected worktree.

**Goal:** Complete unchanged-profile setup from proven native manual credential writes without moving credentials into MCP or enabling sending.
**Architecture:** v7 extends the pinned native assistant with operation-bound handoff, hidden account revision and one-shot native proof. Existing MCP workflow/journal owns approval, completion, replay and status.
**Tech Stack:** PHP8.4+, Mail5.12.5, NC35.0.2/MariaDB, PHPUnit and existing disposable HTTP/Chromium/TLS native fixtures.
**Spec:** [native credential evidence design](../specs/2026-11-10-mail-native-credential-evidence-design.md).

## Global constraints

- Start from 0340439929998f5c4747bcfb1820f261795eb0f6 in the selected worktree.
- Keep 41 tools, all original grants, native credential ownership, Files originals, draft protections and MCP sending disabled.
- No password/code/token or secret hash in MCP or new proof storage. Explicit save observes a write, not secret inequality or authentication.
- Preserve immutable v6 overlay/manifest and its prior qualified scopes; v7 custom version 5.12.5.7. Initially only NC35.0.2 primary InnoDB, no replicas/shards.
- Native handoff/proof lifetime <=24h, OAuth state <=600s, at most 100 pending contexts per owner. No background refresh/diagnostic/page-visit proof.
- No installation, push/PR/release/worktree cleanup. Native cells serial; freeze sources while native snapshots/tests run.

## Review focus

1. Native OAuth finishConnect returns unchanged old tokens on provider error: no proof or completion.
2. Earlier state or another tab/context/owner/account: exact original binding, no proof reassignment.
3. Secret save succeeds but evidence/commit acknowledgment fails: uncertainty, no credential retry.
4. Credential revision changes or permission is revoked before the current request-wide grant snapshot: refusal. A later revocation can allow the admitted request to finish; this preserves the existing snapshot contract.
5. Stock/v6, schema/provider or source tampering: new proof closed, prior features retain scope.

### Task 1 — Characterize failure and pin native contracts

**Files:** tests/Integration/Mail/McpMailCredentialEvidenceTest.php, MailCredentialEvidenceActor.php; native source inspection; unit setup/proof tests.
**Consumes:** Existing MailAccountSetup, NativeAccountSetupLinks, journal/workflow and TLS native cell.
**Produces:** RED for unchanged-profile manual save completion; source-backed expected old-token failure behavior.

- [x] Verify pinned core cookie emission supplies HttpOnly/Secure/webroot scope (NC35 App.php167–175); no credential form replacement.
- [x] Add native scenario: actual browser login, native PUT with CSRF and accepted synthetic same-value password, unchanged profile/no-send, then MCP completion preview. RED8757ba844354 fails exactly on completion preview.
- [x] Add unit expectations for no proof from diagnostic/abandonment and provider failure with old tokens; run RED.

### Task 2 — Native account revision and correlated handoff

**Files:** native/mail-guard/patch-credentials.php, src/Db/CredentialRevision.php, src/Authorization/{SetupEvidence,SetupContext,ManualAuthorizationPermit}.php, src/Migration/Version5125Date20261110200000.php; build.php, drafts-v6-manifest.json.
**Interfaces:** SetupEvidence.register(uid, scope, sourceOperation, kind, ?accountId, intent, expires): string; activate(uid, handle): array; observe(uid, handle, accountId, scope, sourceOperation): ?array; describe(uid, handle, scope, sourceOperation): ?array; claim(uid, handle, accountId, revision, scope, sourceOperation, completionId): void. APIs expose no credential/client.
**Consumes:** Existing native owner coordinator and primary DB; pinned native account/controller/provider/state APIs.
**Produces:** Hidden mcp_auth_revision plus private context/state tables and one-shot proof.

- [x] Unit RED: contexts re-register identically, refuse changed binding/foreign/expired/over-limit; unrelated updates do not advance revision.
- [x] Implement native revision atomically with auth/identity changes, owner coordination and no JSON exposure.
- [x] Implement bounded native context/state lifecycle and primary connection/transaction checks.
- [x] Patch native Page handoff/cookie and exact password create/update paths. Record after successful persistence; bind create only from create route.
- [x] Patch generateState/manual provider success branches with transient accepted permit; record after persistence, never on unchanged return/refresh.
- [x] Native RED→GREEN for actual unchanged-profile save, diagnostic-only refusal and old-token provider failure. Keep new MCP qualifier closed.

### Task 3 — MCP workflow and atomic completion

**Files:** mcp/lib/Modules/Mail/Integration/{NativeSetupEvidence,MailNativeBoundary,NativeGuardManifest,MailServices,NativeAccountSetupLinks}.php, Accounts/MailAccountSetup.php, mcp/lib/Db/MailSetupWorkflowsMapper.php, mcp/lib/Migration/Version002700Date20261110200000.php, Application.php; unit/API/architecture tests.
**Consumes:** Task2 native API, existing private workflow/journal/confirmation.
**Produces:** Optional native_context_id; qualified handoff and proof-based completion of unchanged profiles.

- [x] Unit RED for valid correlated unchanged-profile completion; no proof, changed revision, expired/foreign/revoked source, race and lost commit must refuse or retain uncertainty.
- [x] Add optional workflow field without breaking legacy rows/fixtures; inject a closed Integration adapter and current source/schema/server gates.
- [x] Begin registers only inside approved journal admission; status/replay never register again.
- [x] Include non-secret native proof summary in completion plan. Claim proof and native revision under the same primary transaction as workflow/source completion.
- [x] Preserve changed-profile/create behavior without inventing proof; diagnostics remain separate.
- [x] Run focused setup/operation/API/architecture and both static scopes GREEN.

### Task 4 — Native browser/provider and final regression

**Files:** native credential actor/test and ci TLS fixture/driver; native README, public Mail docs, master plan and delivery evidence.
**Consumes:** Candidate Task2/3 implementation and immutable v6 artifact.
**Produces:** Qualified v7 only if guarantees pass; reviewable local delivery.

- [x] Actual browser credential save into native forms, unchanged non-secret profile, reviewed completion/replay/status, no SMTP submission/outgoing effect.
- [x] Actual native Google/Microsoft state/callback/token persistence against loopback TLS provider fixtures; provider error with valid old tokens, old/reused/expired/cross-owner state, newer-context substitution and automatic refresh all give no manual proof.
- [x] Test native persistence/proof/ack failures and atomic completion/revocation/revision races; no native credential resubmission.
- [x] Run full guarded Mail/browser and stock serially; then enable proof qualifier and rerun enabled-source acceptance. Preserve immutable v6 gate/tamper scopes.
- [x] One fresh final review: one Important originating-page attribution finding, no Critical or Minor; qualifier closed pending correction.
- [x] Add per-page native request binding and actual browser RED→GREEN for password save, late OAuth state and account creation across two MCP connection scopes.
- [x] Run complete devbox check with Node24/project TMPDIR. One fresh final review and one consolidated fix pass; document limits and remaining compatibility.
- [x] Local commit, clean-source package and normalized native artifact/hashes. No deployment/publication.

Candidate verification may register a native-only test context while the new MCP qualifier remains closed. Final enabled-source acceptance must use the actual MCP-issued context/link without manual arming. The v6 artifact remains immutable.

## Progress evidence (candidate; not qualification)

Native password candidate 2d83b32db139 accepted an actual unchanged-profile native
save while MCP completion remained closed. MCP completion tests now cover a
single claim, current revision and source grants, shared rollback, and lost
acknowledgement after a successful commit. Mail/architecture: 260 / 24,311;
API contracts: 3,191 / 7,152; both static scopes pass. These focused results do
not qualify OAuth or replace final native acceptance. Browser candidate
146734ba1241 stopped at an incorrect native menu selector before submitting
credentials; the selector is corrected from the shipped component implementation.
New native candidate acceptance is pending. Sending remains disabled.

Enabled-source acceptance now passes through actual MCP-issued handoffs without
initial manual context arming: native Mail 56 / 1,412 plus plain/HTML and all three
credential browser workflows. Stock: 17 executed / 39 expected skips / 482
assertions. Immutable v6 source compatibility: 1 / 31. Full catalog before the
final status-only correction: 10,370 / 95,487 and 837 browser tests. Final
revalidation/review/artifact state is in the [delivery evidence](../specs/2026-11-10-mail-native-credentials-evidence.md).

Final review correction and regression are complete: actual three-route late-tab
RED b8bba97be475 → GREEN 22c562ce8fb7; enabled acceptance d3beb4bf932f
passes 56 native / 1,412 assertions, plain/HTML and all MCP credential workflows
without candidate arming. Stock cef682bb175b passes 17 executed / 39 expected
skips / 482 assertions; immutable v6 compatibility passes 1 / 31. The final
catalog passes 10,371 PHP / 95,499 assertions and 837 browser tests, both static
scopes and packaging. Local source/package identity follows in the external
execution record. Broader compatibility is a separate continuation. Sending is
disabled.
