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
| V9 | Direct attach/quote refusals (plan and confirm) remain in `VisibilityContractTest` and Talk tests. `StructuredReferenceContractTest::testV9*` proves `talk_read_messages` reports `attachmentId: null` for a room share of a file excluded directly or by an ancestor, of a missing share and of a file only outside the Files scope (scope double), with the message text and type unchanged and excluded byte-identical to missing; without exclusion or scope the id passes as before. `OutputReferenceFilterTest` covers share of another conversation, non-room share and unreadable files. | A4: real room shares (phase 1, tag). B5 wires the real Files scope into the filter and into the attach/quote refusals; B8 proves phase 2 for real. |
| V10 | `StructuredReferenceContractTest::testV10*`: `tasks_read_task`, plans without confirm of edit/complete/delete and confirmed edit/complete results show only allowed or external ATTACH, for a tag exclusion and for a file only outside the scope; `tasks_list_tasks` carries no raw calendar; before/after lose the same ATTACH so the plan shows no removal; the DAV object read back after an edit or a completion keeps every ATTACH. `OutputReferenceFilterTest` covers each supported local URL shape (/f/{id}, /apps/files/files/{id}, ?fileid=, /s/{token}, /remote.php/dav/files/{uid}/…, /remote.php/webdav/…, web root, /index.php, relative, wildcard trusted domains, ?dir=), the same shapes on any host (loopback, IP, alias under another web root; a node of the user refused there is removed; a node the user lacks, a shape naming no checkable node or a path inside a share unknown to this server stays), unresolvable local URLs removed, external URLs without a Nextcloud shape and inline ATTACH kept, a public folder link removed while an exclusion is configured or unreadable (D7), the Calendar app's `X-NC-FILE-ID` (fail-closed) and `FILENAME` parameters (refused → ATTACH removed, absent from the tree → parameter dropped), every alias of an id having to pass, nested VALARM, invalid configuration and that the filter holds no HTTP client. `testA6*` repeat the folder link, the hosts and the Calendar parameters through `tasks_read_task`. `EventAttachmentOutputTest` proves no Calendar tool (every one, plan and confirmed, plus an error) carries ATTACH or a file URL, and that an update keeps the event ATTACH. `CardFormatterTest::testCardReportsOnlyTheAttachmentCount` covers Deck. | Real CalDAV objects and real trusted_domains are exercised by A4. |
| V11 | `LogsModuleTest` covers hidden messages, DAV URL tails, user agents and the disabled switch; `LogRedactorTest` covers field sanitization. | Free text with hiding disabled keeps its documented behavior. |
| V12 | V1/V8 comparisons; `CheckoutControllerTest::testV1CheckoutExcludedAndMissingAreByteIdentical` compares HTTP status and serialized body for download and upload. Existing `ToolRegistryTest` unexpected-failure tests assert generic client errors and class/code-only app logs. | D7 refusal is A3. Missing and excluded checkout targets use the common resource refusal; invalid credentials retain their existing refusals. |
| V13 | New guard tests cover unreadable config with retry, invalid/cyclic ancestry including backup tagging, excluded root preflight, late metadata failure without a positive cache entry, and mounted alias cache isolation. Existing tests cover mapper retry, malformed configuration and failed settings saves preserving prior protection. | Native core resolution and storage failures are additionally exercised by A4. |
| V14 | `testV14RepeatedOperationsReflectTagAndMoveChanges` models request boundaries with cache invalidation; the event test exercises assignment/unassignment on the same guard instance. No credential is revoked. | A4: actual existing Bearer and Basic sessions, tag changes and moves across requests. Local tests do not claim authentication-session certification. |

A2 adds `OutputReferenceFilter` with an empty Files scope step (`FileReferenceScope`, registered as
`UnrestrictedFileReferenceScope` until B5). It changes no schema: `attachmentId` was already nullable in
`talk_read_messages`, and a task's `icalendar` stays a string. With no exclusion and no scope nothing is resolved
and every reference passes untouched.

A1 changes no tool response shape, schema or argument list. It standardizes missing
image-batch reasons and excluded destination messages with existing translated
`CommonMessages::notFound()` and refuses unresolved visibility checks. No new
translation keys are introduced.

A4 must also include real positive controls: an unexcluded file in the user's
home, an internal share, a groupfolder and local external storage must remain
visible. The guard requires ancestry terminating in `IRootFolder`; these controls
must fail CI if a real mount violates that assumption and hides every file.
