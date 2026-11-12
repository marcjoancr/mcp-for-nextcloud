// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// CKEditor normalizes its initial input. Metadata-only saves must not rewrite
// the original body. Once body or editor mode changes, native serialization wins.
if (this.draftOnly && this.mcpInitialEditorBody !== null
    && this.bodyVal === this.mcpInitialEditorBody
    && this.editorMode === this.mcpInitialEditorMode) {
    t.isHtml = this.mcpOriginalBody.format === 'html'
    if (t.isHtml) {
        t.bodyHtml = this.mcpOriginalBody.value
        delete t.bodyPlain
    } else {
        t.bodyPlain = this.mcpOriginalBody.value
        delete t.bodyHtml
    }
}
