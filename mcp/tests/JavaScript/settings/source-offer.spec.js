// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('personal settings expose unavailable source delivery without a fabricated download link', async ({ page, app }) => {
    await app.open('/personal')
    const offer = page.locator('#mcp-source-offer')
    await expect(offer.getByRole('heading', { name: 'Source code for this version' })).toBeVisible()
    await expect(offer).toContainText('Source delivery is unavailable. Contact your administrator.')
    await expect(offer.getByRole('link')).toHaveCount(1)
    await expect(offer.getByRole('link')).toHaveAttribute('href', 'https://sintax.tech')
})
