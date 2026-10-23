<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Deck native file linking implementation and review

> For agentic workers: use superpowers:subagent-driven-development for the scoped implementation and review gates below.

**Goal:** Review the selected 1.2.0 integration and add its remaining Deck file-link capability when consistent with existing fork sharing behavior.

**Architecture:** Adapt the pinned upstream D06 implementation into focused `Modules/Deck/Files/Linking` and `Modules/Deck/Recipients` owners. Reuse existing platform visibility, grant, native session, write confirmation and plan-state boundaries. A thin `Modules/Deck/Tools/LinkCardFile` adapter registers one tool through the existing catalog and lazy module composition.

**Tech stack:** PHP 8.2-8.5; existing Nextcloud 32-35/Deck matrix; unchanged Composer dependencies and migration IDs.

**Specification:** The contract below supersedes D06's deferment in `upstream-1.2.0-inventory.md`. Other original decisions remain. Source behavior is pinned to `02526119b845ccc1d8399be002da3e30b5527df6`; review baseline is `04eb9a6`.

## Decision and alternatives

The user authorizes adding coherent 1.2.0 capabilities. Review initially recommended continued deferment because a native Deck share includes future members and is not automatically revoked after later MCP policy changes. Comparing existing `Files/Sharing/ShareWriter` and its group warning showed that native group sharing already has that lifetime model. Persistence alone is therefore not a consistent reason to exclude D06.

Adopt native Deck semantics with explicit audience/policy preview and strict refusal gates. Retaining deferment would omit the only remaining application capability without a demonstrated incompatible product rule. Creating a private synthetic or fixed-recipient share would change native Deck behavior and introduce unsupported maintenance responsibilities; do not do that.

This authorizes a card-scoped native READ share, including a reshare only when native SHARE permission and current core policy allow it. Preview identifies owner and reshare; existing shared-write confirmation applies. It does not grant general unrestricted resharing or bypass native/MCP permissions. All future-member and non-atomic limits must be visible in the tool and guide.

## Binding contract and global constraints

- Code and comments stay English. Keep original attribution and applicable source-license notices.
- Preserve public app ID, name, version 1.1.2, all migration IDs and dependency locks. F3, human reviews, public HTTPS, Inspector and ChatGPT remain pending. No push, deployment or PR change.
- Register `deck_link_card_file` only with `deck.edit`, `files.read`, `files.share`; check again on preview and execution. Preserve all existing tools and their grants. Correct discovery of `deck_list_card_files` and `deck_unlink_card_file` to require their already enforced `files.read` grant; unlink must not acquire a `files.share` requirement.
- Require positive cardId/fileId, active editable card/board, visible readable regular file (not folder), native SHARE permission and exact authenticated actor. Revalidate Files scope/exclusions/aliases and grants on confirm.
- Preserve pinned D06 policy behavior: deny Guests, unknown identity/policy, group-only sharing, mandatory internal expiry, disabled sharing, forbidden reshare, group/Circle audiences when group sharing is disabled, unresolved/nonlocal/unsupported or over-budget recipients. Do not suggest bypassing these policies.
- Resolve the complete current board audience (owner, direct users, groups, Circles/Teams) with pinned limits, deduplication, bounded display names and a full opaque snapshot. Read actor/native authorization before any privileged Circles expansion and preserve native session discipline; no privileged state may escape to later authorization.
- `confirm:true` requires the existing opaque `plan_state`, bound to arguments, actor, card/board/source, current metadata/permissions, audience, core policy and existing shares. Any drift refuses before mutation. Existing `confirm_shared` behavior must remain consistent with other shared Deck writes.
- Create exactly one native type-12 READ=1 association via IShareManager; no upload/copy/file deletion, synthetic sharing fallback or recipient membership changes. Duplicate/no-op and post-throw observed-write handling must reflect actual native state without hiding uncertain effects. Existing unlink affects only that association.
- Plans/tool description/guide disclose current and future recipients, read-only access, notifications/default-expiry consequences as upstream specifies, no continuous revocation and the gap between last validation and native write. No claim of atomic policy/membership/share enforcement.
- Keep fresh/upgrade/recovery harness behavior and security guards unchanged. No suppressed failures, unsafe test credentials, broad snapshot refresh, extra shared engine control or transition wrappers.

## Review focus

1. A warm optional Circles service or privileged expansion must not let a later request bypass native authorization: unit refusal/session-order checks plus native recipient cases.
2. Same fileId through hidden/Guest aliases must refuse without leaking names or writing: existing alias/Guests fixture extensions.
3. Audience, permission, core policy or file identity changing after preview must invalidate plan before a share is created: boundary regressions.
4. A native write that throws after committing must return truthful observed/uncertain status and avoid duplicate shares or deleting unrelated associations: native boundary tests.
5. Optional services absent or unsupported must fail closed at the relevant call without breaking unrelated discovery or Deck operations: lazy-composition/API contracts.

