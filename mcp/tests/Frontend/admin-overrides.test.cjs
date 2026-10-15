/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of js/admin-overrides.js (T3 UI side, T4, T16) with Node's built-in runner and a minimal DOM. The
 * script runs unchanged against a stub of window.McpAdminMatrix, the surface js/admin-grants.js exposes.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag, document) {
		this.tag = tag
		this.document = document
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.classes = new Set()
		this.hidden = false
		this.disabled = false
		this.checked = false
		this.indeterminate = false
		this.title = ''
		this.parent = null
		this.isConnected = true
		this._text = ''
	}
	get classList() {
		return { add: (...names) => names.forEach((name) => this.classes.add(name)), remove: (...names) => names.forEach((name) => this.classes.delete(name)), contains: (name) => this.classes.has(name) }
	}
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	append(...children) {
		for (const child of children) {
			if (typeof child !== 'string') child.parent = this
			this.children.push(child)
		}
	}
	replaceChildren(...children) {
		for (const child of this.children) if (typeof child !== 'string') child.isConnected = false
		this.children = []
		this._text = ''
		this.append(...children)
	}
	before(node) { this.inserted = (this.inserted || []).concat(node) }
	closest(selector) { return this.closestResult?.[selector] ?? null }
	setAttribute(key, value) { this.attributes[key] = value }
	getAttribute(key) { return this.attributes[key] ?? null }
	addEventListener(name, action) { (this.listeners[name] ||= []).push(action) }
	async fire(name, event = {}) { for (const action of this.listeners[name] || []) await action(event) }
	focus() { this.document.activeElement = this }
	set textContent(value) { this._text = String(value); this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	matches(selector) {
		const [tag, ...classes] = selector.split('.')
		return (tag === '' || tag === this.tag) && classes.every((name) => this.classes.has(name))
	}
	querySelectorAll(selector) {
		return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)])
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null }
	async click() {
		if (this.disabled) return
		await this.onclick?.()
		await this.fire('click')
	}
}

/** Objects built inside the script's realm compare by value, not by prototype. */
const plain = (value) => JSON.parse(JSON.stringify(value))
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const REVISION = '9007199254740993'
const source = fs.readFileSync(path.join(__dirname, '../../js/admin-overrides.js'), 'utf8')

const origin = (kind, groupIds = []) => ({ source: kind, groupIds })
/** A matrix row of the API: `overrides` maps "module:operation" ("eligible" for Can connect) to a source. */
function user(uid, overrides = {}) {
	const grants = { notes: {}, files: {} }
	for (const [module, ops] of Object.entries({ notes: ['read', 'edit'], files: ['read'] })) {
		for (const op of ops) grants[module][op] = origin(overrides[module + ':' + op] || 'default')
	}
	return { uid, displayName: uid[0].toUpperCase() + uid.slice(1), enabled: true, eligible: true, connected: true, origins: { eligible: origin(overrides.eligible || 'group', ['staff']), grants } }
}

/**
 * Loads the script against a stub of the matrix. `answer` receives {method, path, body} and returns [status, body].
 */
