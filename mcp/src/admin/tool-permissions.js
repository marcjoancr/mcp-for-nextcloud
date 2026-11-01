// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../platform/lifecycle.js'

/** Admin editor for inherited service and per-tool permissions. */
export function mountToolPermissions({ root, request, t, notifyError, getResourceScope, getInspector }) {
    const host = root.querySelector('#mcp-tool-permissions')
    if (!host) return { ready: Promise.resolve(), destroy() {} }
    const scope = createScope(host)
    const api = scope.request(request)
    const kindButtons = ['user', 'group'].map(kind => host.querySelector('#mcp-tools-kind-' + kind))
    const search = host.querySelector('#mcp-tools-subject-search')
    const subject = host.querySelector('#mcp-tools-subject')
    const previous = host.querySelector('#mcp-tools-subject-prev')
    const next = host.querySelector('#mcp-tools-subject-next')
    const pageInfo = host.querySelector('#mcp-tools-subject-page')
    const editor = host.querySelector('#mcp-tools-editor')
    const status = host.querySelector('#mcp-tools-editor-status')
    const error = host.querySelector('#mcp-tools-editor-error')
    const filter = host.querySelector('#mcp-tools-filter')
    const onlyAssigned = host.querySelector('#mcp-tools-only-exceptions')
    const onlyBlocked = host.querySelector('#mcp-tools-only-blocked')
    const catalogs = host.querySelector('#mcp-tools-catalogs')
    const service = host.querySelector('#mcp-tools-service-state')
    const membershipList = host.querySelector('#mcp-tools-membership-list')
    const serviceOrigin = host.querySelector('#mcp-tools-service-origin')
    const serviceResult = host.querySelector('#mcp-tools-service-result')
    const personActions = host.querySelector('#mcp-tools-person-actions')
    const globalService = host.querySelector('#mcp-tools-global-service')
    const save = host.querySelector('#mcp-tools-save')
    const discard = host.querySelector('#mcp-tools-discard')
    const saveStatus = host.querySelector('#mcp-tools-save-status')
    const state = { kind: 'user', rows: [], selected: '', page: 1, hasMore: false, snapshot: null, draft: {}, preview: null, loading: false, directorySeq: 0, detailSeq: 0 }
    const plainText = { escape: false }

    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag)
        for (const [key, value] of Object.entries(attrs)) {
            if (key.startsWith('aria-') || key.startsWith('data-')) node.setAttribute(key, String(value))
            else node[key] = value
        }
        node.append(...children)
        return node
    }

    function setMessage(target, message = '') { target.textContent = message }

    function catalogLabel(module) {
        const labels = { files: 'Files', notes: 'Notes', deck: 'Deck', calendar: 'Calendar', contacts: 'Contacts', tasks: 'Tasks', people: 'People', talk: 'Talk', logs: 'Server log', mail: 'Mail' }
        return t('mcp', labels[module] || module)
    }

    function originLabel(row) {
        if (row.effective === null) return t('mcp', 'Could not be checked')
        if (row.groupIds?.length) {
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

    async function loadDirectory() {
        const seq = ++state.directorySeq
        try {
            const path = '/settings/tool-permissions/subjects?' + new URLSearchParams({ kind: state.kind, search: search.value.trim(), page: String(state.page) })
            const data = await api('GET', path)
            if (seq !== state.directorySeq) return
            state.rows = data.subjects.map(item => ({ id: item.id, name: item.displayName || item.id }))
            state.hasMore = data.hasMore
            state.page = data.page
            previous.disabled = state.page <= 1
            next.disabled = !state.hasMore
            pageInfo.textContent = t('mcp', 'Page {page}', { page: state.page })
            const previousSubject = state.requestedSubject || state.selected
            state.requestedSubject = ''
            subject.replaceChildren(...state.rows.map(row => el('option', { value: row.id, textContent: row.name + (row.name === row.id ? '' : ' · ' + row.id) })))
            state.selected = state.rows.some(row => row.id === previousSubject) ? previousSubject : (state.rows[0]?.id || '')
            subject.value = state.selected
            await loadDetail()
        } catch (failure) {
            if (scope.disposed || seq !== state.directorySeq) return
            state.rows = []
            subject.replaceChildren(el('option', { value: '', textContent: t('mcp', 'Could not load entries') }))
            editor.hidden = true
            setMessage(error, t('mcp', 'Could not load people or groups. Try again.'))
            error.hidden = false
        }
    }

    async function loadDetail() {
        const seq = ++state.detailSeq
        if (!state.selected) { editor.hidden = true; return }
        const selected = state.selected
        editor.hidden = true
        error.hidden = true
        setMessage(status, t('mcp', 'Loading permissions…'))
        try {
            const query = new URLSearchParams({ kind: state.kind, subject: selected })
            state.snapshot = await api('GET', '/settings/tool-permissions?' + query)
            if (seq !== state.detailSeq || selected !== state.selected) return
            state.draft = {}
            state.preview = null
            membershipList.textContent = state.kind === 'user'
                ? (state.snapshot.groups?.map(group => group.displayName || group.id).join(', ') || t('mcp', 'No groups'))
                : t('mcp', 'Group permissions apply to current and future members unless a person has an individual assignment.')
            personActions.hidden = state.kind !== 'user'
            service.value = state.snapshot.service.state
            serviceOrigin.textContent = state.snapshot.service.origin || ''
            renderTools()
            renderResult()
            syncButtons()
            editor.hidden = false
            setMessage(status, '')
        } catch (failure) {
            if (seq !== state.detailSeq) return
            if (scope.disposed) return
            editor.hidden = true
            setMessage(error, failure.message === '409'
                ? t('mcp', 'Permissions changed elsewhere. Reload this entry.')
                : t('mcp', 'Could not load this entry’s permissions. Try again.'))
            error.hidden = false
            setMessage(status, '')
        }
    }

    function assignment(key, current) { return Object.hasOwn(state.draft, key) ? state.draft[key] : current }

    function renderTools() {
        const grouped = new Map()
        for (const tool of state.snapshot.tools) {
            if (onlyAssigned.checked && assignment(tool.name, tool.state) === 'inherit') continue
            const projected = state.preview?.impact?.tools?.[tool.name]
            const effective = projected ? projected.effective : tool.effective
            if (onlyBlocked.checked && effective !== false) continue
            if (filter.value && !(tool.title + ' ' + tool.description + ' ' + tool.module).toLocaleLowerCase().includes(filter.value.toLocaleLowerCase())) continue
            if (!grouped.has(tool.module)) grouped.set(tool.module, [])
            grouped.get(tool.module).push(tool)
        }
        const panels = []
        for (const [module, tools] of grouped) {
            const list = el('div', { className: 'mcp-tool-list' })
            for (const tool of tools) {
                const projected = state.preview?.impact?.tools?.[tool.name]
                const resultRow = projected ? { ...tool, ...projected, groupIds: projected.groupIds || [] } : tool
                const key = 'mcp-tool-state-' + tool.name.replace(/[^a-zA-Z0-9_-]/g, '-')
                const select = el('select', { id: key, className: 'mcp-tool-assignment', 'aria-label': t('mcp', 'Assignment for {tool}', { tool: tool.title }) })
                for (const [value, label] of [['inherit', 'Not assigned'], ['allow', 'Allow'], ['deny', 'Block']]) {
                    select.append(el('option', { value, textContent: t('mcp', label) }))
                }
                select.value = assignment(tool.name, tool.state)
                select.addEventListener('change', () => {
                    state.draft[tool.name] = select.value
                    state.preview = null
                    renderResult()
                    syncButtons()
                })
                const heading = el('strong', { textContent: tool.title })
                const description = el('span', { className: 'mcp-hint', textContent: tool.description })
                const result = el('span', { className: 'mcp-tool-result', textContent: resultRow.effective === null ? t('mcp', 'Could not be checked') : (resultRow.effective ? t('mcp', 'Allowed') : t('mcp', 'Blocked')) })
                const origin = el('span', { className: 'mcp-hint', textContent: originLabel(resultRow) })
                if (projected) origin.textContent = t('mcp', 'After saving: {origin}', { origin: originLabel(resultRow) }, undefined, plainText)
                const copy = el('div', { className: 'mcp-tool-copy' }, [heading, description, origin])
                const required = Array.isArray(tool.requiresOperations) ? tool.requiresOperations : Object.keys(tool.requiresOperations || {})
                if (required.length) {
                    const hint = el('span', { className: 'mcp-hint', textContent: t('mcp', 'Some sharing options also depend on operation permissions.') })
                    const advanced = el('button', { type: 'button', textContent: t('mcp', 'Review advanced operation permissions') })
                    advanced.addEventListener('click', () => {
                        const disclosure = root.querySelector('#mcp-tools-advanced')
                        if (disclosure) disclosure.open = true
                        disclosure?.querySelector('summary')?.focus()
                        disclosure?.dispatchEvent(new Event('toggle'))
                    })
                    copy.append(hint, advanced)
                }
                if (state.kind === 'user' && !projected) {
                    const provenance = el('details', { className: 'mcp-tool-origin' })
                    const summary = el('summary', { textContent: t('mcp', 'View decisions from each group') })
                    const details = el('div', { role: 'status', className: 'mcp-hint' })
                    let loaded = false
                    provenance.append(summary, details)
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
                    copy.append(provenance)
                }
                list.append(el('article', { className: 'mcp-tool-row' }, [copy, select, result]))
            }
            const details = el('details', { className: 'mcp-tool-catalog', open: module === 'files' })
            details.append(el('summary', { textContent: t('mcp', '{catalog} · {count} tools', { catalog: catalogLabel(module), count: tools.length }) }), list)
            panels.push(details)
        }
        catalogs.replaceChildren(...panels)
        if (panels.length === 0) catalogs.append(el('p', { className: 'mcp-empty', textContent: t('mcp', 'No tools match this search.') }))
    }

    function renderResult() {
        const serviceValue = assignment('_service', state.snapshot.service.state)
        const effective = serviceValue === 'inherit' ? state.snapshot.service.effective : serviceValue === 'allow'
        serviceResult.textContent = effective === null ? t('mcp', 'Could not be checked') : (effective ? t('mcp', 'Service active') : t('mcp', 'Service blocked'))
        serviceOrigin.textContent = state.snapshot.service.origin || ''
        service.value = serviceValue
        if (Object.keys(state.draft).length) {
            setMessage(saveStatus, state.preview?.impact?.summary || state.preview?.summary || t('mcp', 'Review the changes before saving.'))
        } else setMessage(saveStatus, '')
    }

    function syncButtons() {
        const dirty = Object.keys(state.draft).length > 0
        save.disabled = !dirty || state.loading
        discard.disabled = !dirty || state.loading
        save.textContent = state.preview ? t('mcp', 'Save permissions') : t('mcp', 'Review changes')
    }

    async function submit() {
        if (state.loading || !Object.keys(state.draft).length) return
        state.loading = true
        syncButtons()
        const changes = { ...state.draft }
        try {
            const result = await api('POST', '/settings/tool-permissions', {
                kind: state.kind, subject: state.selected, revision: state.snapshot.revision,
                changes, preview: !state.preview, ...(state.preview ? { previewId: state.preview.previewId || state.preview.id } : {}),
            }, { uncertainWrite: !!state.preview })
            if (!state.preview) {
                state.preview = result
                renderTools()
                const impact = result.impact || {}
                setMessage(saveStatus, impact.summary || t('mcp', 'Review the impact, then save permissions.'))
            } else {
                await loadDetail()
                setMessage(saveStatus, t('mcp', 'Permissions saved.'))
            }
        } catch (failure) {
            if (!scope.disposed) {
                setMessage(saveStatus, failure.message === '409'
                    ? t('mcp', 'Permissions changed elsewhere. Reloading the latest values.')
                    : t('mcp', 'Could not save permissions. Your changes are still here.'))
                if (failure.message === '409') await loadDetail()
            }
        } finally {
            state.loading = false
            syncButtons()
        }
    }

    function setKind(kind) {
        state.kind = kind
        state.page = 1
        kindButtons.forEach(button => button.setAttribute('aria-pressed', String(button.id.endsWith(kind))))
        state.selected = ''
        state.draft = {}
        state.snapshot = null
        loadDirectory()
    }

    scope.on(root, 'mcp-view-tool-permissions', event => {
        const uid = event.detail?.uid
        if (!uid) return
        root.querySelector('#mcp-tab-access')?.click()
        state.requestedSubject = uid
        state.kind = 'user'
        state.page = 1
        state.selected = ''
        state.draft = {}
        search.value = uid
        kindButtons.forEach(button => button.setAttribute('aria-pressed', String(button.id.endsWith('user'))))
        loadDirectory()
    })

    kindButtons.forEach(button => button.addEventListener('click', () => setKind(button.id.endsWith('group') ? 'group' : 'user')))
    search.addEventListener('input', () => {
        clearTimeout(state.searchTimer)
        state.searchTimer = setTimeout(() => { state.page = 1; loadDirectory() }, 250)
    })
    previous.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); loadDirectory() })
    next.addEventListener('click', () => { if (state.hasMore) { state.page += 1; loadDirectory() } })
    subject.addEventListener('change', () => { state.selected = subject.value; loadDetail() })
    globalService.addEventListener('click', () => {
        root.querySelector('#mcp-tab-overview')?.click()
        root.querySelector('#mcp-service')?.focus()
    })
    host.querySelector('#mcp-tools-open-scope').addEventListener('click', event => getResourceScope?.()?.open(state.selected, 'files', event.currentTarget))
    host.querySelector('#mcp-tools-open-inspector').addEventListener('click', event => getInspector?.()?.open(state.selected, event.currentTarget))
    filter.addEventListener('input', renderTools)
    onlyAssigned.addEventListener('change', renderTools)
    onlyBlocked.addEventListener('change', renderTools)
    service.addEventListener('change', () => { state.draft._service = service.value; state.preview = null; renderResult(); syncButtons() })
    save.addEventListener('click', submit)
    discard.addEventListener('click', () => { state.draft = {}; state.preview = null; renderTools(); renderResult(); syncButtons() })
    const ready = loadDirectory()
    return { ready, destroy() { clearTimeout(state.searchTimer); scope.destroy() } }
}
