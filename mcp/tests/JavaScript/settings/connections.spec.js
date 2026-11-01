// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

for (const scope of ['admin', 'personal']) {
    test(`${scope} connection revoke uses its scope and preserves plain text confirmation`, async ({ page, app }) => {
        app.state.connections[0].client.host = "D'Avila <client>"
        await app.open(scope === 'admin' ? '/admin#clients' : '/personal')
        const base = scope === 'admin' ? '/api/connections' : '/api/my/connections'
        expect(app.calls.find(call => call.path === base).query).toEqual(scope === 'admin' ? { page: '1', search: '' } : { page: '1' })
        page.once('dialog', async dialog => {
            expect(dialog.message()).toContain("D'Avila <client>")
            await dialog.accept()
        })
        await page.getByRole('button', { name: /^Revoke (the )?connection|^Revoke D'Avila/ }).first().click()
        await expect(page.locator('#mcp-connections-status')).toHaveText('Connection revoked.')
        expect(app.calls.find(call => call.method === 'DELETE').path).toBe(base + '/1')
        expect(app.calls.find(call => call.method === 'DELETE').headers.requesttoken).toBe('synthetic-request-token')
        expect(app.calls.find(call => call.method === 'DELETE').headers['content-type']).toBeUndefined()
        if (scope === 'admin') await expect(page.locator('#mcp-count-connections')).toHaveText('1')
        else {
            expect(app.calls.some(call => call.path.startsWith('/api/connections'))).toBe(false)
            await expect(page.getByRole('button', { name: /Revoke all/ })).toHaveCount(0)
        }
    })
}

test('connection cancel sends nothing and failed revoke reaches status without Toast', async ({ page, app }) => {
    await app.open('/personal')
    await page.evaluate(() => { delete window.OCP })
    page.once('dialog', dialog => dialog.dismiss())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).first().click()
    expect(app.calls.filter(call => call.method === 'DELETE')).toHaveLength(0)
    app.on('DELETE', '/api/my/connections/1', () => ({ status: 500 }))
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).first().click()
    await expect(page.locator('#mcp-connections-status')).toHaveText('Could not revoke the connection.')
})

test('personal connection tool selection is keyboard operable and saved with its revision', async ({ page, app }) => {
	const rows = [
		{ name: 'files_read', module: 'files', title: 'Read file', description: 'Read file contents.', available: true, selected: false },
		{ name: 'files_edit', module: 'files', title: 'Edit file', description: 'Edit file contents.', available: false, selected: false },
	]
	app.on('GET', '/settings/my/connections/1/tools', () => ({ body: { revision: '0:0', tools: rows } }))
	app.on('PUT', '/settings/my/connections/1/tools', ({ body }) => ({
		body: { revision: '0:1', tools: rows.map(tool => ({ ...tool, selected: body.tools.includes(tool.name) })) },
	}))
	await app.open('/personal')
	await page.getByRole('button', { name: 'Manage tools for client.example' }).first().click()
	await expect(page.getByRole('dialog', { name: 'Tools for this connection' })).toBeVisible()
	await expect(page.getByText('Blocked by your administrator')).toBeVisible()
	const read = page.locator('#mcp-tool-selection-list input[value="files_read"]')
	await read.focus()
	await page.keyboard.press('Space')
	await expect(read).toBeChecked()
	await page.getByRole('button', { name: 'Save selection' }).click()
	await expect(page.locator('#mcp-tool-selection-status')).toHaveText('Tool selection saved.')
	const save = app.calls.find(call => call.method === 'PUT' && call.path === '/settings/my/connections/1/tools')
	expect(save.body).toEqual({ tools: ['files_read'], revision: '0:0' })
	expect(save.headers['content-type']).toContain('application/json')
})