function app(users, answer = () => [200, { revision: '9007199254740994', changed: 1, receiptId: 'receipt-1' }], options = {}) {
	const document = { activeElement: null }
	const wrap = new Element('div', document)
	const table = new Element('table', document)
	// By default the table sits in its wrapper; options.wrapper false drops it, options.detached drops the anchor itself.
	table.closestResult = options.wrapper === false ? {} : { '.mcp-matrix-wrap': wrap }
	if (options.wrapper === false) table.before = (node) => { table.inserted = (table.inserted || []).concat(node) }
	if (options.detached) { table.isConnected = false; wrap.isConnected = false }
	document.getElementById = (id) => id === 'mcp-matrix' ? table : null
	document.createElement = (tag) => new Element(tag, document)
	const timers = new Map()
	let timerId = 0
	const listeners = {}
	const log = { calls: [], notices: [], reloads: 0, busy: [], policyErrors: [], undoFocus: 0 }
	const data = { revision: REVISION, users, catalog: { notes: ['read', 'edit'], files: ['read'] } }
	const window = {
		addEventListener: (name, action) => { (listeners[name] ||= []).push(action) },
	}
	const matrix = {
		data: () => data,
		api: async (method, apiPath, body) => {
			log.calls.push({ method, path: apiPath, body })
			const [status, result] = answer({ method, path: apiPath, body })
			if (status >= 400) throw Object.assign(new Error(String(status)), { data: result })
			return result
		},
		reload: async () => { log.reloads++; render() },
		policyError: async (e) => {
			log.policyErrors.push(e.message)
			if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data?.uncertain)) { log.reloads++; render(); return true }
			return false
		},
		isBusy: () => false,
		setBusy: (on) => log.busy.push(on),
		labels: () => ({ modules: { notes: 'Notes', files: 'Files' }, operations: { read: 'read', edit: 'edit' } }),
		offerUndo: (message, saved) => { const undo = () => {}; log.notices.push({ message, saved, undo }); return undo },
		focusUndo: (undo) => { if (undo !== null) log.undoFocus++ },
		refresh: () => render(),
	}
	/** What render() does in admin-grants.js: redraw every row, then announce it. */
	const rows = new Map()
	function render() {
		// The rows of the old table leave the page: their controls are no longer connected.
		const detach = (node) => { node.isConnected = false; for (const child of node.children) if (typeof child !== 'string') detach(child) }
		rows.forEach(detach)
		rows.clear()
		for (const row of data.users) {
			const tr = new Element('tr', document)
			tr.append(new Element('th', document))
			window.McpAdminOverrides.decorateRow(tr, row)
			rows.set(row.uid, tr)
		}
		for (const action of listeners['mcp-matrix-rendered'] || []) action()
	}
	const context = {
		document, window,
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		n: (_, singular, plural, count, params = {}) => (count === 1 ? singular : plural).replace('%n', String(count)).replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		setTimeout: (fn) => { timers.set(++timerId, fn); return timerId },
		clearTimeout: (id) => { timers.delete(id) },
	}
	window.McpAdminMatrix = matrix
	vm.runInNewContext(source, context)
	const toolbar = (wrap.inserted || table.inserted || [])[0]
	if (!toolbar) return { window, log, toolbar: null }
	const find = (selector) => toolbar.querySelector(selector)
	const ui = {
		document, data, log, rows, timers, toolbar, window, listeners, table,
		status: find('p.mcp-reset-status'),
		alert: find('p.mcp-reset-alert'),
		panel: find('div.mcp-reset-preview'),
		selectAll: find('input'),
		resetSelected: find('button.mcp-reset-selected'),
		flush: () => { for (const [id, action] of [...timers]) { timers.delete(id); action() } },
		rowBox: (uid) => rows.get(uid).querySelector('input.mcp-row-select'),
		rowButton: (uid) => rows.get(uid).querySelector('button.mcp-reset-row'),
		panelButton: (name) => ui.panel.querySelector('button.mcp-reset-' + name),
		commands: () => log.calls.filter((call) => call.path === '/api/grants/commands'),
	}
	return ui
}

const alice = () => user('alice', { eligible: 'individual_allow', 'notes:edit': 'individual_deny', 'files:read': 'invalid' })
const bob = () => user('bob', { 'notes:read': 'individual_allow' })
const carol = () => user('carol')

test('T16: the user reset sends one inherit command per overridden cell, eligibility included, and nothing else', async () => {
	const ui = app([alice(), bob(), carol()])
	assert.equal(ui.rowButton('carol'), null, 'a user without overrides has nothing to reset')
	await ui.rowButton('alice').click()
	assert.equal(ui.panel.hidden, false)
	assert.equal(ui.commands().length, 0, 'the preview writes nothing')
	await ui.panelButton('confirm').click()
	await tick()
	const [call] = ui.commands()
	assert.equal(ui.commands().length, 1)
	assert.equal(call.body.expectedRevision, REVISION)
	assert.deepEqual(plain(call.body.commands), [
		{ uid: 'alice', module: null, operation: 'eligible', state: 'inherit' },
		{ uid: 'alice', module: 'notes', operation: 'edit', state: 'inherit' },
		{ uid: 'alice', module: 'files', operation: 'read', state: 'inherit' },
	])
})

