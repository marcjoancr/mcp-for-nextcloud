// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Native API payload metadata belongs to this loaded page, never the shared cookie.
function mcpSetupPayload(data) {
    const input = document.getElementById('initial-state-mail-mcp-setup-context')
    if (!input) return data
    try {
        const marker = JSON.parse(atob(input.value))
        if (typeof marker === 'string' && /^[a-f0-9]{64}$/.test(marker)) {
            return { ...data, mcpSetupContext: marker }
        }
    } catch {
        // Ordinary native saves and OAuth remain available without evidence.
    }
    return data
}
