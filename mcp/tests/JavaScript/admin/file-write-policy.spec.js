// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('policy lives in the admin bundle and saves canonical extensions with CSRF', async ({ page, app }) => {
    await app.open('/admin#protection')
    await page.locator('#mcp-file-policy-mode').selectOption('allowlist')
    await page.locator('#mcp-file-policy-extensions').fill('.TXT,md\nTxt')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-status')).toHaveText('File type policy saved.')
    const save = app.calls.find(call => call.path === '/api/file-write-policy' && call.method === 'PUT')
    expect(save.body).toEqual({ mode: 'allowlist', extensions: ['md', 'txt'], allowExtensionless: false })
    expect(save.headers.requesttoken).toBe('synthetic-request-token')
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveValue('md\ntxt')
})

test('invalid drafts and server field failures preserve input and focus', async ({ page, app }) => {
    await app.open('/admin#protection')
    await page.locator('#mcp-file-policy-mode').selectOption('allowlist')
    await page.locator('#mcp-file-policy-extensions').fill('txt,,md')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveAttribute('aria-invalid', 'true')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(0)
    app.on('PUT', '/api/file-write-policy', () => ({ status: 400, body: { error: 'invalid_policy', field: 'extensions' } }))
    await page.locator('#mcp-file-policy-extensions').fill('md')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-extensions')).toBeFocused()
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveValue('md')
    await expect(page.locator('#mcp-file-policy-save')).toBeEnabled()
})

test('unavailable load offers deliberate recovery without treating failure as off', async ({ page, app }) => {
    app.on('GET', '/api/file-write-policy', () => ({ status: 503, body: { error: 'policy_unavailable' } }))
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-file-policy-invalid')).toBeVisible()
    await expect(page.locator('#mcp-file-policy-mode')).toHaveValue('')
    await expect(page.locator('#mcp-tab-protection-alert')).not.toHaveAttribute('hidden')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-mode')).toHaveAttribute('aria-invalid', 'true')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(0)
    await page.locator('#mcp-file-policy-mode').selectOption('off')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-status')).toHaveText('File type policy saved.')
    await expect(page.locator('#mcp-file-policy-overview')).toHaveAttribute('hidden')
})

test('unverified save keeps draft and blocks replay until a successful reload', async ({ page, app }) => {
    app.on('PUT', '/api/file-write-policy', ({ body, state }) => {
        Object.assign(state.filePolicy, body)
        return { raw: '<html>ambiguous response</html>' }
    })
    await app.open('/admin#protection')
    await page.locator('#mcp-file-policy-mode').selectOption('allowlist')
    await page.locator('#mcp-file-policy-extensions').fill('md,txt')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-status')).toContainText('could not be saved and verified')
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveValue('md,txt')
    await expect(page.locator('#mcp-file-policy-save')).toBeDisabled()
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(1)
    await page.locator('#mcp-file-policy-reload').click()
    await expect(page.locator('#mcp-file-policy-save')).toBeEnabled()
    await expect(page.locator('#mcp-file-policy-status')).toContainText('Your unsaved input is preserved')
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveValue('md,txt')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(1)
})

test('malformed GET cannot masquerade as a default policy', async ({ page, app }) => {
    app.on('GET', '/api/file-write-policy', () => ({ body: { version: 1, mode: 'off', extensions: [] } }))
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-file-policy-mode')).toHaveValue('')
    await expect(page.locator('#mcp-file-policy-invalid')).toBeVisible()
})

test('tag repair cannot clear a simultaneous policy warning', async ({ page, app }) => {
    app.state.tags = { tags: [], selected: [], invalid: true }
    app.on('GET', '/api/file-write-policy', () => ({ status: 503 }))
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-file-policy-invalid')).toBeVisible()
    page.once('dialog', dialog => dialog.accept())
    await page.locator('#mcp-tags-repair').click()
    await expect(page.locator('#mcp-tags-invalid-overview')).toHaveAttribute('hidden')
    await expect(page.locator('#mcp-tab-protection-alert')).not.toHaveAttribute('hidden')
})

test('disposed policy controller ignores late load and remount owns one save listener', async ({ page, app }) => {
    let release
    app.on('GET', '/api/file-write-policy', async () => {
        await new Promise(resolve => { release = resolve })
        return { body: { version: 1, mode: 'allowlist', extensions: ['txt'], allowExtensionless: false } }
    })
    await page.goto('/admin?bare=1')
    await page.evaluate(async () => {
        const { mountFileWritePolicy } = await import('/modules/admin/protection/file-policy-controller.js')
        const { createRequest } = await import('/modules/platform/http.js')
        const { createNextcloud } = await import('/modules/platform/nextcloud.js')
        const host = createNextcloud()
        window.mountPolicy = () => mountFileWritePolicy({ root: document.getElementById('mcp-file-write-policy'), request: createRequest(host), ...host })
        window.mountedPolicy = window.mountPolicy()
    })
    await expect.poll(() => typeof release).toBe('function')
    await page.evaluate(() => window.mountedPolicy.destroy())
    release()
    await expect(page.locator('#mcp-file-policy-mode')).toHaveValue('')
    app.on('GET', '/api/file-write-policy', ({ state }) => ({ body: state.filePolicy }))
    await page.evaluate(async () => { window.mountedPolicy = window.mountPolicy(); await window.mountedPolicy.ready })
    await page.locator('#mcp-file-policy-mode').selectOption('allowlist')
    await page.locator('#mcp-file-policy-extensions').fill('md')
    await page.locator('#mcp-file-policy-save').click()
    await expect(page.locator('#mcp-file-policy-status')).toHaveText('File type policy saved.')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(1)
})

test('normalizer matches PHP separators and rejects malformed tokens', async ({ page }) => {
    await page.goto('/health')
    const result = await page.evaluate(async () => {
        const { extensions, validPolicy } = await import('/modules/admin/protection/file-policy-state.js')
        const accepted = ['txt\nmd\n', 'txt,\nmd', 'txt,md,', 'txt\n\nmd'].map(value => extensions(value))
        const rejected = ['a,,b', ',txt', '*', 'tar.gz', 'a/php', 'php:txt', 'рhp', 'a'.repeat(33), '\u00a0txt'].map(value => {
            try { extensions(value); return false } catch { return true }
        })
        return { accepted, rejected, malformed: validPolicy({ version: 1, mode: 'off', extensions: ['txt', 'md'], allowExtensionless: false }) }
    })
    expect(result.accepted).toEqual(Array(4).fill(['md', 'txt']))
    expect(result.rejected.every(Boolean)).toBe(true)
    expect(result.malformed).toBe(false)
})


test('a late save response cannot overwrite or claim a newer draft was saved', async ({ page, app }) => {
    let release
    app.on('PUT', '/api/file-write-policy', async ({ body, state }) => {
        Object.assign(state.filePolicy, body)
        await new Promise(resolve => { release = resolve })
        return { body: state.filePolicy }
    })
    await app.open('/admin#protection')
    await page.locator('#mcp-file-policy-mode').selectOption('allowlist')
    await page.locator('#mcp-file-policy-extensions').fill('md')
    await page.locator('#mcp-file-policy-save').click()
    await expect.poll(() => typeof release).toBe('function')
    // Autofill or another page owner can change the draft while this owner's controls are disabled.
    await page.locator('#mcp-file-policy-extensions').evaluate(input => { input.value = 'txt'; input.dispatchEvent(new Event('input')) })
    release()
    await expect(page.locator('#mcp-file-policy-extensions')).toHaveValue('txt')
    await expect(page.locator('#mcp-file-policy-status')).toContainText('Your unsaved input is preserved')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(1)
})
