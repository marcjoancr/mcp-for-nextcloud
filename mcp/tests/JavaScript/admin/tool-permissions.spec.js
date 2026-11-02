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

async function openAlice(page, app) {
    await app.open('/admin#mcp-tool-permissions')
    await page.getByRole('searchbox', { name: 'Search groups or people' }).fill('Alice')
    await page.locator('#mcp-tools-subject').getByRole('group', { name: 'People' }).getByRole('button', { name: 'Alice · alice' }).click()
    await expect(page.locator('#mcp-tools-selected')).toHaveText('Alice')
    await page.locator('.mcp-tool-catalog > summary').first().click()
}

test('admin reviews tri-state tool changes before saving and shows group provenance', async ({ page, app }) => {
    const saved = detail()
    app.on('GET', '/settings/tool-permissions/subjects', () => ({ body: { subjects: [{ id: 'alice', displayName: 'Alice' }], page: 1, hasMore: false } }))
    app.on('GET', '/settings/tool-permissions', () => ({ body: saved }))
    app.on('POST', '/settings/tool-permissions', ({ body }) => {
        if (body.preview && body.changes.files_read === 'inherit') return { body: { previewId: 'preview-2', impact: { summary: 'Inherited from Staff', tools: { files_read: detail().tools[1] } } } }
        if (body.preview) return { body: { ...saved, previewId: 'preview-1', impact: {
            summary: 'Alice will lose file reading through this tool.',
            tools: { files_read: { effective: false, origin: 'Individual assignment' } },
        } } }
        saved.tools[1].state = body.changes.files_read
        saved.tools[1].effective = body.changes.files_read === 'inherit'
        saved.tools[1].origin = saved.tools[1].effective ? 'Group assignment' : 'Individual assignment'
        saved.tools[1].groupIds = saved.tools[1].effective ? ['staff'] : []
        saved.revision = '1'
        return { body: saved }
    })

    await openAlice(page, app)
    await expect(page.locator('#mcp-tools-membership-list')).toContainText('Staff')
    const row = page.locator('.mcp-tool-row').filter({ hasText: 'Read file' })
    await expect(row).toContainText('Allowed by Staff')
    await row.getByRole('button', { name: 'Create exception' }).click()
    const assignment = page.getByRole('combobox', { name: 'Assignment for Read file' })
    await assignment.focus()
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')
    await expect(assignment).toHaveValue('deny')
    await page.getByRole('button', { name: 'Review changes' }).click()
    await expect(page.locator('#mcp-tools-save-status')).toContainText('Alice will lose file reading')
    await expect(page.locator('#mcp-tools-changes')).toContainText('Read file · Inherit → Block · Blocked · Individual assignment')
    await expect(row).toContainText('Allowed by Staff')
    await page.getByRole('button', { name: 'Save permissions' }).click()
    await expect(page.locator('#mcp-tools-editor-status')).toContainText('Permissions saved.')
    await expect(page.locator('#mcp-tools-exceptions')).toContainText('Read file · Blocked')

    const writes = app.calls.filter(call => call.method === 'POST' && call.path === '/settings/tool-permissions')
    expect(writes).toHaveLength(2)
    expect(writes[0].body).toMatchObject({ preview: true, changes: { files_read: 'deny' }, revision: '0' })
    expect(writes[1].body).toMatchObject({ preview: false, previewId: 'preview-1', changes: { files_read: 'deny' } })
    await row.getByRole('button', { name: 'Use inherited rule' }).click()
    await page.getByRole('button', { name: 'Review changes' }).click()
    await expect(page.locator('#mcp-tools-changes')).toContainText('Block → Inherit · Allowed by Staff')
    await page.getByRole('button', { name: 'Save permissions' }).click()
    await expect(page.locator('#mcp-tools-exceptions')).toBeHidden()
    await expect(row).toContainText('Allowed by Staff')
    expect(app.calls.filter(call => call.method === 'POST').at(-1).body).toMatchObject({
        kind: 'user', subject: 'alice', preview: false, changes: { files_read: 'inherit' }, previewId: 'preview-2',
    })

})