test('T16: the selected users are reset together; users without overrides are named and left out', async () => {
	const ui = app([alice(), bob(), carol()])
	await ui.rowBox('alice').fire('change')
	ui.rowBox('alice').checked = true
	await ui.rowBox('alice').fire('change')
	ui.rowBox('carol').checked = true
	await ui.rowBox('carol').fire('change')
	assert.match(ui.resetSelected.textContent, /\(2\)/)
	await ui.resetSelected.click()
	assert.match(ui.panel.textContent, /Back to group inheritance for 1 users?/)
	assert.match(ui.panel.textContent, /1 selected user has no individual overrides and is left out/)
	assert.match(ui.panel.textContent, /Alice: Can connect \(allowed\), Notes: edit \(denied\), Files: read \(invalid, denied\)/)
	assert.doesNotMatch(ui.panel.textContent, /Carol:/)
	await ui.panelButton('confirm').click()
	await tick()
	assert.deepEqual(plain([...new Set(ui.commands()[0].body.commands.map((command) => command.uid))]), ['alice'])
})

test('T16: select-all covers the page and a reload keeps only users that are still on it', async () => {
	const ui = app([alice(), bob(), carol()])
	ui.selectAll.checked = true
	await ui.selectAll.fire('change')
	assert.match(ui.resetSelected.textContent, /\(3\)/)
	ui.data.users = [bob()]
	for (const action of ui.listeners['mcp-matrix-rendered']) action()
	assert.match(ui.resetSelected.textContent, /\(1\)/)
	assert.equal(ui.resetSelected.disabled, false)
})

test('T16/F2: the preview never says “will be signed out”; Allow of Can connect says “may”, other cells say nothing', async () => {
	const ui = app([alice(), bob()])
	await ui.rowButton('alice').click()
	assert.match(ui.panel.textContent, /may be signed out of their MCP clients, and undoing this does not sign them back in/)
	assert.doesNotMatch(ui.panel.textContent, /will be signed out|will lose access|disconnected/)
	await ui.panelButton('cancel').click()
	await ui.rowButton('bob').click()
	assert.doesNotMatch(ui.panel.textContent, /signed out/)
})

test('T16/F2: removing a Deny of Can connect never mentions a sign-out, only that access may come back', async () => {
	const ui = app([user('dave', { eligible: 'individual_deny' })])
	await ui.rowButton('dave').click()
	assert.doesNotMatch(ui.panel.textContent, /signed out/)
	assert.match(ui.panel.textContent, /Removing an individual Deny for “Can connect” lets the groups decide again/)
})

test('T4: the result is told only from the server answer, and the undo is the server receipt', async () => {
	const ui = app([alice()], () => [200, { revision: '9007199254740994', changed: 3, receiptId: 'receipt-1' }])
	await ui.rowButton('alice').click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.log.notices.length, 1)
	assert.match(ui.log.notices[0].message, /3 individual overrides removed/)
	assert.deepEqual(plain(ui.log.notices[0].saved), { revision: '9007199254740994', changed: 3, receiptId: 'receipt-1' })
	assert.equal(ui.log.reloads, 1)
	assert.equal(ui.log.undoFocus, 1)
	assert.equal(ui.panel.hidden, true)
	assert.deepEqual(ui.log.busy, [true, false])
})

