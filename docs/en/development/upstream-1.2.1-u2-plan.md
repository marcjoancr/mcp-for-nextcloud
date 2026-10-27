<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U2 implementation plan

Use the executing-plans workflow for this authorized batch. The
[approved intake](upstream-1.2.1-intake.md) defines its behavior; U3-U5 remain outside
this implementation. Baseline: `19aff24650742aa03baf3ba8e81884ea0a19c37f`.
Upstream input: `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`.

**Goal:** configurable backup locations and honest, administrator-controlled Files
copies without weakening reserved-path, versioning, visibility or Contacts safety.

**Architecture:** `Access/BackupPolicy` owns the persisted document, normalized
name identity, effective copy preference and revisioned writes. Files capabilities
consume that policy at preview, confirmation and mutation boundaries. Contacts
shares the location but never the optional Files switch. The existing
`Platform/FileAccess/BackupLocation.php` is replaced by the canonical policy owner;
all callers move, with no compatibility class, alias or second configuration source.

**Stack:** Nextcloud 33-35 with the existing PHP 8.4 native minimum. The historical
PHP unit matrix also exercises 8.2/8.3 and 8.5; it does not extend native support.
Use the existing PHPUnit/native harness, authored JavaScript modules and the
three-browser Playwright suite.

## Decisions and constraints

The owner has authorized U2 under the existing capability architecture. Adapt the
pinned behavior rather than merge the legacy tree or introduce a second settings
service. New policy dependencies are explicit; production constructors do not fall
back to factory defaults when a dependency is missing. Test fixtures supply the
absent-document default explicitly. No database schema change is needed.

- Persist one `backup_settings` document: version 1, current folder, historical
  reserved names and the Files copy preference. Absence retains `MCP backups` and
  copies on; corruption/backend failure refuses applicable operations.
- Validate one UTF-8 direct-child name, NFC-normalized, at most 255 bytes, with no
  separator/control characters or dot/dot-dot. Apply Nextcloud's filename validator
  to new administrator input. Preserve every prior name; never move/delete old copies.
- Use the existing `PolicyTransaction` mutex/revision and official AppConfig writer.
  Read only this key directly from the shared database connection to observe
  rollback and concurrent changes without flushing unrelated AppConfig caches.
- Suspending Files copies requires positive Versions enablement globally and for
  the actor. Preserve historical Versions, ACL, ETag, locks, scope and confirmation
  checks. Versions availability is not a promise of per-file recovery.
- Contacts always saves and verifies the complete vCard before deletion.
- Cached listing metadata is bounded to a request; every write boundary refreshes
  reservations. Existing issued checkout links and batch plans are not exemptions.
- Admin HTTP retains authentication, admin and CSRF defaults. Revisionless writes
  return 428; stale revisions return 409; uncertain writes require verified reload.
- English source/comments/docs; add new UI strings to existing de/es/pt_BR catalogs.
  Translations and accessibility remain subject to human review.

## Review focus

- Configuration changes between preview, copy, lock acquisition and final mutation
  must not release a newly reserved path, including batch undo and issued checkout.
- NFC-equivalent current/historical actor-root names retain write reservations.
  Canonical share aliases retain the hidden-original mirror restrictions from U1.
  This does not add a general prohibition on writes through renamed shared mounts.
- A malformed or incompatible document cannot be treated as absence; valid lazy
  string documents remain readable; rollback
  must not leave stale writer-cache state that loses historical reservations.
- A suspended copy must not produce a misleading plan, recovery flag or error
  claiming a copy exists; Contacts remains independently mandatory.
- Concurrent admin edits, late HTTP responses and uncertain saves preserve the draft
  and require a fresh verified revision; values are rendered as text, not HTML.

## C0: CI prerequisite

- [x] Inspect the exact published checks, retain failures, reproduce shallow history.
- [x] Fetch full history for PHPUnit, without relaxing its ancestry contract.
- [x] Run actionlint and the full PHP suite; commit the correction separately.
- [x] Observe the corrected public workflows; distinguish their head from U2.

## U2.1: canonical policy and administrator API

Files: create `Access/BackupPolicy/{BackupLocation,BackupFolderName,BackupCopies,
BackupSettingsDocument,BackupSettingsWriter}.php`, `Controller/BackupSettingsController.php`,
and tests under `tests/Unit/Access/BackupPolicy` and `tests/Unit/Controller`.
Replace the old location owner and update `appinfo/routes.php`.

