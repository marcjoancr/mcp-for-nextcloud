# Mail Account Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add owned-account settings, signatures, native-assistant account setup/IMAP/SMTP configuration, separate permissions and observable write outcomes.
**Architecture:** Reuse A's operation journal and lazy native Mail adapters. MCP writes allowlisted preferences/signatures and admits native-UI setup workflows; native Mail alone captures credentials and performs account setup. Explicit result binding verifies observed state without claiming control over independent native UI submissions.
**Tech Stack:** Existing PHP/Nextcloud app, native Mail services/UI, Nextcloud DB abstractions, PHPUnit and the existing browser/native test harness.
**Spec:** [Mail accounts, signatures and connection settings](../specs/2026-11-08-mail-settings-design.md).
**Status:** Plan update requested 2026-11-08; none of the tasks below are implemented by this document.

## Global Constraints

- Start from MCP 2.7.0 plus the reviewed Release A implementation; the planning checkout is based on older source and is not an implementation base.
- Reuse the native Mail assistant for passwords and OAuth; never add secret input fields to MCP schemas, logs, URLs or receipts.
- Support only owned accounts. All new tools start denied and require explicit connection selection. Respect administrator creation policy and native provisioned/delegated editability.
- All write previews are effect-free. Confirmation binds the same `operation_id`, approved effect and fresh `plan_state`; status lookup never writes, probes, opens UI or retries.
- D1 preferences are exactly `editor_mode` (`plaintext`/`html`), `signature_above_quote`, `show_subscribed_only`. Signature limit is 20,000 characters; null explicitly removes it.
- Connection proposals use 255-character hosts, 500-character usernames, ports 1..65535 and `ssl`/`tls`, with `auth_method` `password`/`xoauth2`; refuse controls and unknown keys. Actual transport/auth changes are completed in native Mail.
- Initial approval expires after 15 minutes; admitted UI workflow expires after 24 hours as unverified, not as proof of native failure. Apply A's 30-day metadata compaction/non-replay policy.
- Native probes: 10 seconds per protocol, 20 seconds total, 5 per user/account per minute; send no SMTP message and preserve TLS validation.
- No controller-to-controller calls, direct native SQL mutations, new credential store, standalone sender, global provisioning or production delivery.

## Review Focus

1. An account form was opened but abandoned, repeated or completed in another tab: never infer creation/completion or duplicate a native action (Tasks D3/D4).
2. Native account JSON/errors contain encrypted passwords, provider URLs or tokens: strict output allowlists and secret-sentinel tests (Tasks D1/D5).
3. Signature/preferences/connection edits overwrite omitted auth/security fields or a provisioned identity: patch loaded native entities and refuse unsupported changes (Tasks D1/D2/D4).
4. The native account test succeeds for IMAP while SMTP fails: independent bounded probes and zero SMTP send commands (Task D5).
5. A result arrives after revocation/expiry or names an account recreated under similar metadata: exact IDs, explicit selection and source-workflow checks (Tasks D3/D4/D6).

## File structure and contracts

Create small services under `mcp/lib/Modules/Mail/{Settings,Signatures,Accounts,Connection}` and lazy native adapters under `Integration`. Register tools in `MailToolCatalog.php`/`AppInfo/Application.php`. Reuse `MailOperationReader.php`, `OperationJournal.php`, `OperationStore.php` and `OperationsMapper.php`; extend their contracts explicitly instead of adding a second operation status mechanism.

Proposed service interfaces, with ordinary typed value objects for the named DTOs:

- `MailSettings.readOwned(string $uid, int $accountId): AccountSettingsSnapshot`; `previewPatch(string $uid, int $accountId, array $changes): array`; `write(array $args, string $uid): array`. Snapshot contains only D1 preferences, editability and opaque revision.
- `MailSignatures.readOwned(string $uid, int $accountId, ?int $aliasId): SignatureSnapshot`; `preview/write(array $args, string $uid): array` (two methods). Snapshot contains selected identity, signature and opaque revision, never native credentials.
- `MailConnection.readOwned(string $uid, int $accountId): ConnectionSnapshot`; `probe(array $args, string $uid): array`. Snapshot is the spec's allowlisted non-secret profile; result has separate protocol statuses and categories.
- `MailAccountSetup.preview(array $args, string $uid): array`; `begin(array $args, string $uid): array`; `previewCompletion(array $args, string $uid): array`; `complete(array $args, string $uid): array`. Begin returns awaiting-user receipt plus same-origin native Mail URL. Completion takes `setup_operation_id`, selected `account_id`, original non-secret intent and its own fresh approval proofs.
- `MailAccountRemoval.preview/write(array $args, string $uid): array` (two methods); native local impact is established before this capability is published.
- Workflow record: owner/connection scope, source operation ID, kind (`create_account`/`configure_connection`), before-account IDs, fixed target ID if any, keyed requested-effect digest, admission/expiry times and minimal verified result. No cleartext profile or signature content.
- Receipt additions: `account_id`, native-UI source and observable native progress. `awaiting_user` is distinct from `running`; result binding and completion reservations use compare-and-set transitions.

