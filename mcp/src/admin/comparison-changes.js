// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** The server binds each preview receipt to one identity and a global policy revision. */
export function mountComparisonChanges({ api, t, field, columns, refresh, render, lock, report, announce, alive }) {
    let review = null
    const label = state => state === 'allow' ? t('mcp', 'Allow') : state === 'deny' ? t('mcp', 'Block') : t('mcp', 'No rule')
    const subject = c => (c.kind === 'group' ? t('mcp', 'Group') : t('mcp', 'Person')) + ' · ' + c.label
    const pending = () => columns.filter(c => c.snapshot && Object.keys(c.draft).length)
    const row = (c, name) => name === '_service' ? c.snapshot.service : c.snapshot.tools.find(tool => tool.name === name)
    function reset() { review = null }
    function discard() {
        reset()
        for (const c of columns) { c.draft = {}; c.base = {} }
        render()
    }
    function draw() {
        const changes = field('changes')
        changes.replaceChildren()
        const dirty = pending()
        field('savebar').hidden = !dirty.length
        for (const c of dirty) {
            for (const [name, decision] of Object.entries(c.draft)) {
                const original = row(c, name)
                const item = document.createElement('li')
                item.textContent = subject(c) + ' · ' + (original.title || t('mcp', 'MCP service')) + ' · ' + label(original.state) + ' → ' + label(decision)
                const projected = review?.column === c && review.result.impact.tools[name]
                if (projected) {
                    const service = review.result.impact.tools._service || c.snapshot.service
                    const gated = name !== '_service' && c.kind === 'user' && service.effective !== true
                    const effective = gated ? service.effective : projected.effective
                    const result = effective === null ? t('mcp', 'Could not be checked') : effective ? t('mcp', 'Allowed') : t('mcp', 'Blocked')
                    item.textContent += ' · ' + result + ' · ' + (gated ? t('mcp', 'Service blocked') : projected.origin)
                }
                changes.append(item)
            }
        }
        field('save-status').textContent = review ? review.result.impact.summary : dirty.length > 1
            ? t('mcp', 'Review and save each column in turn. Its impact may change after another column is saved.') : ''
        field('save').textContent = review ? t('mcp', 'Save rules for {name}', { name: review.column.label }) : t('mcp', 'Review changes')
    }
    async function submit() {
        const c = review?.column || pending()[0]
        if (!c || !lock(true)) return
        const saving = review !== null
        const revision = c.snapshot.revision
        const body = { kind: c.kind, subject: c.id, changes: { ...c.draft }, revision, preview: !saving }
        if (saving) body.previewId = review.result.previewId
        try {
            const result = await api('POST', '/settings/tool-permissions', body, { uncertainWrite: saving })
            if (!saving) {
                if (!result.previewId || !result.impact?.tools || result.revision !== revision) throw new Error('Invalid preview')
                review = { column: c, result }
            } else {
                c.snapshot = result
                c.draft = {}
                reset()
                announce(revision, result.revision)
                const warning = await refresh()
                report([t('mcp', 'Saved rules for {name}.', { name: c.label }), warning].filter(Boolean).join(' '), Boolean(warning))
            }
        } catch (e) {
            if (!alive()) return
            reset()
            if (e.message === '409' || e.message === '428' || (saving && !/^4\d\d$/.test(e.message))) {
                // Never replay a failed or uncertain commit. A new preview requires an explicit click.
                await refresh()
                report(e.message === '409' || e.message === '428' ? t('mcp', 'Permissions changed elsewhere. Reloading the latest values.')
                    : t('mcp', 'The save could not be confirmed. Check the current permissions before trying again.'), true)
            } else {
                report(t('mcp', 'Could not save permissions. Your changes are still here.'), true)
            }
        } finally {
            if (alive()) { lock(false); render(); if (pending().length) field('save').focus() }
        }
    }
    return { draw, submit, reset, discard }
}
