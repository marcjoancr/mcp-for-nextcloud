# Exclusion contract matrix (issue #4, A1)

These are local contracts using OCP doubles and an in-memory tree. They do not
certify Nextcloud core behavior, storage mounts, authentication sessions or event
delivery. A4 must provide that evidence in the private Actions repository.

For byte comparisons, keep the request identical and remove its excluded target
between calls. This preserves caller-supplied paths in batch summaries while
proving that the response cannot distinguish exclusion from absence. Creating a
missing destination can succeed; an excluded destination always refuses with
`CommonMessages::notFound()` before any mutation.

| ID | Local executable evidence | Remaining evidence / owner |
| --- | --- | --- |
| V1 | `VisibilityContractTest::testV1ExcludedAndMissingToolsAreByteIdentical` compares complete serialized results for 23 tool inputs, including explicit image batches, versions, shares, Notes and folder reads. `testEveryFileTouchingToolHidesTaggedNodeDirectlyAndViaAncestralFolder` covers direct and inherited tags, listings and writes. `FilesSearchContentTest::testVisibilityGuardFiltersHiddenFileFromFtsAndNameSearch` covers name/full text filtering before snippets. | Real OCR/core extraction behavior is not certified by doubles. |
| V2 | `VisibilityContractTest::testV14RepeatedOperationsReflectTagAndMoveChanges` checks a new multilevel descendant without a direct tag. Existing create/mkdir/Notes contracts refuse creation inside an excluded folder without effects. | A4: actual externally created descendants and native core inheritance. |
| V3 | Repeated-operation contract plus `VisibilityGuardTest` move-in/move-out event tests, partial-mutation invalidation and `testV14TagEventsInvalidateTheSameGuard`. | A4: actual Files/WebDAV mutations and event delivery. |
| V4 | Existing owner-tree guard fixtures cover direct/immediate/high ancestors, multiple owner aliases and failed owner resolution. They are supporting contracts only. | A4: real local file and subfolder shares; no real coverage claimed here. |
| V5 | Mounted-path alias regression in `VisibilityGuardTest::testV13VisibilityCacheIsQualifiedByMountedPath`; existing external/team-folder fixtures check local tags. | A4: provision groupfolders and local files_external; prove narrow mounts and aliases. Gate remains unproven locally. |
| V6 | `VisibilityGuardTest::testFederatedShareStaysVisibleWithoutLocalOwnerFolder` and `testFederatedShareStillHonorsLocalHiddenAncestor`. Remote owner tags are never inferred from a cloud ID; only locally visible tags apply. | Remote server exclusion configuration is outside this connector's authority. |
| V7 | `testV7HiddenDestinationAndResolutionFailureUseGenericRefusal`, existing Files copy/move/batch/create/mkdir/undo, Notes and version tests; `FileBackupPrivacyTest` verifies tag assignment before backup content and no content on tag failure. | D7 folder classification, zero enumeration and folder-share URL suppression belong to A3. Actual core writes/events belong to A4. |
| V8 | `ResourceRegistryTest::testV8ExcludedAndMissingResourcesAreByteIdentical` compares file/folder/Notes failures; existing list/templates tests expose no user items. Both protocol eras retain existing hidden-resource tests. | None for the local resource contract. |
| V9 | Existing direct Talk attach/quote refusal contracts remain in `VisibilityContractTest` and Talk tests. | Structured reference output is A2; real Talk shares are A4. |
| V10 | Outside A1. | A2 owns structured Tasks/Calendar/Deck output and write preservation. |
| V11 | `LogsModuleTest` covers hidden messages, DAV URL tails, user agents and the disabled switch; `LogRedactorTest` covers field sanitization. | Free text with hiding disabled keeps its documented behavior. |
| V12 | V1/V8 comparisons; `CheckoutControllerTest::testV1CheckoutExcludedAndMissingAreByteIdentical` compares HTTP status and serialized body for download and upload. Existing `ToolRegistryTest` unexpected-failure tests assert generic client errors and class/code-only app logs. | D7 refusal is A3. Missing and excluded checkout targets use the common resource refusal; invalid credentials retain their existing refusals. |
| V13 | New guard tests cover unreadable config with retry, invalid/cyclic ancestry including backup tagging, excluded root preflight, late metadata failure without a positive cache entry, and mounted alias cache isolation. Existing tests cover mapper retry, malformed configuration and failed settings saves preserving prior protection. | Native core resolution and storage failures are additionally exercised by A4. |
| V14 | `testV14RepeatedOperationsReflectTagAndMoveChanges` models request boundaries with cache invalidation; the event test exercises assignment/unassignment on the same guard instance. No credential is revoked. | A4: actual existing Bearer and Basic sessions, tag changes and moves across requests. Local tests do not claim authentication-session certification. |

A1 changes no tool response shape, schema or argument list. It standardizes missing
image-batch reasons and excluded destination messages with existing translated
`CommonMessages::notFound()` and refuses unresolved visibility checks. No new
translation keys are introduced.

A4 must also include real positive controls: an unexcluded file in the user's
home, an internal share, a groupfolder and local external storage must remain
visible. The guard requires ancestry terminating in `IRootFolder`; these controls
must fail CI if a real mount violates that assumption and hides every file.

The implemented A3 gate, its D6 precedence, URL nullability and translation fallback
are specified in [the D7 contract map](FOLDER-EXPOSURE.md) and `FolderExposureTest`.
