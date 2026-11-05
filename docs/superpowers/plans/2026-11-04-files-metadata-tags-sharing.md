<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Files Metadata, Tags and Sharing Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans or focused parallel tasks. Track checks below.

**Goal:** Deliver native image descriptions in Files/MCP, user-created tags, and configurable read/upload links.

**Architecture:** Focused Files capabilities use native Nextcloud metadata, system tags and sharing services. Existing MCP confirmation, visibility and grant infrastructure stays authoritative. A Files sidebar calls an authenticated controller backed by the same description service.

**Tech Stack:** PHP 8.2+, Nextcloud 33–35 APIs, JavaScript, PHPUnit, Playwright, esbuild.

**Spec:** [Approved design](../specs/2026-11-04-files-metadata-tags-sharing-design.md)

## Global Constraints

English branches, commits, source and canonical documentation. No production writes.
Native policy, user isolation and per-profile restrictions must remain effective.
No sidecars, no global tag deletion, no embedded image metadata writes, no public description endpoint.

## Review Focus

- A hidden/out-of-scope file ID or shared mount must never expose metadata through the browser adapter.
- Embedded descriptions and explicit empty overrides must be distinguishable and safely rendered.
- System-tag assignment must never grant access or bypass exclusion tags.
- Clearing password/expiry must never silently preserve the old value or violate native policy.
- Upload-link grants must not implicitly grant ordinary sharing, and existing upload links must remain correctly classified.

## Task 1: Descriptions and Files UI

Files: `mcp/lib/Modules/Files/Descriptions/`, description tool classes under `Tools/`,
`mcp/lib/Controller/FileDescriptionController.php`, Files asset entry/listener and focused tests.
Interfaces: `files_get_image_description` uses `files.description_read`;
`files_set_image_description` uses `files.description_edit`. Shared service accepts
authenticated user and path, returns description/source/state; write rechecks access.

- [x] Add failing tests for native precedence, embedded fallback, empty override, read/write isolation and stale state.
- [x] Implement native metadata storage/read fallback and read/write tools with preview.
- [x] Add authenticated sidebar/controller and browser tests for safe rendering, updates and denial.
- [x] Run focused PHP/browser checks; provide integration wiring to coordinator.

## Task 2: Native tags

Files: `mcp/lib/Modules/Files/Tags/`, `Tools/ListTags.php`, `Tools/CreateTag.php`,
`Tools/SetFileTags.php` and focused tests.
Interfaces: `files_list_tags`/`tags_read`, `files_create_tag`/`tags_create`,
`files_set_tags`/`tags_edit`; mutation uses explicit add/remove sets on accessible paths.

- [x] Add failing native-policy, foreign-path, exclusions and confirmation tests.
- [x] Implement bounded listing, public tag creation/reuse and assignment/removal.
- [x] Run focused checks; report constructor and renderer wiring.

## Task 3: Share-link modes

Files: `mcp/lib/Modules/Files/Sharing/`, `Tools/ShareFile.php`, new upload-link tool and focused sharing tests.
Interfaces: preserve `files_share`; add `files_create_upload_link` under `files.upload_link`.
Explicit password/expiry modes supplement backward-compatible omitted arguments.

- [x] Add failing tests for password/expiry clearing, enforced-policy refusals and upload-only permissions.
- [x] Implement preview/state/confirmation and native share creation/update.
- [x] Check isolation and listings/revocation for upload-only links; run sharing regression tests.

## Task 4: Integration and delivery

Files: `mcp/lib/AppInfo/Application.php`, Files catalog/renderers, grants/presentation,
assets build contracts, translations and `docs/en/reference/tools/files.md`.

- [x] Register capabilities, grants, factories and UI entry; extend contract tests.
- [x] Update English/Spanish guides and translations with exact supported behavior.
- [x] Run `devbox run check --cwd PATH --json` and resolve all relevant failures.
- [x] Obtain independent branch review, address findings and recheck affected behavior.
- [x] Create English local commit(s); report checks and local-only scope.

## Verification

- Canonical `devbox run check` passed: 9,638 PHP tests with 85,442 assertions,
  831 browser tests across Chromium, Firefox and WebKit, static analysis,
  documentation validation and package construction.
- Native Nextcloud 35.0.2 SQLite integration passed: two focused scenarios with
  75 assertions for metadata, tags, sharing, exclusions and user isolation.
- The isolated integration environment passed its coordinated recovery rehearsal.
- Independent review completed; follow-up regressions cover upload-link profile
  selection and neutral errors for hidden-file tag previews.
- Delivery remains a local feature branch; production enablement is a separate step.
