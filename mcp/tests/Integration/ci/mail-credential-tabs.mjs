// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { chromium } from '../../../node_modules/@playwright/test/index.mjs'
const [container, run, credentialFile] = process.argv.slice(2)
const secrets = Object.fromEntries(readFileSync(credentialFile, 'utf8').trim().split('\n').map(line => line.split('=')))
function command(args, input) {
    const p = spawnSync('docker', args, { encoding: 'utf8', input, maxBuffer: 1048576, timeout: 60000 })
    if (p.error || p.status !== 0) throw new Error('Native tab fixture command did not complete.')
    return p.stdout.trim()
}
function actor(action, input = {}) {
    return JSON.parse(command(['exec', '-i', '-u', 'www-data', '-e', 'NEXTCLOUD_ROOT=/var/www/html', '-e', 'MCP_IT_WITH_MAIL=1', '-e', 'MCP_IT_MAIL_GUARD=1', '-e', 'MCP_IT_MAIL_CREDENTIAL_CANDIDATE=' + (process.env.MCP_IT_MAIL_CREDENTIAL_CANDIDATE || '0'), container, 'php', '/mcp-it-src/mcp/tests/Integration/Mail/mail-actor.php', 'mcp-it-alice', action], JSON.stringify(input)))
}
let browser, fixture
try {
    assert.match(run, /^mcp-it-\d{14}-[a-f0-9]{12}$/)
    assert.equal(container, run + '-nc')
    const info = JSON.parse(command(['inspect', container]))[0]
    assert.equal(info.Config.Labels['mcp.integration.run'], run)
    const ip = info.NetworkSettings.Networks[run + '-net']?.IPAddress
    assert.match(ip || '', /^(?:172\.(?:1[6-9]|2\d|3[01])|10\.\d+|192\.168)\.\d+\.\d+$/)
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'config:system:set', 'trusted_domains', '1', '--value=' + ip])
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'app:disable', 'firstrunwizard'])
    const origin = 'http://' + ip
    browser = await chromium.launch({ headless: true })
    const context = await browser.newContext()
    const forbidden = []
    await context.route('**/*', route => {
        const r = route.request(), url = new URL(r.url())
        if (url.origin !== origin) return route.abort()
        if (r.method() !== 'GET' && /\/api\/(?:outbox|mdn)(?:\/|$)/.test(url.pathname)) { forbidden.push(url.pathname); return route.abort() }
        return route.continue()
    })
    const login = await context.newPage()
    await login.goto(origin + '/index.php/login')
    await login.locator('#user').fill('mcp-it-alice')
    await login.locator('#password').fill(secrets.FIXTURE_PASSWORD)
    await login.locator('button[type=submit]').click()
    await login.waitForURL(url => !url.pathname.includes('/login'))
    const rewrite = await login.evaluate(() => window.OC?.config?.modRewriteWorking === true)
    await login.close()
    const results = []
    for (const mode of ['password', 'state', 'create']) {
        fixture = actor('credential-tabs-prepare', { mode })
        assert.equal(fixture.scope_differs, true, 'Two actual MCP connections must have distinct scopes.')
        console.log('Native tab regression mode:', mode)
        const a = await context.newPage(), b = await context.newPage()
        a.on('pageerror', error => console.error('Native tab page error:', error.message.slice(0, 300)))
        async function open(page, value) {
            const url = new URL(value)
            if (fixture.candidate_fixture_armed && rewrite) url.pathname = url.pathname.replace('/index.php/', '/')
            assert.equal((await page.goto(origin + url.pathname + url.search)).status(), 200)
        }
        await open(a, fixture.url)
        if (mode !== 'create') {
            const caption = a.locator('.app-navigation-caption').filter({ hasText: fixture.native_fields.emailAddress })
            await caption.waitFor({ state: 'visible' })
            await caption.locator('.action-item__menutoggle').click()
            await a.getByText('Account settings', { exact: true }).filter({ visible: true }).click()
            await a.getByText('Mail server', { exact: true }).first().click()
            await a.locator('#account-form').waitFor({ state: 'visible' })
        }
        // The old originating page stays loaded while B replaces the shared HttpOnly cookie.
        await open(b, fixture.alternate_url)
        if (mode === 'password') {
            for (const id of ['#man-imap-password', '#man-smtp-password']) {
                await a.locator(id).fill('mcp-it-probe-password')
                await a.locator(id).press('Tab')
            }
            const saved = a.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith('/api/accounts/' + fixture.account_id))
            await a.locator('#account-form button[type="submit"]:visible').click()
            assert.equal((await saved).status(), 200, 'An unbound old native form still saves normally.')
        } else if (mode === 'state') {
            // This invokes the original AccountForm -> generateOauthState producer after B opens.
            assert.equal(await a.locator('#man-imap-password').count(), 0, 'Use the original native OAuth form.')
            const generated = a.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/api/oauth/state'))
            const updated = a.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith('/api/accounts/' + fixture.account_id))
            await a.locator('#account-form button[type="submit"]:visible').click()
            assert.equal((await updated).status(), 200, 'Native OAuth profile update must succeed before state generation.')
            const stateReply = await generated
            assert.equal(stateReply.status(), 200)
            const state = (await stateReply.json()).data.state
            const base = new URL(fixture.url).pathname.replace(/\/$/, '')
            const params = new URLSearchParams({ state, code: 'accept-fixture', scope: 'fixture' })
            assert.equal((await context.request.get(origin + base + '/integration/google-auth?' + params)).status(), 200)
        } else {
            // Exercise the actual pinned native AccountService API producer, without replacing transport.
            const id = await a.evaluate(async fields => {
                let require
                const key = 'webpackChunknextcloud_mail'
                if (!Array.isArray(window[key])) throw new Error('Pinned native webpack runtime unavailable.')
                window[key].push([[Date.now()], {}, r => { require = r }])
                const saved = await require(97627).vt(fields)
                return saved.id
            }, fixture.native_fields)
            assert.equal(Number.isInteger(id), true)
            assert.equal(actor('credential-tabs-created', { fixture: fixture.fixture, account_id: id }).recorded, true)
        }
        const check = actor('credential-tabs-inspect', { fixture: fixture.fixture })
        assert.equal(check.no_send, true)
        const result = { mode, wrong_proof: check.other_proof, wrong_target: check.other_target_bound }
        results.push(result)
        console.log('Native tab admission result:', JSON.stringify(result))
        await a.close(); await b.close()
        actor('credential-ui-cleanup', { fixture: fixture.fixture }); fixture = undefined
    }
    console.log('Native late originating-page interleavings:', JSON.stringify(results))
    assert.deepEqual(forbidden, [])
    for (const result of results) {
        assert.equal(result.wrong_proof, false, result.mode + ': another MCP connection cannot acquire this page’s proof.')
        assert.equal(result.wrong_target, false, result.mode + ': another MCP connection cannot acquire this page’s created target.')
    }
} finally {
    if (browser) await browser.close()
    if (fixture) actor('credential-ui-cleanup', { fixture: fixture.fixture })
}
