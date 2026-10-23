/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'
import { extensions, validPolicy } from './file-policy-state.js'
import { policyView } from './file-policy-view.js'

export function mountFileWritePolicy({ root, request, t, overviewAlert, syncProtectionAlert = () => {} }) {
    const scope = createScope(root)
    if (!root) return { ready: Promise.resolve(), destroy: scope.destroy }
    const api = scope.request(request), view = policyView(root, t)
    const state = { busy: false, uncertain: false }
    let generation = 0, edits = 0
    const render = () => view.render(state)
    const unsaved = () => t('mcp', 'The saved policy was verified. Your unsaved input is preserved; review it before saving.')
    function issue(value, message) {
        view.issue(value, message)
        if (overviewAlert) overviewAlert.hidden = !value
        syncProtectionAlert()
    }
    async function exchange(method, body) {
        const policy = await api(method, '/api/file-write-policy', body, { uncertainWrite: method === 'PUT' })
        if (!validPolicy(policy)) throw new Error('Invalid policy response')
        return policy
    }
    async function load() {
        if (state.busy) return
        const current = ++generation, before = edits
        state.busy = true; render()
        try {
            const policy = await exchange('GET')
            if (scope.disposed || current !== generation) return
            // Reload reads saved state without erasing an unsaved or uncertain draft.
            if (before === 0 && edits === 0) view.fill(policy)
            state.uncertain = false
            issue(false)
            view.status(edits === 0 ? '' : unsaved())
        } catch {
            if (scope.disposed || current !== generation) return
            issue(true, t('mcp', 'The file type policy could not be loaded. Applicable MCP writes remain refused if the saved configuration is invalid. Choose a complete policy and save it to recover; no restriction is a deliberate choice.'))
            view.status('')
        } finally {
            if (!scope.disposed && current === generation) { state.busy = false; render() }
        }
    }
    scope.on(view.form, 'submit', async event => {
        event.preventDefault()
        if (state.busy || state.uncertain) return
        view.error(null)
        const draft = view.draft()
        if (!['off', 'allowlist'].includes(draft.mode)) { view.error('mode'); return }
        let parsed
        try { parsed = draft.mode === 'allowlist' ? extensions(draft.extensions) : [] } catch { view.error('extensions'); return }
        const current = ++generation, before = edits
        state.busy = true; render()
        view.status(t('mcp', 'Saving file type policy…'))
        try {
            const policy = await exchange('PUT', { mode: draft.mode, extensions: parsed, allowExtensionless: draft.mode === 'allowlist' && draft.allowExtensionless })
            if (scope.disposed || current !== generation) return
            if (edits === before) { view.fill(policy); edits = 0 }
            issue(false)
            view.status(edits === 0 ? t('mcp', 'File type policy saved.') : unsaved())
        } catch (error) {
            if (scope.disposed || current !== generation) return
            if (error.message === '400' && Object.hasOwn(view.fields, error.data?.field)) {
                view.error(error.data.field)
                view.status('')
            } else {
                state.uncertain = true
                const message = t('mcp', 'The policy could not be saved and verified. Your input is preserved. Reload before relying on the saved state.')
                issue(true, message); view.status(message)
            }
        } finally {
            if (!scope.disposed && current === generation) {
                state.busy = false; render()
                Object.values(view.fields).find(control => control.getAttribute('aria-invalid') === 'true')?.focus()
            }
        }
    })
    for (const control of Object.values(view.fields)) scope.on(control, 'input', () => { edits++; if (!state.uncertain) view.status(''); render() })
    scope.on(view.mode, 'change', () => { edits++; render() })
    scope.on(view.reload, 'click', load)
    render()
    const ready = load()
    return { ready, destroy() { generation++; scope.destroy() } }
}