test('F2: a 503 without uncertainty writes nothing: the preview and selection stay, no “removed” notice, and a retry is a click', async () => {
	let failing = true
	const ui = app([alice(), bob()], () => failing ? [503, { error: 'Unavailable', uncertain: false }] : [200, { revision: '9007199254740994', changed: 3, receiptId: 'receipt-2' }])
	ui.rowBox('alice').checked = true
	await ui.rowBox('alice').fire('change')
	await ui.resetSelected.click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.log.notices.length, 0, 'never reported as reset')
	assert.equal(ui.log.reloads, 0, 'the table is not redrawn as if something changed')
	assert.equal(ui.panel.hidden, false)
	assert.equal(ui.panelButton('confirm').textContent, 'Try again')
	assert.equal(ui.panelButton('confirm').disabled, false)
	assert.equal(ui.document.activeElement, ui.panelButton('confirm'))
	ui.flush()
	assert.match(ui.alert.textContent, /Nothing was changed, and your selection is kept/)
	assert.match(ui.resetSelected.textContent, /\(1\)/)
	assert.equal(ui.commands().length, 1, 'no automatic repeat')
	failing = false
	await ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.commands().length, 2)
	assert.deepEqual(plain(ui.commands()[1].body), plain(ui.commands()[0].body), 'the retry sends the same command')
	assert.equal(ui.log.notices.length, 1)
	assert.equal(ui.log.notices[0].saved.receiptId, 'receipt-2')
})

test('T4/T16: a 409 reloads once through the matrix handler and never repeats the write', async () => {
	const ui = app([alice()], () => [409, { error: 'Conflict', revision: '42' }])
	await ui.rowButton('alice').click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.commands().length, 1)
	assert.deepEqual(plain(ui.log.policyErrors), ['409'])
	assert.equal(ui.log.notices.length, 0)
	assert.equal(ui.panel.hidden, true)
	assert.equal(ui.log.reloads, 1)
})

test('T4: an uncertain 503 is the matrix’s reload path, not a retry offer', async () => {
	const ui = app([alice()], () => [503, { error: 'Unavailable', uncertain: true }])
	await ui.rowButton('alice').click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.deepEqual(plain(ui.log.policyErrors), ['503'])
	assert.equal(ui.panel.hidden, true)
	assert.equal(ui.log.notices.length, 0)
})

test('T16: any other failure reloads the saved values and says nothing was reset', async () => {
	const ui = app([alice()], () => [400, { error: 'Invalid request' }])
	await ui.rowButton('alice').click()
	await ui.panelButton('confirm').click()
	await tick()
	ui.flush()
	assert.equal(ui.log.reloads, 1)
	assert.match(ui.alert.textContent, /Could not return the users to group inheritance/)
	assert.equal(ui.log.notices.length, 0)
})

test('T4/T16: a cell reset targets exactly that cell and an inherited cell has no reset button', async () => {
	const ui = app([alice()])
	const decorate = (module, operation, rowUser = alice()) => {
		const cell = new Element('td', ui.document)
		const box = new Element('input', ui.document)
		box.setAttribute('aria-label', 'Alice — ' + operation)
		cell.title = 'Allowed'
		cell.append(box)
		ui.window.McpAdminOverrides.decorateCell(cell, box, rowUser, module, operation)
		return { cell, box }
	}
	const denied = decorate('notes', 'edit')
	assert.match(denied.box.getAttribute('aria-label'), /Individual override: denied/)
	assert.ok(denied.cell.classes.has('mcp-override-deny'))
	const reset = denied.cell.querySelector('button.mcp-reset-cell')
	assert.match(reset.getAttribute('aria-label'), /Back to inherited: Alice — Notes: edit/)
	const inherited = decorate('notes', 'read')
	assert.match(inherited.box.getAttribute('aria-label'), /Inherited$/)
	assert.equal(inherited.cell.querySelector('button.mcp-reset-cell'), null)
	assert.equal(decorate('files', 'read').cell.classes.has('mcp-override-invalid'), true)
	await reset.click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.deepEqual(plain(ui.commands()[0].body.commands), [{ uid: 'alice', module: 'notes', operation: 'edit', state: 'inherit' }])
})

