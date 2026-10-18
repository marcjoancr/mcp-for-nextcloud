<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Shared MCP infrastructure migration execution report

Status: implemented, locally validated and independently reviewed.
Execution baseline: `2f80fc8` (reviewed plan; production identical to `2d644da`).
Worktree: `docs/talk-capability-plan`. No branch rename, push, PR or deployment.

## Completed ownership

All 39 original Tools/Service owners are retired. No `lib/Tools`, `lib/Service`,
`tests/Unit/Tools` or `tests/Unit/Service` directory remains. All 23 original files
from the retired test/support trees and the five additional mapped tests moved to
final owners; every original method/provider case remains discoverable.

- `Mcp/Protocol`: envelope/version validation, metadata, safe responses and three
  request families. Both supported protocol eras retain their behavior.
- `Mcp/Tools`: ordered registry and contracts, with Discovery, Execution,
  Validation and Results. Execution preserves audit ordering and exception boundaries.
- `Mcp/Guide` and `Mcp/Confirmation`: guide/schema rendering, write gate,
  stateless confirmation, plan rendering and confirmation messages.
- `Access/Grants`, `Visibility`, `Logs`, `AppAvailability`: final policy owners.
- `Platform/FileAccess/{Nodes,Paths,Locks,Sharing}`, existing backup owners and
  FileAccessMessages; `Platform/Time`, `Platform/Logging`; `OAuth/Connections`.

Application composes the same availability instance into registry and executor;
file/note readers, checkout, sharing and Logs privacy use the same visibility guard.
The guide tool and resource use one guide. No alias, wrapper, forwarding constant,
duplicate service ID, transition loader or second registry was added.
VisibilityGuard and LockAwareWrite remain cohesive algorithms, relocated intact.

## Ordered phase evidence

All commands use the catalog's PHP limits (memory, post and upload: 1G).
PHP 8.4.26 / PHPUnit 10.5.65. Baseline: **5,234 tests / 42,798 assertions**.

| Phase | Commit | Focused validation | Full gate |
| --- | --- | --- | --- |
| 0: freeze synthetic contracts | `0526581` | 176 / 1,099 | 5,239 / 42,864 |
| 1: contracts, messages, support | `429de65` | 521 / 17,493 | 5,240 / 42,917 |
| 2: policy and native facilities | `bb8497c` | 292 / 12,868 | 5,241 / 43,008 |
| 3: publication, execution, guide | `cac7463` | 145 / 12,415 | 5,245 / 43,112 |
| 4: protocol request families | `7989955` | 206 / 14,370 | 5,249 / 43,236 |
| 5: boundaries and final composition | this report's commit | architecture 40 / 12,960; composition 1 / 53 | 5,253 / 44,744 + production package |

New extraction seam tests failed before the corresponding owners existed and
passed after extraction. The boundary scanner's negative examples failed against
empty scanners before implementation. Composition tests exercise real registration
closures and native host doubles; only external host boundaries are mocked.
Original visibility, lock, native events, partial mutation, share alias, backup,
ETag, confirmation and protocol malformed/header cases remain mandatory gates.

During execution, fixture setup and relocated test paths/imports were corrected
without weakening expectations. The first Phase 3 full run found missing original
copyright attribution on five extracted owners; restored original SPDX headers,
passed the focused licensing test and repeated the full gate. Phase 0's commit was
issued while its full task was finishing; success was confirmed before Phase 1 began.
Every subsequent phase commit followed inspected full success.

## Compatibility and discovery

`SharedMcpMigrationCompatibilityTest` freezes synthetic publication, protocol and
execution output in English, Brazilian Portuguese and Spanish from unchanged
production. Raw definitions, published definitions, guides, resources, prompts,
default/no/full/alternative grants and both built-ins remain byte-identical.
Dynamic tests and existing suites cover role, app allowlist/version, locale, audit,
confirmation and both protocol-era boundaries. Invalid UTF-8 is asserted as raw PHP
bytes; empty objects remain objects. No captured MCP output was normalized.

Full XML discovery was reconciled by exact class mapping: **5,234 original expanded
cases retained, zero missing, 19 added, 5,253 total**. Original method/provider
names and dataset identities are retained. Native API coverage is unchanged:
**2,129 tests / 4,126 assertions**, passing. No provider was removed or exemption widened.

The only old-capture change is Notes `results.json` at `/missing/type` and
`/too_large/type`, from the retired ToolFailure FQCN to its final Results owner.
These are direct PHP exception identities, not MCP fields. The byte diff is exactly
two value substitutions; messages and every other field are unchanged. No recapture,
namespace normalization, alias or old loader is used. Original capture: `d51e4f4`;
execution baseline: `2f80fc8`; change commit: `429de65`. Its README records provenance.

- Before SHA-256: `efcd79a7e73bb465288ce86d47e9e43472396b3f0fdeddca5fad581523fc309f`.
- After SHA-256: `cf9dabe39bbd337de7ee26fe3e091e93feb7fad8403ca503768894ae0806b5a8`.

All other pre-existing feature capture files and all Phase 0 captures retain their
recorded hashes. All 14 pre-existing tracked test JSON files were also compared
directly to baseline Git; only the same approved Notes identity edit differs. The exact list of new cases is:

