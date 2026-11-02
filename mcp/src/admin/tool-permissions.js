// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../platform/lifecycle.js'
import { mountPermissionDirectory } from './permission-directory.js'
import { renderComparison, ruleSelector } from './comparison-view.js'
import { mountComparisonDetail } from './comparison-detail.js'
import { mountComparisonChanges } from './comparison-changes.js'

export function mountToolPermissions({ root, request, t: translate, getResourceScope, getInspector, openOperations, policyChannel, events }) {
    const host = root.querySelector('#mcp-tool-permissions')
    if (!host) return { ready: Promise.resolve(), destroy() {} }
    const scope = createScope(host), api = scope.request(request)
    const t = (domain, key, params) => translate(domain, key, params, undefined, { escape: false })
    const narrowScreen = window.matchMedia('(max-width: 760px)')
    const field = id => host.querySelector('#mcp-tools-' + id)
    const columns = [], expanded = new Set(), rowsSelected = new Set(), targets = new Set()
    let serial = 0, replacing = null, activeColumn = null, bulkMode = false, busy = false
    let needsReload = false, announcing = false
    const byKey = key => columns.find(c => c.key === key)
    const getRow = (c, name) => name === '_service' ? c.snapshot?.service : c.snapshot?.tools.find(tool => tool.name === name)
    const read = (kind, id) => api('GET', '/settings/tool-permissions?' + new URLSearchParams({ kind, subject: id }))
    const button = (label, action) => {
        const el = document.createElement('button')
        el.type = 'button'
        el.textContent = label
        el.addEventListener('click', scope.guard(action))
        return el
    }
    function report(message, error = false) {
        if (scope.disposed) return
        field('editor-status').textContent = error ? '' : message
        field('editor-error').textContent = error ? message : ''
        field('editor-error').hidden = !error
    }
    function lock(on) {
        if (on && busy) return false
        busy = on
        field('fields').disabled = on
        if (!on && needsReload) {
            needsReload = false
            scope.timeout(reload, 0)
        }
        return true
    }
    function focusCell(key, name) {
        const selector = name ? '[data-tool][data-column-key]' : 'button[data-column-key]:not([data-tool])'
        const control = [...field('grid').querySelectorAll(selector)].find(el => el.tagName === 'BUTTON' && el.dataset.columnKey === key && (!name || el.dataset.tool === name))
        ;(control || field('add')).focus({ preventScroll: true })
    }
    let started = false, autoSelect = true
    function closePicker() {
        directory.close()
        replacing = null
    }
    function openPicker(key = null, owner = field('add')) {
        if (busy) return
        autoSelect = false
        detail.clear()
        replacing = key
        const current = byKey(key)
        directory.open(owner, current)
    }
    function column(kind, id, label, snapshot, key = String(++serial)) {
        return { key, kind, id, label, snapshot, draft: {}, base: {} }
    }
    async function choose(kind, id, label) {
        if (busy || scope.disposed) return
        autoSelect = false
        const previous = byKey(replacing)
        const existing = columns.find(c => c.kind === kind && c.id === id)
        if (existing) {
            if (previous && previous !== existing) {
                const from = columns.indexOf(previous), to = columns.indexOf(existing)
                columns[from] = existing
                columns[to] = previous
            }
            activeColumn = existing.key
            closePicker(); render(); focusCell(existing.key)
            return
        }
        if (previous && Object.keys(previous.draft).length && !window.confirm(t('mcp', 'Discard unsaved permission changes?'))) return
        lock(true)
        try {
            const snapshot = await read(kind, id)
            const c = column(kind, id, label, snapshot, previous?.key)
            if (previous) columns.splice(columns.indexOf(previous), 1, c)
            else columns.push(c)
            targets.delete(c.key)
            activeColumn = c.key
            changes.reset()
            closePicker()
            detail.clear()
            report('')
            render()
        } catch {
            if (!scope.disposed) report(t('mcp', 'Could not load this entry’s permissions. Try again.'), true)
        } finally {
            if (!scope.disposed) { lock(false); if (!field('picker').matches(':popover-open')) focusCell(activeColumn) }
        }
    }
    function remove(key) {
        const c = byKey(key)
        if (!c || busy || (Object.keys(c.draft).length && !window.confirm(t('mcp', 'Discard unsaved permission changes?')))) return
        columns.splice(columns.indexOf(c), 1)
        targets.delete(key)
        if (activeColumn === key) activeColumn = columns[0]?.key
        changes.reset(); detail.clear(); closePicker(); render()
        if (!columns.length) openPicker()
        else focusCell(activeColumn)
    }
    async function compareGroups(groups) {
        if (!lock(true)) return
        try {
            const missing = groups.filter(g => !columns.some(c => c.kind === 'group' && c.id === g.id))
            const added = []
            for (let i = 0; i < missing.length; i += 3) {
                const groupColumns = await Promise.all(missing.slice(i, i + 3).map(async g =>
                    column('group', g.id, g.displayName || g.id, await read('group', g.id))))
                added.push(...groupColumns)
            }
            columns.push(...added)
            activeColumn = columns.find(c => c.kind === 'group' && c.id === groups[0]?.id)?.key || activeColumn
            detail.clear(); closePicker(); report(''); render()
        } catch {
            if (!scope.disposed) report(t('mcp', 'Could not load this entry’s permissions. Try again.'), true)
        } finally {
            if (!scope.disposed) { lock(false); focusCell(activeColumn) }
        }
    }
    function stage(c, name, value) {
        const row = getRow(c, name)
        if (!row || busy) return
        if (value === row.state) { delete c.draft[name]; delete c.base[name] }
        else { c.draft[name] = value; c.base[name] = row.state }
        changes.reset()
    }
    async function refresh() {
        changes.reset(); detail.clear()
        let conflicts = false
        await Promise.all(columns.map(async c => {
            try {
                const fresh = await read(c.kind, c.id)
                c.snapshot = fresh
                for (const name of Object.keys(c.draft)) {
                    // A changed or removed rule must be chosen again from the fresh catalog.
                    const row = getRow(c, name)
                    if (c.base[name] !== row?.state) {
                        if (row?.state !== c.draft[name]) conflicts = true
                        delete c.draft[name]
                        delete c.base[name]
                    }
                }
            } catch {
                if (!scope.disposed) c.snapshot = null
            }
        }))
        if (columns.some(c => !c.snapshot)) return t('mcp', 'Could not load this entry’s permissions. Try again.')
        return conflicts ? t('mcp', 'Some rules changed elsewhere. Review them again.') : ''
    }
    async function reload() {
        if (!lock(true)) return
        const warning = await refresh()
        if (scope.disposed) return
        lock(false); render()
        report(warning, Boolean(warning))
    }
    const changes = mountComparisonChanges({ api, t, field, columns, render, refresh, lock, report,
        announce(from, to) {
            announcing = true
            try { policyChannel?.announce(from, to) } finally { announcing = false }
        }, alive: () => !scope.disposed })
    const detail = mountComparisonDetail({ panel: field('detail'), t, button,
        api, compare: compareGroups, closeFocus: focusCell, operations: openOperations })
    function drawBulk() {
        const box = field('bulk-editor')
        box.hidden = !bulkMode
        box.replaceChildren()
        if (!bulkMode) return
        const hint = document.createElement('p')
        hint.textContent = t('mcp', 'Select tools in the matrix and choose which columns to change.')
        box.append(hint)
        const targetList = document.createElement('fieldset')
        const legend = document.createElement('legend')
        legend.textContent = t('mcp', 'Apply to columns')
        targetList.append(legend)
        for (const c of columns) {
            const label = document.createElement('label'), input = document.createElement('input')
            input.type = 'checkbox'; input.checked = targets.has(c.key); input.disabled = !c.snapshot
            input.addEventListener('change', () => {
                if (input.checked) targets.add(c.key)
                else targets.delete(c.key)
                drawBulk()
                box.querySelectorAll('input')[columns.indexOf(c)]?.focus()
            })
            label.append(input, ' ' + (c.kind === 'group' ? t('mcp', 'Group') : t('mcp', 'Person')) + ' · ' + c.label)
            targetList.append(label)
        }
        box.append(targetList)
        const cells = columns.filter(c => targets.has(c.key)).flatMap(c => [...rowsSelected].filter(name => getRow(c, name)).map(name => [c, name]))
        const count = document.createElement('p')
        count.textContent = t('mcp', 'Selected rules: {count}', { count: cells.length })
        box.append(count)
        const kinds = new Set(cells.map(([c]) => c.kind))
        const picker = ruleSelector(t, kinds.size === 1 ? [...kinds][0] : '', t('mcp', 'Rule for selected tools'), '')
        picker.disabled = !cells.length
        picker.addEventListener('change', () => {
            if (!picker.value) return
            for (const [c, name] of cells) stage(c, name, picker.value)
            render()
            box.querySelector('select').focus()
        })
        box.append(picker)
    }
    function render() {
        detail.closeInline()
        closePicker()
        field('add').textContent = columns.length ? t('mcp', 'Add comparison column') : t('mcp', 'Choose a group or person')
        field('editor').hidden = !columns.length
        field('differences').disabled = columns.length < 2
        field('exceptions').disabled = !columns.some(c => c.kind === 'user')
        field('bulk').setAttribute('aria-pressed', String(bulkMode))
        renderComparison(field('grid'), {
            columns, query: field('filter').value, differences: !field('differences').disabled && field('differences').checked,
            exceptions: !field('exceptions').disabled && field('exceptions').checked, expanded, rowsSelected, activeColumn, bulkMode, compact: narrowScreen.matches, t,
            onPick: openPicker, onRemove: remove, onCell: (key, name, trigger) => detail.cell(byKey(key), name, trigger),
            onRule(key, name, value) { stage(byKey(key), name, value); render() },
            onRow(name, selected) { if (selected) rowsSelected.add(name); else rowsSelected.delete(name); render() },
            onExpand(module) { if (expanded.has(module)) expanded.delete(module); else expanded.add(module); render() },
            onMobileColumn(key) { activeColumn = key; detail.clear(); render() },
            onPersonGroups: key => detail.groups(byKey(key)),
            onInspect: key => getInspector()?.open(byKey(key).id, document.activeElement),
            onScope: key => getResourceScope()?.open(byKey(key).id, 'files', document.activeElement),
        })
        drawBulk(); changes.draw()
    }
    scope.on(narrowScreen, 'change', () => { detail.clear(); render() })
    const directory = mountPermissionDirectory({ host, api, scope, t, choose })
    scope.on(field('add'), 'click', () => openPicker())
    for (const [id, event] of [['filter','input'], ['differences','change'], ['exceptions','change']]) {
        scope.on(field(id), event, () => { rowsSelected.clear(); detail.clear(); render() })
    }
    scope.on(field('bulk'), 'click', () => {
        bulkMode = !bulkMode
        targets.clear(); rowsSelected.clear(); detail.clear()
        if (bulkMode && columns.length === 1) targets.add(columns[0].key)
        render()
    })
    scope.on(field('save'), 'click', changes.submit)
    scope.on(field('discard'), 'click', () => { changes.discard(); detail.clear(); field('filter').focus() })
    scope.on(field('reload'), 'click', reload)
    scope.on(field('global-service'), 'click', () => { root.querySelector('#mcp-tab-overview').click(); root.querySelector('#mcp-service').focus() })
    scope.on(root, 'mcp-view-tool-permissions', event => {
        if (!event.detail?.uid || busy) return
        root.querySelector('#mcp-tab-access').click()
        replacing = null
        choose('user', event.detail.uid, event.detail.uid)
    })
    // Refresh after edits made in the contextual operation or resource controls.
    function refreshAfterEdit() {
        if (busy) needsReload = true
        else if (columns.length) reload()
    }
    scope.use(policyChannel?.follow((from, to) => {
        if (!announcing && (busy || columns.some(c => c.snapshot?.revision !== to))) refreshAfterEdit()
    }) || (() => {}))
    for (const event of ['mcp-group-policy-saved', 'mcp-scope-saved']) {
        if (events) scope.on(events, event, refreshAfterEdit)
    }
    scope.use(() => {
        detail.clear()
        field('add').textContent = t('mcp', 'Choose a group or person')
        field('grid').replaceChildren()
        field('subject').replaceChildren()
        field('fields').disabled = false
        field('editor').hidden = true
        field('editor-status').textContent = field('editor-error').textContent = ''
        field('filter').value = field('subject-search').value = ''
        field('differences').checked = field('exceptions').checked = false
    })
    // Defer the first group lookup until Access is actually visible. An explicit
    // choice (including the access inspector) wins over a late initial response.
    const accessPanel = host.closest('[role="tabpanel"]')
    async function start() {
        if (started || accessPanel?.hidden) return
        started = true
        try {
            for (const kind of ['group', 'user']) {
                const { subjects } = await directory.list(kind, '', 1)
                if (!autoSelect || scope.disposed) return
                if (subjects.length) {
                    const { id, displayName } = subjects[0]
                    const snapshot = await read(kind, id)
                    if (!autoSelect || scope.disposed) return
                    const c = column(kind, id, displayName || id, snapshot)
                    columns.push(c)
                    activeColumn = c.key
                    render()
                    return
                }
            }
        } catch {
            if (autoSelect && !scope.disposed) report(t('mcp', 'Could not load people or groups. Try again.'), true)
        }
    }
    if (accessPanel) {
        const observer = new MutationObserver(() => { if (accessPanel.hidden) closePicker(); else start() })
        observer.observe(accessPanel, { attributes: true, attributeFilter: ['hidden'] })
        scope.use(() => observer.disconnect())
    }
    return { ready: start(), destroy: scope.destroy }
}