### Task D1: Prove native contracts and publish redacted reads

**Files:** Extend `mcp/tests/fixtures/nextcloud-api/{generate-mail.php,mail-sources.json}` as needed; create `Mail/Settings/{MailSettings,AccountSettingsSnapshot}.php`, `Mail/Connection/ConnectionSnapshot.php`, `Mail/Integration/MailSettingsGateway.php`, and Tools `{ReadAccountSettings,ReadConnection}.php`. Tests: `Unit/Modules/Mail/MailSettingsTest.php`, `Integration/Mail/MailAccountSettingsContractTest.php`; extend existing catalog/DI/grant tests.
**Consumes:** A's owned account gateway, current tool availability and pinned Mail source archives.
**Produces:** `MailSettings.readOwned`, a safe connection projection and native capability/editability evidence.

- [ ] Write `testForeignAndDelegatedAccountsAreIndistinguishableFromMissing` and `testConfigurationReadsNeedTheirOwnSelectedTools`; assert no native secret/transport write or network probe.
- [ ] Write `testNativeSerializationAndErrorsNeverEscapeProjection` with password, encrypted-password, refresh-token and OAuth-URL sentinels; none may appear in results, exceptions or journal rows.
- [ ] Write native contract checks for account/alias signature methods, preference entity updates, provisioned/delegated flags, `/setup` and the existing-account form. Record exact native service surfaces from verified archives. Do not invent an edit deep link.
- [ ] Run the focused tests and observe missing capability/projection failures before implementing the strict allowlists and `ReadAccountSettings`/`ReadConnection` tools. Return unsupported fields/capabilities honestly.
- [ ] Re-run focused/native contracts, API fixtures and lazy-registration tests. Commit only this independently working read capability after checks pass.

### Task D2: Add confirmed preference and signature writes

**Files:** Create `Mail/Signatures/{MailSignatures,SignatureSnapshot}.php`, `Mail/Tools/{WriteAccountSettings,ReadSignature,WriteSignature}.php`; modify `MailSettingsGateway.php`, `MailPlanRenderer.php`, catalogs/permissions/DI and `Unit/Modules/Mail/{MailSettingsTest,MailSignaturesTest}.php`.
**Consumes:** D1 projections/native evidence, A's write admission and original-action status authorization.
**Produces:** `MailSettings.previewPatch/write`, `MailSignatures.readOwned/preview/write`, and minimal account-ID receipts for direct configuration writes.

- [ ] Write `testOnePreferencePatchPreservesOtherPreferencesEncryptedAuthAndSecurityFields`, `testAliasSignatureCannotTargetAnotherAccount`, `testNullRemovesSignatureButOmissionDoesNot`, and `testSignatureLimitAndEscapedHtmlPreview` (20,000 accepted, 20,001 refused).
- [ ] Write `testConcurrentSignatureConfirmationsWriteOnce` and `testNativeUiRevisionChangeRequiresANewPlan`; assert repeated confirmation returns the same receipt with zero extra native calls.
- [ ] Observe focused failures, then implement patches against the already loaded owned native entity, exact D1 keys and native account/alias signature services. Never call account setup or serialize whole entities for a preference/signature write.
- [ ] Extend account target claims, current-original-action checks and public `account_id` references in the existing journal. Key changes by the exact owned identity; do not drop A's draft/path handling.
- [ ] Run focused PHP plus native read-back tests proving unrelated settings/auth state unchanged; check that native composition applies a signature once. Commit after checks pass.

### Task D3: Extend the journal for native-UI handoffs and completion