test('T17: unknown and inherited cells are never reset targets', () => {
	const unknown = user('erin')
	unknown.origins.grants.notes.read = origin('unknown', ['broken'])
	unknown.origins.eligible = origin('unknown', ['broken'])
	const ui = app([unknown])
	const result = ui.window.McpAdminOverrides.plan([unknown], null)
	assert.equal(result.cells, 0)
	assert.equal(result.without, 1)
	assert.equal(ui.rowButton('erin'), null)
})

test('T3: more cells or users than one command accepts is refused without a request', async () => {
	const many = Array.from({ length: 51 }, (_, i) => user('user' + i, { 'notes:read': 'individual_allow' }))
	const ui = app(many)
	ui.selectAll.checked = true
	await ui.selectAll.fire('change')
	await ui.resetSelected.click()
	ui.flush()
	assert.equal(ui.panel.hidden, true)
	assert.match(ui.alert.textContent, /Too many overrides to reset at once/)
	assert.equal(ui.log.calls.length, 0)
})

test('T17: regions are fixed (status polite, alert assertive), nothing is announced in the clearing task, and repeats are announced again', async () => {
	const ui = app([carol()], () => [503, { error: 'Unavailable' }])
	assert.equal(ui.status.getAttribute('role'), 'status')
	assert.equal(ui.status.getAttribute('aria-live'), 'polite')
	assert.equal(ui.alert.getAttribute('role'), 'alert')
	assert.equal(ui.alert.getAttribute('aria-live'), 'assertive')
	await ui.resetSelected.click()
	ui.resetSelected.disabled = false
	await ui.resetSelected.click()
	assert.equal(ui.alert.textContent, '', 'cleared first')
	assert.equal(ui.status.textContent, '')
	ui.flush()
	assert.match(ui.status.textContent, /Nothing to reset/)
	assert.equal(ui.status.getAttribute('role'), 'status')
	assert.equal(ui.alert.getAttribute('role'), 'alert')
})

test('T17: an identical error twice in a row is cleared then written again', async () => {
	const many = Array.from({ length: 51 }, (_, i) => user('user' + i, { 'notes:read': 'individual_allow' }))
	const ui = app(many)
	ui.selectAll.checked = true
	await ui.selectAll.fire('change')
	await ui.resetSelected.click()
	ui.flush()
	const first = ui.alert.textContent
	assert.match(first, /Too many/)
	await ui.resetSelected.click()
	assert.equal(ui.alert.textContent, '', 'the old identical message is gone before the repeat is written')
	ui.flush()
	assert.equal(ui.alert.textContent, first)
})

test('T17: focus goes to the preview heading, returns to the control on cancel and Escape, and the heading is reachable', async () => {
	const ui = app([alice()])
	const trigger = ui.rowButton('alice')
	await trigger.click()
	const heading = ui.panel.querySelector('h3')
	assert.equal(ui.document.activeElement, heading)
	assert.equal(heading.tabIndex, -1)
	await ui.panelButton('cancel').click()
	assert.equal(ui.document.activeElement, trigger)
	assert.equal(ui.panel.hidden, true)
	await trigger.click()
	await ui.panel.fire('keydown', { key: 'Escape' })
	assert.equal(ui.panel.hidden, true)
	assert.equal(ui.document.activeElement, trigger)
	assert.equal(ui.commands().length, 0)
})

test('T16: a preview built from a table that was redrawn is closed instead of acting on it', async () => {
	const ui = app([alice()])
	await ui.rowButton('alice').click()
	for (const action of ui.listeners['mcp-matrix-rendered']) action()
	assert.equal(ui.panel.hidden, true)
	ui.flush()
	assert.match(ui.status.textContent, /The table changed, so the reset preview was closed/)
	assert.equal(ui.commands().length, 0)
})