## Task 1 — Complete D06 capability and behavioral qualification

Ownership: new runtime files under `mcp/lib/Modules/Deck/Files/Linking/`, `mcp/lib/Modules/Deck/Recipients/`, and `Tools/LinkCardFile.php`; necessary existing Deck access/contract/composition/catalog/presentation files; existing localized UI messages; focused unit/architecture/API and native/Guests fixtures; fixture provenance/generation only for newly consumed APIs. No unrelated refactor.

Pinned source owners: `Tools/Deck/{CardFileLinkPreparation,CardLinkPolicy,CardLinkPlan,NativeCardFileLink,BoardRecipientResolver,DeckRecipientDirectory,RecipientDirectory,RecipientExpansion,LinkRecipientContext,RecipientSnapshot,RecipientResolutionException,CardLinkMessages,RecipientMessages,CardLinkTool}.php` and `Handler/LinkCardFileHandler.php`. Adapt to local ownership, do not restore upstream `Service/` or generic `Tools/` directories. Focused names may follow native responsibilities; no compatibility forwarders.

Interfaces: use the D06-only `NativeLinkSource` for unenriched native source authorization, and consume existing `DeckCaller`, `GrantPolicy`, `FilesAccessGuard`, `CardFileNodeAccess`, `NodeAccessInfo`, `SharedWriteGuard` and `PlanState` contracts after inspecting their actual signatures; expose the existing local `WriteTool` preview/execute interface and optional native adapter factory. A trailing optional internal `ToolDefinition::grantAllOf` map (`module => list<operation>`) expresses the additional Files read/share requirements generically in `ToolAvailability`; no tool-name-specific branch or new public MCP field. Existing `grantAnyOf` behavior remains unchanged. Do not alter unrelated public interfaces merely to mirror upstream constructors. The source callable avoids the existing all-board ACL hydration path; clear Circles privilege between authorization stages and after recipient expansion as well as at operation boundaries. An absent readback after attempted creation remains uncertain, since it cannot prove that no association was persisted and subsequently removed.

- [x] Port meaningful public/discovery and refusal regression tests first; observe a missing-tool/behavior assertion fail, not only a class-load error.
- [x] Adapt the native capability/recipient/policy/plan implementation, thin tool and lazy composition. Preserve pinned exact limits and explain any required divergence.
- [x] Port focused upstream `CardFileLinkTest`, `BoardRecipientResolverTest`, `DeckRecipientDirectoryTest`, native `CardFileLinkTest`/`BoardRecipientTest` and fixtures, plus necessary Guest scenarios. Preserve behavioral assertions; resolve local fixture interfaces rather than weaken expectations.
- [x] Run focused new tests, complete PHP suite, lint, both static-analysis scopes, API/architecture contracts and attribution. Update precise catalog counts (Deck21/18 without dependencies; modules82; max84 including2diagnostics,81 without dependencies) only after checking actual discovery.
- [x] Independent task review; fix concrete findings with regressions and scoped re-review.
- [ ] Run native focused cases on NC32,33,34,35 using pinned Deck versions, plus PostgreSQL/SQLite on35 where relevant. Run a final unfiltered NC35/MariaDB cell to catch fixture-order interactions. Old16-cell evidence remains valid only for the prior revision; identify new qualification separately. Record failures and cleanup, do not claim a fresh16-cell matrix unless run.
- [ ] Commit the reviewed candidate after local gates to pin native evidence, then close this task only after its native gates pass; no publication.

## Task 2 — Reconcile documentation and review evidence

Ownership: canonical English README/guides/configuration/architecture/store metadata where tool inventory or linking claims change; inventory/source ledger/fork plan/integration report; translations retain true provenance and review-required labels. Historical A-E evidence stays immutable in meaning and is explicitly identified as preceding D06.

- [x] Document review of the existing A-E implementation and independently audit all16 saved JUnit/results/source identities/archive hashes and725 runtime source files per archive against baseline.
- [ ] Replace current D06-deferred claims with actual implemented semantics after Task1 passes; retain historical deferment and explain why the renewed authorization/comparison supersedes it. QQUEUE, German README, F3 metadata and pending human/client paths remain separate.
- [ ] Record new test/review/packaging outcomes and limitations; no human approval or unrestricted full-version compatibility claim.
- [ ] Run docs check, translation provenance, documentation tests, production package and REUSE; use full catalog check for the final applicable code state and targeted interpreter coverage if new syntax/dependencies warrant it.
- [ ] Whole-change review, correct material findings, final local commit and clean-tree verification.
