// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** Browse groups, or search both directories without conflating matching user and group IDs. */
export function mountPermissionDirectory({ host, scope, api, t, choose }) {
    const search = host.querySelector('#mcp-tools-subject-search')
    const panel = host.querySelector('#mcp-tools-directory')
    const results = host.querySelector('#mcp-tools-subject')
    const pager = host.querySelector('#mcp-tools-pager')
    const previous = host.querySelector('#mcp-tools-subject-prev')
    const next = host.querySelector('#mcp-tools-subject-next')
    const pageInfo = host.querySelector('#mcp-tools-subject-page')
    let page = 1, seq = 0, timer

    async function load() {
        const current = ++seq
        panel.hidden = false
        pager.hidden = true
        results.textContent = t('mcp', 'Loading…')
        const query = search.value.trim()
        const kinds = query ? ['group', 'user'] : ['group']
        try {
            const directories = await Promise.all(kinds.map(kind => api('GET', '/settings/tool-permissions/subjects?' +
                new URLSearchParams({ kind, search: query, page: String(page) }))))
            if (current !== seq) return
            results.replaceChildren()
            directories.forEach((data, index) => {
                if (!data.subjects.length) return
                const kind = kinds[index]
                const title = document.createElement('h3')
                title.textContent = kind === 'group' ? t('mcp', 'Groups') : t('mcp', 'People')
                const section = document.createElement('div')
                section.setAttribute('role', 'group')
                section.setAttribute('aria-label', title.textContent)
                section.append(title)
                results.append(section)
                for (const row of data.subjects) {
                    const button = document.createElement('button')
                    button.type = 'button'
                    button.textContent = row.displayName && row.displayName !== row.id ? row.displayName + ' · ' + row.id : row.id
                    button.addEventListener('click', () => choose(kind, row.id, row.displayName || row.id))
                    section.append(button)
                }
            })
            if (!results.children.length) results.textContent = t('mcp', 'No matching entries.')
            previous.disabled = page === 1
            next.disabled = !directories.some(data => data.hasMore)
            pager.hidden = previous.disabled && next.disabled
            pageInfo.textContent = t('mcp', 'Page {page}', { page })
        } catch {
            if (current !== seq || scope.disposed) return
            const retry = document.createElement('button')
            retry.type = 'button'
            retry.textContent = t('mcp', 'Retry')
            retry.addEventListener('click', load)
            results.replaceChildren(t('mcp', 'Could not load people or groups. Try again.'), retry)
        }
    }

    scope.on(search, 'input', () => {
        ++seq
        page = 1
        panel.hidden = false
        pager.hidden = true
        results.textContent = t('mcp', 'Loading…')
        scope.clearTimeout(timer)
        timer = scope.timeout(load, 200)
    })
    scope.on(search, 'focus', () => { if (panel.hidden) load() })
    scope.on(previous, 'click', () => { page--; load() })
    scope.on(next, 'click', () => { page++; load() })
    return { ready: load(), open() {
        search.value = ''
        page = 1
        scope.clearTimeout(timer)
        load()
        search.focus()
    }, close() { ++seq; page = 1; scope.clearTimeout(timer); panel.hidden = true } }
}