**Files:** Create `Mail/Accounts/{MailAccountSetup,SetupIntent,SetupObservation}.php`, `Db/MailSetupWorkflowsMapper.php` and the next ordered MCP migration; extend `Mcp/Operations/{OperationJournal,OperationStore}.php`, `Db/OperationsMapper.php` and `Mail/Operations/MailOperationReader.php`. Tests: `Unit/Db/MailSetupWorkflowsMapperTest.php`, `Unit/Modules/Mail/MailAccountSetupTest.php`.
**Consumes:** A's scoped signing/reservation/receipts and D1 owned redacted profile reads.
**Produces:** The workflow record and `MailAccountSetup.preview/begin/previewCompletion/complete` contracts consumed by D4.

- [ ] Write `testRepeatedBeginReturnsTheSameAwaitingUserWorkflow` and `testPendingUiWorkflowIsNotAnExpiredRunningWorker`: 15-minute approval and 24-hour admitted-workflow expiry have separate meanings. Repeated begin never opens a browser or starts setup.
- [ ] Write `testStatusNeverBindsProbesOrCreates`, `testExpiredUiWorkflowNeverRecreatesOrDeletesAnAccount` and `testWorkflowDigestAndReferencesContainNoProfileOrSecrets`; status reports existing state or derived expiry only.
- [ ] Write `testCompletionNeedsExactAccountOriginalIntentAndFreshRevision`, `testMultipleNewAccountsNeedExplicitSelection`, `testCreationCannotBindAnAccountPresentBeforeHandoff`, `testReconfigurationCannotBindAnotherAccount` and `testCredentialOnlyChangeWithoutNativeAuthorizationEvidenceStaysUnverified`.
- [ ] Observe failing tests, then implement short admission/completion transactions and CAS transitions. Persist original intent only as keyed digest plus minimal before/target IDs. Do not hold a DB transaction or active mutation claim during user interaction.
- [ ] Use a separate confirmed completion operation to bind observed state; require current source workflow/tool/connection permission and `read_connection`. Its own repeats return the prior result. If final workflow-recording fails after admission/binding, retain uncertainty and never replay native setup.
- [ ] Run real SQLite interleaving tests for two begin/completion confirmations and revocation/expiry between preview/confirmation. Check A's existing operation and cleanup suites, then commit.

### Task D4: Expose native setup and IMAP/SMTP configuration workflows

**Files:** Create `Mail/Tools/{AddAccount,ConfigureConnection,CompleteAccountSetup}.php`, `Mail/Integration/NativeAccountSetupLinks.php`; extend D3 services, catalogs/DI, `Mail/Presentation/MailPlanRenderer.php`, and native/browser tests `Integration/Mail/McpMailAccountSetupTest.php` / `tests/JavaScript/mail/account-setup.spec.js`.
**Consumes:** D3 workflow/CAS contracts and D1 capability/editability checks.
**Produces:** The native-assistant flows selected by the user; no credential schema or custom credential UI.

- [ ] Write `testPreviewDoesNotCreateAccountConnectOrStageCredentials`, `testSetupUrlIsSameOriginAndContainsNoSecretsOrUnsupportedPrefill`, `testAdminDisabledCreationAndProvisionedServerSettingsAreRefused`, and `testUnknownSecretFieldsAreRejected`.
- [ ] Observe failures, then generate the create link through verified `mail.page.setup`. For existing accounts, use the verified native settings entry point or Mail root plus exact account navigation instructions. Do not add a fake settings route, query prefill or script injection.
- [ ] Show the requested non-secret profile and the fact that transport/authentication is completed in native Mail. Do not call `SetupService.createNewAccount` from the MCP tool; passwords and provider consent remain in `AccountForm`.
- [ ] Test the native form with disposable fixture credentials provided only by the test harness, never in MCP arguments. Opening/abandoning/repeating a link remains awaiting-user/unverified. Explicitly bind the new owned account, or the approved existing account, and verify the full observed profile before completion.
- [ ] Test independently submitted duplicate native accounts, native user edits that differ from the requested values, unsupported provider consent and foreign-session use. Do not infer completion/uniqueness from URL opening, timestamps or credential booleans alone.
- [ ] Run native UI and scoped operation tests; repeat A's Mail regression cases and commit after checks pass.

### Task D5: Add separately permitted connection diagnostics

