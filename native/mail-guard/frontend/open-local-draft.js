// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Inserted only into the exact pinned native Home.created method.
const mcpDraftState = document.getElementById('initial-state-mail-mcp-local-draft')
if (mcpDraftState) {
    const mcpDraftData = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(mcpDraftState.value), character => character.charCodeAt(0))))
    mcpDraftData.mcpLocalDraft = true
    this.mainStore.startComposerSession({ type: 'local', data: mcpDraftData })
        .then(() => this.mainStore.setComposerMessageSavedMutation(true))
}
