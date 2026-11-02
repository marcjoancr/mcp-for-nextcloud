// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../platform/lifecycle.js'
import { mountPermissionDirectory } from './permission-directory.js'

/** Admin editor for inherited service and per-tool permissions. */
export function mountToolPermissions({ root, request, t, getResourceScope, getInspector }) {
    const host = root.querySelector('#mcp-tool-permissions')
    if (!host) return { ready: Promise.resolve(), destroy() {} }
    const scope = createScope(host)
    const api = scope.request(request)
    const field = id => host.querySelector('#mcp-tools-' + id)
    const editor = field('editor'), status = field('editor-status'), error = field('editor-error')
    const filter = field('filter'), catalogs = field('catalogs'), save = field('save')
    const state = { kind: 'group', selected: '', snapshot: null, draft: {}, preview: null, detailSeq: 0, loading: false }
    const plainText = { escape: false }
    const editing = new Set()
    let opened = new Set()

    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag)
        for (const [key, value] of Object.entries(attrs)) {
            if (key.startsWith('aria-') || key.startsWith('data-')) node.setAttribute(key, String(value))
            else node[key] = value
        }
        node.append(...children)
        return node
    }

    function catalogLabel(module) {
        const labels = { files: 'Files', notes: 'Notes', deck: 'Deck', calendar: 'Calendar', contacts: 'Contacts', tasks: 'Tasks', people: 'People', talk: 'Talk', logs: 'Server log', mail: 'Mail' }
        return t('mcp', labels[module] || module)
    }

    function fromGroups(row) {
        return row.groupIds?.length && (!row.source || ['group', 'legacy_group', 'group_allow', 'group_deny'].includes(row.source))
    }

    function originLabel(row) {
        if (row.effective === null) return t('mcp', 'Could not be checked')
        if (fromGroups(row)) {
            const names = new Map((state.snapshot.groups || []).map(group => [group.id, group.displayName || group.id]))
            const groups = row.groupIds.map(id => names.get(id) || id).join(', ')
            return row.effective
                ? t('mcp', 'Allowed by {groups}', { groups }, undefined, plainText)
                : t('mcp', 'Blocked by {groups}', { groups }, undefined, plainText)
        }
        return row.origin || row.source || ''
    }

    function decisionLabel(row) {
        if (row.effective === null) return t('mcp', 'Could not be checked')
        return row.effective ? t('mcp', 'Allowed') : t('mcp', 'Blocked')
    }

    function accessLabel(row) {
        const label = decisionLabel(row)
        const reason = originLabel(row)
        if (fromGroups(row)) return reason
        return reason && reason !== label ? label + ' · ' + reason : label
    }

    function provenanceFor(tool) {
        const provenance = el('details', { className: 'mcp-tool-origin' })
        const summary = el('summary', { textContent: t('mcp', 'View decisions from each group') })
        const details = el('div', { role: 'status', className: 'mcp-hint' })
        let loaded = false
        provenance.append(summary)
        if (tool.description) provenance.append(el('p', { textContent: tool.description }))
        provenance.append(details)
        provenance.addEventListener('toggle', async () => {
            if (!provenance.open || loaded) return
            loaded = true
            const selectedSubject = state.selected
            const selectedKind = state.kind
            const seq = state.detailSeq
            details.textContent = t('mcp', 'Loading permissions…')
            try {
                const query = new URLSearchParams({ subject: selectedSubject, tool: tool.name })
                const data = await api('GET', '/settings/tool-permissions/origin?' + query)
                if (seq !== state.detailSeq || selectedSubject !== state.selected || selectedKind !== state.kind || !provenance.isConnected) return
                if (data.revision !== state.snapshot.revision) {
                    details.textContent = t('mcp', 'Permissions changed elsewhere. Reload this entry.')
                    return
                }
                const decisions = [data.individual, ...(data.groups || [])].filter(Boolean)
                details.replaceChildren(...decisions.map(item => el('p', { textContent: t('mcp', '{name}: {state} · {result} · {origin}', {
                    name: item.displayName || t('mcp', 'Individual assignment'), state: t('mcp', item.state === 'inherit' ? 'Not assigned' : item.state === 'allow' ? 'Allow' : 'Block'),
                    result: decisionLabel(item), origin: item.origin || '',
                }, undefined, plainText) })))
                if (!decisions.length) details.textContent = t('mcp', 'No individual or group assignments.')
            } catch (failure) {
                if (seq !== state.detailSeq || selectedSubject !== state.selected) return
                loaded = false
                details.textContent = t('mcp', 'Could not load group decisions. Try again.')
            }
        })

        return provenance
    }

    async function choose(kind, id, name) {
        if (state.loading) return
        if (Object.keys(state.draft).length && !window.confirm(t('mcp', 'Discard unsaved permission changes?'))) return
        directory.close()
        field('subject-search').value = ''
        state.kind = kind
        state.selected = id
        field('selected').textContent = name
        filter.value = ''
        opened = new Set()
        await loadDetail()
        if (!editor.hidden) field('selected').focus()
    }

    async function loadDetail() {
        const seq = ++state.detailSeq
        state.snapshot = null
        state.draft = {}
        state.preview = null
        editing.clear()
        editor.hidden = true
        error.hidden = true
        status.textContent = t('mcp', 'Loading permissions…')
        try {
            const query = new URLSearchParams({ kind: state.kind, subject: state.selected })
            const snapshot = await api('GET', '/settings/tool-permissions?' + query)
            if (seq !== state.detailSeq) return
            state.snapshot = snapshot
            renderMembership(snapshot.groups || [])
            field('person-actions').hidden = state.kind !== 'user'
            render()
            editor.hidden = false
            status.textContent = ''
        } catch (failure) {
            if (scope.disposed || seq !== state.detailSeq) return
            error.textContent = failure.message === '409' ? t('mcp', 'Permissions changed elsewhere. Reload this entry.') :
                t('mcp', 'Could not load this entry’s permissions. Try again.')
            error.hidden = false
            status.textContent = ''
        }
    }

    function renderMembership(groups) {
        const list = field('membership-list')
        if (state.kind === 'group') {
            list.textContent = t('mcp', 'Group')
            return
        }
        const names = groups.map(group => group.displayName || group.id).join(', ')
        const label = t('mcp', 'Groups')
        if (groups.length > 3) {
            list.replaceChildren(el('details', {}, [el('summary', { textContent: label + ' (' + groups.length + ')' }), names]))
        } else {
            list.textContent = names ? label + ' · ' + names : t('mcp', 'No groups')
        }
    }

    function assignmentLabel(value) {
        if (value === 'allow') return t('mcp', 'Allow')
        if (value === 'deny') return t('mcp', 'Block')
        return state.kind === 'user' ? t('mcp', 'Inherit') : t('mcp', 'Not assigned')
    }

    function setAssignment(key, row, value) {
        if (value === row.state) delete state.draft[key]
        else state.draft[key] = value
        state.preview = null
        render()
    }

    function button(label, onClick) {
        const control = el('button', { type: 'button', textContent: label })
        control.addEventListener('click', onClick)
        return control
    }

    function controls(key, row) {
        const value = state.draft[key] ?? row.state
        const box = el('div', { className: 'mcp-tool-controls' })
        const label = row.title || t('mcp', 'MCP service')
        const select = el('select', { id: 'mcp-tool-state-' + key, 'aria-label': t('mcp', 'Assignment for {tool}', { tool: label }, undefined, plainText) })
        for (const option of ['inherit', 'allow', 'deny']) select.append(el('option', { value: option, textContent: assignmentLabel(option) }))
        select.value = value
        const focusControl = () => document.getElementById(select.id)?.focus()
        select.addEventListener('change', () => { setAssignment(key, row, select.value); focusControl() })
        if (state.kind === 'group' || editing.has(key)) {
            box.append(select)
        } else {
            const edit = button(value === 'inherit' ? t('mcp', 'Create exception') : t('mcp', 'Change exception'), () => {
                editing.add(key)
                render()
                focusControl()
            })
            edit.id = select.id
            box.append(edit)
        }
        if (state.kind === 'user' && value !== 'inherit') {
            box.append(button(t('mcp', 'Use inherited rule'), () => {
                editing.delete(key)
                setAssignment(key, row, 'inherit')
                focusControl()
            }))
        }
        return box
    }

    // Keep authoritative saved access visible while a separate change list previews the draft.
    function resultFor(tool) {
        const service = state.snapshot.service
        if (state.kind === 'user' && service.effective !== true) {
            return { effective: service.effective, origin: service.effective === false ? t('mcp', 'Service blocked') : '' }
        }
        return tool
    }

    function renderRow(tool) {
        const result = resultFor(tool)
        const heading = el('strong', { textContent: tool.title })
        const copy = el('div', { className: 'mcp-tool-copy' })
        if (state.kind === 'user') {
            copy.append(heading, el('span', { textContent: accessLabel(result) }), provenanceFor(tool))
        } else {
            copy.append(tool.description ? el('details', {}, [el('summary', {}, [heading]), el('p', { textContent: tool.description })]) : heading)
            if (result.effective === null || (tool.state === 'allow' && !result.effective)) {
                copy.append(el('span', { textContent: accessLabel(result) }))
            }
        }
        if (Object.keys(tool.requiresOperations || {}).length) {
            copy.append(button(t('mcp', 'Review advanced operation permissions'), () => {
                const disclosure = root.querySelector('#mcp-tools-advanced')
                disclosure.open = true
                disclosure.querySelector('summary').focus()
            }))
        }
        return el('article', { className: 'mcp-tool-row' }, [copy, controls(tool.name, tool)])
    }

    function renderTools() {
        if (!state.snapshot) return
        const query = filter.value.trim().toLocaleLowerCase()
        const groups = new Map()
        for (const tool of state.snapshot.tools) {
            const module = catalogLabel(tool.module)
            if (query && ![tool.title, tool.description, tool.name, module].join(' ').toLocaleLowerCase().includes(query)) continue
            if (!groups.has(tool.module)) groups.set(tool.module, [])
            groups.get(tool.module).push(tool)
        }
        catalogs.replaceChildren()
        for (const [module, tools] of groups) {
            const allowed = tools.filter(tool => state.kind === 'group' ? tool.state === 'allow' : resultFor(tool).effective === true).length
            const blocked = tools.filter(tool => state.kind === 'group' ? tool.state === 'deny' : resultFor(tool).effective === false).length
            const counts = t('mcp', '{allowed} allowed · {blocked} blocked', { allowed, blocked }, undefined, plainText)
            const unknown = tools.length - allowed - blocked
            const summary = el('summary', {}, [el('strong', { textContent: catalogLabel(module) }), el('span', { className: 'mcp-hint', textContent:
                unknown ? counts + ' · ' + (state.kind === 'group' ? t('mcp', '{count} unassigned', { count: unknown }, undefined, plainText) : t('mcp', '{count} unknown', { count: unknown }, undefined, plainText)) : counts })])
            const panel = el('details', { className: 'mcp-tool-catalog', open: !!query || opened.has(module) }, [summary])
            const populate = () => {
                if (panel.open && panel.children.length === 1) panel.append(el('div', { className: 'mcp-tool-list' }, tools.map(renderRow)))
            }
            populate()
            panel.addEventListener('toggle', () => {
                if (!panel.isConnected) return
                if (!query) { if (panel.open) opened.add(module); else opened.delete(module) }
                populate()
            })
            catalogs.append(panel)
        }
        if (!groups.size) catalogs.append(el('p', { textContent: t('mcp', 'No tools match this search.') }))
    }

    function renderExceptions() {
        const section = field('exceptions')
        const exceptions = state.snapshot.tools.filter(tool => tool.state !== 'inherit')
        section.hidden = state.kind !== 'user' || !exceptions.length
        if (section.hidden) return
        const list = el('div', { className: 'mcp-exception-list' })
        for (const tool of exceptions) {
            list.append(button(tool.title + ' · ' + decisionLabel(resultFor(tool)), () => {
                filter.value = tool.title
                renderTools()
                document.getElementById('mcp-tool-state-' + tool.name)?.focus()
            }))
        }
        section.replaceChildren(el('h4', { textContent: t('mcp', 'Individual exceptions') }), list)
    }

    function render() {
        const service = state.snapshot.service
        field('service-result').textContent = service.effective === null ? t('mcp', 'Could not be checked') :
            (service.effective ? t('mcp', 'Service active') : t('mcp', 'Service blocked'))
        field('service-origin').textContent = originLabel(service)
        field('service-controls').replaceChildren(controls('_service', state.snapshot.service))
        renderExceptions()
        renderTools()
        const changes = Object.entries(state.draft)
        field('savebar').hidden = !changes.length
        field('changes').replaceChildren(...changes.map(([key, value]) => {
            const row = key === '_service' ? state.snapshot.service : state.snapshot.tools.find(tool => tool.name === key)
            const after = state.preview?.impact.tools?.[key]
            const name = row.title || t('mcp', 'MCP service')
            let description = name + ' · ' + assignmentLabel(row.state) + ' → ' + assignmentLabel(value)
            if (after) {
                const serviceAfter = state.preview.impact.tools._service || state.snapshot.service
                const result = key !== '_service' && state.kind === 'user' && serviceAfter.effective !== true
                    ? { effective: serviceAfter.effective, origin: t('mcp', 'Service blocked') } : after
                description += ' · ' + accessLabel(result)
            }
            return el('li', { textContent: description })
        }))
        field('save-status').textContent = state.preview?.impact.summary || ''
        save.textContent = state.preview ? t('mcp', 'Save permissions') : t('mcp', 'Review changes')
    }

    async function submit() {
        if (state.loading || !Object.keys(state.draft).length) return
        state.loading = true
        field('fields').disabled = true
        const preview = !state.preview
        try {
            const result = await api('POST', '/settings/tool-permissions', {
                kind: state.kind, subject: state.selected, revision: state.snapshot.revision, changes: { ...state.draft },
                preview, ...(!preview && { previewId: state.preview.previewId || state.preview.id }),
            }, { uncertainWrite: !preview })
            if (preview) {
                state.preview = result
                render()
            } else {
                await loadDetail()
                if (state.snapshot) status.textContent = t('mcp', 'Permissions saved.')
            }
        } catch (failure) {
            if (scope.disposed) return
            if (failure.message === '409') {
                await loadDetail()
                status.textContent = t('mcp', 'Permissions changed elsewhere. Reloading the latest values.')
            } else if (!preview && (failure.data?.uncertain || !/^4\d\d$/.test(failure.message))) {
                await loadDetail()
                status.textContent = t('mcp', 'The save could not be confirmed. Check the current permissions before trying again.')
            } else {
                field('save-status').textContent = t('mcp', 'Could not save permissions. Your changes are still here.')
            }
        } finally {
            if (!scope.disposed) field('fields').disabled = false
            state.loading = false
            if (!scope.disposed && state.preview) save.focus()
            else if (!scope.disposed && !Object.keys(state.draft).length && !editor.hidden) field('selected').focus()
        }
    }

    const directory = mountPermissionDirectory({ host, api, scope, t, choose })
    scope.on(root, 'mcp-view-tool-permissions', event => {
        if (!event.detail?.uid) return
        root.querySelector('#mcp-tab-access')?.click()
        choose('user', event.detail.uid, event.detail.uid)
    })
    scope.on(filter, 'input', renderTools)
    scope.on(save, 'click', submit)
    scope.on(field('discard'), 'click', () => { state.draft = {}; state.preview = null; editing.clear(); render(); field('selected').focus() })
    scope.on(field('global-service'), 'click', () => {
        root.querySelector('#mcp-tab-overview')?.click()
        root.querySelector('#mcp-service')?.focus()
    })
    scope.on(field('open-scope'), 'click', event => getResourceScope?.()?.open(state.selected, 'files', event.currentTarget))
    scope.on(field('open-inspector'), 'click', event => getInspector?.()?.open(state.selected, event.currentTarget))
    return { ready: directory.ready, destroy() { scope.destroy() } }
}
