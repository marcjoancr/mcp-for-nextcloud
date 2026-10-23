// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { createServer } from 'node:http'
import { once } from 'node:events'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const renderer = fileURLToPath(new URL('../support/render-oauth-navigation.php', import.meta.url))
function document(target) {
    const result = spawnSync('php', [renderer, target], { encoding: 'utf8' })
    if (result.error || result.status !== 0) throw result.error || new Error(result.stderr)
    return result.stdout
}
async function local(handler) {
    const server = createServer(handler)
    server.listen(0, '127.0.0.1')
    await once(server, 'listening')
    return { server, origin: `http://127.0.0.1:${server.address().port}` }
}
function close(server) { return new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve())) }

// A form's self-only policy must survive the consent POST, while a fresh document can follow
// a callback's redirect to another controlled origin. No public callback or hosted client is contacted.
test('consent POST starts a fresh navigation that follows a cross-origin callback chain', async ({ page }) => {
    const seen = []
    const final = await local((request, response) => {
        seen.push({ stage: 'final', method: request.method, referer: request.headers.referer })
        response.setHeader('Content-Type', 'text/html')
        response.end('<h1>Callback complete</h1>')
    })
    const callback = await local((request, response) => {
        seen.push({ stage: 'callback', method: request.method, referer: request.headers.referer })
        response.writeHead(302, { Location: final.origin + '/done' })
        response.end()
    })
    const consent = await local((request, response) => {
        response.setHeader('Content-Type', 'text/html')
        response.setHeader('Content-Security-Policy', "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
        response.setHeader('Referrer-Policy', 'no-referrer')
        response.setHeader('Cache-Control', 'no-store')
        response.setHeader('Pragma', 'no-cache')
        if (request.method === 'POST') {
            seen.push({ stage: 'consent', method: request.method })
            response.end(document(callback.origin + '/callback?code=local-test&state=local-test'))
        } else {
            response.end('<form method="post" action="/finish"><button>Allow</button></form>')
        }
    })
    try {
        await page.goto(consent.origin)
        await page.getByRole('button', { name: 'Allow' }).click()
        await expect(page).toHaveURL(final.origin + '/done')
        await expect(page.getByRole('heading')).toHaveText('Callback complete')
        expect(seen.filter(item => item.stage === 'consent')).toEqual([{ stage: 'consent', method: 'POST' }])
        expect(seen.find(item => item.stage === 'callback')).toEqual({ stage: 'callback', method: 'GET', referer: undefined })
        expect(seen.find(item => item.stage === 'final').method).toBe('GET')
    } finally {
        await page.goto('about:blank')
        await Promise.all([consent, callback, final].map(({ server }) => close(server)))
    }
})

test('navigation template escapes both target attributes and retains a no-referrer fallback link', async ({ page }) => {
    const target = 'https://callback.example/cb?state="/><script>injected()</script>&other=1'
    const html = document(target)
    expect(html).toContain('&quot;/&gt;&lt;script&gt;')
    // Remove the refresh only in the test browser so the fallback can be inspected without navigation.
    await page.setContent(html.replace(/<meta http-equiv="refresh"[^>]*>/, ''))
    await expect(page.locator('script')).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Connect' })).toHaveAttribute('href', target)
    await expect(page.getByRole('link')).toHaveAttribute('rel', 'noreferrer')
})
