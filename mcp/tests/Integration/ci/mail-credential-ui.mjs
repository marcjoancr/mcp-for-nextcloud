// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { chromium } from '../../../node_modules/@playwright/test/index.mjs'
const [container, run, credentialFile] = process.argv.slice(2)
const secrets = Object.fromEntries(readFileSync(credentialFile, 'utf8').trim().split('\n').map(line => line.split('=')))
let browser, fixture, page
const browserErrors = []
function command(args, input) {
    const p = spawnSync('docker', args, { encoding: 'utf8', input, maxBuffer: 1048576, timeout: 60000 })
    if (p.error || p.status !== 0) throw new Error('Native credential fixture command did not complete.')
    return p.stdout.trim()
}
function actor(action, input = {}) {
    return JSON.parse(command(['exec', '-i', '-u', 'www-data', '-e', 'NEXTCLOUD_ROOT=/var/www/html', '-e', 'MCP_IT_WITH_MAIL=1', '-e', 'MCP_IT_MAIL_GUARD=1', '-e', 'MCP_IT_MAIL_CREDENTIAL_CANDIDATE=' + (process.env.MCP_IT_MAIL_CREDENTIAL_CANDIDATE || '0'), container, 'php', '/mcp-it-src/mcp/tests/Integration/Mail/mail-actor.php', 'mcp-it-alice', action], JSON.stringify(input)))
}
try {
    assert.match(run, /^mcp-it-\d{14}-[a-f0-9]{12}$/)
    assert.equal(container, run + '-nc')
    assert.match(secrets.FIXTURE_PASSWORD || '', /^[a-f0-9]{48}$/)
    const info = JSON.parse(command(['inspect', container]))[0]
    assert.equal(info.Config.Labels['mcp.integration.run'], run)
    const ip = info.NetworkSettings.Networks[run + '-net']?.IPAddress
    assert.match(ip || '', /^(?:172\.(?:1[6-9]|2\d|3[01])|10\.\d+|192\.168)\.\d+\.\d+$/)
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'config:system:set', 'trusted_domains', '1', '--value=' + ip])
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'app:disable', 'firstrunwizard'])
    fixture = actor('credential-ui-prepare')
    const origin = 'http://' + ip
    browser = await chromium.launch({ headless: true })
    const context = await browser.newContext()
    const forbidden = []
    await context.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url())
        if (!['http:', 'https:'].includes(url.protocol)) return route.continue()
        if (url.origin !== origin) return route.abort()
        if (request.method() !== 'GET' && /\/api\/(?:outbox|mdn)(?:\/|$)/.test(url.pathname)) { forbidden.push(url.pathname); return route.abort() }
        return route.continue()
    })
    page = await context.newPage()
    page.on('pageerror', error => browserErrors.push(error.message.slice(0, 300)))
    await page.goto(origin + '/index.php/login')
    await page.locator('#user').fill('mcp-it-alice')
    await page.locator('#password').fill(secrets.FIXTURE_PASSWORD)
    await page.locator('button[type=submit]').click()
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 })
    const rewrite = await page.evaluate(() => window.OC?.config?.modRewriteWorking === true)
    const basePath = new URL(fixture.url).pathname.replace(/\/$/, '')
    async function openEntry(url) {
        const entry = new URL(url)
        // Candidate metadata was registered in CLI; use the browser router policy for its native entry.
        if (fixture.candidate_fixture_armed && rewrite) entry.pathname = entry.pathname.replace('/index.php/', '/')
        const opened = await page.goto(origin + entry.pathname + entry.search)
        assert.equal(opened.status(), 200)
    }
    function inspect() { return actor('credential-ui-inspect', { fixture: fixture.fixture }) }
    async function pageMarker() {
        return page.evaluate(() => JSON.parse(atob(document.getElementById('initial-state-mail-mcp-setup-context').value)))
    }
    async function state() {
        const token = await page.evaluate(() => window.OC?.requestToken)
        const reply = await context.request.post(origin + basePath + '/api/oauth/state', {
            headers: { requesttoken: token }, data: { accountId: fixture.account_id, mcpSetupContext: await pageMarker() },
        })
        assert.equal(reply.status(), 200)
        const value = (await reply.json()).data.state
        assert.match(value, /^v7\.\d+\.\d+\.[a-f0-9]{32}\.[a-f0-9]+$/)
        return value
    }
    async function callback(provider, state, accepted = true, session = context) {
        const params = new URLSearchParams({ state, code: accepted ? 'accept-fixture' : 'reject-fixture', scope: 'fixture', session_state: 'fixture' })
        const response = await session.request.get(origin + basePath + '/integration/' + provider + '-auth?' + params)
        assert.equal(response.status(), 200, 'The actual native provider callback must complete.')
    }
    function finish(expectedProtocols) {
        const result = actor('credential-ui-finish', { fixture: fixture.fixture })
        assert.equal(result.profile_unchanged, true)
        assert.equal(result.no_send, true)
        assert.deepEqual(forbidden, [])
        assert.equal(result.native_proof_observed, true)
        assert.deepEqual(result.proof_protocols, expectedProtocols)
        if (process.env.MCP_IT_MAIL_CREDENTIAL_CANDIDATE === '1') {
            assert.equal(fixture.candidate_fixture_armed, true)
            assert.equal(result.completion_preview_ok, false, 'Qualification must remain closed in candidate acceptance.')
            assert.equal(result.completed, false)
        } else {
            assert.equal(fixture.candidate_fixture_armed, false)
            for (const name of ['completion_preview_ok', 'completed', 'replayed', 'consumed', 'recovered']) assert.equal(result[name], true, name)
        }
    }
    function guarantees() {
        const result = actor('credential-ui-guarantees', { fixture: fixture.fixture })
        assert.deepEqual(result, { concurrent_name_write_refused: true, concurrent_credential_write_refused: true, outside_transaction_refused: true, atomic_rollback: true, unrelated_setting_preserves_revision: true })
    }
    assert.deepEqual(actor('credential-ui-gates', { fixture: fixture.fixture }), { source: true, storage: true, restored: true, same_registration: true, changed_binding_refused: true })
    assert.equal(inspect().observed, false, 'Opening or diagnostics cannot create a manual write proof.')
    await openEntry(fixture.url)
    assert.equal(inspect().observed, false)
    // Use the original native account form, its Vue submit handler and CSRF-protected transport.
    const caption = page.locator('.app-navigation-caption').filter({ hasText: fixture.native_fields.emailAddress })
    await caption.waitFor({ state: 'visible' })
    await caption.locator('.action-item__menutoggle').click()
    await page.getByText('Account settings', { exact: true }).filter({ visible: true }).click()
    await page.getByText('Mail server', { exact: true }).first().click()
    await page.locator('#man-imap-password').fill('mcp-it-probe-password')
    await page.locator('#man-imap-password').press('Tab')
    await page.locator('#man-smtp-password').fill('mcp-it-probe-password')
    await page.locator('#man-smtp-password').press('Tab')
    const saved = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith('/api/accounts/' + fixture.account_id))
    await page.locator('#account-form button[type="submit"]:visible').click()
    const nativeSaved = await saved
    const nativeBody = nativeSaved.request().postDataJSON()
    assert.equal(nativeBody.imapPassword === 'mcp-it-probe-password', true, 'Native IMAP model must receive the filled field.')
    assert.equal(nativeBody.smtpPassword === 'mcp-it-probe-password', true, 'Native SMTP model must receive the filled field.')
    for (const [key, value] of Object.entries(fixture.native_fields)) assert.equal(nativeBody[key], value, 'Native profile field: ' + key)
    if (nativeSaved.status() !== 200) {
        const reply = await nativeSaved.json()
        console.error('Native save refusal:', JSON.stringify(Object.fromEntries(['error', 'service', 'host', 'port'].filter(k => reply.data?.[k] !== undefined).map(k => [k, reply.data[k]]))))
    }
    assert.equal(nativeSaved.status(), 200)
    assert.deepEqual(inspect().protocols, ['imap', 'smtp'])
    // SMTP-only native save must report that protocol, never imply current authentication.
    const token = await page.evaluate(() => window.OC?.requestToken)
    const smtp = await context.request.put(origin + basePath + '/api/accounts/' + fixture.account_id, {
        headers: { requesttoken: token }, data: { ...fixture.native_fields, smtpPassword: 'mcp-it-probe-password', mcpSetupContext: await pageMarker() },
    })
    assert.equal(smtp.status(), 200)
    guarantees()
    assert.deepEqual(actor('credential-ui-session-loss', { fixture: fixture.fixture }), { killed: true, refused: true, proof_retained: true, workflow_pending: true, source_pending: true })
    finish(['smtp'])
    actor('credential-ui-cleanup', { fixture: fixture.fixture })
    fixture = undefined
    console.log('Native credential form, SMTP-only wording, shared rollback, unchanged profile and no-send verified.')

    for (const provider of ['google', 'microsoft']) {
        fixture = actor('credential-ui-prepare', { provider })
        await openEntry(fixture.url)
        assert.equal(inspect().observed, false)
        const failed = await state()
        const beforeForeign = inspect().account_revision
        const foreign = await browser.newContext()
        await foreign.route('**/*', async route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort())
        const bob = await foreign.newPage()
        await bob.goto(origin + '/index.php/login')
        await bob.locator('#user').fill('mcp-it-bob')
        await bob.locator('#password').fill(secrets.FIXTURE_PASSWORD)
        await bob.locator('button[type=submit]').click()
        await bob.waitForURL(url => !url.pathname.includes('/login'))
        const ownedEntry = new URL(fixture.url)
        if (fixture.candidate_fixture_armed && rewrite) ownedEntry.pathname = ownedEntry.pathname.replace('/index.php/', '/')
        assert.equal((await foreign.request.get(origin + ownedEntry.pathname + ownedEntry.search)).status(), 404, 'Another owner cannot activate the native handoff.')
        assert.equal((await foreign.request.get(origin + ownedEntry.pathname.replace(/\/$/, '') + '/setup' + ownedEntry.search)).status(), 404, 'The setup wizard must refuse a foreign handoff too.')
        await callback(provider, failed, true, foreign)
        await foreign.close()
        assert.equal(inspect().account_revision, beforeForeign, 'A different logged-in owner cannot consume the native signed state.')
        assert.equal(inspect().observed, false)
        await callback(provider, failed, false)
        assert.equal(inspect().observed, false, 'Failed manual exchange with valid OLD tokens must not prove a write.')
        await callback(provider, failed)
        assert.equal(inspect().observed, false, 'Replaying the failed reserved state must not prove a later exchange.')
        assert.deepEqual(actor('credential-ui-refresh', { fixture: fixture.fixture }), { revision_advanced: true, manual_proof_absent: true })
        const expired = await state()
        assert.equal(actor('credential-ui-expire-state', { fixture: fixture.fixture, state: expired }).expired, true)
        await callback(provider, expired)
        assert.equal(inspect().observed, false, 'Expired state cannot provide evidence.')
        // A state remains bound to its original context even after another tab opens a newer handoff.
        const original = await state()
        const alternate = actor('credential-ui-alternate', { fixture: fixture.fixture })
        await openEntry(alternate.url)
        let overLimit
        for (let i = 0; i < 33; i++) overLimit = await state()
        await callback(provider, overLimit)
        assert.equal(inspect().alternate_observed, false, 'Over-limit state must stay unbound and provide no manual proof.')
        assert.equal(actor('credential-ui-state-count', { fixture: fixture.fixture }).count, 32, 'Native correlation storage must be bounded per handoff.')
        await callback(provider, original)
        const checked = inspect()
        assert.equal(checked.observed, true)
        assert.equal(checked.alternate_observed, false)
        assert.equal(checked.foreign_owner_refused, true)
        assert.equal(checked.foreign_source_refused, true)
        // Reuse after success performs no new attestation; the native revision invalidates the old one.
        await callback(provider, original)
        assert.equal(inspect().observed, false)
        await openEntry(fixture.url)
        const fresh = await state()
        await callback(provider, fresh)
        guarantees()
        finish(['imap', 'smtp'])
        actor('credential-ui-cleanup', { fixture: fixture.fixture })
        fixture = undefined
        console.log('Native ' + provider + ' callback: old-token failure, refresh, expired/reused state, original context, shared rollback and no-send verified.')
    }
    console.log(process.env.MCP_IT_MAIL_CREDENTIAL_CANDIDATE === '1' ? 'Candidate native guarantees passed; MCP completion remains closed.' : 'Qualified MCP admission, completion, one-shot consumption, status recovery and replay passed.')
} catch (error) {
    // This disposable account contains only synthetic data; never include input values or request bodies.
    if (page && !page.isClosed()) console.error('Native UI diagnostics:', JSON.stringify({
        errors: browserErrors.slice(0, 5), title: await page.title(),
        captions: await page.locator('.app-navigation-caption').allTextContents(),
        text: (await page.locator('body').innerText()).slice(0, 1200),
    }))
    throw error
} finally {
    if (browser) await browser.close()
    if (fixture) actor('credential-ui-cleanup', { fixture: fixture.fixture })
}