test('T17: the select boxes and buttons have accessible names that carry the user', () => {
	const ui = app([alice()])
	assert.equal(ui.rowBox('alice').getAttribute('aria-label'), 'Select Alice')
	assert.equal(ui.rowButton('alice').getAttribute('aria-label'), 'Back to group inheritance: Alice')
	assert.equal(ui.toolbar.getAttribute('role'), 'group')
	assert.equal(ui.toolbar.getAttribute('aria-label'), 'Individual overrides')
})

test('T17: the list of exact targets scrolls, so it is a named region that the keyboard can focus', async () => {
	const ui = app([alice()])
	await ui.rowButton('alice').click()
	const list = ui.panel.querySelector('ul.mcp-reset-targets')
	assert.equal(list.tabIndex, 0)
	assert.equal(list.getAttribute('aria-label'), 'Individual overrides')
})

test('T16: while a change is saving the controls are locked and a second reset is refused', async () => {
	let release
	const ui = app([alice(), bob()], () => [200, { revision: '2', changed: 1, receiptId: null }])
	const original = ui.window.McpAdminMatrix.api
	ui.window.McpAdminMatrix.api = (...args) => new Promise((resolve) => { release = () => resolve(original(...args)) })
	await ui.rowButton('alice').click()
	const pending = ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.resetSelected.disabled, true)
	await ui.panelButton('confirm')?.click()
	release()
	await pending
	await tick()
	assert.equal(ui.log.notices.length, 1)
})

test('O1: undoing a 503 without uncertainty keeps the table, offers the same undo again and does not reload', async () => {
	const source = fs.readFileSync(path.join(__dirname, '../../js/admin-grants.js'), 'utf8')
	const start = source.indexOf('\tasync function undoBulk(')
	const end = source.indexOf('\n\t}\n', start) + 4
	const notices = []
	let reloads = 0
	const toasts = []
	const calls = []
	let failing = true
	const context = {
		t: (_, text) => text,
		setBulkBusy: () => {},
		api: async (method, apiPath, body) => {
			calls.push({ method, apiPath, body })
			if (failing) throw Object.assign(new Error('503'), { data: { error: 'Unavailable', uncertain: false } })
			return { revision: '9', changed: 1 }
		},
		showNotice: (message, undo) => notices.push({ message, undo }),
		notifyError: (message) => toasts.push(message),
		reloadLocked: async () => { reloads++ },
	}
	vm.runInNewContext(source.slice(start, end) + '\nthis.undoBulk = undoBulk', context)
	await context.undoBulk('receipt-1', '7')
	assert.equal(reloads, 0)
	assert.equal(notices.length, 1)
	assert.match(notices[0].message, /Nothing was changed\. Try again\./)
	assert.equal(typeof notices[0].undo, 'function', 'the undo is offered again')
	assert.match(toasts[0], /could not be undone/)
	failing = false
	await notices[0].undo()
	assert.deepEqual(plain(calls.map((call) => call.body)), [{ receiptId: 'receipt-1', expectedRevision: '7' }, { receiptId: 'receipt-1', expectedRevision: '7' }])
	assert.equal(notices.at(-1).message, 'Change undone.')
})

test('B1: after a generic error or a conflict the focus lands on a control of the toolbar, never on the page', async () => {
	for (const failure of [[400, { error: 'Invalid request' }], [409, { error: 'Conflict' }], [503, { error: 'Unavailable', uncertain: true }]]) {
		const ui = app([alice()], () => failure)
		await ui.rowButton('alice').click()
		await ui.panelButton('confirm').click()
		await tick()
		assert.equal(ui.panel.hidden, true, String(failure[0]))
		assert.ok(ui.document.activeElement, String(failure[0]) + ': the focus must not fall back to the body')
		assert.equal(ui.document.activeElement, ui.selectAll, 'the old row button is gone, nothing is selected, so the select-all box takes it')
		assert.equal(ui.commands().length, 1, 'never repeated')
	}
})

test('B1: with no user on the page the toolbar itself takes the focus', async () => {
	const ui = app([alice()], () => [400, {}])
	await ui.rowButton('alice').click()
	ui.data.users = []
	await ui.panelButton('confirm').click()
	await tick()
	assert.equal(ui.document.activeElement, ui.toolbar)
	assert.equal(ui.toolbar.tabIndex, -1)
})

