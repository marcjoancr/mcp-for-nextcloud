// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../../platform/lifecycle.js'
import { createPolicyState } from '../../platform/policy-state.js'
import { validSettings } from './state.js'
import { backupView } from './view.js'

export function mountBackupSettings({ root, request, t, policyChannel }) {
    const scope = createScope(root)
    if (!root) return { ready: Promise.resolve(), destroy: scope.destroy }
    const policy = createPolicyState(policyChannel), api = scope.request(request), view = backupView(root, t)
    scope.use(() => policy.destroy())
    const state = { busy: false, uncertain: false, settings: null }
    let edits = 0, generation = 0
    const render = () => view.render(state)
    const preserved = () => t('mcp', 'The saved policy was verified. Your unsaved input is preserved; review it before saving.')
    async function exchange(method, body) {
        const data = await api(method, '/api/backup-settings', body, { uncertainWrite: method === 'PUT' })
        if (!validSettings(data)) throw new Error('Invalid backup settings response')
        return data
    }
    async function load() {
        if (state.busy) return
        const current = ++generation, ticket = policy.read()
        state.busy = true; render()
        try {
            const data = await exchange('GET')
            if (scope.disposed || current !== generation) return
            if (!policy.accept(ticket, data.revision)) throw new Error('Superseded backup settings read')
            state.settings = data; state.uncertain = false
            if (edits === 0) view.fill(data)
            view.error(false); view.effective(data); view.status(edits === 0 ? '' : preserved())
        } catch {
            if (scope.disposed || current !== generation) return
            state.uncertain = true
            view.status(t('mcp', 'Backup settings could not be loaded. Reload before saving.'))
        } finally {
            if (!scope.disposed && current === generation) { state.busy = false; render() }
        }
    }
    scope.on(view.form, 'submit', async event => {
        event.preventDefault()
        if (state.busy || state.uncertain || !state.settings || policy.revision === null) return
        const current = ++generation, from = policy.revision
        const draft = view.draft()
        state.busy = true; view.error(false); render()
        try {
            const data = await exchange('PUT', { expectedRevision: from, ...draft })
            if (scope.disposed || current !== generation) return
            if (!policy.saved(from, data.revision)) throw new Error('Superseded backup settings save')
            state.settings = data; edits = 0
            view.fill(data); view.effective(data); view.status(t('mcp', 'Backup settings saved.'))
        } catch (error) {
            if (scope.disposed || current !== generation) return
            if (error.message === '400') {
                view.error(true)
                view.status(t('mcp', 'Invalid backup settings. Check the folder name and Versions availability.'))
            } else {
                state.uncertain = true
                view.status(t('mcp', 'The policy could not be saved and verified. Your input is preserved. Reload before relying on the saved state.'))
            }
        } finally {
            if (!scope.disposed && current === generation) {
                state.busy = false; render()
                if (view.folder.getAttribute('aria-invalid') === 'true') view.folder.focus()
            }
        }
    })
    for (const field of [view.folder, view.copies]) scope.on(field, 'input', () => { edits++; view.error(false); render() })
    scope.on(view.reload, 'click', load)
    render()
    return { ready: load(), destroy() { generation++; scope.destroy() } }
}