test('late tool loads and saves cannot replace a different connection selection', async ({ page, app }) => {
	const rows = [{ name: 'files_read', module: 'files', title: 'Read file', description: 'Read file contents.', available: true, selected: false }]
	let aSelected = false
	let aLoads = 0
	let releaseLoad
	let releaseSave
	app.on('GET', '/settings/my/connections/1/tools', () => {
		if (aLoads++ > 0) return { body: { revision: 'a:0', tools: rows.map(tool => ({ ...tool, selected: aSelected })) } }
		return new Promise(resolve => { releaseLoad = () => resolve({ body: { revision: 'a:0', tools: rows.map(tool => ({ ...tool, selected: aSelected })) } }) })
	})
	app.on('GET', '/settings/my/connections/2/tools', () => ({ body: { revision: 'b:0', tools: rows.map(tool => ({ ...tool, selected: true })) } }))
	app.on('PUT', '/settings/my/connections/1/tools', () => new Promise(resolve => { aSelected = true; releaseSave = () => resolve({ body: { revision: 'a:1', tools: rows.map(tool => ({ ...tool, selected: true })) } }) }))
	app.on('PUT', '/settings/my/connections/2/tools', ({ body }) => ({ body: { revision: 'b:1', tools: rows.map(tool => ({ ...tool, selected: body.tools.includes(tool.name) })) } }))
	await app.open('/personal')
	const dialog = page.getByRole('dialog', { name: 'Tools for this connection' })
	await page.getByRole('button', { name: 'Manage tools for client.example' }).first().click()
	await expect(dialog).toBeVisible()
	await page.keyboard.press('Escape')
	await expect(dialog).toBeHidden()
	await page.getByRole('button', { name: 'Manage tools for client.example' }).nth(1).click()
	await expect(dialog).toBeVisible()
	await expect(page.locator('#mcp-tool-selection-list input[value="files_read"]')).toBeChecked()
	releaseLoad()
	await page.waitForTimeout(20)
	await expect(page.locator('#mcp-tool-selection-list input[value="files_read"]')).toBeChecked()
	await page.keyboard.press('Escape')
	await expect(dialog).toBeHidden()
	await page.getByRole('button', { name: 'Manage tools for client.example' }).first().click()
	await expect(dialog).toBeVisible()
	const read = page.locator('#mcp-tool-selection-list input[value="files_read"]')
	await expect(read).not.toBeChecked()
	await read.check()
	await page.getByRole('button', { name: 'Save selection' }).click()
	await expect.poll(() => app.calls.some(call => call.method === 'PUT' && call.path === '/settings/my/connections/1/tools')).toBe(true)
	await page.keyboard.press('Escape')
	await expect(dialog).toBeHidden()
	await page.getByRole('button', { name: 'Manage tools for client.example' }).nth(1).click()
	await expect(dialog).toBeVisible()
	await expect(page.locator('#mcp-tool-selection-list input[value="files_read"]')).toBeChecked()
	releaseSave()
	await page.waitForTimeout(20)
	await expect(page.locator('#mcp-tool-selection-list input[value="files_read"]')).toBeChecked()
})

test('personal sharing variants keep saved choices and require an explicit replacement', async ({ page, app }) => {
	const rows = [{ name: 'files_share', module: 'files', title: 'Share file', description: 'Share a file.', available: true, selected: true, variants: ['share'], approvedVariants: ['share', 'link'] }]
	app.on('GET', '/settings/my/connections/1/tools', () => ({ body: { revision: 'v:0', tools: rows } }))
	app.on('PUT', '/settings/my/connections/1/tools', ({ body }) => ({ body: { revision: 'v:1', tools: [{ ...rows[0], variants: body.variants.files_share }] } }))
	await app.open('/personal')
	await page.getByRole('button', { name: 'Manage tools for client.example' }).first().click()
	const people = page.getByRole('checkbox', { name: 'People and groups' })
	const links = page.getByRole('checkbox', { name: 'Public links' })
	await expect(people).toBeChecked()
	await expect(links).not.toBeChecked()
	await links.check()
	await people.uncheck()
	await page.getByRole('button', { name: 'Save selection' }).click()
	await expect(page.locator('#mcp-tool-selection-status')).toHaveText('Tool selection saved.')
	expect(app.calls.find(call => call.method === 'PUT' && call.path === '/settings/my/connections/1/tools').body).toEqual({
		tools: ['files_share'], revision: 'v:0', variants: { files_share: ['link'] },
	})
})

test('admin revoke-user encodes UID, resets the page and refreshes counts', async ({ page, app }) => {
    app.state.connections[0].uid = 'team/one'
    await app.open('/admin#clients')
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke all connections of Alice' }).first().click()
    await expect(page.locator('#mcp-connections-status')).toHaveText('Connections of Alice revoked.')
    expect(app.calls.find(call => call.method === 'DELETE').path).toBe('/api/connections/users/team%2Fone')
    await expect(page.locator('#mcp-count-connections')).toHaveText('0')
    await expect(page.locator('#mcp-connections-table')).toContainText('No active connections.')
})

test('connection load retries and removing the final row returns to the previous page', async ({ page, app }) => {
    let failed = true
    let removed = false
    app.on('GET', '/api/my/connections', ({ query, state }) => {
        if (failed) return { status: 500 }
        const pageNumber = Number(query.get('page'))
        return { body: { connections: removed && pageNumber === 2 ? [] : state.connections.slice(0, 1), page: pageNumber, hasMore: pageNumber === 1 } }
    })
    app.on('DELETE', '/api/my/connections/1', () => { removed = true; return { body: {} } })
    await app.open('/personal')
    failed = false
    await page.getByRole('button', { name: 'Retry', exact: true }).click()
    await page.locator('#mcp-connections-next').click()
    await expect(page.locator('#mcp-connections-page-info')).toHaveText('Page 2')
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).click()
    await expect(page.locator('#mcp-connections-page-info')).toHaveText('Page 1')
})
