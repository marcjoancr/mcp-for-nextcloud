// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Enhance the ordinary consent form. Only its named, checked inputs are submitted. */
export function mountConsent(form) {
    const catalogs = [...form.querySelectorAll('.mcp-consent-catalog')]
    const tools = root => [...root.querySelectorAll('.mcp-consent-tool-toggle')]
    const actions = entry => [...entry.querySelectorAll('.mcp-consent-variants input[type="checkbox"]')]

    function syncEntry(entry) {
        const parent = entry.querySelector('.mcp-consent-tool-toggle')
        const fieldset = entry.querySelector('.mcp-consent-variants')
        if (!fieldset) return
        fieldset.disabled = fieldset.hidden = !parent.checked
        const variants = actions(entry)
        if (!parent.checked) variants.forEach(input => { input.checked = false })
        if (!parent.checked || variants.some(input => input.checked)) entry.querySelector('[role="alert"]').hidden = true
    }
    function syncRange(root, buttons) {
        const all = tools(root)
        const checked = all.filter(input => input.checked).length
        const complete = checked === all.length && !root.querySelector('.mcp-consent-variants input:not(:checked)')
        buttons.querySelector('[data-select="all"]').disabled = complete
        buttons.querySelector('[data-select="none"]').disabled = checked === 0
        return checked
    }
    function refresh() {
        const bar = form.querySelector('.mcp-consent-toolbar')
        bar.querySelector('[data-count]').textContent = syncRange(form, bar)
        for (const catalog of catalogs) {
            catalog.querySelector('[data-count]').textContent = syncRange(catalog, catalog.querySelector('.mcp-consent-choices'))
        }
    }
    form.addEventListener('click', event => {
        const button = event.target.closest('[data-select]')
        if (!button) return
        const range = button.closest('.mcp-consent-catalog') || form
        const checked = button.dataset.select === 'all'
        for (const entry of range.querySelectorAll('.mcp-consent-entry')) {
            entry.querySelector('.mcp-consent-tool-toggle').checked = checked
            for (const variant of actions(entry)) variant.checked = checked
            syncEntry(entry)
        }
        refresh()
    })
    form.addEventListener('change', event => {
        const entry = event.target.closest('.mcp-consent-entry')
        if (entry) { syncEntry(entry); refresh() }
    })
    form.addEventListener('submit', event => {
        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault()
            return
        }
        if (event.submitter?.value === 'allow') {
            for (const entry of form.querySelectorAll('.mcp-consent-entry')) {
                const fieldset = entry.querySelector('.mcp-consent-variants')
                if (!fieldset || fieldset.disabled || actions(entry).some(input => input.checked)) continue
                event.preventDefault()
                entry.closest('details').open = true
                entry.querySelector('[role="alert"]').hidden = false
                fieldset.querySelector('input').focus()
                return
            }
        }
        // The submit button must remain successful so its allow/deny value is included.
        form.setAttribute('aria-busy', 'true')
    })
    window.addEventListener('pageshow', () => form.removeAttribute('aria-busy'))
    form.querySelectorAll('.mcp-consent-entry').forEach(syncEntry)
    refresh()
    form.querySelectorAll('.mcp-consent-toolbar, .mcp-consent-choices, [data-progress]').forEach(el => { el.hidden = false })
}