Interfaces: `BackupLocation(BackupSettingsDocument)` exposes `current(): array`,
`snapshot(): array`, `invalidate(): void`, `contains(string): bool`, plus static
`defaults()` and `validate(string, ?IFilenameValidator)`. `BackupCopies` exposes
`required(string): bool` and `versionsAvailable(?string = null): bool`.
`BackupSettingsWriter::save(string $revision, string $folder, bool $copies): array`
returns the existing policy transaction receipt. HTTP GET/PUT use
`/api/backup-settings` and `expectedRevision`.

- [x] Adapt SQLite policy tests before production code: missing defaults, A-B-A
  history, stale revision, invalid names/NFC, rollback/read-back failure, group-limited
  Versions, incompatible persisted types and bounded/fresh reads. Observe RED.
- [x] Implement the canonical owners using the existing transaction. Run the same
  tests GREEN, plus HTTP malformed-input/admission tests; no new policy mutex.

## U2.2: all consumers and write boundaries

Files: `Access/Visibility/{VisibilityGuard,BackupFolderMatcher}.php`,
`Access/ResourceScope/FilesAccessGuard.php`; Files Editing, Checkout, Versions,
Creation, Reorganization and Presentation; Contacts Deletion and guide notes.
Focused new collaborators live in the capability they serve: copy preparation and
copy creation in Editing, plan decoration/instructions in Presentation. Test
fixtures are adapted to supply explicit policy dependencies.

- [x] Add failing flow tests for configured dot folders, every old reservation,
  suspended receipts/plans, mandatory Contacts copies and late reservation changes.
- [x] Inject the canonical policy into visibility and all write sinks. Preserve U1
  canonical-origin metadata, normalize candidate names and invalidate settings with
  the existing guard caches. No default-only production collaborator remains.
- [x] Recheck reservations immediately around mutation callbacks for edit/replace,
  checkout, restore, create, mkdir, copy/move, batch execution and undo. Keep prior
  ACL/type/lock checks. Name changes never silently migrate existing content.
- [x] Decorate preview plans for the actor's effective preference, preserve specific
  checkout/restore instructions and make failure messages truthful with no copy.
- [x] Pass the focused Files/Contacts/privacy/confirmation suites and architecture
  boundaries before enabling administration UI.

## U2.3: administration UI and user documentation

Files: `src/admin/backups/{controller,state,view}.js`, composition in
`src/admin/bootstrap.js`, `templates/admin-backups.php` and its existing Protection
tab include. Reuse transport/lifecycle/policy-channel owners; do not import upstream's
standalone global script. Tests: `tests/JavaScript/admin/backups.spec.js`.

- [x] Add browser regressions for defaults, revisions, unavailable Versions, invalid
  responses, stale/uncertain saves, preserved drafts, late responses and teardown.
- [x] Implement the section with disabled controls until verified load, safe text
  rendering and an explicit reload after uncertainty. Pass all three browsers.
- [x] Update tool descriptions/manifests, canonical configuration and Files/Contacts
  guides, runtime catalogs and translation freshness without claiming human approval.

## U2.4: native acceptance and closure

- [x] Adapt pinned `BackupConfigurationTest` and `BackupSettingsAdminHttpTest` to
  definitive capability owners. Exercise edit/replace/checkout/restore, historic/NFC
  paths, failed configuration, mandatory Contacts and real admin/non-admin/CSRF HTTP.
- [x] Run full catalog check, REUSE and complete NC35/MariaDB/upgrade-local native
  suite. Verify persisted settings and reservation history through upgrade where
  supported by the baseline; first introduction must retain defaults when absent.
- [x] Obtain one independent whole-branch review, fix material findings with RED/GREEN
  tests, and record exact commands/results and failed attempts. Prepare the validated
  fork delta for the authorized commit/publication.
- [x] Observe U2 CI for the final published head; see the U3 prerequisite record.
- [x] Confirm no legacy owners, transitional aliases or duplicated settings sources.

No deployment or upstream PR #14 changes. U3 hierarchy, U4 Mail and U5 full parity,
LinuxServer L1-L5, intended-server qualification, trusted release distribution,
HTTPS, Inspector's prior nonzero/encrypted-store issues, ChatGPT and human reviews
remain explicit. Completing U2 does not qualify the entire 1.2.1 release.
