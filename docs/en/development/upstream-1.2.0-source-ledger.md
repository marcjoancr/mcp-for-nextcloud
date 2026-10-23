<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.0 source coverage ledger

This is the exhaustive source ledger for the
[capability inventory and selection](upstream-1.2.0-inventory.md), recorded on
2026-10-23. It describes original repository paths, not proposed fork paths or
files copied into this branch. The inventory analysis itself ported no behavior.
Selected batches A-E and local automated qualification are now complete in the
[integration record](upstream-1.2.0-integration.md); F3 and human review remain pending.
The dispositions below remain the historical selection, not a current completion
ledger. D06's original deferment is superseded by the
[authorized native-link follow-up](deck-file-link-integration.md); its source rows
retain the original classification for traceability. QQUEUE remains deferred.

Range: `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` to
`02526119b845ccc1d8399be002da3e30b5527df6`. The fork comparison is
`3d06e7b87a7d310b6e165231e4c2284dc452ef72`.
The list was generated from `git diff --name-status` and `git log --reverse` for
these exact objects, then assigned to the reviewed capability decisions.
There are 284 distinct paths and 67 commits (44 non-merge, 23 merge).

## Reading the decisions

File boundaries often mix selected and deferred behaviors. A group is the owner
of the audit decision, not permission to copy an entire file. The main inventory
contains the precise contracts, source commits, target owners and test gates.

| Group | Disposition |
| --- | --- |
| D01/D04/QA | Adapt privacy, alias and Guests tests to selected Deck behavior; retain existing fork guards |
| D02, D03, D04, D05 | Port the named selected Deck capability and its necessary tests |
| D06 | Initially deferred; native sharing/recipient code and tests are now adapted under the separate follow-up contract; no dormant tools |
| DECK | Adapt selected D01-D05 and shared presentation/contract tests; introduce reference filtering with D05; omit D06-only paths and keep already-present move behavior |
| FILES | Adapt file safety and optional W01 write coverage; keep existing capability owners and receipts |
| N01/W01 | Adapt Notes partial edits and complete optional file-policy integration |
| W01 | Port complete optional policy and sink/UI coverage; default off; no legacy script or helper layer |
| O01 | Port consent navigation and adapt session/HTTP regression tests |
| M01 | Adapt installed-version identity and protocol tests to the split fork owners |
| C01/W01 | Port contact backup lock correction and tests; retain mandatory internal backup policy exemption |
| T01 | Adapt no-match explanation/tests; retain existing listing/repository separation |
| OBS | Adapt narrowly classified expected-refusal log levels |
| QA/QQUEUE | Adapt pinned test app provisioning and manifest checks; defer Colima/engine lifecycle and queue code |
| QA/D06 | Adapt required API/parser/provenance behavior; recipient/Circles surfaces initially deferred, now consumed by D06 follow-up |
| QA/META | Adapt selected contracts and tests to fork paths; do not wholesale replace expectations or add deferred claims |
| META | Adapt implemented feature descriptions/presentation later; defer version choice to F3 |
| DOC | Adapt selected messages/docs/credits, retain our English/i18n owners; defer German README expansion and reject duplicate README/HISTORY ownership |
| QQUEUE | Defer Colima-specific engine queue and its tests |

## Changed paths

