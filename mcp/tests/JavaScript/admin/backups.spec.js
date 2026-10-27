// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('backup settings save a complete revisioned draft with CSRF', async ({ page, app }) => {
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('MCP backups')
    await page.locator('#mcp-backup-folder').fill('.mcp-backups')
    await page.locator('#mcp-backup-copies').uncheck()
    await page.locator('#mcp-backup-save').click()
    await expect(page.locator('#mcp-backup-status')).toHaveText('Backup settings saved.')
    const save = app.calls.find(call => call.path === '/api/backup-settings' && call.method === 'PUT')
    expect(save.body).toEqual({ expectedRevision: '0', folder: '.mcp-backups', copies: false })
    expect(save.headers.requesttoken).toBe('synthetic-request-token')
    await expect(page.locator('#mcp-backup-effective')).toContainText('suspended for your account')
})

test('unavailable Versions prevents suspension but permits enabling copies', async ({ page, app }) => {
    Object.assign(app.state.backups, { versionsAvailable: false, versionsForActor: false, copies: false, effectiveCopies: true })
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-backup-copies')).toBeEnabled()
    await page.locator('#mcp-backup-copies').check()
    await expect(page.locator('#mcp-backup-copies')).toBeDisabled()
    await page.locator('#mcp-backup-save').click()
    await expect(page.locator('#mcp-backup-effective')).toContainText('unavailable without the Versions app')
})

for (const failure of ['conflict', 'uncertain', 'malformed']) {
    test(`${failure} save preserves draft and blocks replay until verified reload`, async ({ page, app }) => {
        app.on('PUT', '/api/backup-settings', () => failure === 'malformed' ? { raw: '<html>unknown outcome</html>' }
            : { status: failure === 'conflict' ? 409 : 503, body: { error: 'Unavailable', uncertain: failure === 'uncertain' } })
        await app.open('/admin#protection')
        await page.locator('#mcp-backup-folder').fill('Unsaved copies')
        await page.locator('#mcp-backup-save').click()
        await expect(page.locator('#mcp-backup-save')).toBeDisabled()
        await expect(page.locator('#mcp-backup-folder')).toHaveValue('Unsaved copies')
        await page.locator('#mcp-backup-reload').click()
        await expect(page.locator('#mcp-backup-save')).toBeEnabled()
        await expect(page.locator('#mcp-backup-folder')).toHaveValue('Unsaved copies')
        await expect(page.locator('#mcp-backup-status')).toContainText('unsaved input is preserved')
        expect(app.calls.filter(call => call.path === '/api/backup-settings' && call.method === 'PUT')).toHaveLength(1)
    })
}

test('invalid responses never offer defaults or a saveable form', async ({ page, app }) => {
    app.on('GET', '/api/backup-settings', () => ({ body: { folder: 'MCP backups', copies: true, revision: 0 } }))
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-backup-status')).toContainText('could not be loaded')
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('')
    await expect(page.locator('#mcp-backup-save')).toBeDisabled()
})

test('validation failures retain the editable draft and keyboard focus', async ({ page, app }) => {
    app.on('PUT', '/api/backup-settings', () => ({ status: 400, body: { error: 'Invalid backup settings' } }))
    await app.open('/admin#protection')
    await page.locator('#mcp-backup-folder').fill('invalid/name')
    await page.locator('#mcp-backup-save').click()
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('invalid/name')
    await expect(page.locator('#mcp-backup-folder')).toBeFocused()
    await expect(page.locator('#mcp-backup-folder')).toHaveAttribute('aria-invalid', 'true')
    await expect(page.locator('#mcp-backup-save')).toBeEnabled()
})

test('page-local confirmed revisions advance the draft without overwriting it', async ({ page, app }) => {
    await app.open('/admin#protection')
    await page.locator('#mcp-backup-folder').fill('My copies')
    await page.locator('#mcp-tab-overview').click()
    await page.locator('#mcp-service').press('Space')
    await expect(page.locator('#mcp-service-status')).toHaveText('Saved')
    await page.locator('#mcp-tab-protection').click()
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('My copies')
    await page.locator('#mcp-backup-save').click()
    await expect(page.locator('#mcp-backup-status')).toHaveText('Backup settings saved.')
    expect(app.calls.find(call => call.path === '/api/backup-settings' && call.method === 'PUT').body.expectedRevision).toBe('1')
})

test('a disposed backup panel ignores a late read and remounts cleanly', async ({ page, app }) => {
    let release
    app.on('GET', '/api/backup-settings', async () => {
        await new Promise(resolve => { release = resolve })
        return { body: { ...app.state.backups, revision: '0' } }
    })
    await page.goto('/admin?bare=1#protection')
    await page.evaluate(async () => {
        const { mountBackupSettings } = await import('/modules/admin/backups/controller.js')
        const { createRequest } = await import('/modules/platform/http.js')
        const { createNextcloud } = await import('/modules/platform/nextcloud.js')
        const host = createNextcloud()
        document.getElementById('mcp-backup-settings').closest('[role="tabpanel"]').hidden = false
        window.mountBackups = () => mountBackupSettings({ root: document.getElementById('mcp-backup-settings'), request: createRequest(host), ...host })
        window.backupsOwner = window.mountBackups()
    })
    await expect.poll(() => typeof release).toBe('function')
    await page.evaluate(() => window.backupsOwner.destroy())
    release()
    await page.evaluate(() => window.backupsOwner.ready)
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('')
    release = null
    await page.evaluate(() => { window.backupsOwner = window.mountBackups() })
    await expect.poll(() => typeof release).toBe('function')
    release()
    await expect(page.locator('#mcp-backup-folder')).toHaveValue('MCP backups')
    await expect(page.locator('#mcp-backup-save')).toBeEnabled()
})