**Files:** Create `Mail/Connection/{MailConnection,ConnectionProbeResult}.php`, `Mail/Integration/NativeConnectionProbe.php`, `Mail/Tools/TestConnection.php`; extend native fixture fault cases, permissions and `Unit/Modules/Mail/MailConnectionTest.php` / `Integration/Mail/McpMailConnectionTest.php`.
**Consumes:** D1 redacted profiles, native IMAP/SMTP factories, native host validation and A's non-replay operation admission.
**Produces:** Explicit per-protocol diagnostic receipts; never a send or a setup completion inferred from IMAP alone.

- [ ] Write `testImapOkAndSmtpFailureAreReportedSeparately`, `testProbeEmitsNoSmtpEnvelopeOrDataCommands`, `testNativeTlsHostPolicyAndProvisioningRestrictionsArePreserved`, and `testProbeBudgetAndRateLimit` (10 seconds each, 20 total, 5/user/account/minute).
- [ ] Write `testStatusAndRepeatedOperationDoNotProbeAgain` and `testProviderErrorsAreRedacted`; no transcripts/tokens/password sentinels may escape.
- [ ] Observe failing tests, then use native public client-factory boundaries with bounded native timeouts and normal certificate checks. Never reflect into protected `SetupService.testConnectivity` or claim SMTP success from `AccountService.testAccountConnection`.
- [ ] Classify the explicit diagnostic as a confirmed network action using its own operation ID and selected permission; status lookup remains read-only. If the native transport cannot meet budgets or avoid message submission, mark that protocol unsupported.
- [ ] Run isolated IMAP/SMTP authentication-failure/TLS/timeout captures with zero message delivery; commit after checks pass.

### Task D6: Gate account removal and complete permission/release integration

**Files:** Create `Mail/Accounts/MailAccountRemoval.php`, `Mail/Tools/DeleteAccount.php`; extend `MailToolCatalog.php`, `AppInfo/Application.php`, `Access/Grants/GrantPolicy.php`, `Mcp/Confirmation/WriteGate.php`, `Mcp/Tools/Discovery/ToolPresentation.php`, `src/admin/access/{levels,presentation}.js`, group/inspection labels, `mcp/l10n/*` and Mail/permissions documentation. Tests: `Unit/Modules/Mail/MailAccountRemovalTest.php`, native settings/removal cases and existing permission/browser suites.
**Consumes:** D1 native local-impact evidence, D2-D5 contracts and A's journal/status behavior.
**Produces:** Measured owner-scoped removal, denied-by-default capability matrix, accurate user contracts and a checked D release.

- [ ] Establish the native removal impact on aliases, cached mail, local drafts and background work. Write `testDeleteAccountPreviewShowsMeasuredLocalImpactAndLeavesImapAndFilesUntouched`, `testRemovedAccountIsNotRecreatedOnLostResponse`, and `testDeletionDoesNotEraseUncertainHistoryOrAuthorizeARecreatedAccount`.
- [ ] Observe the failures and implement only the proven native removal path, with exact account-ID revision and its independent delete permission. If required local-data preservation/impact cannot be proven, keep removal disabled and publish its native-UI fallback.
- [ ] Add every proposed tool's independent grant, explicit connection selection, current-original-action status checks, UI tier and translated labels. Completion dynamically checks its source action as well as its own permission. Tests must prove old grants/defaults/selections grant none of D.
- [ ] Run focused PHP/native settings/connection cases, real DB concurrency and browser permission/UI cases before `devbox run check --cwd IMPLEMENTATION_WORKTREE --json`. No production credentials or external delivery; generated servers remain loopback/private to the fixture.
- [ ] Update the guide with awaiting-user vs observed-native completion, exact supported settings, separate IMAP/SMTP results, removal impact, revocation limits of native UI, retained uncertain history and credential-free status receipts. Review the final change before a local commit; publishing/deployment requires its own request.

## Plan self-review

The five review risks each have named owner tests. Every new permission and proposed tool in the design has an owning task; D6 integrates their admin/connection presentation. D3 distinguishes approved MCP actions from independently completed native UI work and does not promise native exactly-once creation. D5 proves both protocols separately; D6 gates removal instead of assuming its local impact. D adds no sending, global administration or new credential entry UI. This document is a plan, not evidence that D has run or passed.
