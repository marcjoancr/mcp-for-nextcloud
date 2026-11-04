/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getSidebar } from '@nextcloud/files'

const componentName = 'mcp-files-description-tab'
const tabId = 'mcp-image-description'

function t(app, text) {
    return window.t(app, text)
}

function filePath(node) {
    const path = typeof node?.path === 'string' ? node.path : ''
    const root = typeof node?.root === 'string' ? node.root : ''
    if (!path) return ''
    if (root && path.startsWith(root)) return path.slice(root.length) || '/'
    return path
}

function endpoint() {
    return window.OC.generateUrl('/apps/mcp/api/files/description')
}

function requestHeaders(json = false) {
    const headers = { Accept: 'application/json', requesttoken: window.OC.requestToken }
    if (json) headers['Content-Type'] = 'application/json'
    return headers
}

class ImageDescriptionTab extends HTMLElement {
    requestId = 0
    canEdit = false

    set node(value) {
        this._node = value
        this.invalidate()
        this.load()
    }

    get node() { return this._node }

    set active(value) {
        this._active = Boolean(value)
        if (this._active) this.load()
        else this.invalidate()
    }

    get active() { return this._active }

    connectedCallback() {
        this.render()
        if (this._node && this._active !== false) this.load()
    }

    invalidate() {
        this.requestId++
        this.path = filePath(this._node)
        this.state = null
        this.canEdit = false
        if (this.textarea) {
            this.textarea.value = ''
            this.textarea.disabled = true
        }
        if (this.saveButton) this.saveButton.disabled = true
    }

    render() {
        this.replaceChildren()
        this.className = 'mcp-image-description'
        this.status = document.createElement('p')
        this.status.setAttribute('role', 'status')
        this.textarea = document.createElement('textarea')
        this.textarea.rows = 5
        this.textarea.maxLength = 16000
        this.textarea.disabled = true
        this.textarea.setAttribute('aria-label', t('mcp', 'Image description'))
        this.saveButton = document.createElement('button')
        this.saveButton.type = 'button'
        this.saveButton.textContent = t('mcp', 'Save description')
        this.saveButton.disabled = true
        this.saveButton.addEventListener('click', () => this.save())
        this.replaceChildren(this.status, this.textarea, this.saveButton)
    }

    async load() {
        if (!this.isConnected || !this._node) return
        const path = filePath(this._node)
        if (!path) return this.showError(t('mcp', 'This image is unavailable.'))
        const requestId = ++this.requestId
        this.path = path
        this.state = null
        this.canEdit = false
        this.textarea.value = ''
        this.textarea.disabled = true
        this.saveButton.disabled = true
        this.status.textContent = t('mcp', 'Loading description…')
        try {
            const url = new URL(endpoint(), window.location.origin)
            url.searchParams.set('path', path)
            const response = await window.fetch(url, { method: 'GET', headers: requestHeaders(), credentials: 'same-origin' })
            const data = await response.json()
            if (requestId !== this.requestId) return
            if (!response.ok) throw new Error(data.error || t('mcp', 'This image is unavailable.'))
            this.state = data.state
            this.canEdit = data.can_edit === true
            this.textarea.value = data.description
            this.textarea.disabled = !this.canEdit
            const sharedWarning = data.access && data.access.scope !== 'personal'
                ? t('mcp', 'This file may be shared with others. Saving can change what they see. ') : ''
            const sourceStatus = data.source === 'embedded_exif'
                ? t('mcp', 'Showing the embedded EXIF description. Saving will store it in Nextcloud metadata.')
                : data.source === 'photos_metadata'
                    ? t('mcp', 'Showing the description read from Nextcloud Photos metadata.')
                    : data.source === 'files_metadata' ? t('mcp', 'Saved in Nextcloud Files metadata.') : t('mcp', 'No description is saved.')
            const editStatus = this.canEdit ? '' : ` ${t('mcp', 'You have read-only access to this description.')}`
            this.status.textContent = sharedWarning + sourceStatus + editStatus
        } catch (error) {
            if (requestId === this.requestId) this.showError(error.message || t('mcp', 'This image is unavailable.'))
        } finally {
            if (requestId === this.requestId) this.saveButton.disabled = !this.canEdit
        }
    }

    async save() {
        if (!this.path || typeof this.state !== 'string' || !this.canEdit) return
        const requestId = this.requestId
        const path = this.path
        const state = this.state
        const description = this.textarea.value
        this.saveButton.disabled = true
        this.status.textContent = t('mcp', 'Saving description…')
        try {
            const response = await window.fetch(endpoint(), {
                method: 'PUT',
                headers: requestHeaders(true),
                credentials: 'same-origin',
                body: JSON.stringify({ path, description, state }),
            })
            const data = await response.json()
            if (requestId !== this.requestId) return
            if (!response.ok) throw new Error(data.error || t('mcp', 'The description could not be saved.'))
            this.state = data.state
            this.textarea.value = data.description
            this.status.textContent = t('mcp', 'Description saved.')
        } catch (error) {
            if (requestId === this.requestId) this.showError(error.message || t('mcp', 'The description could not be saved.'))
        } finally {
            if (requestId === this.requestId) this.saveButton.disabled = !this.canEdit
        }
    }

    showError(message) {
        this.status.textContent = message
    }
}

export function registerFileDescriptionSidebar() {
    getSidebar().registerTab({
        id: tabId,
        displayName: t('mcp', 'Description'),
        iconSvgInline: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M4 4h16v2H4zm0 5h16v2H4zm0 5h11v2H4zm0 5h8v2H4z"/></svg>',
        order: 80,
        tagName: componentName,
        enabled: ({ node }) => node?.type === 'file' && String(node.mime || '').startsWith('image/'),
        async onInit() {
            if (!customElements.get(componentName)) customElements.define(componentName, ImageDescriptionTab)
        },
    })
}