- `OCA\Mcp\Tests\Architecture\SharedMcpBoundaryTest::testNoRetiredRuntimeSupportOrLoaderRemains`
- `OCA\Mcp\Tests\Architecture\SharedMcpBoundaryTest::testScannerRejectsForbiddenEdgesWithoutPrefixFalsePositives`
- `OCA\Mcp\Tests\Architecture\SharedMcpBoundaryTest::testScannerRejectsOldImportsStringsLoadersAndAliases`
- `OCA\Mcp\Tests\Unit\ApplicationRegistrationTest::testSharedPolicyFactoriesPreserveInstanceIdentity`
- `OCA\Mcp\Tests\Unit\ApplicationRegistrationTest::testSharedProtocolFactoriesPreserveComposition`
- `OCA\Mcp\Tests\Unit\Contract\SharedMcpMigrationCompatibilityTest::testFrozenSharedContracts`
- `OCA\Mcp\Tests\Unit\Contract\SharedMcpMigrationCompatibilityTest::testSharedConfirmationRemainsStatelessAndBound`
- `OCA\Mcp\Tests\Unit\Contract\SharedMcpMigrationCompatibilityTest::testSharedExecutionPreservesAuditAndFailureOrder`
- `OCA\Mcp\Tests\Unit\Contract\SharedMcpMigrationCompatibilityTest::testSharedProtocolPreservesBothEraBoundaries`
- `OCA\Mcp\Tests\Unit\Contract\SharedMcpMigrationCompatibilityTest::testSharedPublicationRechecksIdentityGrantsAppsAndLocale`
- `OCA\Mcp\Tests\Unit\Mcp\Confirmation\ConfirmationMessagesTest::testExactInstructions`
- `OCA\Mcp\Tests\Unit\Mcp\Guide\GuideSchemaTest::testNestedTypesConstraintsAndFalseDefaultsStayReadable`
- `OCA\Mcp\Tests\Unit\Mcp\Protocol\JsonRpcResponseTest::testSafeFieldsNeverExposeServerState`
- `OCA\Mcp\Tests\Unit\Mcp\Protocol\PromptRequestsTest::testGrantChangesAndArgumentAcceptanceAreUnchanged`
- `OCA\Mcp\Tests\Unit\Mcp\Protocol\ResourceRequestsTest::testMissingHiddenAndInvalidResourcesKeepEraSpecificErrors`
- `OCA\Mcp\Tests\Unit\Mcp\Protocol\ToolRequestsTest::testDiagnosticAndValidationEnvelopesMatchBothEras`
- `OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery\ToolAvailabilityTest::testRoleGrantAndAppChecksKeepTheirOrder`
- `OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery\ToolPublicationTest::testProjectionKeepsOrderObjectsAndConfirmation`
- `OCA\Mcp\Tests\Unit\Mcp\Tools\Execution\ToolExecutorTest::testAuditUsesCurrentArgumentsAndPreservesFailureBoundaries`

## Package and composition

`devbox run check` passed the full suite and production archive validation.
Archive SHA-256: `e20b3a2f13d9e2cf74c14ebfd0a0c329eaadb7de47662da9e79df6430162a882`.
A fresh PHP process loaded the actual archive's production autoloader, without
repository vendor or test bootstrap. Minimal explicit Nextcloud/Psr declarations
supply host contracts; they are not runtime shims and are not packaged.

The probe loaded **51 final mapped/extracted types plus Application (52)** and
compared their SHA-256 bytes to the worktree. All classmap paths stayed inside the
extracted package; no tests/PHPUnit or old owner classes/trees were present. With
synthetic full access and throwing feature factories, all nine catalogs, published
definitions and the guide matched frozen English output. The protocol listed
**74 feature tools plus `mcp_status` and `mcp_guide` (76)**. No feature factory ran.

Application registration tests verify the actual controller → protocol → registry
chain, lazy unavailable apps, role/grant refusals and invalid Logs audit without
constructing LogReader. Revoking connection policy affects the next controller call.
Registered native events invalidate the shared visibility guard; corrupt settings
remain fail-closed. This is host-double composition evidence, not a live server test.

## Decisions and review

The only workflow ruling was to keep the execution ledger, briefs and probe scripts
outside the repository, honoring server instructions instead of the skill's local
scratch folder. Cost if wrong: manual bookkeeping; no runtime effect. The plan's
approved two Notes identity substitutions were applied exactly as specified.

Independent final review covered `2f80fc8` through the complete Phase 5 working
tree. It found no Critical, Important or Minor issue and declined no behavior for
judgment. It checked extraction ordering, unchanged executable policy/feature
algorithms, all 17 split message bodies, five guide-schema methods, final composition,
retirement, captured bytes and package evidence. No review fix pass was needed.
The reviewer inspected recorded test/probe results rather than rerunning the suite.
No deferred minor findings remain. Final worktree remains on the existing branch
for human review, as required by the plan; no integration action was requested.

## Remaining work

The shared MCP migration itself has no remaining source/test transition work.
Retained Db migrations, OAuth internals, framework controllers/settings and native
API version compatibility are genuine product owners, not migration leftovers.

Before release, run the PHP 8.2–8.5 CI matrix and disposable Nextcloud 32–35 checks
with synthetic users/files/logs: Basic/Bearer identity, both protocol eras, grant and
connection revocation, optional apps, resources, confirmations, share/hidden-tag
privacy, events, locks and audit failure. Those environments were not run locally.

Next planning candidates, in order: stable contributor commands and incremental
static analysis; administration JavaScript separation; README/docs navigation and
translation rollout. Keep English canonical, use parallel locale paths and link
only translations that exist. No work in these scopes was implemented here.
