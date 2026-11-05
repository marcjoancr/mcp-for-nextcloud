// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

async function mountDescriptionTab(page) {
    await page.goto('/admin?bare=1')
    await page.addScriptTag({ url: '/apps/mcp/js/files.js' })
    await page.evaluate(async () => {
        const tab = window._nc_files_scope.v4_0.filesSidebarTabs.get('mcp-image-description')
        await tab.onInit()
        const element = document.createElement(tab.tagName)
        element.node = { type: 'file', mime: 'image/jpeg', path: '/Photos/lake.jpg', root: '' }
        element.active = true
        document.body.append(element)
    })
    return page.locator('mcp-files-description-tab')
}

test('description sidebar switches file state, renders hostile text literally, and saves the selected path', async ({ page, app }) => {
    const writes = []
    await page.route('**/apps/mcp/api/files/description**', async route => {
        const url = new URL(route.request().url())
        const path = url.searchParams.get('path')
        if (route.request().method() === 'GET') {
            const shared = path === '/Photos/lake.jpg'
            await route.fulfill({ json: {
                description: shared ? '<img src=x onerror=alert(1)>' : 'second file caption',
                source: 'files_metadata', stored: true, state: shared ? 'state-1' : 'state-2',
                etag: shared ? 'etag-1' : 'etag-2', supported_fields: [],
                access: { scope: shared ? 'shared' : 'personal', ownerDisplayName: 'Owner' }, can_edit: true,
            } })
            return
        }
        writes.push(route.request().postDataJSON())
        await route.fulfill({ json: {
            description: writes.at(-1).description, source: 'files_metadata', stored: true,
            state: 'state-3', etag: 'etag-2', supported_fields: [],
            access: { scope: 'personal', ownerDisplayName: 'Alice' }, can_edit: true,
        } })
    })

    const tab = await mountDescriptionTab(page)
    await expect(tab.locator('textarea')).toHaveValue('<img src=x onerror=alert(1)>')
    await expect(tab.locator('img')).toHaveCount(0)
    await expect(tab.getByRole('status')).toContainText('shared with others')

    await page.evaluate(() => {
        document.querySelector('mcp-files-description-tab').node = {
            type: 'file', mime: 'image/jpeg', path: '/Photos/other.jpg', root: '',
        }
    })
    await expect(tab.locator('textarea')).toHaveValue('second file caption')
    await expect(tab.getByRole('status')).not.toContainText('shared with others')
    await tab.locator('textarea').fill('<svg onload=alert(2)>')
    await tab.getByRole('button', { name: 'Save description' }).click()
    await expect(tab.getByRole('status')).toContainText('Description saved.')
    expect(writes).toEqual([{ path: '/Photos/other.jpg', description: '<svg onload=alert(2)>', state: 'state-2' }])
})

test('description sidebar reports a stale-state refusal instead of claiming success', async ({ page, app }) => {
    await page.route('**/apps/mcp/api/files/description**', async route => {
        if (route.request().method() === 'GET') {
            await route.fulfill({ json: { description: 'caption', source: 'files_metadata', stored: true, state: 'stale-state', access: { scope: 'personal' }, can_edit: true } })
            return
        }
        await route.fulfill({ status: 409, json: { error: 'The image description changed; preview the update again.' } })
    })
    const tab = await mountDescriptionTab(page)
    await expect(tab.locator('textarea')).toHaveValue('caption')
    await tab.getByRole('button', { name: 'Save description' }).click()
    await expect(tab.getByRole('status')).toContainText('changed; preview the update again')
})

test('late load for the previous file cannot replace the selected file description', async ({ page, app }) => {
    let releasePrevious
    const previous = new Promise(resolve => { releasePrevious = resolve })
    await page.route('**/apps/mcp/api/files/description**', async route => {
        const url = new URL(route.request().url())
        const path = url.searchParams.get('path')
        if (path === '/Photos/lake.jpg') {
            await previous
            await route.fulfill({ json: { description: 'old file', source: 'files_metadata', state: 'old-state', access: { scope: 'personal' }, can_edit: true } })
            return
        }
        await route.fulfill({ json: { description: 'selected file', source: 'files_metadata', state: 'new-state', access: { scope: 'personal' }, can_edit: true } })
    })
    const tab = await mountDescriptionTab(page)
    await page.evaluate(() => {
        document.querySelector('mcp-files-description-tab').node = { type: 'file', mime: 'image/jpeg', path: '/Photos/selected.jpg', root: '' }
    })
    await expect(tab.locator('textarea')).toHaveValue('selected file')
    releasePrevious()
    await expect(tab.locator('textarea')).toHaveValue('selected file')
})

test('late save response for the previous file cannot replace the selected file description', async ({ page, app }) => {
    let releaseSave
    const savePending = new Promise(resolve => { releaseSave = resolve })
    await page.route('**/apps/mcp/api/files/description**', async route => {
        const request = route.request()
        const path = new URL(request.url()).searchParams.get('path')
        if (request.method() === 'GET') {
            await route.fulfill({ json: { description: path.endsWith('lake.jpg') ? 'first file' : 'second file', source: 'files_metadata', state: path.endsWith('lake.jpg') ? 'state-a' : 'state-b', access: { scope: 'personal' }, can_edit: true } })
            return
        }
        await savePending
        await route.fulfill({ json: { description: 'saved first file', source: 'files_metadata', state: 'state-saved', access: { scope: 'personal' }, can_edit: true } })
    })
    const saveResponse = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes('/apps/mcp/api/files/description'))
    const tab = await mountDescriptionTab(page)
    await expect(tab.locator('textarea')).toHaveValue('first file')
    await tab.locator('textarea').fill('save first')
    await tab.getByRole('button', { name: 'Save description' }).click()
    await page.evaluate(() => {
        document.querySelector('mcp-files-description-tab').node = { type: 'file', mime: 'image/jpeg', path: '/Photos/second.jpg', root: '' }
    })
    await expect(tab.locator('textarea')).toHaveValue('second file')
    releaseSave()
    await saveResponse
    await page.waitForTimeout(100)
    await expect(tab.getByRole('status')).toHaveText('Saved in Nextcloud Files metadata.')
    await expect(tab.locator('textarea')).toHaveValue('second file')
})

test('description sidebar disables editing when the edit grant or native write permission is absent', async ({ page, app }) => {
    await page.route('**/apps/mcp/api/files/description**', route => route.fulfill({ json: {
        description: 'read only caption', source: 'files_metadata', state: 'read-state',
        access: { scope: 'personal', permissions: { update: true } }, can_edit: false,
    } }))
    const tab = await mountDescriptionTab(page)
    await expect(tab.locator('textarea')).toHaveValue('read only caption')
    await expect(tab.locator('textarea')).toBeDisabled()
    await expect(tab.getByRole('button', { name: 'Save description' })).toBeDisabled()
    await expect(tab.getByRole('status')).toContainText('read-only access')
})
