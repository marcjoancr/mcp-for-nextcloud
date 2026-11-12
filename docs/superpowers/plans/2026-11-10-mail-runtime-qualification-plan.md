# Mail runtime qualification implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline, TDD and one fresh whole-slice final review. Keep native cells serial and preserve the selected worktree.

**Goal:** Qualify the unchanged 41-tool Mail module on the exact pinned NC33/34 pairs, retaining NC35 and sending disabled.
**Architecture:** A separate v8 source artifact preserves immutable v7. One exact runtime-pair policy gates capabilities, draft entry, credential evidence and alias writes; direct native candidate guarantees precede per-pair admission and full enabled acceptance.
**Tech Stack:** MCP2.7.0, PHP8.4+, Mail5.12.5.8, pinned NC33.0.10/34.0.5/35.0.2, MariaDB10.11.19, PHPUnit and Chromium/native TLS fixtures.
**Spec:** [runtime qualification design](../specs/2026-11-10-mail-runtime-qualification-design.md).

## Global constraints

- Start from 425d7fb58ef48e77237693559d296e82db79f7ae in the selected worktree.
- Preserve immutable v1–v7 manifests, v7 overlay 903c4ec7 and clean MCP package 2c4f2c21.
- Keep 41 tools, grants, credential ownership, A/D journal, native protocol/cache/draft safeguards and MCP sending disabled.
- Qualify exact core/build pairs only, primary unsharded/unreplicated InnoDB. No upgrade, new DB/provider, live install, publication or cleanup scope.
- Extended pairs begin closed. No deployment or production policy environment bypass.
- Native cells serial, functional sources frozen per run. Node24 and project TMPDIR for checks.

### Task 1 — Exact runtime and harness policies

**Files:** new Integration/MailRuntimePairs.php; MailNativeBoundary.php, MailCapabilities.php, NativeDraftLinks.php, NativeSetupEvidence.php, MailAliasBoundary.php; Unit/Modules/Mail/MailRuntimePairsTest.php, Unit/Integration/MailHarnessTest.php; ci/matrix.php, harness.sh, run-local.sh; native builder and immutable auth-v7-manifest.json.

- [x] RED: exact NC35 legacy pairs, malformed/future/legacy-on-NC33 rejection and 33/34 fresh Mail matrix.
- [x] Implement shared runtime matching with no extended cores initially; use it at all four admission surfaces.
- [x] Produce v8 with only native appinfo version/range differences, add immutable v7 fallback to all prior source scopes, compare native overlapping bytes.
- [x] Extend only 33/34 MariaDB/fresh Mail matrix; explicitly validate candidate/enabled phase for guarded extended cells.
- [x] GREEN focused policy/matrix/source/API/architecture checks; preserve existing NC35 behavior.

### Task 2 — Closed native candidate guarantees

**Files:** Integration/Mail/McpMailRuntimeQualificationTest.php; credential fixture and browser runners; existing native protocol/session/cache tests.

- [x] Candidate assertion: new pair not admitted, guarded capability metadata unverified, draft/credential adapters unavailable, aliases refused, sending disabled.
- [x] Candidate credential arming only if actual adapter unavailable; reuse original native form/provider/tab producers and exact scopes.
- [x] Execute NC33 candidate with direct native selection/generation, move/folder/tag, bounded refresh, cache/session/SQL and draft concurrency guarantees plus all credential browser cases.
- [x] Execute NC34 candidate with the same guarantees. Diagnose native failures from implementation; retain any failing pair closed.

Candidate PHP filter initially selects runtime closure plus direct native
selection, native movement/cache, folders, keyword semantics, refresh and seven
draft concurrency tests. Existing exact-cache tests that call the MCP tag target
remain for enabled acceptance; direct native refresh verifies exact cache
transactions before admission.

### Task 3 — Per-pair enabled acceptance

- [x] After each candidate passes, add a failing exact-core/.8 admission expectation, admit only that core, run GREEN focused policy tests.
- [x] For each admitted core run complete native Mail group, plain/HTML draft browser and credential/browser/tab cases, no manual context arming.
- [x] Both pairs passed enabled acceptance; no closure was needed. A later failing pair must close again before fixing.
- [x] Run stock NC33/34 with explicit unverified guarded metadata expectations; retain legacy behavior.
- [x] Run current NC35/v8 source/runtime admission and tamper, alias/journal and native thread round-trip regression plus full plain/HTML/credential browsers; retain the exhaustive v7 NC35 protocol/session evidence because all 128 implementation fingerprints are unchanged. Verify immutable v6/v7 scope/tamper compatibility.

### Task 4 — Final acceptance and local artifacts

- [x] Complete devbox check with Node24/project TMPDIR, documentation and native artifact verification.
- [x] One fresh whole-slice final review, one consolidated fix pass and relevant verification.
- [x] Update master plan, runtime evidence/limits and exact hashes.
- [x] Local commit and clean source/native artifacts; no push, PR, live install or sending.

## Final acceptance

Both extended pairs pass closed-candidate native guarantees (18 / 176 each) and
complete enabled acceptance (57 / 1,424 each), plus all plain/HTML and
password/Google/Microsoft/late-tab browser workflows without manual context arming.
Stock NC33/34 each pass 18 executed / 39 expected unsupported skips / 412
assertions. NC35/v8 passes four changed-surface regression tests / 105 assertions
and every browser workflow, retaining exhaustive v7 NC35 native evidence for the
128 unchanged implementation files.

The one fresh review's Important source-phase mismatch is fixed in one
consolidated pass: actual source and isolated pre-admission copies pass RED→GREEN,
and qualification phases reject external kits. Twenty focused tests / 199
assertions pass. Final source intentionally rejects candidate mode for admitted
pairs before any run/container creation. No production gate was weakened.

The final complete catalog passes 10,379 PHP / 95,640 assertions and 837 browser
tests, both static scopes and package. Actual immutable v6/v7 source/tamper checks
pass 1 / 31 and 1 / 37 respectively. Final documentation validates 230 Markdown
files and 116 package sources. Translation freshness is advisory (100 review
required, four missing); provenance is valid.

The normalized v8 overlay reproduces the 129-entry source manifest with 88 files,
modes644/755 and uid/gid/time0. Local source/package identity is retained in the
external execution record after clean packaging. All work remains local, sending
disabled; unknown pairs, upgrades and deployment are separate acceptance work.
