// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('the permission comparison starts with groups and only opens operation rules in context', async ({ page, app }) => {
    await app.open('/admin#mcp-tool-permissions')
    await expect(page.locator('#mcp-tools-advanced > summary')).toBeHidden()
    await expect(page.getByRole('button', { name: 'Choose comparison for Staff' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Add comparison column' })).toBeVisible()
    await expect(page.locator('#mcp-tools-picker')).toBeHidden()
    expect(app.calls.filter(call => call.method !== 'GET')).toHaveLength(0)
})
