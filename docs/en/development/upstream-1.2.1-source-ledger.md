<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 path ledger

This exhaustive **path inventory** accompanies the [release intake](upstream-1.2.1-intake.md)
and [U1 implementation plan](upstream-1.2.1-u1-plan.md). It maps all 165 changed
paths from `02526119b845ccc1d8399be002da3e30b5527df6` to
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`; `A` is added and `M` modified.
Original paths are evidence references, not target locations in the fork.
This does not claim a complete line-by-line security review or implementation of
all paths. U1's source review is focused; U2-U5 require their own detailed audit
before implementation. Mixed groups denote overlapping responsibilities, not
permission to copy an entire file.

| Group | Disposition / fork ownership |
| --- | --- |
| C0/U5 | Keep the fork's existing disposable matrix and baseline probes; repair public baseline reproducibility in C0. Evaluate upstream release-upgrade probes for any missing coverage later. Do not copy queue/engine control. |
| U1 | Adapt root/origin recognition and mirror visibility into `Access/Visibility`, with unit and native privacy tests. |
| U1/U2 | Apply only visibility changes for U1; configuration injection and full reserved-name handling belong to U2. |
| U2 | Pending: shared backup settings and mutation policy, Files editing/checkout/versions/reorganization/creation, Contacts deletion, admin controller, authored `src` modules and templates. Never reintroduce generated/monolithic administration scripts. |
| U3 | Pending: Tasks Content, Reading, Creation, Editing and Presentation owners; keep hierarchy serialization at the DAV write boundary. |
| U4 | Pending: `Modules/Mail` read capabilities, optional app admission, explicit grants, native/API fixtures and attributed sources. |
| U4/U5 | Pending: adapt only required host API/source contracts and generators. Preserve the fork's independent fixtures and supported platform floor; source evidence for older versions is not a new support promise. |
| U2/U4 | Pending: compose selected owners explicitly and adapt prompts without rebuilding a shared catch-all service. |
| U3/U4/U5 | Pending: expose implemented capability contracts in the current MCP presentation owners and targeted tests. |
| U5 | Pending: canonical English guides, interface locales, tool/route/manifest contracts and original credits. Retain fork identity/version and translation provenance. Do not copy duplicate README trees or assert unimplemented features. |

No upstream dependency lock or database migration path changes occur in this
range; that observation does not replace runtime data-compatibility checks.
Backup settings add persisted app configuration, and Mail changes the grant
catalog: both require explicit existing-installation and fail-closed tests.
Fixtures remain subject to provenance and license checks before adaptation.

## Changed paths

| Status | Upstream path | Group |
| --- | --- | --- |
| M | `CHANGELOG.md` | U5 |
| M | `README.de.md` | U5 |
| M | `README.es.md` | U5 |
| M | `README.md` | U5 |
| M | `README.pt-BR.md` | U5 |
| M | `mcp/README.md` | U5 |
| M | `mcp/README.pt-BR.md` | U5 |
| M | `mcp/appinfo/info.xml` | U5 |
| M | `mcp/appinfo/routes.php` | U2 |
| A | `mcp/js/admin-backups.js` | U2 |
| M | `mcp/js/admin-levels.js` | U4 |
| M | `mcp/l10n/de.js` | U5 |
| M | `mcp/l10n/de.json` | U5 |
| M | `mcp/l10n/es.js` | U5 |
| M | `mcp/l10n/es.json` | U5 |
| M | `mcp/l10n/pt_BR.js` | U5 |
| M | `mcp/l10n/pt_BR.json` | U5 |
| M | `mcp/lib/AppInfo/Application.php` | U2/U4 |
| A | `mcp/lib/Controller/BackupSettingsController.php` | U2 |
| A | `mcp/lib/Service/BackupCopies.php` | U2 |
| A | `mcp/lib/Service/BackupFolderMatcher.php` | U1 |
| A | `mcp/lib/Service/BackupFolderName.php` | U2 |
| A | `mcp/lib/Service/BackupLocation.php` | U2 |
| A | `mcp/lib/Service/BackupMirrorVisibility.php` | U1 |
| A | `mcp/lib/Service/BackupSettingsDocument.php` | U2 |
| A | `mcp/lib/Service/BackupSettingsWriter.php` | U2 |
| A | `mcp/lib/Service/CheckoutPrompt.php` | U2 |
| M | `mcp/lib/Service/Compat/AppEnablement.php` | U4 |
| M | `mcp/lib/Service/GrantMatrix.php` | U4 |
| M | `mcp/lib/Service/GrantPolicy.php` | U4 |
| M | `mcp/lib/Service/PromptCatalog.php` | U2/U4 |
| M | `mcp/lib/Service/Scope/FilesAccessGuard.php` | U1/U2 |
| M | `mcp/lib/Service/VisibilityGuard.php` | U1/U2 |
| M | `mcp/lib/Settings/AdminSettings.php` | U2 |
| M | `mcp/lib/Tools/Contacts/ContactBackup.php` | U2 |
| A | `mcp/lib/Tools/Contacts/ContactBackupDirectory.php` | U2 |
| A | `mcp/lib/Tools/Contacts/ContactBackupText.php` | U2 |
| M | `mcp/lib/Tools/Contacts/ContactsModule.php` | U2 |
| A | `mcp/lib/Tools/Contacts/Handler/DeleteContactHandler.php` | U2 |
| A | `mcp/lib/Tools/Files/BackupMessages.php` | U2 |
| A | `mcp/lib/Tools/Files/BackupPlan.php` | U2 |
| A | `mcp/lib/Tools/Files/BackupPlanInstructions.php` | U2 |
| A | `mcp/lib/Tools/Files/BackupWriteBoundary.php` | U2 |
| M | `mcp/lib/Tools/Files/ContentCommit.php` | U2 |
| M | `mcp/lib/Tools/Files/ContentWritePlan.php` | U2 |
| M | `mcp/lib/Tools/Files/CopyMoveContext.php` | U2 |
| M | `mcp/lib/Tools/Files/FileBackup.php` | U2 |
| A | `mcp/lib/Tools/Files/FileBackupCopy.php` | U2 |
| A | `mcp/lib/Tools/Files/FileBackupPreparation.php` | U2 |
| M | `mcp/lib/Tools/Files/FileCreation.php` | U2 |
| A | `mcp/lib/Tools/Files/FilesGuideNotes.php` | U2 |
| M | `mcp/lib/Tools/Files/FilesMessages.php` | U2 |
| M | `mcp/lib/Tools/Files/FilesModule.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/CheckoutFileHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/CheckoutWriteHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/CopyHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/EditFileHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/ReplaceFileHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Handler/RestoreVersionHandler.php` | U2 |
| M | `mcp/lib/Tools/Files/Reorganization.php` | U2 |
| M | `mcp/lib/Tools/Files/VersionRestore.php` | U2 |
| A | `mcp/lib/Tools/Mail/Handler/ListAccountsHandler.php` | U4 |
| A | `mcp/lib/Tools/Mail/Handler/ListMailboxesHandler.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailGateway.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailMessages.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailModule.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailRows.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailServices.php` | U4 |
| A | `mcp/lib/Tools/Mail/MailUnavailableException.php` | U4 |
| A | `mcp/lib/Tools/Tasks/Handler/CreateTaskHandler.php` | U3 |
| A | `mcp/lib/Tools/Tasks/Handler/EditTaskHandler.php` | U3 |
| M | `mcp/lib/Tools/Tasks/Handler/ListTasksHandler.php` | U3 |
| A | `mcp/lib/Tools/Tasks/Handler/ParentTaskWrite.php` | U3 |
| A | `mcp/lib/Tools/Tasks/Handler/ReadTaskHandler.php` | U3 |
| A | `mcp/lib/Tools/Tasks/ParentPlanText.php` | U3 |
| A | `mcp/lib/Tools/Tasks/ParentTitles.php` | U3 |
| M | `mcp/lib/Tools/Tasks/TaskData.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskHierarchy.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskHierarchyLock.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskHierarchyMessages.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskObjects.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskParentStep.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskRelations.php` | U3 |
| A | `mcp/lib/Tools/Tasks/TaskWriteFlow.php` | U3 |
| M | `mcp/lib/Tools/Tasks/TasksModule.php` | U3 |
| M | `mcp/lib/Tools/Tasks/TasksPlanRenderer.php` | U3 |
| M | `mcp/lib/Tools/ToolPresentation.php` | U3/U4/U5 |
| A | `mcp/templates/admin-backups.php` | U2 |
| M | `mcp/templates/admin.php` | U2 |
| A | `mcp/tests/Frontend/admin-backups.test.cjs` | U2 |
| A | `mcp/tests/Integration/Compat/LegacyCatalogContract.php` | C0/U5 |
| A | `mcp/tests/Integration/Compat/ReleaseUpgradeProbe.php` | C0/U5 |
| A | `mcp/tests/Integration/Compat/ReleaseUpgradeTest.php` | C0/U5 |
| A | `mcp/tests/Integration/Compat/UpgradeBaseline.php` | C0/U5 |
| M | `mcp/tests/Integration/Compat/UpgradeSnapshotTest.php` | C0/U5 |
| A | `mcp/tests/Integration/Exclusion/BackupConfigurationTest.php` | U2 |
| A | `mcp/tests/Integration/Exclusion/BackupFolderTest.php` | U1 |
| A | `mcp/tests/Integration/Mail/ImapProbe.php` | U4 |
| A | `mcp/tests/Integration/Mail/MailActor.php` | U4 |
| A | `mcp/tests/Integration/Mail/McpMailHttpTest.php` | U4 |
| A | `mcp/tests/Integration/Mail/McpMailReadTest.php` | U4 |
| A | `mcp/tests/Integration/Mail/mail-actor.php` | U4 |
| A | `mcp/tests/Integration/Policy/BackupSettingsAdminHttpTest.php` | U2 |
| A | `mcp/tests/Integration/Tasks/InterleavingDav.php` | U3 |
| A | `mcp/tests/Integration/Tasks/McpSubtasksTest.php` | U3 |
| M | `mcp/tests/Integration/ci/harness.sh` | C0/U5 |
| M | `mcp/tests/Integration/ci/legacy-credentials.php` | C0/U5 |
| M | `mcp/tests/Integration/ci/legacy-policy.php` | C0/U5 |
| A | `mcp/tests/Integration/ci/mail-cell.sh` | U4 |
| A | `mcp/tests/Integration/ci/mail/mcp-it.conf` | U4 |
| M | `mcp/tests/Integration/ci/run-local.sh` | C0/U5 |
| A | `mcp/tests/Unit/Admin/AdminAssetContract.php` | U5 |
| M | `mcp/tests/Unit/Admin/AdminAssetsTest.php` | U5 |
| M | `mcp/tests/Unit/Admin/GrantMatrixTest.php` | U4 |
| M | `mcp/tests/Unit/Admin/GrantsControllerTest.php` | U4 |
| M | `mcp/tests/Unit/Checkout/CheckoutCreateTest.php` | U2 |
| A | `mcp/tests/Unit/Compat/UpgradeBaselineContractTest.php` | C0/U5 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/FixtureFile.php` | U4/U5 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/FixtureFileTest.php` | U4/U5 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcePins.php` | U4 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcePinsTest.php` | U4 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/MailSourcesContractTest.php` | U4 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiContractTest.php` | U4/U5 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php` | U4/U5 |
| A | `mcp/tests/Unit/Contract/NextcloudApi/OldestCoveredRelease.php` | U4/U5 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/SourceParser.php` | U4/U5 |
| M | `mcp/tests/Unit/Contract/VisibilityContractTest.php` | U1/U2 |
| A | `mcp/tests/Unit/Controller/BackupSettingsControllerTest.php` | U2 |
| M | `mcp/tests/Unit/GrantPolicyTest.php` | U4 |
| M | `mcp/tests/Unit/InfoXmlTest.php` | U5 |
| M | `mcp/tests/Unit/RoutesTest.php` | U5 |
| A | `mcp/tests/Unit/Service/BackupDocumentFixture.php` | U2 |
| A | `mcp/tests/Unit/Service/BackupFolderMatcherTest.php` | U1 |
| A | `mcp/tests/Unit/Service/BackupSettingsTest.php` | U2 |
| M | `mcp/tests/Unit/Service/Compat/AppEnablementTest.php` | U4 |
| A | `mcp/tests/Unit/StoreLocaleContract.php` | U5 |
| M | `mcp/tests/Unit/Tools/Deck/DeckCutPresentationTest.php` | U3/U4/U5 |
| M | `mcp/tests/Unit/Tools/FileWriteGuardCoverageTest.php` | U2 |
| A | `mcp/tests/Unit/Tools/Files/BackupOperationsTest.php` | U2 |
| M | `mcp/tests/Unit/Tools/Files/FilesToolsTestCase.php` | U2 |
| A | `mcp/tests/Unit/Tools/Mail/FakeMailApp.php` | U4 |
| A | `mcp/tests/Unit/Tools/Mail/Handler/ListAccountsHandlerTest.php` | U4 |
| A | `mcp/tests/Unit/Tools/Mail/Handler/ListMailboxesHandlerTest.php` | U4 |
| A | `mcp/tests/Unit/Tools/Mail/MailGatewayTest.php` | U4 |
| A | `mcp/tests/Unit/Tools/Mail/MailModuleTest.php` | U4 |
| A | `mcp/tests/Unit/Tools/Mail/RecordingDouble.php` | U4 |
| M | `mcp/tests/Unit/Tools/Tasks/Handler/TaskListingCase.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/HierarchyCalendars.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/IneffectiveLocks.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/TaskHierarchyLockTest.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/TaskHierarchyTest.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/TaskParentWritesTest.php` | U3 |
| A | `mcp/tests/Unit/Tools/Tasks/TaskSubtasksTest.php` | U3 |
| M | `mcp/tests/Unit/Tools/ToolPresentationTest.php` | U3/U4/U5 |
| M | `mcp/tests/Unit/Tools/WritePlanContractTest.php` | U3/U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/31.php` | U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/32.php` | U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/33.php` | U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/34.php` | U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/35.php` | U4/U5 |
| A | `mcp/tests/fixtures/nextcloud-api/generate-mail.php` | U4 |
| M | `mcp/tests/fixtures/nextcloud-api/generate-recipients.php` | U4/U5 |
| M | `mcp/tests/fixtures/nextcloud-api/generate.php` | U4/U5 |
| A | `mcp/tests/fixtures/nextcloud-api/mail-sources.json` | U4 |
| A | `mcp/tests/stubs/OCA/Mail/Exception/ClientException.php` | U4 |
