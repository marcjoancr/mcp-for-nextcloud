/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy editor. Plain JavaScript, no build. Drafts survive refused saves; writes are never replayed. */
(function () {
	'use strict'
	const root = document.getElementById('mcp-group-editor')
	if (!root) return
	const search = document.getElementById('mcp-group-search')
	const list = document.getElementById('mcp-group-list')
	const detail = document.getElementById('mcp-group-detail')
	const status = document.getElementById('mcp-group-status')
	const alert = document.getElementById('mcp-group-alert')
	const prev = document.getElementById('mcp-group-prev')
	const next = document.getElementById('mcp-group-next')
	const counter = document.getElementById('mcp-group-count')
	let page = 1
	/** Rows of the page on screen, kept so the open group can be marked without reading the list again. */
	let rows = []
	let listSeq = 0
	let detailSeq = 0
	let current = null
	/** Name of the open group as the list shows it; the detail API returns only the ID. */
	let shownName = null
	let draft = {}
	let preview = null
	/** Whether the detail shows each module's operations instead of its level (the "Operations" switch). */
	let advanced = false
	let busy = false
	let timer
	let sayTimer
	/** Milliseconds between clearing a live region and writing into it. */
	const SAY_DELAY = 60
	/** Groups per page of the directory API. */
	const PAGE_SIZE = 50
	/** Options of t() for text written with textContent, which escapes by itself. */
	const RAW = { escape: false }

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	/**
	 * Writes into the fixed live regions. Both are cleared at once and the message is written shortly after, in
	 * another task, so the same error twice in a row is announced again, and a message that was replaced or cleared
	 * in the meantime never appears.
	 */
	function say(message, error = false) {
		clearTimeout(sayTimer)
		status.textContent = ''
		alert.textContent = ''
		if (message === '') return
		const region = error ? alert : status
		sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
	}
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { data })
		return data
	}
	const copyGrants = (grants) => Object.fromEntries(Object.entries(grants).map(([module, ops]) => [module, [...ops]]))
	const unknown = () => t('mcp', 'Unknown count')
	const count = (value) => value === null || value === undefined ? unknown() : String(value)
	const untrusted = () => t('mcp', 'This group comes from a source that cannot guarantee group identity (for example LDAP). Grant MCP access to its members individually.')
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log') }[module] || module
	}
	function operationName(operation) {
		return { read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore') }[operation] || operation
	}
	function grantKeys(grants) {
		return Object.entries(grants).flatMap(([module, operations]) => operations.map((operation) => module + ':' + operation)).sort()
	}
	function dirty() {
		return current && JSON.stringify(grantKeys(draft)) !== JSON.stringify(grantKeys(current.grants))
	}
	function discardDraft() {
		return !dirty() || window.confirm(t('mcp', 'Discard the unsaved group changes?'))
	}
	function diagnosis(group) {
		if (group.invalidId) return t('mcp', 'The directory returned an invalid group ID. This group cannot be edited.')
		if (group.trusted === null) return t('mcp', 'The group source is unavailable. Retry before editing.')
		if (group.exists === false) return t('mcp', 'This group no longer exists. Its saved policy does not grant access; you can clear it.')
		if (!group.trusted) return untrusted()
		if (group.state === 'needs_confirm') return t('mcp', 'This policy is awaiting confirmation and does not grant access. Confirming or saving will let it grant access again.')
		if (group.state === 'pending_delete') return t('mcp', 'Group deletion is pending. This policy does not grant access. Check the group before confirming it.')
		return ''
	}
	/**
	 * The state of a group in one short phrase, for its row in the list; '' when the group can grant normally. The
	 * phrase always carries the state in words, next to a symbol, so it never depends on colour.
	 *
	 * @return {{symbol: string, text: string, warning: boolean}|null}
	 */
	function rowState(group) {
		if (group.invalidId) return { symbol: '⊘', text: t('mcp', 'Cannot be edited: invalid group ID'), warning: false }
		if (group.trusted === null) return { symbol: '?', text: t('mcp', 'Source unavailable'), warning: true }
		if (group.exists === false) return { symbol: '⊘', text: t('mcp', 'Deleted group'), warning: false }
		if (!group.trusted) return { symbol: '🔒', text: t('mcp', 'Cannot grant access'), warning: false }
		if (group.state === 'needs_confirm') return { symbol: '⚠', text: t('mcp', 'Awaiting confirmation'), warning: true }
		if (group.state === 'pending_delete') return { symbol: '⚠', text: t('mcp', 'Deletion pending'), warning: true }
		return null
	}
	/** @return {string} what a group grants, in a few words, for its row in the list */
	function grantSummary(group) {
		const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
		if (granted.length === 0) return t('mcp', 'Grants nothing')
		return t('mcp', 'Grants: {permissions}', { permissions: granted.map((module) => module === '_system' ? t('mcp', 'Can connect') : moduleName(module)).join(', ') }, undefined, RAW)
	}
	/** One row of the list: the name, the ID only when it differs from the name, and the state or what the group grants. */
	function groupRow(group) {
		const children = [el('span', { className: 'mcp-group-name', textContent: group.displayName })]
		if (group.groupId !== null && group.groupId !== group.displayName) {
			children.push(el('span', { className: 'mcp-group-sub', textContent: t('mcp', 'ID: {id}', { id: group.groupId }, undefined, RAW) }))
		}
		const state = rowState(group)
		if (state) {
			const symbol = el('span', { className: 'mcp-group-symbol', textContent: state.symbol })
			symbol.setAttribute('aria-hidden', 'true')
			children.push(el('span', { className: 'mcp-group-sub mcp-group-state' + (state.warning ? ' mcp-group-state-warning' : '') }, [symbol, state.text]))
		} else {
			children.push(el('span', { className: 'mcp-group-sub', textContent: grantSummary(group) }))
		}
		const button = el('button', { type: 'button', className: 'mcp-group-item', disabled: group.invalidId === true, onclick: () => open(group.groupId, false, false, group.displayName) }, children)
		button.setAttribute('aria-current', String(current !== null && group.groupId !== null && current.groupId === group.groupId))
		return el('li', {}, [button])
	}
	function drawList() {
		list.replaceChildren(...rows.map(groupRow))
		if (!rows.length) list.append(el('li', { className: 'mcp-hint', textContent: t('mcp', 'No groups found.') }))
	}
	async function loadList() {
		const seq = ++listSeq
		list.setAttribute('aria-busy', 'true')
		prev.disabled = next.disabled = true
		try {
			const data = await api('GET', '/api/group-directory?' + new URLSearchParams({ search: search.value.trim(), page: String(page) }))
			if (seq !== listSeq) return
			rows = data.groups
			drawList()
			const first = (page - 1) * PAGE_SIZE + 1
			counter.textContent = page === 1 && !data.hasMore
				? n('mcp', '%n group', '%n groups', rows.length)
				: rows.length === 0 ? '' : t('mcp', 'Groups {first}–{last}', { first, last: first + rows.length - 1 }, undefined, RAW)
			prev.disabled = page <= 1
			next.disabled = !data.hasMore
			say('')
		} catch (e) {
			if (seq !== listSeq) return
			rows = []
			counter.textContent = ''
			list.replaceChildren(el('li', {}, [t('mcp', 'Could not load groups.'), retry(loadList)]))
			say(t('mcp', 'Could not load groups.'), true)
		} finally {
			if (seq === listSeq) list.setAttribute('aria-busy', 'false')
		}
	}
	function retry(action) {
		return el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
	}
	async function open(gid, preserve = false, keepMessage = false, displayName = null) {
		if (busy || (!preserve && !discardDraft())) return
		if (displayName !== null) shownName = displayName
		const seq = ++detailSeq
		// Keep the draft's base until a read succeeds, including after a failed refresh.
		preview = null
		detail.replaceChildren(el('p', { textContent: t('mcp', 'Loading…') }))
		try {
			const data = await api('GET', '/api/group-directory/detail?' + new URLSearchParams({ gid }))
			if (seq !== detailSeq) return
			current = data
			if (!preserve) draft = copyGrants(data.grants)
			if (!keepMessage) say('')
			drawList()
			render('heading')
		} catch (e) {
			if (seq !== detailSeq) return
			detail.replaceChildren(retry(() => open(gid, preserve)))
			say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
		}
	}
	/** Operations of a module in a grants object, whether it came as an object or as PHP's empty array. */
	const operationsIn = (grants, module) => (grants && !Array.isArray(grants) && grants[module]) || []
	/** The catalog's modules, without the "Can connect" key. */
	const modules = () => Object.entries(current.catalog).filter(([module]) => module !== '_system')
	/**
	 * The level a set of granted operations makes, with the matrix's tiers (js/admin-levels.js). A group only grants, so an
	 * operation outside the set is "not granted", never denied.
	 */
	function levelIn(grants, module) {
		const operations = current.catalog[module]
		const granted = operationsIn(grants, module)
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)])))
	}
	/** What a member gets without any grant: GrantPolicy::defaultGranted(), reading except the server log. */
	function defaultLevel(module) {
		const operations = current.catalog[module]
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, operation === 'read' && module !== 'logs'])))
	}
	/** For a group the lowest level is "Not granted": the members keep what they have from elsewhere. */
	function levelName(level) {
		return { none: t('mcp', 'Not granted'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom') }[level] || level
	}
	/** A level in words, a custom one with its operations, since "Custom → Custom" would say nothing. */
	function levelText(grants, module) {
		const level = levelIn(grants, module)
		if (level !== 'custom') return levelName(level)
		return t('mcp', 'Custom ({operations})', { operations: current.catalog[module].filter((operation) => operationsIn(grants, module).includes(operation)).map(operationName).join(', ') }, undefined, RAW)
	}
	/** One line per changed module, "Files: Read → Edit", and one for "Can connect". */
	function changes(before, after) {
		const lines = []
		const eligible = (grants) => operationsIn(grants, '_system').includes('eligible')
		if (eligible(before) !== eligible(after)) {
			lines.push(t('mcp', '{module}: {before} → {after}', { module: t('mcp', 'Can connect'), before: eligible(before) ? t('mcp', 'Yes') : t('mcp', 'No'), after: eligible(after) ? t('mcp', 'Yes') : t('mcp', 'No') }, undefined, RAW))
		}
		for (const [module] of modules()) {
			const was = [...operationsIn(before, module)].sort().join()
			const will = [...operationsIn(after, module)].sort().join()
			if (was !== will) lines.push(t('mcp', '{module}: {before} → {after}', { module: moduleName(module), before: levelText(before, module), after: levelText(after, module) }, undefined, RAW))
		}
		return lines
	}
	function setOperations(module, operations, focusKey) {
		if (operations.length) draft[module] = [...operations].sort()
		else delete draft[module]
		preview = null
		render(focusKey)
	}
	/**
	 * The matrix, filtered by this group and by "With exceptions", for the members whose individual settings win over it.
	 * The matrix's group filter lists the groups it can filter by; a group it does not list (an external group, or one that
	 * no longer exists) is said so here, and the page does not move. The focus goes to the exceptions filter, a real
	 * control, which also tells a screen reader where it landed.
	 */
	function viewInMatrix(gid, name) {
		const select = document.getElementById('mcp-group')
		const exceptionsOnly = document.getElementById('mcp-only-exceptions')
		if (!select || !Array.from(select.options).some((option) => option.value === gid)) {
			say(t('mcp', 'The matrix cannot show only the members of {group}: its group filter does not list this group.', { group: name }, undefined, RAW))
			return
		}
		select.value = gid
		select.dispatchEvent(new Event('change'))
		exceptionsOnly?.click()
		document.getElementById('mcp-block-matrix')?.scrollIntoView?.({ block: 'start' })
		exceptionsOnly?.focus()
	}
	/** A pressed level carries a check mark besides aria-pressed, as in the matrix popover, so it never depends on colour. */
	function levelButton(module, level, pressed, onclick) {
		const children = []
		if (pressed) {
			const mark = el('span', { className: 'mcp-pressed-mark', textContent: '✓' })
			mark.setAttribute('aria-hidden', 'true')
			children.push(mark)
		}
		children.push(levelName(level))
		const button = el('button', { type: 'button', name: 'mcp-group-level-' + module, value: level, className: 'mcp-group-level' + (level === 'none' ? ' mcp-group-level-none' : ''), onclick }, children)
		button.setAttribute('aria-pressed', String(pressed))
		return button
	}
	/**
	 * Draws the open group and focuses the control drawn under focusKey, so a change redraws without losing the place.
	 *
	 * @param {string|null} focusKey key of the control to focus, or null to leave the focus alone
	 */
	function render(focusKey = null) {
		const controls = new Map()
		const keyed = (key, node) => { controls.set(key, node); return node }
		const editable = !busy && current.trusted === true
		const name = shownName ?? current.groupId
		const heading = keyed('heading', el('h3', { tabIndex: -1, textContent: name }))
		heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
		const stats = current.counts
		const members = stats.members === null || stats.members === undefined
			? t('mcp', 'Unknown number of members')
			: n('mcp', '%n member', '%n members', stats.members)
		const head = el('div', { className: 'mcp-group-head' }, [heading])
		if (name !== current.groupId) head.append(el('span', { className: 'mcp-hint', textContent: t('mcp', 'ID: {id}', { id: current.groupId }, undefined, RAW) }))
		head.append(el('span', { className: 'mcp-hint', textContent: members }))
		const clear = el('button', { type: 'button', className: 'mcp-group-clear', textContent: t('mcp', 'Preview clearing group policy'), disabled: busy || grantKeys(current.grants).length === 0, onclick: () => {
			if (!discardDraft()) return
			draft = {}
			run('preview')
		} })
		head.append(clear)
		const confirm = el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: !editable, onclick: () => {
			if (!discardDraft()) return
			draft = copyGrants(current.grants)
			run('preview')
		} })
		confirm.hidden = !['needs_confirm', 'pending_delete'].includes(current.state)
		// The group's notices live in its detail, under the title, with the action they ask for.
		const reason = diagnosis(current)
		const note = el('div', { className: 'mcp-note mcp-group-note', hidden: reason === '' }, [el('p', { id: 'mcp-group-diagnosis', textContent: reason }), confirm])

		const operationsSwitch = keyed('operations', el('input', { type: 'checkbox', id: 'mcp-group-operations', checked: advanced }))
		operationsSwitch.setAttribute('role', 'switch')
		operationsSwitch.addEventListener('change', () => { advanced = operationsSwitch.checked; render('operations') })
		const toolbar = el('div', { className: 'mcp-group-toolbar' }, [el('label', { htmlFor: 'mcp-group-operations' }, [operationsSwitch, t('mcp', 'Operations')])])

		const fields = el('fieldset', { disabled: !editable })
		fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		fields.append(el('legend', { className: 'mcp-sr-only', textContent: t('mcp', 'Group grants') }))
		const eligible = keyed('eligible', el('input', { type: 'checkbox', name: 'mcp-group-eligible', id: 'mcp-group-eligible', checked: operationsIn(draft, '_system').includes('eligible') }))
		eligible.setAttribute('role', 'switch')
		eligible.addEventListener('change', () => setOperations('_system', eligible.checked ? ['eligible'] : [], 'eligible'))
		fields.append(el('div', { className: 'mcp-group-row' }, [el('label', { htmlFor: 'mcp-group-eligible', className: 'mcp-group-module' }, [t('mcp', 'Can connect')]), el('span', {}, [eligible]), el('span')]))
		for (const [module, operations] of modules()) {
			const label = el('span', { className: 'mcp-group-module', id: 'mcp-group-module-' + module, textContent: moduleName(module) })
			const granted = operationsIn(draft, module)
			const level = levelIn(draft, module)
			let control
			if (advanced) {
				control = el('span', { className: 'mcp-group-operations' }, operations.map((operation) => {
					const box = keyed('op:' + module + ':' + operation, el('input', { type: 'checkbox', name: 'mcp-group-op-' + module, value: operation, checked: granted.includes(operation) }))
					box.setAttribute('aria-label', moduleName(module) + ': ' + operationName(operation))
					box.addEventListener('change', () => {
						const values = new Set(granted)
						box.checked ? values.add(operation) : values.delete(operation)
						setOperations(module, [...values], 'op:' + module + ':' + operation)
					})
					return el('label', {}, [box, operationName(operation)])
				}))
			} else {
				control = el('span', { className: 'mcp-group-levels' }, window.McpAdminLevels.levels(module, operations).map((choice) => keyed('level:' + module + ':' + choice,
					levelButton(module, choice, choice === level, () => setOperations(module, window.McpAdminLevels.operationsFor(module, operations, choice), 'level:' + module + ':' + choice)))))
			}
			control.setAttribute('role', 'group')
			control.setAttribute('aria-labelledby', 'mcp-group-module-' + module)
			let hint = ''
			if (level === 'none') hint = t('mcp', 'Default: {level}', { level: defaultLevel(module) === 'none' ? t('mcp', 'None') : levelName(defaultLevel(module)) }, undefined, RAW)
			else if (level === 'custom' && !advanced) hint = t('mcp', 'Custom: change it in Operations')
			fields.append(el('div', { className: 'mcp-group-row' }, [label, control, el('span', { className: 'mcp-hint', textContent: hint })]))
		}

		// One sentence and a link instead of a table of counters: who wins over this group, and where to see them.
		const overrides = stats.overrides
		const exceptions = el('p', { className: 'mcp-group-exceptions' }, [overrides === null || overrides === undefined
			? t('mcp', 'The members with individual settings could not be counted.')
			: overrides === 0 ? t('mcp', 'No member has individual settings that take precedence over this group.')
				: n('mcp', '%n member has individual settings that take precedence over this group.', '%n members have individual settings that take precedence over this group.', overrides)])
		if (overrides !== 0) exceptions.append(el('button', { type: 'button', className: 'mcp-link-button', textContent: t('mcp', 'View in the matrix'), onclick: () => viewInMatrix(current.groupId, name) }))

		const parts = [head, note, toolbar, fields, exceptions]
		const save = keyed('save', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Save group policy'), disabled: busy || !preview, onclick: () => run('save') }))
		const review = keyed('review', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Review and save'), disabled: !editable, onclick: () => run('preview') }))
		for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		if (preview) {
			// The review of the preview the server returned: what changes, who is affected, and the save it allows.
			const region = el('div', { className: 'mcp-group-review' })
			region.setAttribute('role', 'region')
			region.setAttribute('aria-labelledby', 'mcp-group-review-title')
			const lines = changes(preview.current || current.grants, preview.grants || draft)
			region.append(el('h4', { id: 'mcp-group-review-title', textContent: t('mcp', 'Review the changes to {group}', { group: name }, undefined, RAW) }),
				lines.length ? el('ul', {}, lines.map((line) => el('li', { textContent: line }))) : el('p', { textContent: t('mcp', 'No change to the saved policy.') }),
				el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }),
				el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: count(preview.members), revokes: count(preview.revokes) }) }))
			if (preview.withdraws) region.append(el('p', { className: 'mcp-group-warning', textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
			const back = el('button', { type: 'button', textContent: t('mcp', 'Back'), disabled: busy, onclick: () => { preview = null; render('review') } })
			region.append(el('div', { className: 'mcp-group-actions' }, [back, save]))
			parts.push(region)
		} else if (dirty()) {
			const lines = changes(current.grants, draft)
			const bar = el('div', { className: 'mcp-group-savebar' }, [
				el('div', { className: 'mcp-group-savebar-text' }, [el('strong', { textContent: n('mcp', '%n unsaved change', '%n unsaved changes', lines.length) }), el('span', { className: 'mcp-hint', textContent: lines.join('; ') })]),
				el('button', { type: 'button', textContent: t('mcp', 'Discard'), disabled: busy, onclick: () => { draft = copyGrants(current.grants); render('heading') } }),
				review,
			])
			parts.push(bar)
		}
		detail.replaceChildren(...parts)
		if (focusKey !== null) (controls.get(focusKey) || heading).focus()
	}
	async function run(action) {
		if (busy || !current || (action === 'save' && !preview)) return
		busy = true
		const gid = current.groupId
		const body = { gid, grants: structuredClone(draft), expectedRevision: action === 'save' ? preview.revision : current.revision }
		if (action === 'save') body.previewId = preview.previewId
		render()
		root.setAttribute('aria-busy', 'true')
		let refresh = false
		try {
			const data = await api(action === 'save' ? 'PUT' : 'POST', '/api/group-grants' + (action === 'save' ? '' : '/preview'), body)
			if (action === 'preview') {
				preview = data
				say(t('mcp', 'Review the impact, then save the group policy.'))
			} else {
				preview = null
				refresh = true
				say(t('mcp', 'Saved'))
				window.dispatchEvent(new Event('mcp-group-policy-saved'))
			}
		} catch (e) {
			preview = null
			if (e.message === '409' || e.message === '428') {
				// A fresh read pairs the revision with the stored policy. Never adopt an error revision or replay.
				refresh = true
				say(t('mcp', 'The policy or group members changed. Your draft is kept. Review a new preview before saving again.'), true)
			} else if (e.message === '503') {
				refresh = true
				say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
			} else if (e.message === '422') {
				refresh = true
				say(untrusted(), true)
			} else {
				say(t('mcp', 'Could not save the group policy. Your draft is kept. Request a new preview and retry.'), true)
			}
		} finally {
			busy = false
			root.setAttribute('aria-busy', 'false')
			if (refresh) await open(gid, true, true)
			else render(action === 'preview' && preview ? 'save' : 'heading')
		}
	}
	search.addEventListener('input', () => {
		clearTimeout(timer)
		timer = setTimeout(() => { page = 1; loadList() }, 300)
	})
	prev.addEventListener('click', () => { page = Math.max(1, page - 1); loadList() })
	next.addEventListener('click', () => { page++; loadList() })
	loadList()
})()
