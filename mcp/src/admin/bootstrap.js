// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createPolicyChannel } from '../platform/policy-state.js'
import { mountTabs } from './tabs.js'
import { createPicker } from './picker.js'
import { mountMatrix } from './access/controller.js'
import { mountService } from './overview/service.js'
import { mountCheckoutLimit } from './overview/checkout-limit.js'
import { mountEndpointCopy } from './overview/endpoint-copy.js'
import { mountOauthClients } from './clients/oauth-clients.js'
import { mountBackupSettings } from './backups/controller.js'
import { mountFileWritePolicy } from './protection/file-policy-controller.js'
import { mountExcludedTags } from './protection/excluded-tags.js'
import { mountLogAccess } from './logs/access-groups.js'
import { mountLogPrivacy } from './logs/privacy.js'
import { mountConnections } from '../settings/connections.js'

import { mountExceptions } from './access/exceptions.js'
import { mountOverrides } from './access/overrides.js'
import { mountRowActions } from './access/row-actions.js'
import { mountLevelPopover } from './access/level-popover.js'
import { mountGroups } from './groups/controller.js'
import { mountInspector } from './inspection/controller.js'
import { mountResourceScope } from './scope/controller.js'
import { mountToolPermissions } from './tool-permissions.js'

/** Compose independent settings; keep state and failure recovery in their owners. */
export function mountAdmin({ root, ...dependencies }) {
    const policyChannel = createPolicyChannel()
    const section = id => ({ ...dependencies, policyChannel, root: root.querySelector('#' + id) })
    const picker = spec => createPicker({ ...spec, t: dependencies.t })
    const tabs = mountTabs({ root })
    const events = new EventTarget()
    let matrix, overrides, exceptions, rowActions, levelPopover, resourceScope, inspector, groupEditor
    const access = { ...dependencies, root, events, policyChannel,
        getMatrix: () => matrix, getOverrides: () => overrides, getExceptions: () => exceptions,
        getRowActions: () => rowActions, getLevelPopover: () => levelPopover,
        getResourceScope: () => resourceScope, getInspector: () => inspector }
    resourceScope = mountResourceScope(access)
    inspector = mountInspector(access)
    const advanced = root.querySelector('#mcp-tools-advanced')
    let advancedOwners = []
    let advancedReady = Promise.resolve()
    function mountAdvanced() {
        if (matrix) return advancedReady
        matrix = mountMatrix({ ...access, root: root.querySelector('#mcp-block-matrix') })
        overrides = mountOverrides(access)
        exceptions = mountExceptions(access)
        rowActions = mountRowActions(access)
        levelPopover = mountLevelPopover(access)
        groupEditor = mountGroups(access)
        advancedOwners = [matrix, overrides, exceptions, rowActions, levelPopover, groupEditor]
        advancedReady = Promise.all(advancedOwners.map(owner => owner.ready))
        return advancedReady
    }
    let contextTicket = 0, operationOpener = null
    async function openOperations(column, module, operation) {
        const ticket = ++contextTicket
        operationOpener = root.ownerDocument.activeElement
        advanced.open = true
        const query = new URLSearchParams({ view: 'operations', app: module })
        window.location.hash = 'access?' + query
        await mountAdvanced()
        if (ticket !== contextTicket || !advanced.open) return
        const userBlock = root.querySelector('#mcp-block-matrix')
        const groupBlock = root.querySelector('#mcp-group-editor')
        userBlock.hidden = column.kind === 'group'
        groupBlock.hidden = column.kind !== 'group'
        if (column.kind === 'group') {
            await groupEditor.open(column.id, false, false, column.label, { module, operation })
            return
        }
        await matrix.showUser(column.id)
        if (ticket !== contextTicket || !advanced.open) return
        userBlock.scrollIntoView({ block: 'start' })
        userBlock.querySelector('h2')?.setAttribute('tabindex', '-1')
        userBlock.querySelector('h2')?.focus({ preventScroll: true })
    }
    const onAdvancedToggle = () => {
        if (advanced.open) mountAdvanced()
        else {
            ++contextTicket
            root.querySelector('#mcp-block-matrix').hidden = false
            root.querySelector('#mcp-group-editor').hidden = false
            if (operationOpener) {
                if (window.location.hash.startsWith('#access?')) window.location.hash = 'access'
                const target = operationOpener.isConnected ? operationOpener : root.querySelector('#mcp-tools-add')
                target.focus()
                operationOpener = null
            }
        }
    }
    const openAdvancedForDeepLink = () => {
        if (!advanced) return
        const [tab, query = ''] = window.location.hash.slice(1).split('?')
        const params = new URLSearchParams(query)
        if (tab !== 'access' || (params.get('view') !== 'operations' && !params.has('app'))) {
            if (operationOpener && ['access', 'mcp-tool-permissions'].includes(tab)) advanced.open = false
            return
        }
        advanced.open = true
        mountAdvanced()
    }
    advanced?.addEventListener('toggle', onAdvancedToggle)
    window.addEventListener('hashchange', openAdvancedForDeepLink)
    if (advanced?.open) mountAdvanced()
    openAdvancedForDeepLink()
    const syncProtectionAlert = () => {
        const marker = root.querySelector('#mcp-tab-protection-alert')
        marker.hidden = !['mcp-tags-invalid-overview', 'mcp-file-policy-overview'].some(id => {
            const alert = root.querySelector('#' + id)
            return alert && !alert.hidden
        })
    }
    const owners = [tabs, resourceScope, inspector,
        mountToolPermissions({ ...access, root, openOperations }),
        mountService(section('mcp-block-status')),
        mountCheckoutLimit(section('mcp-block-status')),
        mountEndpointCopy({ ...dependencies, root }),
        mountOauthClients(section('mcp-block-oauth')),
        mountBackupSettings(section('mcp-backup-settings')),
        mountFileWritePolicy({ ...section('mcp-file-write-policy'), syncProtectionAlert, overviewAlert: root.querySelector('#mcp-file-policy-overview') }),
        mountExcludedTags({ ...section('mcp-block-tags'), createPicker: picker, syncProtectionAlert,
            overviewAlert: root.querySelector('#mcp-tags-invalid-overview'),
            tabAlert: root.querySelector('#mcp-tab-protection-alert') }),
        mountLogAccess({ ...section('mcp-block-logs'), createPicker: picker, reloadMatrix: () => matrix?.reload() }),
        mountLogPrivacy(section('mcp-block-logs-privacy')),
        mountConnections({ ...section('mcp-connections'), mode: 'admin', onCounts(result) {
            if (Number.isInteger(result?.counts?.connections)) {
                root.querySelector('#mcp-count-connections').textContent = String(result.counts.connections)
            }
        } }),
    ]
    return { ready: Promise.all(owners.map(owner => owner.ready)), destroy() {
        ++contextTicket
        advanced?.removeEventListener('toggle', onAdvancedToggle)
        window.removeEventListener('hashchange', openAdvancedForDeepLink)
        for (const owner of [...owners, ...advancedOwners].reverse()) owner.destroy()
    } }
}