`A` means added and `M` modified in the original release. Upstream originals can
be read from the [frozen source tree](https://github.com/sintax-tech/mcp-for-nextcloud/tree/02526119b845ccc1d8399be002da3e30b5527df6).

| Status | Original path | Decision group |
| --- | --- | --- |
| M | `CHANGELOG.md` | DOC |
| A | `HISTORY.md` | DOC |
| A | `HISTORY.pt-BR.md` | DOC |
| A | `README.de.md` | DOC |
| A | `README.es.md` | DOC |
| M | `README.md` | DOC |
| M | `README.pt-BR.md` | DOC |
| M | `mcp/README.md` | DOC |
| M | `mcp/README.pt-BR.md` | DOC |
| M | `mcp/appinfo/info.xml` | META |
| M | `mcp/appinfo/routes.php` | W01 |
| A | `mcp/js/admin-file-write-policy.js` | W01 |
| M | `mcp/l10n/de.js` | DOC |
| M | `mcp/l10n/de.json` | DOC |
| M | `mcp/l10n/es.js` | DOC |
| M | `mcp/l10n/es.json` | DOC |
| M | `mcp/l10n/pt_BR.js` | DOC |
| M | `mcp/l10n/pt_BR.json` | DOC |
| M | `mcp/lib/AppInfo/Application.php` | M01 |
| M | `mcp/lib/Controller/CheckoutController.php` | W01 |
| A | `mcp/lib/Controller/FileWritePolicyController.php` | W01 |
| M | `mcp/lib/Controller/OAuthController.php` | O01 |
| A | `mcp/lib/Service/FileWritePolicy.php` | W01 |
| A | `mcp/lib/Service/FileWritePolicyUnavailable.php` | W01 |
| M | `mcp/lib/Service/McpProtocol.php` | M01 |
| A | `mcp/lib/Tools/Common/FileTypeCheck.php` | W01 |
| A | `mcp/lib/Tools/Common/FileTypeDenied.php` | W01 |
| A | `mcp/lib/Tools/Common/FileTypeGuard.php` | W01 |
| A | `mcp/lib/Tools/Common/FileTypeMessages.php` | W01 |
| M | `mcp/lib/Tools/Contacts/ContactBackup.php` | C01/W01 |
| A | `mcp/lib/Tools/Contacts/ContactBackupLock.php` | C01/W01 |
| M | `mcp/lib/Tools/Deck/BoardBlueprint.php` | DECK |
| A | `mcp/lib/Tools/Deck/BoardRecipientResolver.php` | D06 |
| M | `mcp/lib/Tools/Deck/CardCriteria.php` | DECK |
| A | `mcp/lib/Tools/Deck/CardDueDate.php` | D02 |
| A | `mcp/lib/Tools/Deck/CardDueDateMessages.php` | D02 |
| A | `mcp/lib/Tools/Deck/CardDueDateView.php` | D02 |
| A | `mcp/lib/Tools/Deck/CardFileLinkPreparation.php` | D06 |
| A | `mcp/lib/Tools/Deck/CardFileNodeAccess.php` | D04 |
| A | `mcp/lib/Tools/Deck/CardFiles.php` | D04 |
| M | `mcp/lib/Tools/Deck/CardFormatter.php` | DECK |
| M | `mcp/lib/Tools/Deck/CardInput.php` | DECK |
| A | `mcp/lib/Tools/Deck/CardLabelChanges.php` | D03 |
| A | `mcp/lib/Tools/Deck/CardLinkMessages.php` | D06 |
| A | `mcp/lib/Tools/Deck/CardLinkPlan.php` | D06 |
| A | `mcp/lib/Tools/Deck/CardLinkPolicy.php` | D06 |
| A | `mcp/lib/Tools/Deck/CardLinkTool.php` | D06 |
| A | `mcp/lib/Tools/Deck/CardReferenceFilter.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckCapabilities.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckCardFileGateway.php` | D04 |
| A | `mcp/lib/Tools/Deck/DeckDependencyGateway.php` | D05 |
| A | `mcp/lib/Tools/Deck/DeckFailureLog.php` | OBS |
| M | `mcp/lib/Tools/Deck/DeckGatewayInterface.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckGuideNotes.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckLabelGateway.php` | D03 |
| M | `mcp/lib/Tools/Deck/DeckMessages.php` | DECK |
| M | `mcp/lib/Tools/Deck/DeckPlanRenderer.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckRecipientDirectory.php` | D06 |
| M | `mcp/lib/Tools/Deck/DeckRefusalException.php` | DECK |
| A | `mcp/lib/Tools/Deck/DeckServiceContext.php` | DECK |
| M | `mcp/lib/Tools/Deck/DeckServiceGateway.php` | DECK |
| M | `mcp/lib/Tools/Deck/DeckToolModule.php` | DECK |
| A | `mcp/lib/Tools/Deck/DependencyChange.php` | D05 |
| A | `mcp/lib/Tools/Deck/DependencyGraph.php` | D05 |
| A | `mcp/lib/Tools/Deck/DependencyNodeSource.php` | D05 |
| M | `mcp/lib/Tools/Deck/Handler/AbstractHandler.php` | DECK |
| A | `mcp/lib/Tools/Deck/Handler/CardFileHandler.php` | D04 |
| A | `mcp/lib/Tools/Deck/Handler/ChangeCardDependencyHandler.php` | D05 |
| M | `mcp/lib/Tools/Deck/Handler/CreateBoardHandler.php` | DECK |
| M | `mcp/lib/Tools/Deck/Handler/CreateCardHandler.php` | DECK |
| M | `mcp/lib/Tools/Deck/Handler/EditCardHandler.php` | DECK |
| A | `mcp/lib/Tools/Deck/Handler/EditCardLabelsHandler.php` | D03 |
| A | `mcp/lib/Tools/Deck/Handler/LinkCardFileHandler.php` | D06 |
| A | `mcp/lib/Tools/Deck/Handler/ListLabelsHandler.php` | D03 |
| A | `mcp/lib/Tools/Deck/Handler/ReadCardDependenciesHandler.php` | D05 |
| M | `mcp/lib/Tools/Deck/Handler/ReadCardHandler.php` | DECK |
| A | `mcp/lib/Tools/Deck/LinkRecipientContext.php` | D06 |
| A | `mcp/lib/Tools/Deck/NativeCardFileLink.php` | D06 |
| A | `mcp/lib/Tools/Deck/RecipientDirectory.php` | D06 |
| A | `mcp/lib/Tools/Deck/RecipientExpansion.php` | D06 |
| A | `mcp/lib/Tools/Deck/RecipientMessages.php` | D06 |
| A | `mcp/lib/Tools/Deck/RecipientResolutionException.php` | D06 |
| A | `mcp/lib/Tools/Deck/RecipientSnapshot.php` | D06 |
| A | `mcp/lib/Tools/Files/ContentCommit.php` | FILES |
| A | `mcp/lib/Tools/Files/ContentTypeGate.php` | FILES |
| A | `mcp/lib/Tools/Files/ContentWritePlan.php` | FILES |
| A | `mcp/lib/Tools/Files/CopyMoveContext.php` | FILES |
| M | `mcp/lib/Tools/Files/FileCreation.php` | FILES |
| A | `mcp/lib/Tools/Files/FileCreationWriter.php` | FILES |
| A | `mcp/lib/Tools/Files/FileDestinationPolicy.php` | FILES |
| A | `mcp/lib/Tools/Files/FileTreeMessages.php` | FILES |
| A | `mcp/lib/Tools/Files/FileTypePreflight.php` | FILES |
| A | `mcp/lib/Tools/Files/FileWriteBoundary.php` | FILES |
| M | `mcp/lib/Tools/Files/FilesMessages.php` | FILES |
| M | `mcp/lib/Tools/Files/FilesModule.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/CheckoutFileHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/CheckoutWriteHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/CopyHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/CreateFileHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/EditFileHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/MoveBatchHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/MoveHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/ReplaceFileHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/RestoreVersionHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/UndoBatchHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/Handler/UploadFileHandler.php` | FILES |
| A | `mcp/lib/Tools/Files/ListedTreeCopy.php` | FILES |
| A | `mcp/lib/Tools/Files/MoveBatchPlanner.php` | FILES |
| M | `mcp/lib/Tools/Files/MovePlanner.php` | FILES |
| M | `mcp/lib/Tools/Files/Reorganization.php` | FILES |
| A | `mcp/lib/Tools/Files/ReplaceSource.php` | FILES |
| A | `mcp/lib/Tools/Files/Sharing/ShareFailureLog.php` | OBS |
| M | `mcp/lib/Tools/Files/Sharing/ShareRemover.php` | OBS |
| M | `mcp/lib/Tools/Files/Sharing/ShareWriter.php` | OBS |
| A | `mcp/lib/Tools/Files/UniqueSnippet.php` | FILES |
| A | `mcp/lib/Tools/Files/VersionRestore.php` | FILES |
| M | `mcp/lib/Tools/Files/VersionTools.php` | FILES |
| A | `mcp/lib/Tools/Notes/Handler/CreateNoteHandler.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/Handler/EditNoteHandler.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/Handler/MoveNoteHandler.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteChange.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteChangeConfirmation.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteChangePlan.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteChangePreview.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteChangeTitle.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteContentSink.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteNames.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteRenameSink.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteTypeBoundary.php` | N01/W01 |
| A | `mcp/lib/Tools/Notes/NoteWrite.php` | N01/W01 |
| M | `mcp/lib/Tools/Notes/NotesMessages.php` | N01/W01 |
| M | `mcp/lib/Tools/Notes/NotesModule.php` | N01/W01 |
| M | `mcp/lib/Tools/Notes/NotesPlanRenderer.php` | N01/W01 |
| M | `mcp/lib/Tools/Notes/NotesRepository.php` | N01/W01 |
| A | `mcp/lib/Tools/Tasks/CategoryFilterNote.php` | T01 |
| A | `mcp/lib/Tools/Tasks/Handler/ListTasksHandler.php` | T01 |
| A | `mcp/lib/Tools/Tasks/TaskRowReader.php` | T01 |
| M | `mcp/lib/Tools/Tasks/TasksModule.php` | T01 |
| M | `mcp/lib/Tools/ToolPresentation.php` | META |
| A | `mcp/templates/admin-file-write-policy-overview.php` | W01 |
| A | `mcp/templates/admin-file-write-policy.php` | W01 |
| M | `mcp/templates/admin.php` | W01 |
| A | `mcp/templates/oauth-redirect.php` | O01 |
| A | `mcp/tests/Frontend/admin-file-write-policy.test.cjs` | W01 |
| A | `mcp/tests/Integration/Contacts/ContactBackupPolicyTest.php` | C01/W01 |
| A | `mcp/tests/Integration/Deck/BoardRecipientFixture.php` | D06 |
| A | `mcp/tests/Integration/Deck/BoardRecipientTest.php` | D06 |
| A | `mcp/tests/Integration/Deck/CardDueDateFixture.php` | D02 |
| A | `mcp/tests/Integration/Deck/CardDueDatePersistenceTest.php` | D02 |
| A | `mcp/tests/Integration/Deck/CardFileLinkFixture.php` | D06 |
| A | `mcp/tests/Integration/Deck/CardFileLinkTest.php` | D06 |
| A | `mcp/tests/Integration/Deck/DeckFixture.php` | DECK |
| A | `mcp/tests/Integration/Deck/DeckReferenceCostTest.php` | DECK |
| A | `mcp/tests/Integration/Deck/McpDeckCardFilesTest.php` | D04 |
| A | `mcp/tests/Integration/Deck/McpDeckDependenciesTest.php` | D05 |
| A | `mcp/tests/Integration/Deck/McpDeckDependencyWritesTest.php` | D05 |
| A | `mcp/tests/Integration/Deck/McpDeckLabelsTest.php` | D03 |
| A | `mcp/tests/Integration/Deck/McpDeckReadTest.php` | DECK |
| A | `mcp/tests/Integration/Deck/NativeDeckTest.php` | DECK |
| A | `mcp/tests/Integration/Deck/actor.php` | DECK |
| A | `mcp/tests/Integration/Files/CopyMoveFilePolicyTest.php` | FILES |
| A | `mcp/tests/Integration/Files/TreeBatchFilePolicyTest.php` | FILES |
| A | `mcp/tests/Integration/Files/TreeBatchPolicyHttpTest.php` | FILES |
| A | `mcp/tests/Integration/Files/TreePolicySecondClient.php` | FILES |
| A | `mcp/tests/Integration/Guests/GuestDeckParityFixture.php` | DECK |
| A | `mcp/tests/Integration/Guests/GuestDeckParityProbe.php` | DECK |
| A | `mcp/tests/Integration/Guests/GuestDeckParityTest.php` | DECK |
| M | `mcp/tests/Integration/Guests/GuestRestrictionsTest.php` | D01/D04/QA |
| A | `mcp/tests/Integration/Guests/GuestScenario.php` | D01/D04/QA |
| A | `mcp/tests/Integration/Guests/GuestTestSupport.php` | D01/D04/QA |
| A | `mcp/tests/Integration/Notes/NoteTypePolicyTest.php` | N01/W01 |
| A | `mcp/tests/Integration/Notes/NotesPartialEditTest.php` | N01/W01 |
| A | `mcp/tests/Integration/OAuth/ConsentHttpTest.php` | O01 |
| A | `mcp/tests/Integration/Policy/FileTypeEditRestoreTest.php` | W01 |
| A | `mcp/tests/Integration/Policy/FileWriteCheckoutTest.php` | W01 |
| A | `mcp/tests/Integration/Policy/FileWritePolicyAdminHttpTest.php` | W01 |
| A | `mcp/tests/Integration/Policy/FileWritePolicyRecoveryTest.php` | W01 |
| M | `mcp/tests/Integration/README.md` | QA/QQUEUE |
| A | `mcp/tests/Integration/ci/check-deck-manifest.php` | QA/QQUEUE |
| A | `mcp/tests/Integration/ci/engine-queue-test.sh` | QQUEUE |
| A | `mcp/tests/Integration/ci/engine-queue.sh` | QQUEUE |
| M | `mcp/tests/Integration/ci/harness.sh` | QA/QQUEUE |
| M | `mcp/tests/Integration/ci/run-local.sh` | QA/QQUEUE |
| M | `mcp/tests/Unit/Admin/AdminAssetsTest.php` | W01 |
| M | `mcp/tests/Unit/ApplicationRegistrationTest.php` | QA/META |
| M | `mcp/tests/Unit/Checkout/CheckoutControllerTest.php` | W01 |
| M | `mcp/tests/Unit/Checkout/CheckoutCreateTest.php` | W01 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiContractTest.php` | QA/D06 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php` | QA/D06 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/SourceParser.php` | QA/D06 |
| M | `mcp/tests/Unit/Contract/NextcloudApi/SourceParserTest.php` | QA/D06 |
| M | `mcp/tests/Unit/Contract/ToolErrorsLanguagesTest.php` | QA/META |
| M | `mcp/tests/Unit/Contract/ToolsListContractTest.php` | QA/META |
| M | `mcp/tests/Unit/Contract/WriteGateContractTest.php` | QA/META |
| A | `mcp/tests/Unit/Controller/FileWritePolicyControllerTest.php` | W01 |
| A | `mcp/tests/Unit/InstalledApp.php` | M01 |
| M | `mcp/tests/Unit/McpControllerTest.php` | M01 |
| M | `mcp/tests/Unit/McpModernProtocolTest.php` | M01 |
| M | `mcp/tests/Unit/McpProtocolTest.php` | M01 |
| M | `mcp/tests/Unit/OAuth/IssuerRedirectTest.php` | O01 |
| M | `mcp/tests/Unit/OAuth/ManagedClientWithdrawalTest.php` | O01 |
| M | `mcp/tests/Unit/OAuth/OAuthControllerFlowTest.php` | O01 |
| M | `mcp/tests/Unit/RoutesTest.php` | QA/META |
| A | `mcp/tests/Unit/Service/FileWritePolicyRecoveryTest.php` | W01 |
| A | `mcp/tests/Unit/Service/FileWritePolicyTest.php` | W01 |
| M | `mcp/tests/Unit/Service/PromptCatalogTest.php` | QA/META |
| M | `mcp/tests/Unit/Service/ResourceRegistryTest.php` | QA/META |
| A | `mcp/tests/Unit/Service/ServerInfoTest.php` | M01 |
| A | `mcp/tests/Unit/Support/GuestScenarioTest.php` | D01/D04/QA |
| A | `mcp/tests/Unit/Tools/Contacts/ContactBackupLockTest.php` | C01/W01 |
| A | `mcp/tests/Unit/Tools/Deck/BoardRecipientResolverTest.php` | D06 |
| M | `mcp/tests/Unit/Tools/Deck/CardCriteriaTest.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/CardDueDateTest.php` | D02 |
| A | `mcp/tests/Unit/Tools/Deck/CardDueTimeTest.php` | D02 |
| A | `mcp/tests/Unit/Tools/Deck/CardFileLinkTest.php` | D06 |
| A | `mcp/tests/Unit/Tools/Deck/CardFilesTest.php` | D04 |
| M | `mcp/tests/Unit/Tools/Deck/CardFormatterTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/CardInputTest.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/CardLabelsGatewayTest.php` | D03 |
| A | `mcp/tests/Unit/Tools/Deck/CardLabelsTest.php` | D03 |
| A | `mcp/tests/Unit/Tools/Deck/DeckCutPresentationTest.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/DeckFailureLogTest.php` | OBS |
| M | `mcp/tests/Unit/Tools/Deck/DeckPlanRendererTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/DeckPreviewTest.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/DeckRecipientDirectoryTest.php` | D06 |
| A | `mcp/tests/Unit/Tools/Deck/DeckReferenceBoundaryTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/DeckServiceGatewayTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/DeckStructureToolsTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/DeckToolModuleTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/DeckTranslationTest.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/DependencyCapabilitiesTest.php` | D05 |
| A | `mcp/tests/Unit/Tools/Deck/DependencyGraphTest.php` | D05 |
| A | `mcp/tests/Unit/Tools/Deck/DependencyWriteGatewayTest.php` | D05 |
| A | `mcp/tests/Unit/Tools/Deck/DependencyWriteHandlerTest.php` | D05 |
| M | `mcp/tests/Unit/Tools/Deck/Handler/CreateCardHandlerTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/Handler/EditCardHandlerTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/Handler/StructureHandlersTest.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/Stubs/deck_entities.php` | DECK |
| M | `mcp/tests/Unit/Tools/Deck/Stubs/deck_services.php` | DECK |
| A | `mcp/tests/Unit/Tools/Deck/Stubs/recipient_circle.php` | D06 |
| M | `mcp/tests/Unit/Tools/FakeTree.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileDeletionGuard.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileDeletionGuardTest.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileTypeGuardTest.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileTypePolicyFixture.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileWriteGuardCoverageTest.php` | W01 |
| A | `mcp/tests/Unit/Tools/FileWriteSinkInventory.php` | W01 |
| A | `mcp/tests/Unit/Tools/Files/AbsentFileTypePolicy.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/CopyMoveFilePolicyTest.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/CopyMovePolicyFixture.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/FileContentTypePolicyTest.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/FileWritePolicyBoundariesTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/Files/FilesEditToolsTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/Files/FilesSearchContentTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/Files/FilesShareTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/Files/FilesToolsTestCase.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/ReplaceSourceTest.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/Sharing/ShareFailureLogTest.php` | OBS |
| A | `mcp/tests/Unit/Tools/Files/TreeBatchPolicyTest.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/TreeDeletionBoundaryTest.php` | FILES |
| A | `mcp/tests/Unit/Tools/Files/UniqueSnippetTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/Files/VersionToolsTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/FilesModuleTest.php` | FILES |
| M | `mcp/tests/Unit/Tools/NoFileDeletionTest.php` | W01 |
| A | `mcp/tests/Unit/Tools/Notes/NoteTypePolicyTest.php` | N01/W01 |
| A | `mcp/tests/Unit/Tools/NotesPartialEditTest.php` | N01/W01 |
| M | `mcp/tests/Unit/Tools/NotesPlanRendererTest.php` | N01/W01 |
| M | `mcp/tests/Unit/Tools/NotesTranslationTest.php` | N01/W01 |
| M | `mcp/tests/Unit/Tools/Talk/TalkTranslationTest.php` | QA/META |
| A | `mcp/tests/Unit/Tools/Tasks/Handler/ListTasksHandlerTest.php` | T01 |
| A | `mcp/tests/Unit/Tools/Tasks/Handler/TaskListingCase.php` | T01 |
| M | `mcp/tests/Unit/Tools/Tasks/TaskCategoriesTest.php` | T01 |
| M | `mcp/tests/Unit/Tools/ToolPresentationTest.php` | QA/META |
| M | `mcp/tests/Unit/Tools/ToolRegistryTest.php` | QA/META |
| M | `mcp/tests/fixtures/nextcloud-api/31.php` | QA/D06 |
| M | `mcp/tests/fixtures/nextcloud-api/32.php` | QA/D06 |
| M | `mcp/tests/fixtures/nextcloud-api/33.php` | QA/D06 |
| M | `mcp/tests/fixtures/nextcloud-api/34.php` | QA/D06 |
| M | `mcp/tests/fixtures/nextcloud-api/35.php` | QA/D06 |
| A | `mcp/tests/fixtures/nextcloud-api/RECIPIENTS.md` | QA/D06 |
| A | `mcp/tests/fixtures/nextcloud-api/fetch-recipients.py` | QA/D06 |
| A | `mcp/tests/fixtures/nextcloud-api/generate-recipients.php` | QA/D06 |
| M | `mcp/tests/fixtures/nextcloud-api/generate.php` | QA/D06 |
| A | `mcp/tests/fixtures/nextcloud-api/recipient-sources.json` | QA/D06 |

## Commit coverage

Non-merge subjects locate original contributions; merge subjects record integration
history rather than extra features. No merge commit is selected for wholesale
cherry-picking. The reviewed final release tree takes precedence over intermediate
implementation details and test corrections. The disposition column ties each
contribution to the decisions above; it does not authorize copying intermediate
trees. Version/release preparation remains
META/F3 work; runner queue changes remain deferred.

| Source commit | Kind | Disposition | Original subject |
| --- | --- | --- | --- |
| [d526e2f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/d526e2fc6cb05bc085d8ee19a7f3f8ac90026efb) | Contribution | QA: adapt pinned provisioning | Pin Deck per Nextcloud major in the integration harness |
| [e0a6b39](https://github.com/sintax-tech/mcp-for-nextcloud/commit/e0a6b3956358731df4a0f9d4435364b762605319) | Contribution | D01/D03/D05: adapt privacy, labels and reference filtering | Read Deck labels and drop unreadable card references (#9) |
| [a007fe8](https://github.com/sintax-tech/mcp-for-nextcloud/commit/a007fe8b83041467098f0181b693c5719cb12f55) | Contribution | D03: port incremental label edits | Add and remove Deck card labels with plan and confirmation (#9) |
| [7ce8ee2](https://github.com/sintax-tech/mcp-for-nextcloud/commit/7ce8ee24bad3ae1062ee90e1d71e4545864f447e) | Contribution | D05: port gated graph reads | Read the native Deck dependency graph on Nextcloud 34 and 35 (#9) |
| [f4609bf](https://github.com/sintax-tech/mcp-for-nextcloud/commit/f4609bf3c551639c842bcdcd1d53094b543f3037) | Contribution | D05: port gated dependency writes | Add and remove native Deck card dependencies with cycle checks (#9) |
| [5cb19f1](https://github.com/sintax-tech/mcp-for-nextcloud/commit/5cb19f1c22f83521f80e51742f0cd8f2b79cbd20) | Contribution | O01: port consent navigation | Navigate to the registered redirect after OAuth consent (#13) |
| [fb39797](https://github.com/sintax-tech/mcp-for-nextcloud/commit/fb397977cd47d03d929edac70bfa39d472665b88) | Contribution | D04: port listing/unlink | List and unlink files attached to Deck cards without deleting them (#9) |
| [e5d9c5f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/e5d9c5fd96114a28fbd94809e02c136e963aa7af) | Integration only | History only; use reviewed final tree | Merge 1.1.2 and the OAuth consent fix (#13) into the 1.2.0 work |
| [704bdce](https://github.com/sintax-tech/mcp-for-nextcloud/commit/704bdce19cbcf2c50f2e4c110784dbd19ccf0355) | Contribution | D01/D04/QA: adapt Guests alias regressions | Prove Guests external storage parity between WebDAV and MCP for Deck aliases (#9) |
| [fc28ab9](https://github.com/sintax-tech/mcp-for-nextcloud/commit/fc28ab9691bb40f14cc13e7bbfb89bd58a944ba2) | Contribution | Already present: retain fork capability boundaries; adapt selected behavior only | Split new Deck label, file and dependency logic out of DeckServiceGateway (#9) |
| [b9b131e](https://github.com/sintax-tech/mcp-for-nextcloud/commit/b9b131ea900d8cbe295696e97424f6c595c06872) | Contribution | QA: adapt Guests test support/cleanup | Harden Guests parity tests: cleanup order, shared support and docs (#9) |
| [aa7a30d](https://github.com/sintax-tech/mcp-for-nextcloud/commit/aa7a30d851d452c16a28ced63ef616aba730e658) | Contribution | M01: port installed-version identity | Report the installed app version in the MCP server info |
| [4909e05](https://github.com/sintax-tech/mcp-for-nextcloud/commit/4909e05c90f8c903fdc83b3c1c11fd6458cf5a39) | Contribution | N01/F02: port partial edits and bounded matcher | Edit notes in place with append and single-snippet replace |
| [35683b5](https://github.com/sintax-tech/mcp-for-nextcloud/commit/35683b5a6e7f2cbb4587082fea8382d0f6b7f4b9) | Contribution | F01: port size preflight | Check the reported size before files_replace reads the file |
| [7fef90b](https://github.com/sintax-tech/mcp-for-nextcloud/commit/7fef90baf2cb5b7429b52b295d542e3f579647d4) | Contribution | D06: initially deferred; recipient expansion adapted in follow-up | Resolve Deck board recipients and bind link plans to canonical audience hashes (#9) |
| [da7ff40](https://github.com/sintax-tech/mcp-for-nextcloud/commit/da7ff403ee441691dcf837ee221008011146e40e) | Contribution | D06: initially deferred; native linking adapted in follow-up | Link files to Deck cards natively under strict sharing policies (#9) |
| [5f67546](https://github.com/sintax-tech/mcp-for-nextcloud/commit/5f67546ed4250c99f9aa66f51f5e1d8d2e60e362) | Contribution | T01: port no-match note | Explain empty category-filtered task listings instead of returning a bare empty page (#16) |
| [ab905c0](https://github.com/sintax-tech/mcp-for-nextcloud/commit/ab905c0b5cadc59a6b1daec53491f54a1c73e65f) | Contribution | Already present: retain Tasks listing/repository owners | Move the tasks_list_tasks listing into its own handler with a shared protected-task reader (#16) |
| [efeafc5](https://github.com/sintax-tech/mcp-for-nextcloud/commit/efeafc5a61d34e8311e1188476408d7eec78c6bb) | Contribution | DOC: retain canonical docs; do not copy README/HISTORY layout | Move earlier release notes to HISTORY and tidy the README |
| [ec2f276](https://github.com/sintax-tech/mcp-for-nextcloud/commit/ec2f276d092cb196289cda9cb033786b4f6e86f6) | Contribution | D02: port date-only UTC correction | Store Deck due dates in UTC so date-only deadlines read back as the same day (#9) |
| [ee01bf2](https://github.com/sintax-tech/mcp-for-nextcloud/commit/ee01bf2fd4d2d084273be43dde383d436f590209) | Integration only | History only; use reviewed final tree | Merge the #16 category filter note and its handler into the 1.2.0 work |
| [371d478](https://github.com/sintax-tech/mcp-for-nextcloud/commit/371d478a5d0f678d19d5b1aa7d47c99f36f692ca) | Integration only | History only; use reviewed final tree | Merge notes_edit partial edits and the files_replace size check into the 1.2.0 work |
| [e4d3a93](https://github.com/sintax-tech/mcp-for-nextcloud/commit/e4d3a9353c14dd43f17a2083c42fc38bb9701ebd) | Integration only | History only; use reviewed final tree | Merge the reported app version in server info into the 1.2.0 work |
| [56c76b1](https://github.com/sintax-tech/mcp-for-nextcloud/commit/56c76b12d5f7f1eb633dbdd20e2d612871c74f9a) | Integration only | History only; use reviewed final tree | Merge the README tidy-up and HISTORY files into the 1.2.0 work |
| [43c1657](https://github.com/sintax-tech/mcp-for-nextcloud/commit/43c1657365da3d89e3a4fe3864a0837bde4ef3a6) | Contribution | F02/DOC: retain match-count message removal | Drop the snippet-count translation that the notes_edit merge brought back |
| [42a44f7](https://github.com/sintax-tech/mcp-for-nextcloud/commit/42a44f7f3ed08f343b0c13bcef73c75d62d4f3d4) | Contribution | W01: port complete optional policy and sink inventory | Add the optional file type policy, its guard and a sink coverage check (#15) |
| [144945b](https://github.com/sintax-tech/mcp-for-nextcloud/commit/144945b76e76247eb6065a3986dea9be9f6a7140) | Contribution | D02: port time-of-day support | Accept and return a time of day on Deck card due dates (#9) |
| [c21dab8](https://github.com/sintax-tech/mcp-for-nextcloud/commit/c21dab8b703845e77cad36906424fe3c5bf2ab11) | Integration only | History only; use reviewed final tree | Merge the optional file type policy foundation (#15) into the 1.2.0 work |
| [3ceabe2](https://github.com/sintax-tech/mcp-for-nextcloud/commit/3ceabe24f1a88bf5d9696e1f2de9f29f81607c61) | Contribution | W01: adapt Notes sink classification | Classify the notes_edit writer in the file write sink inventory (#15) |
| [1a97d2d](https://github.com/sintax-tech/mcp-for-nextcloud/commit/1a97d2d1e3f2fa0f0a2d1f10f318c1cfbd02987b) | Contribution | DOC: preserve credit; badge layout not required | docs: add XERJ Corpus Hub contributor badge |
| [af17f96](https://github.com/sintax-tech/mcp-for-nextcloud/commit/af17f964532079b119d7f18988829d7c5254a01f) | Integration only | History only; use reviewed final tree | Merge the XERJ Corpus Hub contributor badge into the 1.2.0 work |
| [b909649](https://github.com/sintax-tech/mcp-for-nextcloud/commit/b909649a67ce5fedb75f275ece1ab6572982d655) | Contribution | QQUEUE: defer engine queue/host management | Serve integration test runs in arrival order with configurable engine slots |
| [3212646](https://github.com/sintax-tech/mcp-for-nextcloud/commit/3212646f958b5449cb03649909ab7b6cc2cbabf6) | Integration only | History only; use reviewed final tree | Merge the arrival-order engine queue with slots into the 1.2.0 work |
| [772f81f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/772f81f67ae6bbd6292a52cc5ad6580aed4fa6d0) | Contribution | W01: adapt admin UI to existing bundle | Add the admin page for the file type policy (#15) |
| [18fe511](https://github.com/sintax-tech/mcp-for-nextcloud/commit/18fe511b705beb8fbb415dcfc884f5637ef438d5) | Contribution | W01: port create/upload/checkout guards | Apply the file type policy to create, upload and checkout (#15) |
| [c33e1d5](https://github.com/sintax-tech/mcp-for-nextcloud/commit/c33e1d5f54bf66cee07d3438089df64ee9a556ee) | Integration only | History only; use reviewed final tree | Merge the file type policy admin page into the 1.2.0 work |
| [8746174](https://github.com/sintax-tech/mcp-for-nextcloud/commit/874617400d2ee2bcab4e47f8b90b3e0111995026) | Integration only | History only; use reviewed final tree | Merge file type policy checks for create, upload and checkout into the 1.2.0 work |
| [2f90d5f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/2f90d5f014e644989765c0214f5251f15209c4bf) | Contribution | W01: port copy/move guards | Apply the file type policy to file copy and move (#15) |
| [c8e0817](https://github.com/sintax-tech/mcp-for-nextcloud/commit/c8e08172c55fc4d576b0461e298bfe870e518886) | Integration only | History only; use reviewed final tree | Merge file type policy checks for file copy and move into the 1.2.0 work |
| [a55d554](https://github.com/sintax-tech/mcp-for-nextcloud/commit/a55d554ef479dcba9a8c9fb6181883ddab0eefdc) | Contribution | W01: port folder/batch/undo guards | Apply the file type policy to folder copy and move, batches and undo (#15) |
| [d7da63f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/d7da63fd4797f8f16e09db9e5e54d1f68aa80794) | Integration only | History only; use reviewed final tree | Merge file type policy checks for folders, batches and undo into the 1.2.0 work |
| [97752bd](https://github.com/sintax-tech/mcp-for-nextcloud/commit/97752bd69433a2ecf6d48038c070ec841649c966) | Contribution | DOC: initial selected features; D06 claims added with its qualified follow-up | Present the full Deck cut in the guide, messages, translations and READMEs (#9) |
| [a285be3](https://github.com/sintax-tech/mcp-for-nextcloud/commit/a285be37cdb62c069d07cba96b226dc339fa79ba) | Integration only | History only; use reviewed final tree | Merge the full Deck guide, messages and documentation into the 1.2.0 work |
| [5a88b32](https://github.com/sintax-tech/mcp-for-nextcloud/commit/5a88b32294e7e7a6319b672e0fde5fcfeb6b2366) | Contribution | W01: port edit/replace/restore guards | Apply the file type policy to edit, replace and version restore (#15) |
| [a5e3070](https://github.com/sintax-tech/mcp-for-nextcloud/commit/a5e30705539640a3c698a938f77e19fe21c93b9d) | Integration only | History only; use reviewed final tree | Merge file type policy checks for edit, replace and version restore into the 1.2.0 work |
| [725773f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/725773f694342063e639da14de3721d8a74927a1) | Contribution | Already present: retain Files capability owners; no legacy helpers to import | Drop FilesModule helpers left unused by the handler extraction (#15) |
| [29b1761](https://github.com/sintax-tech/mcp-for-nextcloud/commit/29b1761e1a0e7dd816fbf9e2d3251c9a97d7642c) | Contribution | W01/N01: port complete Notes coverage | Apply the file type policy to Notes, including partial edits (#15) |
| [02dd161](https://github.com/sintax-tech/mcp-for-nextcloud/commit/02dd161609ab6f92e0686e9039cb5f336cd87dd2) | Integration only | History only; use reviewed final tree | Merge file type policy checks for Notes and partial edits into the 1.2.0 work |
| [ecba970](https://github.com/sintax-tech/mcp-for-nextcloud/commit/ecba970d8d7803b6a76939a673647cfaf4f2233e) | Contribution | W01/QA: adapt fixture; checkout ownership already present | Fix the file type policy fixture and move the checkout write handler out of the controllers (#15) |
| [b3d2705](https://github.com/sintax-tech/mcp-for-nextcloud/commit/b3d2705b1e926f193ad9f1a788f23358227d6608) | Contribution | D03/D05/QA: adapt message assertions | Align the Deck tests with the approved label and dependency messages (#9) |
| [e41cafd](https://github.com/sintax-tech/mcp-for-nextcloud/commit/e41cafd39102b618d23966c49adee9faf11dc0fa) | Contribution | W01: preserve receipts and vanished-item outcomes | Keep the file copy receipt unchanged and let a listed folder copy continue past a vanished item (#15) |
| [276376e](https://github.com/sintax-tech/mcp-for-nextcloud/commit/276376e3ffcc26af52d6ab5c9bf7531a512b8094) | Integration only | History only; use reviewed final tree | Merge the file type policy fixture and checkout handler fixes into the 1.2.0 work |
| [94815b7](https://github.com/sintax-tech/mcp-for-nextcloud/commit/94815b751be90678a8589ee3b3acb11f92af37b0) | Integration only | History only; use reviewed final tree | Merge the file copy receipt and listed copy fixes into the 1.2.0 work |
| [3286f48](https://github.com/sintax-tech/mcp-for-nextcloud/commit/3286f48a198266a956cb99e61a61b88bfc26520e) | Integration only | History only; use reviewed final tree | Merge the Deck test alignment into the 1.2.0 work |
| [d43c0f9](https://github.com/sintax-tech/mcp-for-nextcloud/commit/d43c0f9fac71bc0e63b7020f1eb51bc210beb5ab) | Contribution | D04/QA: adapt visible-folder unlink refusal; retain hidden/missing equivalence | Expect the folder answer for a Deck folder the editor can read (#9) |
| [5822966](https://github.com/sintax-tech/mcp-for-nextcloud/commit/5822966d9fe274b41126080758a2216e9d123437) | Contribution | W01/QA: retain structured plan assertions | Read the structured plan in the batch and undo policy tests (#15) |
| [9cd158d](https://github.com/sintax-tech/mcp-for-nextcloud/commit/9cd158d0d455931fa6cfbf7030233b74e40d94a4) | Contribution | C01: port bounded backup lock key | Keep the contact backup lock key within the file lock column so a second deletion is not blocked |
| [5f0ac1b](https://github.com/sintax-tech/mcp-for-nextcloud/commit/5f0ac1bd6b59d04853609853b46675f17e9455fe) | Integration only | History only; use reviewed final tree | Merge the Deck folder answer test fix into the 1.2.0 work |
| [63a99d7](https://github.com/sintax-tech/mcp-for-nextcloud/commit/63a99d7608d95f38842a18f1ad5d937a2bd42ebb) | Integration only | History only; use reviewed final tree | Merge the batch and undo policy test fix into the 1.2.0 work |
| [e1603eb](https://github.com/sintax-tech/mcp-for-nextcloud/commit/e1603eb39402f784fc3760df0823640af7928c6a) | Integration only | History only; use reviewed final tree | Merge the contact backup lock key fix into the 1.2.0 work |
| [229a1c8](https://github.com/sintax-tech/mcp-for-nextcloud/commit/229a1c80d2cc73dfc7601f7c06d86fabe3169d0b) | Contribution | QA: adapt Contacts provisioning | Provision the Contacts app in the integration harness |
| [d544b2f](https://github.com/sintax-tech/mcp-for-nextcloud/commit/d544b2f36bd81481c90b7c4e3c887ef5b27d6916) | Integration only | History only; use reviewed final tree | Merge the Contacts provisioning for the integration harness into the 1.2.0 work |
| [31720e1](https://github.com/sintax-tech/mcp-for-nextcloud/commit/31720e1b02fadd192211be466295227fd947fb1a) | Contribution | DOC: retain Spanish translation; defer German README | docs: add German and Spanish root READMEs |
| [eb89a29](https://github.com/sintax-tech/mcp-for-nextcloud/commit/eb89a2980953088ad7c1fb3687af5ca7c78ac9e0) | Integration only | History only; use reviewed final tree | Merge the German and Spanish root READMEs into the 1.2.0 work |
| [6fb38e4](https://github.com/sintax-tech/mcp-for-nextcloud/commit/6fb38e4796c234a863dd884ea6ec4a5bc1f0319d) | Contribution | META: defer release version to F3 | Prepare the 1.2.0-rc.1 release candidate |
| [55c23d4](https://github.com/sintax-tech/mcp-for-nextcloud/commit/55c23d4bbd76f46cfa74560a0141762123608b93) | Contribution | OBS/DOC: adapt log levels/wording; move guide already corrected | Correct the Deck move guide, Brazilian Portuguese card wording and expected refusal log levels |
| [0252611](https://github.com/sintax-tech/mcp-for-nextcloud/commit/02526119b845ccc1d8399be002da3e30b5527df6) | Contribution | META: defer release version; adapt only selected feature metadata | Release 1.2.0 |
