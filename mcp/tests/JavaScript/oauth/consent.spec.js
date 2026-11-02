// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

test('global and catalog selections submit only the chosen authorized tools and actions', async ({ page }) => {
    await page.goto('/consent')
    const form = page.locator('form')
    const catalogs = form.locator('.mcp-consent-catalog')
    const total = form.locator('output [data-count]')
    await expect(total).toHaveText('0')
    await expect(catalogs.locator('input:checked')).toHaveCount(0)
    await expect(form.locator('details[open]')).toHaveCount(0)
    await form.getByRole('button', { name: 'Select all tools' }).click()
    await expect(total).toHaveText('3')
    await expect(form.locator('input[name^="variants"]:checked')).toHaveCount(1)
    const files = catalogs.first()
    await files.locator(':scope > summary').click()
    await files.getByRole('button', { name: 'Clear this catalog' }).click()
    await expect(total).toHaveText('1')
    let fields = await form.evaluate(el => [...new FormData(el).entries()])
    expect(fields.filter(([name]) => name === 'tools[]')).toEqual([['tools[]','notes_read']])
    expect(fields.filter(([name]) => name.startsWith('variants['))).toEqual([])
    await files.getByRole('button', { name: 'Select this catalog' }).click()
    await form.getByRole('button', { name: 'Clear selection' }).click()
    await expect(total).toHaveText('0')
    fields = await form.evaluate(el => [...new FormData(el).entries()])
    expect(fields).toContainEqual(['toolsSubmitted','1'])
    expect(fields.some(([name]) => name === 'tools[]')).toBe(false)
})

test('missing actions keep the selection on screen and let the user correct it', async ({ page }) => {
    await page.goto('/consent')
    const form = page.locator('form'), files = form.locator('.mcp-consent-catalog').first()
    const posts = []
    page.on('request', r => { if (r.method() === 'POST') posts.push(r) })
    await files.locator(':scope > summary').click()
    await files.locator('[value=files_share][type=checkbox]').check()
    await files.locator(':scope > summary').click()
    await form.getByRole('button', { name:'Allow', exact:true }).click()
    await expect(files).toHaveAttribute('open','')
    await expect(files.getByRole('alert')).toBeVisible()
    await expect(files.locator('[value=share]')).toBeFocused()
    await files.locator('[value=share]').check()
    await expect(files.getByRole('alert')).toBeHidden()
    expect(posts).toHaveLength(0)
    await form.getByRole('button', { name:'Allow', exact:true }).click()
    expect(posts).toHaveLength(1)
    expect(new URLSearchParams(posts[0].postData()).getAll('variants[files_share][]')).toEqual(['share'])
})

test('a second submit cannot replace the navigation for a single-use consent', async ({ page }) => {
    await page.goto('/consent')
    const allowed = await page.locator('form').evaluate(form => {
        const submitter = form.querySelector('[value="allow"]')
        const submit = () => form.dispatchEvent(new SubmitEvent('submit', { bubbles:true, cancelable:true, submitter }))
        return [submit(), submit(), new FormData(form, submitter).get('decision')]
    })
    expect(allowed).toEqual([true, false, 'allow'])
})

test('without JavaScript, permitted sharing actions remain selectable', async ({ browser, baseURL }) => {
    const context = await browser.newContext({javaScriptEnabled:false, baseURL})
    try {
        const page = await context.newPage()
        await page.goto('/consent')
        const files = page.locator('.mcp-consent-catalog').first()
        await files.locator(':scope > summary').click()
        await files.locator('[value=files_share][type=checkbox]').check()
        await files.locator('[value=share]').check()
        await expect(files.locator('[value=share]')).toBeChecked()
        await expect(page.getByRole('button',{name:'Select all tools'})).toHaveCount(0)
    } finally { await context.close() }
})
