/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** JSON transport only: sections retain their own error, retry and rollback policy. */
export function createRequest({ generateUrl, requestToken, fetch }) {
    return async (method, path, body, { json = true } = {}) => {
        const headers = { Accept: 'application/json', requesttoken: requestToken() }
        if (json) headers['Content-Type'] = 'application/json'
        const response = await fetch(generateUrl('/apps/mcp' + path), {
            method, headers, body: body === undefined ? undefined : JSON.stringify(body),
        })
        if (!response.ok) {
            const error = new Error(String(response.status))
            // Structured uncertainty/conflict information belongs to the section's recovery policy.
            try { error.data = await response.json() } catch { error.data = null }
            throw error
        }
        return response.json()
    }
}
