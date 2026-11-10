// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { chromium } from '../../../node_modules/@playwright/test/index.mjs'
const format = process.env.MCP_IT_MAIL_UI_FORMAT || 'plain'
assert.ok(['plain', 'html'].includes(format))
const [container, run, credentialFile] = process.argv.slice(2)
const secrets = Object.fromEntries(readFileSync(credentialFile, 'utf8').trim().split('\n').map(line => line.split('=')))
const password = secrets.FIXTURE_PASSWORD
let browser, prepared, page
const browserErrors = [], failedAssets = [], consoleErrors = []
function command(args, input) {
    const p = spawnSync('docker', args, { encoding: 'utf8', input, maxBuffer: 1048576, timeout: 60000 })
    if (p.error || p.status !== 0) throw new Error('Owned native UI fixture command failed: ' + (p.stderr || p.error?.message || ''))
    return p.stdout.trim()
}
function actor(action, input = {}) {
    return JSON.parse(command(['exec', '-i', '-u', 'www-data', '-e', 'NEXTCLOUD_ROOT=/var/www/html', '-e', 'MCP_IT_WITH_MAIL=1', '-e', 'MCP_IT_MAIL_GUARD=1', container, 'php', '/mcp-it-src/mcp/tests/Integration/Mail/mail-actor.php', 'mcp-it-alice', action], JSON.stringify(input)))
}
try {
    assert.match(run, /^mcp-it-\d{14}-[a-f0-9]{12}$/)
    assert.equal(container, run + '-nc')
    assert.match(password || '', /^[a-f0-9]{48}$/)
    const info = JSON.parse(command(['inspect', container]))[0]
    assert.equal(info.Config.Labels['mcp.integration.run'], run)
    const ip = info.NetworkSettings.Networks[run + '-net']?.IPAddress
    assert.match(ip || '', /^(?:172\.(?:1[6-9]|2\d|3[01])|10\.\d+|192\.168)\.\d+\.\d+$/)
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'config:system:set', 'trusted_domains', '1', '--value=' + ip])
    command(['exec', '-u', 'www-data', container, 'php', '/var/www/html/occ', 'app:disable', 'firstrunwizard'])
    prepared = actor('draft-ui-prepare', { format })
    assert.equal(prepared.initially_held, true)
    const safety = actor('draft-ui-safety', prepared)
    assert.equal(safety.source_retained, true)
    assert.equal(safety.tamper_refused, true)
    assert.equal(safety.crypto_mutation_refused, true)
    assert.equal(safety.crypto_controller_refused, true)
    assert.deepEqual(safety.refused, { missing_alias: true, foreign_alias: true, account_changed: true, empty_parent: true, header_injection: true, oversized_parent: true, crypto: true })
    const origin = 'http://' + ip
    browser = await chromium.launch({ headless: true })
    const context = await browser.newContext()
    page = await context.newPage()
    page.on('console', message => { if (['error', 'warning'].includes(message.type())) consoleErrors.push(message.text().slice(0, 700)) })
    page.on('pageerror', error => browserErrors.push(error.message.slice(0, 500)))
    page.on('requestfailed', request => { const url = new URL(request.url()); if (/\.js$/.test(url.pathname)) failedAssets.push(url.pathname) })
    const forbidden = []
    const guardRoute = async route => {
        const req = route.request(), url = new URL(req.url())
        if (!['http:', 'https:'].includes(url.protocol)) return route.continue()
        if (url.origin !== origin) return route.abort()
        if (req.method() !== 'GET' && /\/api\/(?:outbox|mdn)(?:\/|$)/.test(url.pathname)) { forbidden.push(url.pathname); return route.abort() }
        return route.continue()
    }
    await context.route('**/*', guardRoute)
    await page.goto(origin + '/index.php/login')
    await page.locator('#user').fill('mcp-it-alice')
    await page.locator('#password').fill(password)
    await page.locator('button[type=submit]').click()
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 })
    if (!prepared.candidate_fixture_armed) assert.equal(typeof prepared.native_edit_url, 'string', 'Enabled HTTP writer must expose its qualified native entry.')
    const nativeEntry = new URL(prepared.native_edit_url || prepared.native_entry_url)
    // The prequalification fixture runs its lookup in CLI, where PHP cannot see
    // Apache's rewrite environment. Use the native browser router's own policy.
    if (prepared.candidate_fixture_armed) {
        const rewrite = await page.evaluate(() => window.OC?.config?.modRewriteWorking === true)
        if (rewrite) nativeEntry.pathname = nativeEntry.pathname.replace('/index.php/', '/')
    }
    assert.equal(nativeEntry.searchParams.get('mcpLocalDraft'), String(prepared.draft_id))
    assert.match(nativeEntry.pathname, /\/apps\/mail\/?$/)
    const url = origin + nativeEntry.pathname + nativeEntry.search
    const opened = await page.goto(url)
    assert.equal(opened.status(), 200)
    await page.locator('.message-composer #subject').waitFor({ state: 'visible', timeout: 30000 })
    assert.equal(await page.locator('.message-composer #subject').inputValue(), prepared.subject)
    assert.equal(await page.locator('.message-composer button[type=submit]').isDisabled(), true, 'The native entry must stay draft-only.')
    const saved = page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname.endsWith('/api/drafts/' + prepared.draft_id), { timeout: 20000 })
    await page.locator('.message-composer #subject').fill(prepared.subject + ' edited')
    const response = await saved
    assert.equal(response.status(), 202)
    const data = response.request().postDataJSON()
    assert.equal(data.aliasId, prepared.alias_id)
    assert.equal(data.inReplyToMessageId, prepared.parent)
    assert.equal(data.isHtml, format === 'html')
    if (format === 'html') assert.equal(data.bodyHtml, prepared.body_html)
    else assert.equal(data.bodyPlain, prepared.body_plain)
    assert.equal(data.attachments.length, 1)
    const bodyEdited = 'Body explicitly edited in native Mail.'
    const editedField = format === 'html' ? 'bodyHtml' : 'bodyPlain'
    let editedBody = bodyEdited
    const bodySaved = page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname.endsWith('/api/drafts/' + prepared.draft_id) && ['bodyPlain', 'bodyHtml'].some(field => String(r.request().postDataJSON()?.[field] || '').includes(bodyEdited)), { timeout: 20000 })
    await page.locator('.message-composer .ck-editor__editable[contenteditable=true]').fill(bodyEdited)
    const editedResponse = await bodySaved
    assert.equal(editedResponse.status(), 202)
    const editedData = editedResponse.request().postDataJSON()
    assert.equal(editedData.isHtml, format === 'html')
    editedBody = editedData[editedField]
    if (format === 'plain') assert.equal(editedBody, bodyEdited)
    else assert.equal(editedBody.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '\u00a0').trim(), bodyEdited)
    assert.equal((await page.locator('.message-composer .ck-editor__editable[contenteditable=true]').innerText()).trim(), bodyEdited)
    const missing = new URL(url)
    missing.searchParams.set('mcpLocalDraft', '2147483647')
    assert.equal((await context.request.get(missing.href)).status(), 404)
    const foreignContext = await browser.newContext()
    await foreignContext.route('**/*', guardRoute)
    const foreignPage = await foreignContext.newPage()
    await foreignPage.goto(origin + '/index.php/login')
    await foreignPage.locator('#user').fill('mcp-it-bob')
    await foreignPage.locator('#password').fill(password)
    await foreignPage.locator('button[type=submit]').click()
    await foreignPage.waitForURL(u => !u.pathname.includes('/login'), { timeout: 30000 })
    assert.equal((await foreignContext.request.get(url)).status(), 404)
    await foreignContext.close()
    await page.close()
    const final = actor('draft-ui-finish', prepared)
    const normal = actor('draft-ui-normal')
    assert.equal(normal.proof.draft_only, true)
    assert.equal(normal.proof.initially_held, true)
    assert.equal(normal.proof.smtp_unchanged, true)
    assert.equal(normal.proof.outgoing_unchanged, true)
    assert.equal(normal.proof.imap_drafts.length, 1)
    assert.equal(normal.proof.imap_drafts[0].from_is_account, true)
    assert.equal(normal.proof.imap_drafts[0].in_reply_to, null)
    assert.equal(normal.proof.imap_drafts[0].references, null)
    assert.equal(final.ui_outgoing_unchanged, true)
    assert.equal(final.ui_smtp_submission_unchanged, true)
    assert.deepEqual(final.send_refusals, { normal: true, stale_conversion: true, stale_schedule: true })
    assert.equal(final.held_after_attempts, true)
    assert.equal(final.after_ui.subject, prepared.subject + ' edited')
    assert.equal(final.after_ui.is_html, format === 'html')
    assert.equal(final.after_ui[format === 'html' ? 'body_html' : 'body_plain'], editedBody)
    assert.equal(final.after_ui.alias_id, prepared.alias_id)
    assert.equal(final.after_ui.parent, prepared.parent)
    assert.equal(final.after_ui.attachments, 1)
    assert.equal(final.original_file_unchanged, true)
    assert.equal(final.proof.smtp_unchanged, true)
    assert.equal(final.proof.outgoing_unchanged, true)
    assert.equal(final.proof.imap_drafts.length, 1)
    const draft = final.proof.imap_drafts[0]
    assert.ok(draft.from.includes(prepared.alias_email))
    assert.equal(draft.in_reply_to, prepared.parent)
    assert.equal(draft.references, prepared.parent)
    assert.equal(draft.attachment_preserved, true)
    assert.deepEqual(forbidden, [])
    console.log('Native browser draft lifecycle (' + format + '): HTTP MCP reply + Files → native composer edit/save → IMAP identity/attachment proof; no-send verified.')
} catch (error) {
    if (page && !page.isClosed()) {
        const diagnostic = await page.evaluate(() => ({
            path: location.pathname,
            vue: (() => {
                const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_ALL)
                let node
                while ((node = walk.nextNode())) {
                    const vue = node.__vue__
                    if (vue?.$root?.$options?.name === 'Mail') {
                        const root = vue.$root
                        return { name: root.$options.name, base: root.$router?.options.base, path: root.$route?.path, matched: root.$route?.matched.length }
                    }
                }
                return null
            })(),
            contentIds: [...document.querySelectorAll('[id]')].map(element => element.id).filter(id => /content|app-mail/.test(id)),
            hash: location.hash,
            appChildren: document.querySelector('#content')?.children.length,
            resources: performance.getEntriesByType('resource').filter(entry => /\.js(?:\?|$)/.test(entry.name)).map(entry => new URL(entry.name).pathname).slice(-25),
            draftState: !!document.getElementById('initial-state-mail-mcp-local-draft'),
            composerCount: document.querySelectorAll('.message-composer').length,
            subjectCount: document.querySelectorAll('#subject').length,
            scripts: [...document.scripts].map(script => script.src ? new URL(script.src).pathname : '').filter(Boolean),
        })).catch(() => ({ unavailable: true }))
        let safeDiagnostic = JSON.stringify({ diagnostic, browserErrors, failedAssets, consoleErrors })
        for (const value of Object.values(secrets)) if (value) safeDiagnostic = safeDiagnostic.split(value).join('[redacted]')
        console.error(safeDiagnostic)
        await page.screenshot({ path: credentialFile.replace(/credentials$/, 'artifacts/native-draft-ui.png') }).catch(() => {})
    }
    let message = String(error?.stack || error)
    for (const value of Object.values(secrets)) if (value) message = message.split(value).join('[redacted]')
    console.error(message)
    process.exitCode = 1
} finally {
    await browser?.close()
    if (prepared) {
        try { actor('draft-ui-cleanup', prepared) }
        catch { console.error('Native UI fixture cleanup failed; owned container cleanup remains required.'); process.exitCode = 1 }
    }
}