test('B2: an unavailable cell with an override shows its state and keeps its reset, and one without says nothing more', async () => {
	const ui = app([alice()])
	const cell = (rowUser, module, operation) => {
		const td = new Element('td', ui.document)
		td.title = 'Unavailable: This app is unavailable for this user'
		ui.window.McpAdminOverrides.decorateCell(td, null, rowUser, module, operation)
		return td
	}
	const overridden = cell(alice(), 'notes', 'edit')
	assert.ok(overridden.classes.has('mcp-override-deny'))
	assert.match(overridden.title, /Individual override: denied$/)
	assert.match(overridden.querySelector('span.mcp-sr-only').textContent, /Individual override: denied/)
	assert.match(overridden.querySelector('button.mcp-reset-cell').getAttribute('aria-label'), /Back to inherited: Alice — Notes: edit/)
	const inherited = cell(alice(), 'notes', 'read')
	assert.equal(inherited.querySelector('button.mcp-reset-cell'), null)
	assert.equal(inherited.querySelector('span.mcp-sr-only'), null)
	assert.equal(inherited.title, 'Unavailable: This app is unavailable for this user')
	await overridden.querySelector('button.mcp-reset-cell').click()
	await ui.panelButton('confirm').click()
	await tick()
	assert.deepEqual(plain(ui.commands()[0].body.commands), [{ uid: 'alice', module: 'notes', operation: 'edit', state: 'inherit' }])
})

test('B2: the row preview says that overrides in cells the table does not offer are removed too', async () => {
	const hidden = alice()
	hidden.appsEnabled = { notes: false, files: true }
	const ui = app([hidden, bob()])
	await ui.rowButton('alice').click()
	assert.match(ui.panel.textContent, /1 of these overrides is in a permission the table does not offer for that user/)
	assert.match(ui.panel.textContent, /It is removed too/)
	assert.match(ui.panel.textContent, /Notes: edit \(denied\)/, 'the override is listed like any other')
	await ui.panelButton('cancel').click()
	const disabled = bob()
	disabled.enabled = false
	const other = app([disabled])
	assert.equal(other.window.McpAdminOverrides.plan([disabled], null).unseen, 1)
	const notConnecting = alice()
	notConnecting.eligible = false
	assert.equal(other.window.McpAdminOverrides.plan([notConnecting], null).unseen, 2, 'the cells of a user who may not connect are not offered; "Can connect" itself is')
	const noColumn = bob()
	other.data.catalog = { files: ['read'] }
	assert.equal(other.window.McpAdminOverrides.plan([noColumn], null).unseen, 1, 'a hidden column counts too')
})

test('B3: an invalid stored value of “Can connect” gets the same “may give access” warning as a Deny', async () => {
	const ui = app([user('frank', { eligible: 'invalid' })])
	await ui.rowButton('frank').click()
	assert.match(ui.panel.textContent, /Can connect \(invalid, denied\)/)
	assert.match(ui.panel.textContent, /Removing an individual Deny for “Can connect” lets the groups decide again, which may give access/)
	assert.doesNotMatch(ui.panel.textContent, /signed out/)
})

test('B4: without the matrix wrapper the toolbar goes before the table; with nothing attached nothing is installed', async () => {
	const ui = app([alice()], undefined, { wrapper: false })
	assert.ok(ui.toolbar, 'the toolbar is in the page, before the table')
	assert.equal(ui.table.inserted[0], ui.toolbar)
	await ui.rowButton('alice').click()
	assert.equal(ui.panel.hidden, false)
	const detached = app([alice()], undefined, { detached: true })
	assert.equal(detached.toolbar, null)
	assert.equal(detached.window.McpAdminOverrides, undefined, 'no hooks: the matrix keeps working without the reset')
	assert.equal(detached.log.reloads, 0)
})
