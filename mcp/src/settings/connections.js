/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../platform/lifecycle.js'

export function mountConnections({ root, pageRoot = root, mode, request, t, notifyError: reportError, onCounts = () => {} }) {
	const scope = createScope(root)
	const api = scope.request((method, path, body, options) => request(method, path, body, options || { json: false }))
	scope.use(() => root.querySelector('tbody').replaceChildren())
	const admin = mode === 'admin'
	const base = admin ? '/api/connections' : '/api/my/connections'
	const state = { search: '', page: 1, data: null }
	const table = root.querySelector('#mcp-connections-table')
	const searchInput = root.querySelector('#mcp-connections-search')
	if (searchInput) searchInput.value = state.search
	const prevButton = root.querySelector('#mcp-connections-prev')
	const nextButton = root.querySelector('#mcp-connections-next')
	const pageInfo = root.querySelector('#mcp-connections-page-info')
	const pager = root.querySelector('.mcp-pager')
	const status = root.querySelector('#mcp-connections-status')
	const toolsDialog = pageRoot.querySelector('#mcp-tool-selection-dialog')
	const toolsList = pageRoot.querySelector('#mcp-tool-selection-list')
	const toolsDescription = pageRoot.querySelector('#mcp-tool-selection-description')
	const toolsStatus = pageRoot.querySelector('#mcp-tool-selection-status')
	const toolsSave = pageRoot.querySelector('#mcp-tool-selection-save')
	const toolsCancel = pageRoot.querySelector('#mcp-tool-selection-cancel')
	const basicToolsButton = pageRoot.querySelector('#mcp-basic-tools-open')
	let toolSelection = null
	let toolSelectionGeneration = 0
	const notifyError = message => reportError(message, status)
	// t() escapes variables as HTML by default; the confirm dialog and the status region take plain text, where
	// that would show "D&#039;Avila". Never pass this for a result that is inserted as HTML.
	const PLAIN_TEXT = { escape: false }

	function el(tag, props, children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props || {})) node[key] = typeof value === 'function' ? scope.guard(value) : value
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	function when(seconds) {
		const locale = (document.documentElement.dataset.locale || '').replace('_', '-') || undefined
		try {
			return new Date(seconds * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
		} catch (e) {
			return new Date(seconds * 1000).toLocaleString()
		}
	}

	function clientName(client) {
		if (client.kind === 'native') return t('mcp', 'Local program (native client)')
        if (client.kind === 'custom') return client.name || t('mcp', 'Self-hosted client')
        return client.host
	}

	function clientCell(client) {
		return el('td', { title: client.id, textContent: clientName(client) })
	}

	function catalogLabel(module) {
		const labels = { files: 'Files', notes: 'Notes', deck: 'Deck', calendar: 'Calendar', contacts: 'Contacts', tasks: 'Tasks', people: 'People', talk: 'Talk', logs: 'Server log', mail: 'Mail' }
		return t('mcp', labels[module] || module)
	}

	function renderToolSelection(data) {
		const selected = new Set(data.tools)
		const variants = new Map(Object.entries(data.variants || {}).map(([name, values]) => [name, new Set(values)]))
		const groups = new Map()
		for (const tool of data.allowedTools) {
			if (!groups.has(tool.module)) groups.set(tool.module, [])
			groups.get(tool.module).push(tool)
		}
		const panels = []
		for (const [module, tools] of groups) {
			const list = el('div', { className: 'mcp-tool-selection-group' })
			for (const tool of tools) {
				const checkbox = el('input', { type: 'checkbox', checked: selected.has(tool.name), value: tool.name })
				checkbox.addEventListener('change', () => {
					if (checkbox.checked) selected.add(tool.name)
					else selected.delete(tool.name)
					toolSelection.tools = [...selected]
				})
				const row = el('div', { className: 'mcp-tool-selection-row' }, [el('label', {}, [checkbox,
					el('span', {}, [el('strong', { textContent: tool.title }), el('span', { className: 'mcp-hint', textContent: tool.description })])])])
				if (Array.isArray(tool.approvedVariants) && tool.approvedVariants.length) {
					const variantSet = variants.get(tool.name) || new Set()
					const variantList = el('fieldset', { className: 'mcp-tool-variants' })
					variantList.append(el('legend', { textContent: t('mcp', 'Allowed actions') }))
					for (const variant of tool.approvedVariants) {
						const label = variant === 'share' ? t('mcp', 'People and groups') : variant === 'link' ? t('mcp', 'Public links') : variant
						const variantCheckbox = el('input', { type: 'checkbox', value: variant, checked: variantSet.has(variant), disabled: !selected.has(tool.name) })
						variantCheckbox.addEventListener('change', () => {
							if (variantCheckbox.checked) variantSet.add(variant)
							else variantSet.delete(variant)
							variants.set(tool.name, variantSet)
							toolSelection.variants = Object.fromEntries([...variants].map(([name, values]) => [name, [...values]]))
						})
						variantList.append(el('label', {}, [variantCheckbox, document.createTextNode(label)]))
					}
					checkbox.addEventListener('change', () => {
						for (const variantCheckbox of variantList.querySelectorAll('input')) variantCheckbox.disabled = !checkbox.checked
					})
					row.append(variantList)
				}
				list.append(row)
			}
			const details = el('details', { open: module === 'files' })
			details.append(el('summary', { textContent: t('mcp', '{catalog} · {count} tools', { catalog: catalogLabel(module), count: tools.length }) }), list)
			panels.push(details)
		}
		if (data.blockedTools.length) {
			const blocked = el('details', {})
			blocked.append(el('summary', { textContent: t('mcp', 'Blocked by your administrator') }))
			blocked.append(el('ul', {}, data.blockedTools.map(tool => el('li', { textContent: tool.title + ' — ' + (tool.reason || t('mcp', 'Not available')) }))))
			panels.push(blocked)
		}
		toolsList.replaceChildren(...panels)
		const counter = t('mcp', '{selected} of {available} available tools selected', {
			selected: selected.size, available: data.allowedTools.length,
		})
		toolsStatus.textContent = selected.size ? counter : counter + ' ' + t('mcp', 'With no data tools selected, only built-in MCP guidance and status tools remain; they reveal no user resources.')
	}

	function normalizeToolSelection(data) {
		const rows = Array.isArray(data.tools) && data.tools.length && typeof data.tools[0] === 'object'
			? data.tools : (Array.isArray(data.allowedTools) ? data.allowedTools : data.tools)
		if (rows.length && typeof rows[0] === 'object') {
			const selected = Array.isArray(data.tools) && data.tools.every(tool => typeof tool === 'string')
				? new Set(data.tools) : null
			const tools = selected ? rows.map(tool => ({ ...tool, selected: selected.has(tool.name) })) : rows
			const variants = data.variants && !Array.isArray(data.variants) ? data.variants : Object.fromEntries(tools.filter(tool => Array.isArray(tool.variants)).map(tool => [tool.name, tool.variants]))
			return {
				revision: data.revision,
				tools: tools.filter(tool => tool.selected).map(tool => tool.name),
				variants,
				allowedTools: tools.filter(tool => tool.available !== false),
				blockedTools: tools.filter(tool => tool.available === false),
			}
		}
		return { ...data, variants: data.variants || {}, allowedTools: data.allowedTools || [], blockedTools: data.blockedTools || [] }
	}

	async function manageTools(connection, sharedProfile = false) {
		if (!toolsDialog) return
		const connectionId = sharedProfile ? '0' : String(connection.id)
		const generation = ++toolSelectionGeneration
		toolsDialog.dataset.connectionId = connectionId
		toolSelection = null
		toolsSave.disabled = true
		toolsDialog.showModal()
		toolsList.setAttribute('aria-busy', 'true')
		toolsDescription.textContent = sharedProfile
			? t('mcp', 'This selection is shared by app-password and other connections that do not identify a separate OAuth client.')
			: t('mcp', 'Choose the tools this client may use. Your administrator decides which tools are available.')
		toolsStatus.textContent = t('mcp', 'Loading tools…')
		try {
			const loaded = await api('GET', '/settings/my/connections/' + encodeURIComponent(connectionId) + '/tools')
			if (generation !== toolSelectionGeneration || toolsDialog.dataset.connectionId !== connectionId || !toolsDialog.open) return
			toolSelection = normalizeToolSelection(loaded)
			renderToolSelection(toolSelection)
			toolsSave.disabled = false
			toolsStatus.textContent = ''
		} catch (failure) {
			if (generation !== toolSelectionGeneration || !toolsDialog.open) return
			toolsStatus.textContent = t('mcp', 'Could not load this connection’s tools.')
		}
		finally {
			if (generation === toolSelectionGeneration) toolsList.setAttribute('aria-busy', 'false')
		}
	}

	async function saveTools() {
		if (!toolSelection || toolsSave.disabled) return
		const missingVariants = toolSelection.allowedTools.find(tool => toolSelection.tools.includes(tool.name)
			&& Array.isArray(tool.approvedVariants) && tool.approvedVariants.length
			&& !(toolSelection.variants?.[tool.name] || []).some(variant => tool.approvedVariants.includes(variant)))
		if (missingVariants) {
			toolsStatus.textContent = t('mcp', 'Choose at least one allowed action for {tool}.', { tool: missingVariants.title })
			return
		}
		const generation = toolSelectionGeneration
		const connectionId = toolsDialog.dataset.connectionId || '0'
		const submitted = { ...toolSelection, tools: [...toolSelection.tools] }
		toolsSave.disabled = true
		toolsCancel.disabled = true
		toolsStatus.textContent = t('mcp', 'Saving selection…')
		try {
			const variantNames = new Set(submitted.allowedTools.filter(tool => Array.isArray(tool.approvedVariants) && tool.approvedVariants.length).map(tool => tool.name))
			const variants = Object.fromEntries(submitted.tools.filter(name => variantNames.has(name)).map(name => [name, submitted.variants[name] || []]))
			const payload = { tools: submitted.tools, revision: submitted.revision }
			if (Object.keys(variants).length) payload.variants = variants
			const saved = await api('PUT', '/settings/my/connections/' + encodeURIComponent(connectionId) + '/tools', payload, { uncertainWrite: true })
			if (generation !== toolSelectionGeneration || toolsDialog.dataset.connectionId !== connectionId || !toolsDialog.open) return
			toolSelection = normalizeToolSelection({ ...submitted, ...saved })
			renderToolSelection(toolSelection)
			toolsStatus.textContent = t('mcp', 'Tool selection saved.')
		} catch (failure) {
			if (generation !== toolSelectionGeneration || !toolsDialog.open) return
			toolsStatus.textContent = failure.message === '409'
				? t('mcp', 'Available tools changed. Reload this selection before saving.')
				: t('mcp', 'Could not save this selection. Your changes are still here.')
		} finally {
			if (generation === toolSelectionGeneration && toolsDialog.open) {
				toolsCancel.disabled = false
				toolsSave.disabled = false
			}
		}
	}

	/** @param {string} text message for the screen reader status region */
	function say(text) {
		if (status) {
			status.textContent = text
		}
	}

	/** Reads the current page and re-renders the table; a failure is shown in the table with a retry. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		const query = new URLSearchParams({ page: String(state.page) })
		if (admin) {
			query.set('search', state.search)
		}
		try {
			state.data = await api('GET', base + '?' + query.toString())
			if (state.data.connections.length === 0 && state.page > 1) {
				// The last row of the last page was revoked: show the previous page instead of an empty one.
				state.page -= 1
				await load()
				return
			}
			render()
		} catch (e) {
			if (scope.disposed) return
			const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
			table.tBodies[0].replaceChildren(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the connections.'), ' ', retry])]))
		} finally {
			if (!scope.disposed) {
				table.setAttribute('aria-busy', 'false')
			}
		}
	}

	/** Renders the rows and the pager from state.data. */
	function render() {
		const data = state.data
		const labels = [...table.tHead.rows[0].cells].map(header => header.textContent.trim())
		const rows = data.connections.map((connection) => {
			const cells = []
			if (admin) {
				cells.push(el('th', { scope: 'row' }, [
					el('span', { className: 'mcp-name', textContent: connection.displayName }),
					el('span', { className: 'mcp-uid', textContent: connection.uid }),
				]))
			}
			cells.push(clientCell(connection.client))
			cells.push(el('td', { textContent: when(connection.createdAt) }))
			cells.push(el('td', { textContent: when(connection.expiresAt) }))
			// Every row has the same visible button text, so the accessible name says which connection it revokes.
			const client = clientName(connection.client)
			const revokeButton = el('button', {
				type: 'button',
				textContent: t('mcp', 'Revoke'),
				onclick: () => revoke(connection),
			})
			const actions = el('td', { className: 'mcp-actions' }, [revokeButton])
			if (!admin && toolsDialog) {
				const manage = el('button', { type: 'button', textContent: t('mcp', 'Manage tools'), onclick: () => {
					toolsDialog.dataset.connectionId = String(connection.id)
					manageTools(connection)
				} })
				manage.setAttribute('aria-label', t('mcp', 'Manage tools for {client}', { client }))
				actions.prepend(manage, ' ')
			}
			revokeButton.setAttribute('aria-label', admin
				? t('mcp', 'Revoke {client} for {user}', { client, user: connection.displayName }, undefined, PLAIN_TEXT)
				: t('mcp', 'Revoke {client}', { client }, undefined, PLAIN_TEXT))
			if (admin) {
				const all = el('button', {
					type: 'button',
					textContent: t('mcp', 'Revoke all of this user'),
					onclick: () => revokeUser(connection),
				})
				all.setAttribute('aria-label', t('mcp', 'Revoke all connections of {user}', { user: connection.displayName }, undefined, PLAIN_TEXT))
				actions.append(' ', all)
			}
			cells.push(actions)
			cells.forEach((cell, index) => { cell.dataset.label = labels[index] || '' })
			return el('tr', {}, cells)
		})
		if (rows.length === 0) {
			const text = admin
				? (state.search === '' ? t('mcp', 'No active connections.') : t('mcp', 'No connection matches the search.'))
				: t('mcp', 'You have no active connections.')
			rows.push(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty', textContent: text })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		prevButton.disabled = data.page <= 1
		nextButton.disabled = !data.hasMore
		pageInfo.textContent = t('mcp', 'Page {page}', { page: data.page })
		pager.hidden = !data.hasMore && data.page <= 1
	}

	/** @param {object} connection row from the API */
	async function revoke(connection) {
		const name = clientName(connection.client)
		const question = admin
			? t('mcp', 'Revoke the connection of {user} through {client}? The client is signed out at once.', { user: connection.displayName, client: name }, undefined, PLAIN_TEXT)
			: t('mcp', 'Revoke the connection through {client}? The client is signed out at once.', { client: name }, undefined, PLAIN_TEXT)
		if (!window.confirm(question)) {
			return
		}
		try {
			onCounts(await api('DELETE', base + '/' + encodeURIComponent(String(connection.id))))
			say(t('mcp', 'Connection revoked.'))
		} catch (e) {
			if (scope.disposed) return
			notifyError(t('mcp', 'Could not revoke the connection.'))
		}
		await load()
	}

	/** @param {object} connection any row of the user whose connections are revoked */
	async function revokeUser(connection) {
		if (!window.confirm(t('mcp', 'Revoke every connection of {user}? Their clients are signed out at once; the user can sign in again.', { user: connection.displayName }, undefined, PLAIN_TEXT))) {
			return
		}
		try {
			onCounts(await api('DELETE', '/api/connections/users/' + encodeURIComponent(connection.uid)))
			say(t('mcp', 'Connections of {user} revoked.', { user: connection.displayName }, undefined, PLAIN_TEXT))
		} catch (e) {
			if (scope.disposed) return
			notifyError(t('mcp', 'Could not revoke the connections of {user}.', { user: connection.displayName }))
		}
		state.page = 1
		await load()
	}

	if (searchInput) {
		let timer = null
		scope.on(searchInput, 'input', () => {
			scope.clearTimeout(timer)
			timer = scope.timeout(() => {
				state.search = searchInput.value.trim()
				state.page = 1
				load()
			}, 300)
		})
	}
	if (toolsSave) scope.on(toolsSave, 'click', saveTools)
	if (toolsCancel) scope.on(toolsCancel, 'click', () => toolsDialog.close())
	if (toolsDialog) scope.on(toolsDialog, 'close', () => {
		toolSelectionGeneration += 1
		toolSelection = null
		toolsList.setAttribute('aria-busy', 'false')
	})
	if (basicToolsButton) scope.on(basicToolsButton, 'click', () => manageTools(null, true))
	scope.on(prevButton, 'click', () => {
		state.page = Math.max(1, state.page - 1)
		load()
	})
	scope.on(nextButton, 'click', () => {
		state.page += 1
		load()
	})

	const ready = load()
	return { ready, reload: load, destroy: scope.destroy }
}
