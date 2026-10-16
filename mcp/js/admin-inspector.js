/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Effective access inspector (ticket 13; issue #4 A5, B6). Plain JavaScript, no build. It only reads: the effective
 * access of one user per operation, and why one path or one calendar is reached or not. Every text comes from the
 * codes the server answers, the same codes the tools are decided by; nothing here decides access.
 */
(function () {
	'use strict'
	const root = document.getElementById('mcp-inspector')
	if (!root) return
	const form = document.getElementById('mcp-inspector-form')
	const user = document.getElementById('mcp-inspector-user')
	const status = document.getElementById('mcp-inspector-status')
	const alert = document.getElementById('mcp-inspector-alert')
	const access = document.getElementById('mcp-inspector-access')
	const resources = document.getElementById('mcp-inspector-resources')
	const pathForm = document.getElementById('mcp-inspector-path-form')
	const pathInput = document.getElementById('mcp-inspector-path')
	const pathResult = document.getElementById('mcp-inspector-path-result')
	const calendarForm = document.getElementById('mcp-inspector-calendar-form')
	const calendarModule = document.getElementById('mcp-inspector-calendar-module')
	const calendarSelect = document.getElementById('mcp-inspector-calendar')
	const calendarOperation = document.getElementById('mcp-inspector-calendar-operation')
	const calendarResult = document.getElementById('mcp-inspector-calendar-result')
	let inspected = null
	let catalog = {}
	let seq = 0

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		status.textContent = ''
		alert.textContent = ''
		;(error ? alert : status).textContent = message
	}
	async function get(path, query) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
			headers: { Accept: 'application/json', requesttoken: OC.requestToken },
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
		if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
		return t('mcp', 'The inspection failed. Try again.')
	}
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log') }[module] || module
	}
	function operationName(operation) {
		return { read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore') }[operation] || operation
	}
	/** The first check that blocks, by the code of the effective access (EffectiveResult::blockingGate). */
	function gateText(gate) {
		return {
			account: t('mcp', 'The account does not exist or is disabled.'),
			service: t('mcp', 'The MCP service is switched off.'),
			eligibility: t('mcp', 'The user may not connect to MCP.'),
			eligibility_unknown: t('mcp', 'Unknown: whether the user may connect depends on a group whose policy cannot be confirmed now.'),
			connection: t('mcp', 'The user has not switched on their MCP connection.'),
			app: t('mcp', 'The app of this module is not available to the user.'),
			logs_available: t('mcp', 'The server log cannot be read on this instance.'),
			logs_role: t('mcp', 'The user does not have the role needed to read the server log.'),
			grant: t('mcp', 'Not granted.'),
			grant_unknown: t('mcp', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.'),
			resource_scope: t('mcp', 'Outside the resource scope of this user.'),
			resource_scope_unknown: t('mcp', 'Unknown: the resource scope of this user cannot be read.'),
		}[gate] || gate
	}
	function sourceText(grant) {
		const groups = (grant.groupIds || []).join(', ')
		return {
			individual_allow: t('mcp', 'Individual: allowed'),
			individual_deny: t('mcp', 'Individual: denied'),
			group: t('mcp', 'Group: {groups}', { groups }),
			default: t('mcp', 'Default'),
			invalid: t('mcp', 'Invalid stored value (denied)'),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups }),
		}[grant.source] || grant.source
	}
	function resultText(allowed) {
		return allowed === true ? t('mcp', 'Allowed') : allowed === false ? t('mcp', 'Blocked') : t('mcp', 'Unknown')
	}
	/** What a path or calendar answer means, from its codes (AccessInspector, CalendarAccessExplainer). */
	function explanation(answer) {
		const checks = { policy: t('mcp', 'the MCP policy'), native_acl: t('mcp', 'the Nextcloud permissions'), exclusion: t('mcp', 'the exclusion tags'), resource_scope_unknown: t('mcp', 'the resource scope'), grant_unknown: t('mcp', 'a group grant'), eligibility_unknown: t('mcp', 'a group eligibility') }
		const exclusions = {
			tag_direct: t('mcp', 'Not reached: it carries an exclusion tag.'),
			tag_folder: t('mcp', 'Not reached: it is inside the excluded folder {folder}.', { folder: answer.folder || '' }),
			tag_share_origin: t('mcp', 'Not reached: the shared item is excluded in the files of its owner.'),
			backup_of_excluded: t('mcp', 'Not reached: it is an MCP backup of an excluded file.'),
			invalid_configuration: t('mcp', 'Not reached: the saved exclusion tags cannot be read, so every file is refused.'),
		}
		switch (answer.reason) {
		case 'allowed': return t('mcp', 'Reached: every check passed.')
		case 'blocked': return t('mcp', 'Not reached: {reason}', { reason: gateText(answer.gate) })
		case 'out_of_scope': return t('mcp', 'Not reached: outside the resource scope of this user.')
		case 'not_found': return t('mcp', 'Not reached: the path does not exist in the files of this user.')
		case 'no_native_access': return t('mcp', 'Not reached: the user has no access to it in Nextcloud.')
		case 'read_only': return t('mcp', 'Not reached for this operation: the user can only read it in Nextcloud.')
		case 'excluded': return exclusions[answer.exclusion] || t('mcp', 'Not reached: it is excluded from MCP access.')
		case 'not_evaluated': return t('mcp', 'Not confirmed: {check} could not be checked, so access is not known.', { check: checks[answer.gate] || answer.gate })
		default: return answer.reason
		}
	}

	function renderAccess(data) {
		catalog = {}
		const rows = data.operations.map((row) => {
			;(catalog[row.module] = catalog[row.module] || []).push(row.operation)
			const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
			return el('tr', {}, [
				el('th', { scope: 'row', textContent: moduleName(row.module) + ': ' + operationName(row.operation) }),
				el('td', { textContent: resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
				el('td', { textContent: row.blockingGate === null ? '—' : gateText(row.blockingGate) }),
				el('td', { textContent: sourceText(row.grant) + scope }),
			])
		})
		const head = el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => el('th', { scope: 'col', textContent: text })))
		access.replaceChildren(el('table', { className: 'mcp-inspector-table' }, [el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: inspected }) }), el('thead', {}, [head]), el('tbody', {}, rows)]))
	}

	async function inspect() {
		const uid = user.value.trim()
		if (uid === '') {
			say(t('mcp', 'Enter the user ID to inspect.'), true)
			return
		}
		const mine = ++seq
		access.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Inspecting…'))
		try {
			const data = await get('/api/inspector/access', { uid })
			if (mine !== seq) return
			inspected = uid
			renderAccess(data)
			resources.hidden = false
			pathResult.textContent = ''
			calendarResult.textContent = ''
			await loadCalendars()
			say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
		} catch (error) {
			if (mine !== seq) return
			inspected = null
			access.replaceChildren()
			resources.hidden = true
			say(failure(error), true)
		} finally {
			if (mine === seq) access.setAttribute('aria-busy', 'false')
		}
	}

	/** A request to the user or module that is no longer on screen is answered too late: its answer is dropped. */
	const asked = { calendars: 0, path: 0, calendar: 0 }
	function stale(kind, mine, user) {
		return mine !== asked[kind] || user !== inspected
	}

	async function loadCalendars() {
		const module = calendarModule.value
		const mine = ++asked.calendars
		const user = inspected
		calendarOperation.replaceChildren(...(catalog[module] || ['read']).map((operation) => el('option', { value: operation, textContent: operationName(operation) })))
		calendarSelect.replaceChildren()
		try {
			const data = await get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
			if (stale('calendars', mine, user) || module !== calendarModule.value) return
			calendarSelect.replaceChildren(...data.items.map((item) => el('option', { value: item.id, textContent: item.name })))
			if (data.items.length === 0) calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
		} catch (error) {
			if (stale('calendars', mine, user)) return
			say(failure(error), true)
		}
	}

	async function explain(kind, target, path, query, module) {
		const mine = ++asked[kind]
		const user = inspected
		target.textContent = t('mcp', 'Checking…')
		try {
			const answer = await get(path, Object.assign({ uid: user }, query))
			if (stale(kind, mine, user)) return
			target.textContent = explanation(answer)
			if (answer.reason === 'out_of_scope' && window.McpScopeUi) {
				const change = el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
				change.addEventListener('click', () => window.McpScopeUi.open(user, module))
				target.append(' ', change)
			}
		} catch (error) {
			if (stale(kind, mine, user)) return
			// An error is announced like every other error of the inspector, in its alert region.
			target.textContent = ''
			say(failure(error), true)
		}
	}

	form.addEventListener('submit', (event) => { event.preventDefault(); inspect() })
	pathForm.addEventListener('submit', (event) => {
		event.preventDefault()
		const path = pathInput.value.trim()
		if (inspected === null || path === '') return
		explain('path', pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	calendarModule.addEventListener('change', () => { if (inspected !== null) loadCalendars() })
	calendarForm.addEventListener('submit', (event) => {
		event.preventDefault()
		if (inspected === null || calendarSelect.value === '') return
		explain('calendar', calendarResult, '/api/inspector/calendar', { module: calendarModule.value, calendarId: calendarSelect.value, operation: calendarOperation.value }, calendarModule.value)
	})
})()
