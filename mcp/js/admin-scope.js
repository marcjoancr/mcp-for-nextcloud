/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Resource scope panel of the admin page (issue #4, B7; contracts B.6). Plain JavaScript, no build. One user at a
 * time: per module of the scope (Files, Calendar, Tasks) the administrator chooses "All resources" or "Only
 * selected" and picks folders, calendars or task lists from the user's own Nextcloud resources, through the admin
 * routes of the scope (GET /api/scope/{uid}/{module}, its candidates, PUT with expectedRevision, undo through
 * /api/grants/undo). Nothing here decides access: the server validates every item and the tools apply the scope.
 *
 * A module whose activation barrier is still closed on the server (`active: false`) is shown with its saved state
 * and browsable, but cannot be saved, and says so: the tools of that module do not apply a scope yet. A conflict
 * (409) never repeats the write: the current scope is read again and shown. Exposes window.McpScopeUi for the
 * matrix, which shows "Restricted to N" in the module's first cell and opens this panel from it.
 */
(function () {
	'use strict'
	const MODULES = ['files', 'calendar', 'tasks']
	const UNDO_SECONDS = 10
	const root = document.getElementById('mcp-scope')
	const form = document.getElementById('mcp-scope-form')
	const userInput = document.getElementById('mcp-scope-user')
	const status = document.getElementById('mcp-scope-status')
	const alertBox = document.getElementById('mcp-scope-alert')
	const container = document.getElementById('mcp-scope-modules')
	let uid = null
	let revision = null
	let seq = 0
	/** @type {Object<string, object>} state of each module of the open user */
	let modules = {}

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		status.textContent = ''
		alertBox.textContent = ''
		;(error ? alertBox : status).textContent = message
	}
	async function api(method, path, query, body) {
		const url = OC.generateUrl('/apps/mcp' + path) + (query ? '?' + new URLSearchParams(query) : '')
		const response = await fetch(url, {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function scopePath(module, suffix = '') {
		return '/api/scope/' + encodeURIComponent(uid) + '/' + module + suffix
	}
	function title(module) {
		return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
	}
	function emptyText(module) {
		return {
			files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
			calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
			tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
		}[module]
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can change the resource scope.')
		if (error.status === 400) return t('mcp', 'Check the user ID and try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
		return t('mcp', 'The request failed. Try again.')
	}
	/** What a saved or selected item is called on screen: its path for a folder, its name otherwise. */
	function label(item) {
		if (item.found === false) return t('mcp', 'Resource no longer found ({id})', { id: item.id })
		return item.path || item.name
	}
	function badges(item) {
		const out = []
		if (item.found === false) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'not found: it has no effect') }))
		if (item.excluded) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'excluded by a tag: choosing it has no effect') }))
		if (item.inaccessible) out.push(el('span', { className: 'mcp-badge mcp-badge-error', textContent: t('mcp', 'the user cannot reach it in Nextcloud') }))
		return out
	}

	/**
	 * A message of one module: shown under it and announced through the page's live regions, which exist from the
	 * start (a region created with its text already in it is not reliably announced).
	 */
	function note(state, message, error = false) {
		state.line = message
		if (message !== '') say(title(state.module) + ': ' + message, error)
	}

	/** @return {boolean} whether the draft differs from what is saved */
	function dirty(state) {
		const ids = (items) => items.map((item) => item.id).sort().join(',')
		return state.mode !== state.savedMode || (state.mode === 'restricted' && ids(state.items) !== ids(state.savedItems))
	}

	async function openUser(target, focusModule) {
		const mine = ++seq
		uid = target
		modules = {}
		container.replaceChildren()
		container.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Loading the resource scope…'))
		try {
			const loaded = await Promise.all(MODULES.map((module) => api('GET', scopePath(module))))
			if (mine !== seq) return
			loaded.forEach((data) => {
				revision = data.revision
				modules[data.module] = {
					module: data.module, active: data.active, limit: data.limit,
					mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
					search: '', parents: [], results: null, page: 1, undo: null,
				}
			})
			MODULES.forEach((module) => container.append(renderModule(modules[module])))
			say(t('mcp', 'Resource scope of {user}.', { user: uid }))
			const heading = document.getElementById('mcp-scope-heading-' + (focusModule || MODULES[0]))
			if (focusModule && heading) {
				heading.scrollIntoView?.({ block: 'start' })
				heading.focus?.()
			}
		} catch (error) {
			if (mine !== seq) return
			uid = null
			say(failure(error), true)
		} finally {
			if (mine === seq) container.setAttribute('aria-busy', 'false')
		}
	}

	/** Reads one module again from the server: after a save, an undo or a conflict. */
	async function reload(state) {
		const data = await api('GET', scopePath(state.module))
		revision = data.revision
		Object.assign(state, { active: data.active, mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items] })
		rerender(state)
	}

	function rerender(state, focusId) {
		const old = document.getElementById('mcp-scope-module-' + state.module)
		const fresh = renderModule(state)
		if (old) old.replaceWith(fresh)
		if (focusId) document.getElementById(focusId)?.focus?.()
	}

	function renderModule(state) {
		const module = state.module
		const section = el('section', { id: 'mcp-scope-module-' + module, className: 'mcp-scope-module' })
		const heading = el('h3', { id: 'mcp-scope-heading-' + module, textContent: title(module), tabIndex: -1 })
		section.setAttribute('aria-labelledby', heading.id)
		section.append(heading)
		if (!state.active) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Not available yet: the tools of this module do not apply a resource scope in this version, so it cannot be saved and nothing is restricted. You can still browse what the user has.') }))
		}
		const fieldset = el('fieldset', {}, [el('legend', { textContent: t('mcp', 'Resources the MCP tools of {user} may reach', { user: uid }) })])
		;[['unrestricted', t('mcp', 'All resources')], ['restricted', t('mcp', 'Only selected')]].forEach(([value, text]) => {
			const id = 'mcp-scope-mode-' + module + '-' + value
			const radio = el('input', { type: 'radio', name: 'mcp-scope-mode-' + module, id, value, checked: state.mode === value })
			radio.addEventListener('change', () => {
				if (!radio.checked) return
				state.mode = value
				rerender(state, id)
			})
			fieldset.append(el('span', { className: 'mcp-scope-choice' }, [radio, el('label', { htmlFor: id, textContent: text })]))
		})
		section.append(fieldset)
		if (state.mode === 'restricted') {
			section.append(renderSelection(state), renderPicker(state))
		}
		const save = el('button', { type: 'button', id: 'mcp-scope-save-' + module, textContent: t('mcp', 'Save'), disabled: !state.active || !dirty(state) })
		save.addEventListener('click', () => saveModule(state))
		const discard = el('button', { type: 'button', textContent: t('mcp', 'Discard changes'), disabled: !dirty(state) })
		discard.addEventListener('click', () => {
			Object.assign(state, { mode: state.savedMode, items: [...state.savedItems] })
			rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
		})
		const actions = el('div', { className: 'mcp-scope-actions' }, [save, discard])
		if (state.undo) {
			const undo = el('button', { type: 'button', id: 'mcp-scope-undo-' + module, textContent: t('mcp', 'Undo') })
			undo.addEventListener('click', () => undoModule(state))
			actions.append(undo)
		}
		section.append(actions)
		section.append(el('p', { id: 'mcp-scope-line-' + module, className: 'mcp-scope-line', textContent: state.line || '', tabIndex: -1 }))
		return section
	}

	function renderSelection(state) {
		const module = state.module
		const wrap = el('div', { className: 'mcp-scope-selection' })
		wrap.append(el('p', { textContent: state.items.length === 0 ? emptyText(module) : t('mcp', '{count} of at most {limit} selected', { count: state.items.length, limit: state.limit }) }))
		if (state.items.length > 0) {
			const list = el('ul', { className: 'mcp-scope-list' })
			list.setAttribute('aria-label', t('mcp', 'Selected resources'))
			state.items.forEach((item, index) => {
				const remove = el('button', { type: 'button', id: 'mcp-scope-remove-' + module + '-' + item.id, textContent: t('mcp', 'Remove') })
				remove.setAttribute('aria-label', t('mcp', 'Remove {name}', { name: label(item) }))
				remove.addEventListener('click', () => {
					state.items = state.items.filter((other) => other.id !== item.id)
					// The focus goes to the next item, else the previous one, else the mode choice.
					const next = state.items[index] || state.items[index - 1]
					rerender(state, next ? 'mcp-scope-remove-' + module + '-' + next.id : 'mcp-scope-mode-' + module + '-restricted')
				})
				list.append(el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), remove]))
			})
			wrap.append(list)
		}
		return wrap
	}

	function renderPicker(state) {
		const module = state.module
		const picker = el('div', { className: 'mcp-scope-picker' })
		const searchId = 'mcp-scope-search-' + module
		const search = el('input', { type: 'search', id: searchId, value: state.search, autocomplete: 'off', spellcheck: false })
		const go = el('button', { type: 'button', textContent: t('mcp', 'Search') })
		const run = () => { state.search = search.value; state.page = 1; state.results = null; browse(state, searchId) }
		go.addEventListener('click', run)
		search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run() } })
		picker.append(el('label', { htmlFor: searchId, textContent: module === 'files' ? t('mcp', 'Find a folder of the user') : t('mcp', 'Find a calendar or task list of the user') }), search, go)
		if (module === 'files') {
			const here = state.parents[state.parents.length - 1]
			picker.append(el('p', { className: 'mcp-scope-where', textContent: here ? t('mcp', 'In folder {path}', { path: here.path }) : t('mcp', 'In the top folder of the user') }))
			if (here) {
				const up = el('button', { type: 'button', id: 'mcp-scope-up-files', textContent: t('mcp', 'Up one folder') })
				up.addEventListener('click', () => { state.parents.pop(); state.page = 1; state.results = null; browse(state, 'mcp-scope-up-files') })
				picker.append(up, addButton(state, here, t('mcp', 'Add this folder')))
			}
		}
		const results = el('ul', { className: 'mcp-scope-results' })
		results.setAttribute('aria-label', t('mcp', 'Resources found'))
		const count = el('p', { className: 'mcp-scope-count' })
		count.setAttribute('aria-live', 'polite')
		if (state.results === null) {
			count.textContent = t('mcp', 'Search, or press Search with an empty field to list everything.')
		} else {
			count.textContent = state.results.total === 0 ? t('mcp', 'Nothing found.') : t('mcp', '{shown} of {total} shown', { shown: state.results.items.length, total: state.results.total })
			state.results.items.forEach((item) => {
				const row = el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), addButton(state, item)])
				if (module === 'files') {
					const open = el('button', { type: 'button', textContent: t('mcp', 'Open') })
					open.setAttribute('aria-label', t('mcp', 'Open {name}', { name: label(item) }))
					open.addEventListener('click', () => { state.parents.push(item); state.page = 1; state.results = null; state.search = ''; browse(state, searchId) })
					row.append(open)
				}
				results.append(row)
			})
			if (state.results.hasMore) {
				const more = el('button', { type: 'button', id: 'mcp-scope-more-' + module, textContent: t('mcp', 'Show more') })
				more.addEventListener('click', () => { state.page += 1; browse(state, 'mcp-scope-more-' + module, true) })
				picker.append(results, count, more)
				return picker
			}
		}
		picker.append(results, count)
		return picker
	}

	function addButton(state, item, text) {
		const chosen = state.items.some((other) => other.id === item.id)
		const add = el('button', { type: 'button', textContent: text || (chosen ? t('mcp', 'Added') : t('mcp', 'Add')), disabled: chosen })
		add.setAttribute('aria-label', chosen ? t('mcp', '{name} is selected', { name: label(item) }) : t('mcp', 'Add {name}', { name: label(item) }))
		add.addEventListener('click', () => {
			if (state.items.length >= state.limit) {
				note(state, t('mcp', 'At most {limit} resources can be selected.', { limit: state.limit }), true)
				rerender(state)
				return
			}
			state.items = [...state.items, { id: item.id, name: item.name, path: item.path, found: true, excluded: item.excluded }]
			note(state, t('mcp', '{name} added. Save to apply it.', { name: label(item) }))
			rerender(state, 'mcp-scope-search-' + state.module)
		})
		return add
	}

	async function browse(state, focusId, append = false) {
		const query = { search: state.search, page: String(state.page) }
		const here = state.parents[state.parents.length - 1]
		if (state.module === 'files' && here) query.parent = here.id
		try {
			const data = await api('GET', scopePath(state.module, '/candidates'), query)
			state.results = append && state.results ? Object.assign(data, { items: [...state.results.items, ...data.items] }) : data
			state.line = ''
		} catch (error) {
			state.results = null
			note(state, failure(error), true)
		}
		rerender(state, focusId)
	}

	async function saveModule(state) {
		const body = state.mode === 'unrestricted'
			? { expectedRevision: revision, mode: 'unrestricted' }
			: { expectedRevision: revision, mode: 'restricted', items: state.items.map((item) => item.id) }
		note(state, t('mcp', 'Saving…'))
		rerender(state)
		try {
			const result = await api('PUT', scopePath(state.module), null, body)
			revision = result.revision
			await reload(state)
			note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
			if (result.changed && result.receiptId) offerUndo(state, result.receiptId, result.revision)
			rerender(state, 'mcp-scope-line-' + state.module)
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			await saveFailed(state, error)
		}
	}

	async function saveFailed(state, error) {
		const data = error.data || {}
		if (error.status === 409) {
			// Never written again from here: the admin sees what is saved now and decides.
			note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
			await reload(state).catch(() => {})
		} else if (error.status === 422 && data.error === 'ScopeInactive') {
			state.active = false
			note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
		} else if (error.status === 422 && data.error === 'Inaccessible') {
			const missing = new Set(data.items || [])
			state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
			note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
		} else if (error.status === 422 && data.error === 'TooManyResources') {
			note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }), true)
		} else if (error.status === 503 && data.uncertain) {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			await reload(state).catch(() => {})
		} else {
			note(state, failure(error), true)
		}
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	function offerUndo(state, receiptId, savedRevision) {
		const token = {}
		state.undo = { receiptId, savedRevision, token }
		setTimeout(() => {
			if (state.undo && state.undo.token === token) {
				state.undo = null
				rerender(state)
			}
		}, UNDO_SECONDS * 1000)
	}

	async function undoModule(state) {
		const { receiptId, savedRevision } = state.undo
		state.undo = null
		try {
			const result = await api('POST', '/api/grants/undo', null, { receiptId, expectedRevision: savedRevision })
			revision = result.revision
			note(state, t('mcp', 'Change undone.'))
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			note(state, error.status === 409
				? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
				: error.status === 410
					? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
					: failure(error), true)
		}
		const line = state.line
		await reload(state).catch(() => {})
		state.line = line
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	/**
	 * The matrix cell mark of a restricted module: visible text, no colour or icon alone, and a way into the panel.
	 *
	 * @param {string} target uid of the row
	 * @param {string} module scope module
	 * @param {number} count resources kept by the scope
	 * @return {HTMLButtonElement}
	 */
	function indicator(target, module, count) {
		const mark = el('span', { className: 'mcp-scope-mark', textContent: '◩' })
		mark.setAttribute('aria-hidden', 'true')
		const button = el('button', { type: 'button', className: 'mcp-scope-indicator' }, [mark, ' ' + t('mcp', 'Restricted to {count}', { count })])
		button.title = t('mcp', 'The MCP tools of this user reach only the selected resources of this module. Open the resource scope.')
		button.addEventListener('click', () => open(target, module))
		return button
	}

	function open(target, module) {
		if (!root) return
		userInput.value = target
		openUser(target, module)
	}

	window.McpScopeUi = { indicator, open }
	if (!root) return
	form.addEventListener('submit', (event) => {
		event.preventDefault()
		const target = userInput.value.trim()
		if (target === '') {
			say(t('mcp', 'Enter the user ID.'), true)
			return
		}
		openUser(target, null)
	})
})()
