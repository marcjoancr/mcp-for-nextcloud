<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 path ledger

This exhaustive **path inventory** accompanies the [release intake](upstream-1.2.1-intake.md)
and [U1 implementation plan](upstream-1.2.1-u1-plan.md). It maps all 165 changed
paths from `02526119b845ccc1d8399be002da3e30b5527df6` to
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`; `A` is added and `M` modified.
Original paths are evidence references, not target locations in the fork.
The U5 audit below assigns each changed path a final disposition and a real fork
owner. This is traceable reconciliation, not a claim that every upstream line was
copied or independently security-audited. U1 and [U2](upstream-1.2.1-u2-integration.md) have focused source
reviews; [U3](upstream-1.2.1-u3-integration.md) adapts the Tasks hierarchy after its
focused audit. [U4](upstream-1.2.1-u4-integration.md) covers Mail;
[U5](upstream-1.2.1-u5-integration.md) records final reconciliation and validation. Mixed groups denote overlapping responsibilities, not
permission to copy an entire file.

| Group | Disposition / fork ownership |
| --- | --- |
| C0/U5 | Keep the fork's existing disposable matrix and baseline probes; repair public baseline reproducibility in C0. U5 adapts explicit legacy Mail comparisons and adds a pinned 1.2.0 upgrade using the existing state/credential probe. Do not copy queue/engine control. |
| U1 | Adapt root/origin recognition and mirror visibility into `Access/Visibility`, with unit and native privacy tests. |
| U1/U2 | Adapted: U1 canonical origin/mirror recognition plus U2 configured NFC/historical names and explicit policy injection. |
| U2 | Adapted: `Access/BackupPolicy`, Files capability consumers and mutation boundaries, mandatory Contacts copies, admin controller, authored `src/admin/backups` modules/template and checkout guidance. See the U2 record for validation and limits. |
| U3 | Adapted: Tasks Content, Reading, Hierarchy, Creation, Editing and Presentation; preserve shared DAV ports, use mandatory locking around parent-setting preparation/write, and report detected external cycles after save. See the U3 record for evidence and limits. |
| U4 | Adapted: `Modules/Mail` read capabilities, optional app admission, explicit grants, native/API fixtures and attributed sources. |
| U4/U5 | Mail API surfaces and source-verified generator adapted; unrelated API captures preserved. U5 preserves all unrelated fixture captures and checks generator provenance. Preserve the fork's independent fixtures and supported platform floor; source evidence for older versions is not a new support promise. |
| U2/U4 | U2 uses explicit policy dependencies and updates existing checkout guidance. U4 uses explicit MailReader/resolver composition; no shared catch-all service is restored. |
| U3/U4/U5 | U3 updates only parent schemas, Tasks guide notes and plans in canonical owners. The shared ToolPresentation and WritePlanContractTest upstream deltas add Mail; they are adapted in U4. |
| U5 | Reconciled: canonical English guides, interface locales, tool/route/manifest contracts and original credits. Retain fork identity/version and translation provenance. Do not copy duplicate README trees or assert unimplemented features. |

No upstream dependency lock or database migration path changes occur in this
range; that observation does not replace runtime data-compatibility checks.
Backup settings add persisted app configuration, and Mail changes the grant
catalog: both require explicit existing-installation and fail-closed tests.
Fixtures remain subject to provenance and license checks before adaptation.

## Changed paths

All 165 paths are accounted for: 138 adapted, 26 equivalent,
1 deliberately excluded. Equivalence refers to the named behavior, not identical
source text or identical test coverage. U5 records remaining platform/client gates.

| Status | Upstream path | Group | Disposition | Fork owner and decision |
| --- | --- | --- | --- | --- |
| M | `CHANGELOG.md` | U5 | Adapted | [`CHANGELOG.md`](../../../CHANGELOG.md) — Canonical owner; see batch evidence. |
| M | `README.de.md` | U5 | Excluded | [`README.md`](../../../README.md) — German README remains outside the agreed five-language docs tree; German UI and store metadata are retained. |
| M | `README.es.md` | U5 | Equivalent | [`docs/en/reference/tools.md`](../../../docs/en/reference/tools.md) — Canonical guides and entrypoints replace duplicated release prose; translation drafts retain provenance. |
| M | `README.md` | U5 | Equivalent | [`docs/en/reference/tools.md`](../../../docs/en/reference/tools.md) — Canonical guides and entrypoints replace duplicated release prose; translation drafts retain provenance. |
| M | `README.pt-BR.md` | U5 | Equivalent | [`docs/en/reference/tools.md`](../../../docs/en/reference/tools.md) — Canonical guides and entrypoints replace duplicated release prose; translation drafts retain provenance. |
| M | `mcp/README.md` | U5 | Equivalent | [`docs/en/reference/tools.md`](../../../docs/en/reference/tools.md) — Canonical guides and entrypoints replace duplicated release prose; translation drafts retain provenance. |
| M | `mcp/README.pt-BR.md` | U5 | Equivalent | [`docs/en/reference/tools.md`](../../../docs/en/reference/tools.md) — Canonical guides and entrypoints replace duplicated release prose; translation drafts retain provenance. |
| M | `mcp/appinfo/info.xml` | U5 | Adapted | [`mcp/appinfo/info.xml`](../../../mcp/appinfo/info.xml) — All selected behavior, four store locales, fork identity/2.0.0 and NC33–35/PHP8.4; language review pending. |
| M | `mcp/appinfo/routes.php` | U2 | Adapted | [`mcp/appinfo/routes.php`](../../../mcp/appinfo/routes.php) — Canonical owner; see batch evidence. |
| A | `mcp/js/admin-backups.js` | U2 | Adapted | [`mcp/src/admin/backups/controller.js`](../../../mcp/src/admin/backups/controller.js) — Canonical owner; see batch evidence. |
| M | `mcp/js/admin-levels.js` | U4 | Adapted | [`mcp/src/admin/access/levels.js`](../../../mcp/src/admin/access/levels.js) — Canonical owner; see batch evidence. |
| M | `mcp/l10n/de.js` | U5 | Adapted | [`mcp/l10n/de.js`](../../../mcp/l10n/de.js) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/l10n/de.json` | U5 | Adapted | [`mcp/l10n/de.json`](../../../mcp/l10n/de.json) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/l10n/es.js` | U5 | Adapted | [`mcp/l10n/es.js`](../../../mcp/l10n/es.js) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/l10n/es.json` | U5 | Adapted | [`mcp/l10n/es.json`](../../../mcp/l10n/es.json) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/l10n/pt_BR.js` | U5 | Adapted | [`mcp/l10n/pt_BR.js`](../../../mcp/l10n/pt_BR.js) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/l10n/pt_BR.json` | U5 | Adapted | [`mcp/l10n/pt_BR.json`](../../../mcp/l10n/pt_BR.json) — 34/40 changed upstream keys exact; six replaced by seven authored UI equivalents; JS/JSON parity checked. |
| M | `mcp/lib/AppInfo/Application.php` | U2/U4 | Adapted | [`mcp/lib/AppInfo/Application.php`](../../../mcp/lib/AppInfo/Application.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Controller/BackupSettingsController.php` | U2 | Adapted | [`mcp/lib/Controller/BackupSettingsController.php`](../../../mcp/lib/Controller/BackupSettingsController.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupCopies.php` | U2 | Adapted | [`mcp/lib/Access/BackupPolicy/BackupCopies.php`](../../../mcp/lib/Access/BackupPolicy/BackupCopies.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupFolderMatcher.php` | U1 | Adapted | [`mcp/lib/Access/Visibility/BackupFolderMatcher.php`](../../../mcp/lib/Access/Visibility/BackupFolderMatcher.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupFolderName.php` | U2 | Adapted | [`mcp/lib/Access/BackupPolicy/BackupFolderName.php`](../../../mcp/lib/Access/BackupPolicy/BackupFolderName.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupLocation.php` | U2 | Adapted | [`mcp/lib/Access/BackupPolicy/BackupLocation.php`](../../../mcp/lib/Access/BackupPolicy/BackupLocation.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupMirrorVisibility.php` | U1 | Adapted | [`mcp/lib/Access/Visibility/BackupMirrorVisibility.php`](../../../mcp/lib/Access/Visibility/BackupMirrorVisibility.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupSettingsDocument.php` | U2 | Adapted | [`mcp/lib/Access/BackupPolicy/BackupSettingsDocument.php`](../../../mcp/lib/Access/BackupPolicy/BackupSettingsDocument.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/BackupSettingsWriter.php` | U2 | Adapted | [`mcp/lib/Access/BackupPolicy/BackupSettingsWriter.php`](../../../mcp/lib/Access/BackupPolicy/BackupSettingsWriter.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Service/CheckoutPrompt.php` | U2 | Equivalent | [`mcp/lib/Mcp/Prompts/PromptCatalog.php`](../../../mcp/lib/Mcp/Prompts/PromptCatalog.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Service/Compat/AppEnablement.php` | U4 | Adapted | [`mcp/lib/Access/AppAvailability/AppEnablement.php`](../../../mcp/lib/Access/AppAvailability/AppEnablement.php) — Mail needs a known supported version; unknown Mail version fails closed. |
| M | `mcp/lib/Service/GrantMatrix.php` | U4 | Adapted | [`mcp/lib/Access/Grants/GrantMatrix.php`](../../../mcp/lib/Access/Grants/GrantMatrix.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Service/GrantPolicy.php` | U4 | Adapted | [`mcp/lib/Access/Grants/GrantPolicy.php`](../../../mcp/lib/Access/Grants/GrantPolicy.php) — Explicit mail.read addition defaults to Deny, deliberately stricter than upstream. |
| M | `mcp/lib/Service/PromptCatalog.php` | U2/U4 | Adapted | [`mcp/lib/Mcp/Prompts/PromptCatalog.php`](../../../mcp/lib/Mcp/Prompts/PromptCatalog.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Service/Scope/FilesAccessGuard.php` | U1/U2 | Adapted | [`mcp/lib/Access/ResourceScope/FilesAccessGuard.php`](../../../mcp/lib/Access/ResourceScope/FilesAccessGuard.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Service/VisibilityGuard.php` | U1/U2 | Adapted | [`mcp/lib/Access/Visibility/VisibilityGuard.php`](../../../mcp/lib/Access/Visibility/VisibilityGuard.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Settings/AdminSettings.php` | U2 | Adapted | [`mcp/lib/Settings/AdminSettings.php`](../../../mcp/lib/Settings/AdminSettings.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Contacts/ContactBackup.php` | U2 | Adapted | [`mcp/lib/Modules/Contacts/Deletion/ContactBackup.php`](../../../mcp/lib/Modules/Contacts/Deletion/ContactBackup.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Contacts/ContactBackupDirectory.php` | U2 | Equivalent | [`mcp/lib/Modules/Contacts/Deletion/ContactBackup.php`](../../../mcp/lib/Modules/Contacts/Deletion/ContactBackup.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| A | `mcp/lib/Tools/Contacts/ContactBackupText.php` | U2 | Equivalent | [`mcp/lib/Modules/Contacts/Presentation/ContactDeletionRenderer.php`](../../../mcp/lib/Modules/Contacts/Presentation/ContactDeletionRenderer.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Contacts/ContactsModule.php` | U2 | Adapted | [`mcp/lib/Modules/Contacts/ContactsModule.php`](../../../mcp/lib/Modules/Contacts/ContactsModule.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Contacts/Handler/DeleteContactHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Contacts/Deletion/ContactDeleter.php`](../../../mcp/lib/Modules/Contacts/Deletion/ContactDeleter.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/BackupMessages.php` | U2 | Adapted | [`mcp/lib/Platform/FileAccess/BackupMessages.php`](../../../mcp/lib/Platform/FileAccess/BackupMessages.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/BackupPlan.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Presentation/BackupPlan.php`](../../../mcp/lib/Modules/Files/Presentation/BackupPlan.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/BackupPlanInstructions.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Presentation/BackupPlanInstructions.php`](../../../mcp/lib/Modules/Files/Presentation/BackupPlanInstructions.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/BackupWriteBoundary.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/BackupWriteBoundary.php`](../../../mcp/lib/Modules/Files/Editing/BackupWriteBoundary.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/ContentCommit.php` | U2 | Equivalent | [`mcp/lib/Modules/Files/Editing/ContentWriter.php`](../../../mcp/lib/Modules/Files/Editing/ContentWriter.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Files/ContentWritePlan.php` | U2 | Equivalent | [`mcp/lib/Modules/Files/Editing/ContentPlan.php`](../../../mcp/lib/Modules/Files/Editing/ContentPlan.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Files/CopyMoveContext.php` | U2 | Equivalent | [`mcp/lib/Modules/Files/Reorganization/CopyMove.php`](../../../mcp/lib/Modules/Files/Reorganization/CopyMove.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Files/FileBackup.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/FileBackup.php`](../../../mcp/lib/Modules/Files/Editing/FileBackup.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/FileBackupCopy.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/FileBackupCopy.php`](../../../mcp/lib/Modules/Files/Editing/FileBackupCopy.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/FileBackupPreparation.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/FileBackupPreparation.php`](../../../mcp/lib/Modules/Files/Editing/FileBackupPreparation.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/FileCreation.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Creation/FileCreation.php`](../../../mcp/lib/Modules/Files/Creation/FileCreation.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Files/FilesGuideNotes.php` | U2 | Equivalent | [`mcp/lib/Modules/Files/FilesModule.php`](../../../mcp/lib/Modules/Files/FilesModule.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Files/FilesMessages.php` | U2 | Equivalent | [`mcp/lib/Modules/Files/Presentation/BackupPlan.php`](../../../mcp/lib/Modules/Files/Presentation/BackupPlan.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Files/FilesModule.php` | U2 | Adapted | [`mcp/lib/Modules/Files/FilesModule.php`](../../../mcp/lib/Modules/Files/FilesModule.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/CheckoutFileHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Checkout/FileCheckout.php`](../../../mcp/lib/Modules/Files/Checkout/FileCheckout.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/CheckoutWriteHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Checkout/CheckoutWriter.php`](../../../mcp/lib/Modules/Files/Checkout/CheckoutWriter.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/CopyHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Reorganization/CopyMove.php`](../../../mcp/lib/Modules/Files/Reorganization/CopyMove.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/EditFileHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/TextFileEditor.php`](../../../mcp/lib/Modules/Files/Editing/TextFileEditor.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/ReplaceFileHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Editing/TextReplacer.php`](../../../mcp/lib/Modules/Files/Editing/TextReplacer.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Handler/RestoreVersionHandler.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Versions/VersionRestorer.php`](../../../mcp/lib/Modules/Files/Versions/VersionRestorer.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/Reorganization.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Reorganization/BatchUndo.php`](../../../mcp/lib/Modules/Files/Reorganization/BatchUndo.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Files/VersionRestore.php` | U2 | Adapted | [`mcp/lib/Modules/Files/Versions/VersionRestorer.php`](../../../mcp/lib/Modules/Files/Versions/VersionRestorer.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/Handler/ListAccountsHandler.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Accounts/AccountLister.php`](../../../mcp/lib/Modules/Mail/Accounts/AccountLister.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/Handler/ListMailboxesHandler.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Mailboxes/MailboxLister.php`](../../../mcp/lib/Modules/Mail/Mailboxes/MailboxLister.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailGateway.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Integration/MailGateway.php`](../../../mcp/lib/Modules/Mail/Integration/MailGateway.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailMessages.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Presentation/MailMessages.php`](../../../mcp/lib/Modules/Mail/Presentation/MailMessages.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailModule.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/MailModule.php`](../../../mcp/lib/Modules/Mail/MailModule.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailRows.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Presentation/MailRows.php`](../../../mcp/lib/Modules/Mail/Presentation/MailRows.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailServices.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Integration/MailServices.php`](../../../mcp/lib/Modules/Mail/Integration/MailServices.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Mail/MailUnavailableException.php` | U4 | Adapted | [`mcp/lib/Modules/Mail/Integration/MailUnavailableException.php`](../../../mcp/lib/Modules/Mail/Integration/MailUnavailableException.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/Handler/CreateTaskHandler.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Creation/TaskCreator.php`](../../../mcp/lib/Modules/Tasks/Creation/TaskCreator.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/Handler/EditTaskHandler.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Editing/TaskEditor.php`](../../../mcp/lib/Modules/Tasks/Editing/TaskEditor.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Tasks/Handler/ListTasksHandler.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Reading/TaskLister.php`](../../../mcp/lib/Modules/Tasks/Reading/TaskLister.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/Handler/ParentTaskWrite.php` | U3 | Equivalent | [`mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| A | `mcp/lib/Tools/Tasks/Handler/ReadTaskHandler.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Reading/TaskReader.php`](../../../mcp/lib/Modules/Tasks/Reading/TaskReader.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/ParentPlanText.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Presentation/ParentPlanText.php`](../../../mcp/lib/Modules/Tasks/Presentation/ParentPlanText.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/ParentTitles.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Reading/ParentTitles.php`](../../../mcp/lib/Modules/Tasks/Reading/ParentTitles.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Tasks/TaskData.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Content/TaskDocument.php`](../../../mcp/lib/Modules/Tasks/Content/TaskDocument.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskHierarchy.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchy.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchy.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskHierarchyLock.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchyLock.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchyLock.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskHierarchyMessages.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchyMessages.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskHierarchyMessages.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskObjects.php` | U3 | Equivalent | [`mcp/lib/Modules/Tasks/Reading/TaskQuery.php`](../../../mcp/lib/Modules/Tasks/Reading/TaskQuery.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| A | `mcp/lib/Tools/Tasks/TaskParentStep.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskRelations.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Content/TaskRelations.php`](../../../mcp/lib/Modules/Tasks/Content/TaskRelations.php) — Canonical owner; see batch evidence. |
| A | `mcp/lib/Tools/Tasks/TaskWriteFlow.php` | U3 | Equivalent | [`mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php`](../../../mcp/lib/Modules/Tasks/Hierarchy/TaskParentStep.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/lib/Tools/Tasks/TasksModule.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/TasksModule.php`](../../../mcp/lib/Modules/Tasks/TasksModule.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/Tasks/TasksPlanRenderer.php` | U3 | Adapted | [`mcp/lib/Modules/Tasks/Presentation/TasksPlanRenderer.php`](../../../mcp/lib/Modules/Tasks/Presentation/TasksPlanRenderer.php) — Canonical owner; see batch evidence. |
| M | `mcp/lib/Tools/ToolPresentation.php` | U3/U4/U5 | Adapted | [`mcp/lib/Mcp/Tools/Discovery/ToolPresentation.php`](../../../mcp/lib/Mcp/Tools/Discovery/ToolPresentation.php) — Canonical owner; see batch evidence. |
| A | `mcp/templates/admin-backups.php` | U2 | Adapted | [`mcp/templates/admin-backups.php`](../../../mcp/templates/admin-backups.php) — Canonical owner; see batch evidence. |
| M | `mcp/templates/admin.php` | U2 | Adapted | [`mcp/templates/admin.php`](../../../mcp/templates/admin.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Frontend/admin-backups.test.cjs` | U2 | Adapted | [`mcp/tests/JavaScript/admin/backups.spec.js`](../../../mcp/tests/JavaScript/admin/backups.spec.js) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Compat/LegacyCatalogContract.php` | C0/U5 | Adapted | [`mcp/tests/Integration/Compat/LegacyCatalogContract.php`](../../../mcp/tests/Integration/Compat/LegacyCatalogContract.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Compat/ReleaseUpgradeProbe.php` | C0/U5 | Equivalent | [`mcp/tests/Integration/ci/replacement.php`](../../../mcp/tests/Integration/ci/replacement.php) — Existing pinned mode and state/credential probe; U5 adds the 1.2.0 cell and explicit legacy Mail check, without a second snapshot format. |
| A | `mcp/tests/Integration/Compat/ReleaseUpgradeTest.php` | C0/U5 | Equivalent | [`mcp/tests/Integration/ci/replacement.php`](../../../mcp/tests/Integration/ci/replacement.php) — Existing pinned mode and state/credential probe; U5 adds the 1.2.0 cell and explicit legacy Mail check, without a second snapshot format. |
| A | `mcp/tests/Integration/Compat/UpgradeBaseline.php` | C0/U5 | Equivalent | [`mcp/tests/Integration/ci/matrix.php`](../../../mcp/tests/Integration/ci/matrix.php) — Existing pinned mode and state/credential probe; U5 adds the 1.2.0 cell and explicit legacy Mail check, without a second snapshot format. |
| M | `mcp/tests/Integration/Compat/UpgradeSnapshotTest.php` | C0/U5 | Adapted | [`mcp/tests/Integration/Compat/UpgradeSnapshotTest.php`](../../../mcp/tests/Integration/Compat/UpgradeSnapshotTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Exclusion/BackupConfigurationTest.php` | U2 | Adapted | [`mcp/tests/Integration/Exclusion/BackupConfigurationTest.php`](../../../mcp/tests/Integration/Exclusion/BackupConfigurationTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Exclusion/BackupFolderTest.php` | U1 | Adapted | [`mcp/tests/Integration/Exclusion/BackupFolderTest.php`](../../../mcp/tests/Integration/Exclusion/BackupFolderTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Mail/ImapProbe.php` | U4 | Adapted | [`mcp/tests/Integration/Mail/ImapProbe.php`](../../../mcp/tests/Integration/Mail/ImapProbe.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Mail/MailActor.php` | U4 | Adapted | [`mcp/tests/Integration/Mail/MailActor.php`](../../../mcp/tests/Integration/Mail/MailActor.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Mail/McpMailHttpTest.php` | U4 | Adapted | [`mcp/tests/Integration/Mail/McpMailHttpTest.php`](../../../mcp/tests/Integration/Mail/McpMailHttpTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Mail/McpMailReadTest.php` | U4 | Adapted | [`mcp/tests/Integration/Mail/McpMailReadTest.php`](../../../mcp/tests/Integration/Mail/McpMailReadTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Mail/mail-actor.php` | U4 | Adapted | [`mcp/tests/Integration/Mail/mail-actor.php`](../../../mcp/tests/Integration/Mail/mail-actor.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Policy/BackupSettingsAdminHttpTest.php` | U2 | Adapted | [`mcp/tests/Integration/Policy/BackupSettingsAdminHttpTest.php`](../../../mcp/tests/Integration/Policy/BackupSettingsAdminHttpTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Tasks/InterleavingDav.php` | U3 | Adapted | [`mcp/tests/Integration/Tasks/InterleavingDav.php`](../../../mcp/tests/Integration/Tasks/InterleavingDav.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/Tasks/McpSubtasksTest.php` | U3 | Adapted | [`mcp/tests/Integration/Tasks/McpSubtasksTest.php`](../../../mcp/tests/Integration/Tasks/McpSubtasksTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Integration/ci/harness.sh` | C0/U5 | Adapted | [`mcp/tests/Integration/ci/harness.sh`](../../../mcp/tests/Integration/ci/harness.sh) — Run-owned disposable harness and opt-in Mail; shared-engine control, queue and image pruning excluded. |
| M | `mcp/tests/Integration/ci/legacy-credentials.php` | C0/U5 | Equivalent | [`mcp/tests/Integration/ci/legacy-credentials.php`](../../../mcp/tests/Integration/ci/legacy-credentials.php) — Remain exclusive to the 1.0.8 migration; generation-qualified 1.2.0 uses replacement.php instead. |
| M | `mcp/tests/Integration/ci/legacy-policy.php` | C0/U5 | Equivalent | [`mcp/tests/Integration/ci/legacy-policy.php`](../../../mcp/tests/Integration/ci/legacy-policy.php) — Remain exclusive to the 1.0.8 migration; generation-qualified 1.2.0 uses replacement.php instead. |
| A | `mcp/tests/Integration/ci/mail-cell.sh` | U4 | Adapted | [`mcp/tests/Integration/ci/mail-cell.sh`](../../../mcp/tests/Integration/ci/mail-cell.sh) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Integration/ci/mail/mcp-it.conf` | U4 | Adapted | [`mcp/tests/Integration/ci/mail/mcp-it.conf`](../../../mcp/tests/Integration/ci/mail/mcp-it.conf) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Integration/ci/run-local.sh` | C0/U5 | Adapted | [`mcp/tests/Integration/ci/run-local.sh`](../../../mcp/tests/Integration/ci/run-local.sh) — Run-owned disposable harness and opt-in Mail; shared-engine control, queue and image pruning excluded. |
| A | `mcp/tests/Unit/Admin/AdminAssetContract.php` | U5 | Equivalent | [`mcp/tests/Unit/Admin/AdminAssetsTest.php`](../../../mcp/tests/Unit/Admin/AdminAssetsTest.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/tests/Unit/Admin/AdminAssetsTest.php` | U5 | Adapted | [`mcp/tests/Unit/Admin/AdminAssetsTest.php`](../../../mcp/tests/Unit/Admin/AdminAssetsTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Admin/GrantMatrixTest.php` | U4 | Adapted | [`mcp/tests/Unit/Access/Grants/GrantMatrixTest.php`](../../../mcp/tests/Unit/Access/Grants/GrantMatrixTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Admin/GrantsControllerTest.php` | U4 | Adapted | [`mcp/tests/Unit/Admin/GrantsControllerTest.php`](../../../mcp/tests/Unit/Admin/GrantsControllerTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Checkout/CheckoutCreateTest.php` | U2 | Adapted | [`mcp/tests/Unit/Modules/Files/Checkout/CheckoutCreateTest.php`](../../../mcp/tests/Unit/Modules/Files/Checkout/CheckoutCreateTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Compat/UpgradeBaselineContractTest.php` | C0/U5 | Equivalent | [`mcp/tests/Unit/Integration/LegacyCatalogContractTest.php`](../../../mcp/tests/Unit/Integration/LegacyCatalogContractTest.php) — Existing pinned mode and state/credential probe; U5 adds the 1.2.0 cell and explicit legacy Mail check, without a second snapshot format. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/FixtureFile.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/FixtureFile.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/FixtureFile.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/FixtureFileTest.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/FixtureFileTest.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/FixtureFileTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcePins.php` | U4 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/MailSourcePins.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/MailSourcePins.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcePinsTest.php` | U4 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/MailSourcePinsTest.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/MailSourcePinsTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcesContractTest.php` | U4 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/MailSourcesContractTest.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/MailSourcesContractTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiContractTest.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiContractTest.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiContractTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Contract/NextcloudApi/OldestCoveredRelease.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/OldestCoveredRelease.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/OldestCoveredRelease.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Contract/NextcloudApi/SourceParser.php` | U4/U5 | Adapted | [`mcp/tests/Unit/Contract/NextcloudApi/SourceParser.php`](../../../mcp/tests/Unit/Contract/NextcloudApi/SourceParser.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Contract/VisibilityContractTest.php` | U1/U2 | Adapted | [`mcp/tests/Unit/Contract/VisibilityContractTest.php`](../../../mcp/tests/Unit/Contract/VisibilityContractTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Controller/BackupSettingsControllerTest.php` | U2 | Adapted | [`mcp/tests/Unit/Controller/BackupSettingsControllerTest.php`](../../../mcp/tests/Unit/Controller/BackupSettingsControllerTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/GrantPolicyTest.php` | U4 | Adapted | [`mcp/tests/Unit/Access/Grants/GrantPolicyTest.php`](../../../mcp/tests/Unit/Access/Grants/GrantPolicyTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/InfoXmlTest.php` | U5 | Adapted | [`mcp/tests/Unit/InfoXmlTest.php`](../../../mcp/tests/Unit/InfoXmlTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/RoutesTest.php` | U5 | Adapted | [`mcp/tests/Unit/RoutesTest.php`](../../../mcp/tests/Unit/RoutesTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Service/BackupDocumentFixture.php` | U2 | Adapted | [`mcp/tests/Support/Access/BackupPolicy/BackupDocumentFixture.php`](../../../mcp/tests/Support/Access/BackupPolicy/BackupDocumentFixture.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Service/BackupFolderMatcherTest.php` | U1 | Adapted | [`mcp/tests/Unit/Access/Visibility/BackupFolderMatcherTest.php`](../../../mcp/tests/Unit/Access/Visibility/BackupFolderMatcherTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Service/BackupSettingsTest.php` | U2 | Adapted | [`mcp/tests/Unit/Access/BackupPolicy/BackupSettingsTest.php`](../../../mcp/tests/Unit/Access/BackupPolicy/BackupSettingsTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Service/Compat/AppEnablementTest.php` | U4 | Adapted | [`mcp/tests/Unit/Access/AppAvailability/AppEnablementTest.php`](../../../mcp/tests/Unit/Access/AppAvailability/AppEnablementTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/StoreLocaleContract.php` | U5 | Equivalent | [`mcp/tests/Unit/InfoXmlTest.php`](../../../mcp/tests/Unit/InfoXmlTest.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| M | `mcp/tests/Unit/Tools/Deck/DeckCutPresentationTest.php` | U3/U4/U5 | Equivalent | [`mcp/tests/Unit/InfoXmlTest.php`](../../../mcp/tests/Unit/InfoXmlTest.php) — The upstream delta changes the manifest version assertion; the fork identity contract retains 2.0.0. |
| M | `mcp/tests/Unit/Tools/FileWriteGuardCoverageTest.php` | U2 | Equivalent | [`mcp/tests/Architecture/FileWritePolicySinksTest.php`](../../../mcp/tests/Architecture/FileWritePolicySinksTest.php) — Existing capability owner supplies the behavior; no duplicate helper or legacy wrapper. |
| A | `mcp/tests/Unit/Tools/Files/BackupOperationsTest.php` | U2 | Adapted | [`mcp/tests/Unit/Modules/Files/Editing/BackupPolicyOperationsTest.php`](../../../mcp/tests/Unit/Modules/Files/Editing/BackupPolicyOperationsTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Tools/Files/FilesToolsTestCase.php` | U2 | Adapted | [`mcp/tests/Support/Files/FilesToolsTestCase.php`](../../../mcp/tests/Support/Files/FilesToolsTestCase.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/FakeMailApp.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/FakeMailApp.php`](../../../mcp/tests/Unit/Modules/Mail/FakeMailApp.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/Handler/ListAccountsHandlerTest.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/Handler/AccountListerTest.php`](../../../mcp/tests/Unit/Modules/Mail/Handler/AccountListerTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/Handler/ListMailboxesHandlerTest.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/Handler/MailboxListerTest.php`](../../../mcp/tests/Unit/Modules/Mail/Handler/MailboxListerTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/MailGatewayTest.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/MailGatewayTest.php`](../../../mcp/tests/Unit/Modules/Mail/MailGatewayTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/MailModuleTest.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/MailModuleTest.php`](../../../mcp/tests/Unit/Modules/Mail/MailModuleTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Mail/RecordingDouble.php` | U4 | Adapted | [`mcp/tests/Unit/Modules/Mail/RecordingDouble.php`](../../../mcp/tests/Unit/Modules/Mail/RecordingDouble.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Tools/Tasks/Handler/TaskListingCase.php` | U3 | Adapted | [`mcp/tests/Support/Tasks/TaskListingCase.php`](../../../mcp/tests/Support/Tasks/TaskListingCase.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/HierarchyCalendars.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/HierarchyCalendars.php`](../../../mcp/tests/Unit/Modules/Tasks/HierarchyCalendars.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/IneffectiveLocks.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/IneffectiveLocks.php`](../../../mcp/tests/Unit/Modules/Tasks/IneffectiveLocks.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/TaskHierarchyLockTest.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/TaskHierarchyLockTest.php`](../../../mcp/tests/Unit/Modules/Tasks/TaskHierarchyLockTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/TaskHierarchyTest.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/TaskHierarchyTest.php`](../../../mcp/tests/Unit/Modules/Tasks/TaskHierarchyTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/TaskParentWritesTest.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/TaskParentWritesTest.php`](../../../mcp/tests/Unit/Modules/Tasks/TaskParentWritesTest.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/Unit/Tools/Tasks/TaskSubtasksTest.php` | U3 | Adapted | [`mcp/tests/Unit/Modules/Tasks/TaskSubtasksTest.php`](../../../mcp/tests/Unit/Modules/Tasks/TaskSubtasksTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Tools/ToolPresentationTest.php` | U3/U4/U5 | Adapted | [`mcp/tests/Unit/Mcp/Tools/Discovery/ToolPresentationTest.php`](../../../mcp/tests/Unit/Mcp/Tools/Discovery/ToolPresentationTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/Unit/Tools/WritePlanContractTest.php` | U3/U4/U5 | Adapted | [`mcp/tests/Unit/Contract/WritePlanContractTest.php`](../../../mcp/tests/Unit/Contract/WritePlanContractTest.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/31.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/31.php`](../../../mcp/tests/fixtures/nextcloud-api/31.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/32.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/32.php`](../../../mcp/tests/fixtures/nextcloud-api/32.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/33.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/33.php`](../../../mcp/tests/fixtures/nextcloud-api/33.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/34.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/34.php`](../../../mcp/tests/fixtures/nextcloud-api/34.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/35.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/35.php`](../../../mcp/tests/fixtures/nextcloud-api/35.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/fixtures/nextcloud-api/generate-mail.php` | U4 | Adapted | [`mcp/tests/fixtures/nextcloud-api/generate-mail.php`](../../../mcp/tests/fixtures/nextcloud-api/generate-mail.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/generate-recipients.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/generate-recipients.php`](../../../mcp/tests/fixtures/nextcloud-api/generate-recipients.php) — Canonical owner; see batch evidence. |
| M | `mcp/tests/fixtures/nextcloud-api/generate.php` | U4/U5 | Adapted | [`mcp/tests/fixtures/nextcloud-api/generate.php`](../../../mcp/tests/fixtures/nextcloud-api/generate.php) — Canonical owner; see batch evidence. |
| A | `mcp/tests/fixtures/nextcloud-api/mail-sources.json` | U4 | Adapted | [`mcp/tests/fixtures/nextcloud-api/mail-sources.json`](../../../mcp/tests/fixtures/nextcloud-api/mail-sources.json) — Canonical owner; see batch evidence. |
| A | `mcp/tests/stubs/OCA/Mail/Exception/ClientException.php` | U4 | Adapted | [`mcp/tests/stubs/OCA/Mail/Exception/ClientException.php`](../../../mcp/tests/stubs/OCA/Mail/Exception/ClientException.php) — Canonical owner; see batch evidence. |
