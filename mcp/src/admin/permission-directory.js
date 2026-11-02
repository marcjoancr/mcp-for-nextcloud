// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** A searchable, paged directory shared by the Add button and column headers. */
export function mountPermissionDirectory({ host, scope, api, t, choose }) {
    const popup = host.querySelector('#mcp-tools-picker')
    const search = host.querySelector('#mcp-tools-subject-search')
    const results = host.querySelector('#mcp-tools-subject')
    let trigger = null, subject = null, sequence = 0, debounce
    const list = (kind, query, page) => api('GET', '/settings/tool-permissions/subjects?' +
        new URLSearchParams({ kind, search: query, page: String(page) }))

    // The popover lives in the top layer, outside the table's scrolling and clipping.
    function position() {
        if (!trigger) return
        const anchor = trigger.getBoundingClientRect()
        const viewport = window.visualViewport
        const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0
        const width = viewport?.width || document.documentElement.clientWidth
        const height = viewport?.height || document.documentElement.clientHeight
        if (anchor.bottom < top || anchor.top > top + height || anchor.right < left || anchor.left > left + width) {
            close()
            return
        }
        const below = top + height - anchor.bottom - 12
        const above = anchor.top - top - 12
        const useAbove = below < 375 && above > below
        popup.style.maxHeight = Math.max(0, useAbove ? above : below) + 'px'
        const x = getComputedStyle(trigger).direction === 'rtl' ? anchor.right - popup.offsetWidth : anchor.left
        popup.style.left = Math.max(left + 8, Math.min(x, left + width - popup.offsetWidth - 8)) + 'px'
        popup.style.top = (useAbove ? anchor.top - 4 : anchor.bottom + 4) + 'px'
        popup.style.transform = useAbove ? 'translateY(-100%)' : ''
    }
    function close(restore = false) {
        const owner = trigger
        if (popup.matches(':popover-open')) popup.hidePopover()
        if (restore && owner?.isConnected) owner.focus({ preventScroll: true })
    }
    scope.on(popup, 'beforetoggle', event => {
        if (event.newState !== 'closed') return
        ++sequence
        scope.clearTimeout(debounce)
        trigger?.setAttribute('aria-expanded', 'false')
        trigger = subject = null
    })
    function load() {
        const current = ++sequence
        results.replaceChildren()
        results.scrollTop = 0
        const query = search.value.trim()
        for (const kind of ['group', 'user']) {
            const section = document.createElement('div')
            const title = document.createElement('h3')
            title.textContent = kind === 'group' ? t('mcp', 'Groups') : t('mcp', 'People')
            section.setAttribute('role', 'group')
            section.setAttribute('aria-label', title.textContent)
            const options = document.createElement('div')
            const status = document.createElement('span')
            status.setAttribute('role', 'status')
            const more = document.createElement('button')
            more.type = 'button'
            more.hidden = true
            section.append(title, options, status, more)
            results.append(section)
            let page = 0, remaining = [], hasMore = true
            async function append(focusNew) {
                more.disabled = true
                try {
                    if (!remaining.length && hasMore) {
                        status.textContent = t('mcp', 'Loading…')
                        const data = await list(kind, query, page + 1)
                        if (sequence !== current) return
                        page++
                        remaining = data.subjects.slice()
                        hasMore = data.hasMore
                    }
                    status.textContent = ''
                    let first
                    for (const row of remaining.splice(0, focusNew ? 10 : 4)) {
                        const option = document.createElement('button')
                        option.type = 'button'
                        option.dataset.entry = ''
                        option.textContent = row.displayName && row.displayName !== row.id ? row.displayName + ' · ' + row.id : row.id
                        if (kind === subject?.kind && row.id === subject.id) option.setAttribute('aria-current', 'true')
                        option.addEventListener('click', () => choose(kind, row.id, row.displayName || row.id))
                        options.append(option)
                        first ??= option
                    }
                    if (!options.children.length) status.textContent = t('mcp', 'No matching entries.')
                    more.textContent = t('mcp', 'Show more')
                    more.hidden = !remaining.length && !hasMore
                    if (focusNew) first?.focus()
                } catch {
                    if (sequence !== current || scope.disposed) return
                    status.textContent = t('mcp', 'Could not load people or groups. Try again.')
                    more.textContent = t('mcp', 'Retry')
                    more.hidden = false
                } finally { more.disabled = false }
            }
            more.addEventListener('click', () => append(true))
            append(false)
        }
    }
    function open(owner, currentSubject) {
        if (owner === trigger) { close(true); return }
        close()
        trigger = owner
        subject = currentSubject
        trigger.setAttribute('aria-expanded', 'true')
        search.value = ''
        popup.showPopover()
        position()
        load()
        const focusTarget = window.matchMedia('(pointer: coarse)').matches ? popup : search
        focusTarget.focus({ preventScroll: true })
    }
    scope.on(search, 'input', () => {
        ++sequence
        results.textContent = t('mcp', 'Loading…')
        scope.clearTimeout(debounce)
        debounce = scope.timeout(load, 160)
    })
    scope.on(popup, 'keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(true); return }
        const options = [...results.querySelectorAll('button:not([hidden]):not(:disabled)')]
        if (!options.length) return
        const index = options.indexOf(document.activeElement)
        if (event.key === 'Enter' && event.target === search) {
            event.preventDefault()
            options[0].click()
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()
            const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1)
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length
            options[next].focus()
        }
    })
    scope.on(host.querySelector('#mcp-tools-cancel-picker'), 'click', () => close(true))
    function dismissOutside(event) {
        if (trigger && !trigger.contains(event.target) && !popup.contains(event.target)) close()
    }
    scope.on(document, 'pointerdown', dismissOutside)
    scope.on(document, 'focusin', dismissOutside)
    scope.on(document, 'scroll', position, true)
    scope.on(window, 'resize', position)
    if (window.visualViewport) {
        scope.on(window.visualViewport, 'resize', position)
        scope.on(window.visualViewport, 'scroll', position)
    }
    scope.use(close)
    return { open, close, list }
}
