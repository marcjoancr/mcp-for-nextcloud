# Native Mail Draft Lifecycle Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline, TDD and one fresh final review. Keep harness records outside the repository.

**Goal:** Preserve reply parent and selected owned sender across native UI edit and native IMAP draft conversion, with sending disabled.
**Architecture:** Extend the pinned native serializer through v6 of the existing dependency. Preserve the MCP local hold, native MIME ownership and shared journal; publish stronger capabilities only after native qualification.
**Tech Stack:** PHP8.4+, Mail5.12.5, NC35.0.2/MariaDB, existing PHPUnit/Playwright/disposable native runner.
**Spec:** [draft lifecycle design](../specs/2026-11-10-mail-draft-lifecycle-design.md).

## Global constraints

- Baseline9c2e1ffc, existing selected worktree/branch. No deployment, push/PR or worktree cleanup.
- Keep41tools, all grants, MCP failed=true hold, Files originals, current source checks and no-send unchanged.
- Native serializer plus bounded owner-only composer entry and persisted draft-only protection; no MCP SMTP/outbox/enqueue/MDN call or alternate MIME/IMAP implementation.
- Explicit missing/foreign alias refuses; never adopt an address or substitute a sender. Validate parent before effects.
- Keep v5 immutable and accepted for prior scopes. New draft qualifier stays closed until native proof.

## Review focus

1. Alias removed, foreign or moved to another account between local creation and conversion: refuse before APPEND; retain local content.
2. Parent header controls/oversize/empty value: refuse before native client creation; no injected headers.
3. UI editing of held reply with Files/MIME attachments: preserve explicit reviewed data and original Files bytes.
4. Fingerprint tampering, stock and legacy v5: do not falsely report v6 guarantees or regress prior guards.
5. Native conversion/SMTP shared class: sending methods unchanged and no outgoing, SMTP or automatic synchronization effect from MCP/status.

### Task 1 — Observe native failure

**Files:** `mcp/tests/Integration/Mail/{McpMailLifecycleTest,MailLifecycleActor}.php`.
**Consumes:** Existing HTTP reply writer, native fixture alias and c0-draft-proof action.
**Produces:** Real IMAP header assertions for qualified candidate; stock remains a negative control.

- [x] Change the guarded-cell reply lifecycle expectation to exact alias From, In-Reply-To and References; preserve native hold/outgoing/SMTP assertions, including the new protected mode.
- [x] Preview then run NC35/MariaDB fresh Mail candidate with `--filter testReplyParentAcrossNativeUiEditAndImapFlush`; observe missing native identity/header assertion fail.

### Task 2 — Native serializer and closed qualification

**Files:** `native/mail-guard/{patch-drafts.php,build.php,refresh-v5-manifest.json}`, native manifest and `mcp/lib/Modules/Mail/Integration/{MailNativeBoundary,MailCapabilities}.php`; capability/architecture tests.
**Consumes:** Pinned official archive and existing v5 guard manifests.
**Produces:** Deterministic v6 overlay, legacy v5 compatibility and source-qualified draft capability.

- [x] Add only bounded exact native source transformations for owned alias and validated reply headers in saveLocalDraft. Assert native sending method unchanged.
- [x] Preserve v5 data, generate v6 manifest and extend prior guard matching without weakening source/schema/provider checks.
- [x] Add qualifier/capability regression: stock/legacy/tamper never gain draft guarantees. Keep new qualifier closed until native acceptance.
- [x] Run focused unit/architecture tests and the failing native serializer case to GREEN (1 test / 17 assertions).
- [x] Complete the native composer entry, persisted draft-only mode and schema/provider qualification. Actual plain browser edit/save and server no-send enforcement passed; final plain/HTML regression remains required after the concurrency fix.
- [x] Run both static-analysis scopes after the completed implementation (including shared owner coordination).

### Task 2b — Resolve final-review native concurrency finding

- [x] Reproduce stale background and explicit native converters consuming a newer protected body/attachment revision (two real native RED cases).
- [x] Add shared owner-session coordination, full hydrated snapshots, native mapper/service/attachment participation and late IMAP/cleanup ownership fences.
- [x] Acquire the qualified native scope before MCP staging; replace check-then-delete cleanup with the qualified atomic orphan operation and conservative legacy retention.
- [x] Verify stale conversions, live contention, physical session loss and attachment reassociation/removal/cleanup boundaries in separate native processes (7 tests / 46 assertions).
- [x] Repeat guarded Mail plus actual plain/HTML browser, stock Mail, immutable v5 source gates and all local checks on the final source.

### Task 3 — Safety acceptance and release record

**Files:** Native lifecycle cases/actor, public Mail docs, master plan, this plan and delivery evidence.
**Consumes:** Task2candidate and existing A/D/C0–C7 tests.
**Produces:** Qualified v6 serializer, recorded remaining work and clean-source package.

- [x] Cover wrong/missing alias, invalid parent, native UI edit, Files/MIME bytes, normal account sender and no parent; verify no foreign/native outgoing/SMTP effect (58 native tests plus plain/HTML browser).
- [x] Run full guarded and stock Mail cells serially. Final enabled-source acceptance passes 58 / 1,458 plus plain/HTML browser; stock 19 executed / 39 expected skips / 530 assertions.
- [x] Run full devbox check with Node24 and project-local TMPDIR: 10,341 PHP / 95,113 assertions, 837 browser tests, both static scopes and packaging pass.
- [x] Correct stale C1/C3 master checkboxes, document v6 limits/evidence and remaining native credential/compatibility work. One fresh final review and its consolidated fix pass are complete.
- [x] Prepare validated local delivery inputs. The resulting Git commit and clean source offer record the final artifact provenance; no publication or installation.
