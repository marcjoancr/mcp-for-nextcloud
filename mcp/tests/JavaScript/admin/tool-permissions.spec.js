// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

const detail = () => ({
    kind: 'user', subject: 'alice', revision: '0',
    groups: [{ id: 'staff', displayName: 'Staff' }],
    service: { state: 'inherit', effective: true, origin: 'Group assignment', groupIds: ['staff'] },
    tools: [
        { name: 'files_list', module: 'files', title: 'List files', description: 'List a folder.', state: 'inherit', effective: true, origin: 'Group assignment', groupIds: ['staff'] },
        { name: 'files_read', module: 'files', title: 'Read file', description: 'Read file contents.', state: 'inherit', effective: true, origin: 'Group assignment', groupIds: ['staff'] },
    ],
})

test('admin reviews tri-state tool changes before saving and shows group provenance', async ({ page, app }) => {
    const saved = detail()
    app.on('GET', '/settings/tool-permissions/subjects', () => ({ body: { subjects: [{ id: 'alice', displayName: 'Alice' }], page: 1, hasMore: false } }))
    app.on('GET', '/settings/tool-permissions', () => ({ body: saved }))
    app.on('POST', '/settings/tool-permissions', ({ body }) => {
        if (body.preview) return { body: { ...saved, previewId: 'preview-1', impact: {
            summary: 'Alice will lose file reading through this tool.',
            tools: { files_read: { effective: false, origin: 'Individual assignment' } },
        } } }
        saved.tools[1].state = body.changes.files_read
        saved.tools[1].effective = false
        saved.tools[1].origin = 'Individual assignment'
        saved.revision = '1'
        return { body: saved }
    })

    await app.open('/admin#mcp-tool-permissions')
    await expect(page.locator('#mcp-tools-membership-list')).toHaveText('Staff')
    const assignment = page.getByRole('combobox', { name: 'Assignment for Read file' })
    await assignment.focus()
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')
    await expect(assignment).toHaveValue('deny')
    await page.getByRole('button', { name: 'Review changes' }).click()
    await expect(page.locator('#mcp-tools-save-status')).toContainText('Alice will lose file reading')
    await expect(page.locator('#mcp-tool-permissions')).toContainText('After saving: Individual assignment')
    await page.getByRole('button', { name: 'Save permissions' }).click()
    await expect(page.locator('#mcp-tools-save-status')).toContainText('Permissions saved.')

    const writes = app.calls.filter(call => call.method === 'POST' && call.path === '/settings/tool-permissions')
    expect(writes).toHaveLength(2)
    expect(writes[0].body).toMatchObject({ preview: true, changes: { files_read: 'deny' }, revision: '0' })
    expect(writes[1].body).toMatchObject({ preview: false, previewId: 'preview-1', changes: { files_read: 'deny' } })
})

test('group provenance loads only when requested and lists all decisions', async ({ page, app }) => {
	app.on('GET', '/settings/tool-permissions/subjects', () => ({ body: { subjects: [{ id: 'alice', displayName: 'Alice' }], page: 1, hasMore: false } }))
	app.on('GET', '/settings/tool-permissions', () => ({ body: detail() }))
	app.on('GET', '/settings/tool-permissions/origin', () => ({ body: {
		individual: { state: 'inherit', effective: true, origin: 'Allowed by Staff' },
		groups: [{ id: 'staff', displayName: 'Staff', state: 'allow', effective: true, origin: 'Group assignment' }],
		revision: '0',
	} }))
	await app.open('/admin#mcp-tool-permissions')
	expect(app.calls.some(call => call.path === '/settings/tool-permissions/origin')).toBe(false)
	await page.locator('.mcp-tool-origin summary').first().click()
	await expect(page.locator('.mcp-tool-origin').first()).toContainText('Staff: Allow · Allowed · Group assignment')
	expect(app.calls.some(call => call.path === '/settings/tool-permissions/origin')).toBe(true)
})

test('admin permissions remain usable at phone width without horizontal overflow', async ({ page, app }) => {
    app.on('GET', '/settings/tool-permissions/subjects', () => ({ body: { subjects: [{ id: 'alice', displayName: 'Alice' }], page: 1, hasMore: false } }))
    app.on('GET', '/settings/tool-permissions', () => ({ body: detail() }))
    await page.setViewportSize({ width: 375, height: 812 })
    await app.open('/admin#mcp-tool-permissions')
    await page.getByRole('button', { name: 'Groups', exact: true }).click()
    await expect(page.locator('#mcp-tools-subject')).toHaveCount(1)
    const dimensions = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, page: document.documentElement.scrollWidth }))
    expect(dimensions.page).toBeLessThanOrEqual(dimensions.viewport)
})
