// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createPresentation } from './access/presentation.js'

/** A cell can explain many inherited decisions without repeating them throughout the grid. */
export function mountComparisonDetail({ panel, api, t, button, stage, compare, closeFocus, operations }) {
    const labels = createPresentation({ t })
    let sequence = 0
    const text = (tag, copy) => Object.assign(document.createElement(tag), { textContent: copy })
    function clear() {
        ++sequence
        panel.hidden = true
        panel.replaceChildren()
    }
    function start(column, name, title) {
        clear()
        panel.hidden = false
        panel.append(text('h3', title + ' · ' + column.label), button(t('mcp', 'Close'), () => {
            clear()
            closeFocus(column.key, name)
        }))
        panel.focus()
    }
    function groupChoices(groups, host) {
        if (!groups.length) { host.append(text('p', t('mcp', 'No groups'))); return }
        const list = document.createElement('fieldset')
        list.append(text('legend', t('mcp', 'Compare with groups')))
        const selected = new Map()
        if (groups.length > 6) {
            const search = document.createElement('input')
            search.type = 'search'
            search.setAttribute('aria-label', t('mcp', 'Search groups'))
            list.append(search)
            search.addEventListener('input', () => {
                for (const label of list.querySelectorAll('label')) label.hidden = !label.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())
            })
        }
        for (const group of groups) {
            const input = document.createElement('input')
            input.type = 'checkbox'
            const label = document.createElement('label')
            label.append(input, ' ' + (group.displayName || group.id))
            list.append(label)
            input.addEventListener('change', () => {
                if (input.checked) selected.set(group.id, group)
                else selected.delete(group.id)
                add.disabled = !selected.size
            })
        }
        const add = button(t('mcp', 'Compare selected groups'), () => compare([...selected.values()]))
        add.disabled = true
        list.append(add)
        host.append(list)
    }
    function groups(column) {
        start(column, null, t('mcp', 'Compare with groups'))
        groupChoices(column.snapshot.groups, panel)
    }
    async function cell(column, name) {
        const item = name === '_service' ? column.snapshot.service : column.snapshot.tools.find(row => row.name === name)
        if (!item) return
        const title = item.title || t('mcp', 'MCP service')
        start(column, name, title)
        const generation = sequence
        if (item.description) panel.append(text('p', item.description))
        const select = document.createElement('select')
        const label = text('label', t('mcp', 'Assignment for {tool}', { tool: title }))
        for (const [value, string] of [['inherit', column.kind === 'group' ? t('mcp', 'No rule') : t('mcp', 'Inherit')], ['allow', t('mcp', 'Allow')], ['deny', t('mcp', 'Block')]]) {
            select.append(new Option(string, value))
        }
        select.value = column.draft[name] ?? item.state
        label.append(' ', select)
        panel.append(label, button(t('mcp', 'Stage rule'), () => {
            stage(column, name, select.value)
            clear()
            closeFocus(column.key, name)
        }))
        if (column.kind === 'user' && (column.draft[name] ?? item.state) !== 'inherit') {
            panel.append(button(t('mcp', 'Use inherited rule'), () => {
                stage(column, name, 'inherit')
                clear()
                closeFocus(column.key, name)
            }))
        }
        if (name !== '_service') {
            const related = document.createElement('details')
            related.append(text('summary', t('mcp', 'Related operation rules')))
            const links = { ...item.requiredModules }
            links[item.module] = [...new Set([...(links[item.module] || []), ...(item.requiresOperations?.length ? item.requiresOperations : [item.operation])].filter(Boolean))]
            const modules = labels.moduleLabels(), operationsById = labels.operationLabels()
            for (const [module, ops] of Object.entries(links)) {
                related.append(button(t('mcp', 'Edit {module} operations: {operations}', { module: modules[module] || module, operations: ops.map(op => operationsById[op] || op).join(', ') }), () => operations(column, module, ops[0])))
            }
            panel.append(related)
        }
        if (column.kind !== 'user') return
        const info = text('div', t('mcp', 'Loading permissions…'))
        info.setAttribute('role', 'status')
        panel.append(info)
        try {
            const data = await api('GET', '/settings/tool-permissions/origin?' + new URLSearchParams({ subject: column.id, tool: name }))
            if (generation !== sequence) return
            if (data.revision !== column.snapshot.revision) {
                info.textContent = t('mcp', 'Permissions changed elsewhere. Reload this entry.')
                return
            }
            info.replaceChildren(text('h4', t('mcp', 'View decisions from each group')))
            const assigned = (data.groups || []).filter(group => group.state !== 'inherit' || item.groupIds?.includes(group.id))
            for (const group of assigned) {
                const result = group.effective === null ? t('mcp', 'Could not be checked') : group.effective ? t('mcp', 'Allowed') : t('mcp', 'Blocked')
                info.append(text('p', (group.displayName || group.id) + ' · ' + result))
            }
            if (assigned.length) groupChoices(assigned, info)
            else info.append(text('p', t('mcp', 'No group rule applies.')))
        } catch {
            if (generation === sequence) info.replaceChildren(text('p', t('mcp', 'Could not load group decisions. Try again.')), button(t('mcp', 'Retry'), () => cell(column, name)))
        }
    }
    return { cell, groups, clear }
}
