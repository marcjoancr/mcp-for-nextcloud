<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Files descriptions, tags and sharing

The user approved implementation of the native-metadata approach on 2026-11-04.

Store image descriptions through Nextcloud Files Metadata, associated with the file
ID. Add an authenticated Files sidebar description view/editor using the supported
Files extension API. MCP can read and edit the same value. No sidecar files or
image rewriting. Read existing embedded descriptions when available, identify the
source, and prefer an explicitly stored description (including an empty value).

Use public, assignable native system tags. Users may create categories such as
Tacos and Interior when native policy permits. Reading, creation and assignment
have distinct MCP permissions. Categories are server-wide; file results remain
restricted to the caller's native access, MCP visibility, folder scopes and
protected-path policies. Do not rename/delete global tags or bypass restricted tags.

Public read links may have or omit a password and expiry. Upload-only folder links
require a password and may have or omit expiry. Uploaders cannot read, list, update
or delete existing content. Respect native enforced password/expiry/upload policies.
Keep omitted options backward compatible; explicit modes can preserve, set or
remove password/expiry as applicable. Creating upload links has a separate grant.
All MCP writes retain preview/confirmation, binding confirmation to the requested
operation and current resource state where required. Never reveal passwords in
previews, listings or logs.

## Approaches

Native Files Metadata plus a supported sidebar integration was selected because it
meets Files/MCP visibility without changing image bytes or installing Memories.
Embedded EXIF/XMP writing would make descriptions portable but adds format-specific
writes and is not selected. Embedded metadata is a read fallback only. Comments and
sidecar documents do not meet the requested description semantics.

## Validation and boundaries

Check user/profile isolation, native read/write permissions, hidden/excluded files,
path scope, stale confirmations, hostile text, native tag policy and public share
policy. Keep canonical code/docs, branches and commits in English. Add Spanish UI
translations for new product text. No production changes, profile enablement, push
or release is part of this implementation request. Preserve all existing work.