test('group provenance loads only when requested and lists all decisions', async ({ page, app }) => {
	app.on('GET', '/settings/tool-permissions/subjects', () => ({ body: { subjects: [{ id: 'alice', displayName: 'Alice' }], page: 1, hasMore: false } }))
	app.on('GET', '/settings/tool-permissions', () => ({ body: detail() }))
	app.on('GET', '/settings/tool-permissions/origin', () => ({ body: {
		individual: { state: 'inherit', effective: true, origin: 'Allowed by Staff' },
		groups: [{ id: 'staff', displayName: 'Staff', state: 'allow', effective: true, origin: 'Group assignment' }],
		revision: '0',
	} }))
	await openAlice(page, app)
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
    await page.getByRole('searchbox', { name: 'Search groups or people' }).fill('Alice')
    await page.locator('#mcp-tools-subject button').last().click()
    await page.getByRole('searchbox', { name: 'Find a tool' }).fill('Read file')
    await expect(page.locator('.mcp-tool-row')).toHaveCount(1)
    await expect(page.locator('#mcp-tools-subject')).toHaveCount(1)
    const dimensions = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, page: document.documentElement.scrollWidth }))
    expect(dimensions.page).toBeLessThanOrEqual(dimensions.viewport)
})

test('groups are the landing directory and a person can be found without switching modes', async ({ page, app }) => {
    await app.open('/admin#mcp-tool-permissions')
    const directory = page.locator('#mcp-tools-subject')
    await expect(directory.getByRole('button', { name: 'Staff' })).toBeVisible()
    await expect(page.locator('#mcp-tools-editor')).toBeHidden()
    expect(app.calls.some(call => call.method === 'GET' && call.path === '/settings/tool-permissions')).toBe(false)
    await directory.getByRole('button', { name: 'Staff' }).click()
    await expect(page.locator('#mcp-tools-selected')).toHaveText('Staff')
    await page.getByRole('searchbox', { name: 'Search groups or people' }).fill('alice')
    await directory.getByRole('button', { name: 'alice', exact: true }).click()
    await expect(page.locator('#mcp-tools-selected')).toHaveText('alice')
    await expect(page.locator('#mcp-tools-catalogs details[open]')).toHaveCount(0)
    expect(app.calls.some(call => call.method === 'POST' && call.path === '/settings/tool-permissions')).toBe(false)
})

test('a blocked service takes precedence over tool grants on the user card', async ({ page, app }) => {
    const snapshot = detail()
    snapshot.service = { state: 'deny', effective: false, origin: 'Individual assignment', groupIds: [] }
    app.on('GET', '/settings/tool-permissions/subjects', ({ query }) => ({ body: {
        subjects: query.get('kind') === 'user' ? [{ id: 'alice', displayName: 'Alice' }] : [], page: 1, hasMore: false,
    } }))
    app.on('GET', '/settings/tool-permissions', () => ({ body: snapshot }))
    await openAlice(page, app)
    await expect(page.locator('#mcp-tools-service-result')).toHaveText('Service blocked')
    await expect(page.locator('#mcp-tools-service-origin')).toHaveText('Individual assignment')
    await expect(page.locator('.mcp-tool-row').first()).toContainText('Blocked · Service blocked')
    expect(app.calls.some(call => call.method === 'POST')).toBe(false)
})

test('group rules are edited together and only the chosen group is saved', async ({ page, app }) => {
    const snapshot = { ...detail(), kind: 'group', subject: 'staff', groups: [] }
    app.on('GET', '/settings/tool-permissions', () => ({ body: snapshot }))
    app.on('POST', '/settings/tool-permissions', ({ body }) => {
        if (body.preview) return { body: { previewId: 'group-preview', impact: { tools: {}, summary: 'Review group changes.' } } }
        for (const tool of snapshot.tools) tool.state = body.changes[tool.name]
        snapshot.revision = '1'
        return { body: snapshot }
    })
    await app.open('/admin#mcp-tool-permissions')
    await page.locator('#mcp-tools-subject').getByRole('button', { name: 'Staff' }).click()
    const catalog = page.locator('.mcp-tool-catalog')
    await expect(catalog.locator('summary').first()).toContainText('2 unassigned')
    await catalog.locator('summary').first().click()
    await page.getByRole('combobox', { name: 'Assignment for List files' }).selectOption('allow')
    await page.getByRole('combobox', { name: 'Assignment for Read file' }).selectOption('deny')
    await page.getByRole('button', { name: 'Review changes' }).click()
    await page.getByRole('button', { name: 'Save permissions' }).click()
    await expect(catalog.locator('summary').first()).toContainText('1 allowed · 1 blocked')
    const writes = app.calls.filter(call => call.method === 'POST' && call.path === '/settings/tool-permissions')
    expect(writes).toHaveLength(2)
    expect(writes[1].body).toMatchObject({ kind: 'group', subject: 'staff', preview: false, previewId: 'group-preview',
        changes: { files_list: 'allow', files_read: 'deny' },
    })
})
