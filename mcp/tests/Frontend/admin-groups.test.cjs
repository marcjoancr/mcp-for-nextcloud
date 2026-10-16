/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of the shipped script, using only Node's built-in test runner and a minimal DOM. */
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
		this.value = ''
		this.disabled = false
		this._text = ''
	}
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = children; this._text = '' }
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	focus() { this.document.activeElement = this }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
	querySelector(selector) {
		if (selector === 'button:last-child') return this.children.at(-1)
		return this.all(selector)[0]
	}
	async click() { if (!this.disabled) await this.onclick?.() }
}
/** Announcement timers of the app under test, run by tick() but not by settle(), so a test can see the cleared region. */
let flushTimers = () => {}
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const tick = async () => { for (let i = 0; i < 12; i++) { await new Promise(setImmediate); flushTimers() } }
const stored = (extra = {}) => ({
	groupId: 'staff', trusted: true, exists: true, state: 'active', grants: [], revision: '9007199254740993',
	catalog: { _system: ['eligible'], notes: ['read', 'edit'] },
	counts: { members: null, overrides: null, permissions: null }, ...extra,
})
async function app(handler, group = stored()) {
	const document = { activeElement: null }
	const ids = Object.fromEntries(['editor', 'search', 'count', 'list', 'detail', 'status', 'alert', 'prev', 'next'].map((id) => ['mcp-group-' + id, new Element('div', document)]))
	const template = fs.readFileSync(path.join(__dirname, '../../templates/admin.php'), 'utf8')
	for (const id of ['status', 'alert']) {
		const markup = template.match(new RegExp('<p id="mcp-group-' + id + '"[^>]+>'))[0]
		for (const name of ['role', 'aria-live']) ids['mcp-group-' + id].attributes[name] = markup.match(new RegExp(name + '="([^"]+)"'))[1]
	}
	document.getElementById = (id) => ids[id]
	document.createElement = (tag) => new Element(tag, document)
	const calls = []
	const events = []
	const confirmations = { allow: true, messages: [] }
	// The search debounce (300 ms) runs at once; every other timer (the live-region announcements) waits for a flush.
	const timers = new Map()
	let timerId = 1000
	flushTimers = () => { for (const [id, action] of [...timers]) { timers.delete(id); action() } }
	const context = {
		document, URLSearchParams, structuredClone, Event,
		window: { dispatchEvent: (event) => events.push(event.type), confirm: (message) => { confirmations.messages.push(message); return confirmations.allow } },
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		n: (_, one, many, count, params = {}) => (count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		setTimeout: (fn, ms) => { if (ms === 300) { fn(); return 1 } timers.set(++timerId, fn); return timerId }, clearTimeout: (id) => { timers.delete(id) },
		fetch: async (url, options) => {
			const call = { url, method: options.method, body: options.body ? JSON.parse(options.body) : undefined }
			calls.push(call)
			const result = await handler?.(call)
			const [status, body] = result || [200, url.includes('/detail?') ? group : { groups: [{ displayName: 'Staff', ...group }], hasMore: true }]
			return { ok: status < 400, status, json: async () => { if (body instanceof Error) throw body; return body } }
		},
	}
	vm.createContext(context)
	vm.runInContext(fs.readFileSync(path.join(__dirname, '../../js/admin-levels.js'), 'utf8'), context)
	vm.runInContext(fs.readFileSync(path.join(__dirname, '../../js/admin-groups.js'), 'utf8'), context)
	await tick()
	const open = async () => { await ids['mcp-group-list'].all('button')[0].click(); await tick() }
	const button = (text) => ids['mcp-group-detail'].all('button').find((node) => node.textContent === text)
	const input = (name, value) => ids['mcp-group-detail'].all('input').find((node) => node.name === name && (value === undefined || node.value === value))
	const level = (module, choice) => ids['mcp-group-detail'].all('button').find((node) => node.name === 'mcp-group-level-' + module && node.value === choice)
	const check = (box, checked = true) => { box.checked = checked; box.listeners.change() }
	const operations = () => { const box = ids['mcp-group-detail'].all('input').find((node) => node.id === 'mcp-group-operations'); check(box, !box.checked) }
	return { ids, calls, events, document, open, button, input, level, check, operations, confirmations, timers }
}
const preview = { previewId: 'receipt', revision: '9007199254740993', members: 51, revokes: 2, withdraws: true }

test('T17: unknown counters stay distinct from zero and the heading receives focus', async () => {
	const ui = await app()
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /Unknown number of members/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /members with individual settings could not be counted/)
	assert.doesNotMatch(ui.ids['mcp-group-detail'].textContent, /\b0 members|No member/)
	assert.equal(ui.document.activeElement.tag, 'h3')
	assert.equal(ui.button('Save group policy'), undefined, 'saving is offered only by a preview')
})
test('T16: edits from an empty PHP array produce an object payload; saving requires a preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined)
	await ui.open()
	ui.check(ui.input('mcp-group-eligible'))
	await ui.button('Review and save').click()
	await tick()
	const call = ui.calls.find((call) => call.method === 'POST')
	assert.deepEqual(call.body.grants, { _system: ['eligible'] })
	assert.equal(call.body.expectedRevision, '9007199254740993')
	assert.match(ui.ids['mcp-group-detail'].textContent, /51 current members; 2 credential holders/)
	assert.equal(ui.document.activeElement, ui.button('Save group policy'))
})
test('T16/T17: confirm current policy previews the saved set and PUT carries the preview receipt', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : call.method === 'PUT' ? [200, { revision: '9007199254740994' }] : undefined,
		stored({ state: 'needs_confirm', grants: { notes: ['edit'] } }))
	await ui.open()
	await ui.button('Confirm current policy').click()
	await tick()
	assert.deepEqual(ui.calls.find((call) => call.method === 'POST').body.grants, { notes: ['edit'] })
	await ui.button('Save group policy').click()
	await tick()
	const save = ui.calls.find((call) => call.method === 'PUT')
	assert.equal(save.body.previewId, 'receipt')
	assert.equal(save.body.expectedRevision, preview.revision)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 1)
	assert.deepEqual(ui.events, ['mcp-group-policy-saved'])
})
test('T16: a membership conflict keeps the draft, reloads, and never repeats the write', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : call.method === 'PUT' ? [409, { revision: '42', state: { preview } }] : undefined)
	await ui.open()
	ui.operations()
	ui.check(ui.input('mcp-group-op-notes', 'edit'))
	await ui.button('Review and save').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 1)
	assert.equal(ui.input('mcp-group-op-notes', 'edit').checked, true)
	assert.equal(ui.button('Save group policy'), undefined)
	assert.match(ui.ids['mcp-group-alert'].textContent, /Your draft is kept/)
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
	assert.equal(ui.document.activeElement.attributes['aria-describedby'], 'mcp-group-alert mcp-group-diagnosis')
	assert.match(ui.ids['mcp-group-detail'].textContent, /1 unsaved change/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Notes: Not granted → Custom \(edit\)/)
})
test('T17: non-native and orphaned groups expose diagnosis, disabled grants and a clearing preview', async () => {
	const ui = await app(undefined, stored({ trusted: false, exists: false, grants: { notes: ['edit'] } }))
	await ui.open()
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].disabled, true)
	assert.ok(ui.level('notes', 'read'), 'the levels are drawn inside the disabled fieldset')
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].all('button').includes(ui.level('notes', 'read')), true)
	assert.equal(ui.button('Review and save'), undefined)
	assert.equal(ui.button('Preview clearing group policy').disabled, false)
	assert.match(ui.ids['mcp-group-detail'].textContent, /no longer exists/)
})
test('T17: 503 refuses the save, preserves draft and demands an explicit new preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, { error: 'Unavailable' }] : undefined)
	await ui.open()
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 1)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.level('notes', 'read').attributes['aria-pressed'], 'true', 'the draft survives the refusal')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Retry after checking the group/)
	assert.doesNotMatch(ui.ids['mcp-group-alert'].textContent, /credential holders/)
})
test('T17: group search and pagination use their own endpoint and page state', async () => {
	const ui = await app()
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /page=2/)
	ui.ids['mcp-group-search'].value = 'group 201'
	ui.ids['mcp-group-search'].listeners.input()
	await tick()
	assert.match(ui.calls.at(-1).url, /search=group\+201&page=1/)
})

/** Exercise the matrix's shipped render/planning functions with the same lightweight elements. */
function matrixFunctions() {
	const source = fs.readFileSync(path.join(__dirname, '../../js/admin-grants.js'), 'utf8')
	const document = {}
	const context = {
		t: (_, text) => text, document, window: {},
		el: (tag, props) => Object.assign(new Element(tag, document), props),
		paintState: (cell, state, detail) => { cell.title = state + ': ' + detail },
		reloadLocked: () => {},
		state: { data: { users: [] } },
	}
	for (const name of ['rowUnavailableReason', 'unavailableReason', 'permissionCell', 'bulkPlan']) {
		const start = source.indexOf('\tfunction ' + name + '(')
		const end = source.indexOf('\n\t}', start) + 3
		vm.runInNewContext(source.slice(start, end), context)
	}
	return context
}
test('T10/T17: unknown eligibility is neither denied nor included in a bulk write, with a retry control', () => {
	const matrix = matrixFunctions()
	const user = { displayName: 'Ana', enabled: true, eligible: null, grants: { notes: { edit: null } } }
	assert.equal(matrix.rowUnavailableReason(user), null)
	const cell = matrix.permissionCell(user, null, null, 'Can connect', { eligible: null })
	assert.equal(cell.all('input').length, 0)
	assert.match(cell.textContent, /—/)
	assert.equal(cell.all('button')[0].textContent, 'Retry')
	matrix.state.data.users = [user]
	const plan = matrix.bulkPlan(null, ['eligible'], true)
	assert.equal(plan.changes.length, 0)
	assert.equal(plan.unavailable, 1)
})

test('I2: fixed polite/status and assertive/alert regions survive errors; successful retry clears the old error', async () => {
	let failed = false
	const ui = await app((call) => {
		if (!call.url.includes('/detail?') && !failed) { failed = true; return [503, {}] }
	})
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
	assert.equal(ui.ids['mcp-group-alert'].attributes['aria-live'], 'assertive')
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-status'].attributes['aria-live'], 'polite')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Could not load groups/)
	await ui.ids['mcp-group-list'].all('button')[0].click()
	await tick()
	assert.equal(ui.ids['mcp-group-alert'].textContent, '')
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
})
test('M1/M2: abandoning edits requires confirmation and draft differences are visible before a preview', async () => {
	const ui = await app(undefined, stored({ state: 'needs_confirm', grants: { notes: ['read'] } }))
	await ui.open()
	ui.operations()
	ui.check(ui.input('mcp-group-op-notes', 'edit'))
	assert.match(ui.ids['mcp-group-detail'].textContent, /Notes: Read → Full/)
	ui.confirmations.allow = false
	await ui.button('Confirm current policy').click()
	await ui.button('Preview clearing group policy').click()
	await ui.open()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 0)
	assert.equal(ui.input('mcp-group-op-notes', 'edit').checked, true)
	assert.equal(ui.confirmations.messages.length, 3)
})
test('M3/M8: an HTML 503 retains its status, reloads the policy and gives a generic error', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, new SyntaxError('HTML error page')] : undefined)
	await ui.open()
	await ui.level('notes', 'full').click()
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.url.includes('/detail?')).length, 2)
	assert.match(ui.ids['mcp-group-alert'].textContent, /The change could not be confirmed/)
	assert.equal(ui.button('Save group policy'), undefined)
})
test('M5: group origin is part of the permission checkbox accessible name', () => {
	const matrix = matrixFunctions()
	const user = { displayName: 'Ana', enabled: true, eligible: true, origins: { eligible: { source: 'group', groupIds: ['staff'] } } }
	const cell = matrix.permissionCell(user, null, true, 'Can connect', { eligible: null })
	assert.match(cell.all('input')[0].attributes['aria-label'], /Granted by groups/)
})
test('M6: disabled group grant fields and actions describe their source diagnosis', async () => {
	const ui = await app(undefined, stored({ trusted: false }))
	await ui.open()
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].attributes['aria-describedby'], 'mcp-group-diagnosis')
	assert.equal(ui.button('Preview clearing group policy').attributes['aria-describedby'], 'mcp-group-diagnosis')
	assert.equal(ui.button('Confirm current policy').attributes['aria-describedby'], 'mcp-group-diagnosis')
	const trusted = await app()
	await trusted.open()
	await trusted.level('notes', 'read').click()
	assert.equal(trusted.button('Review and save').attributes['aria-describedby'], 'mcp-group-diagnosis')
})
test('M7: an invalid directory identity has a diagnostic row without an editable replacement ID', async () => {
	const ui = await app(undefined, stored({ groupId: null, invalidId: true, trusted: null }))
	assert.equal(ui.ids['mcp-group-list'].all('button')[0].disabled, true)
	assert.match(ui.ids['mcp-group-list'].textContent, /invalid group ID/)
})

test('O2: an identical error twice in a row is announced twice, and a replaced message never appears', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, { error: 'Unavailable' }] : undefined)
	await ui.open()
	const alert = ui.ids['mcp-group-alert']
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await settle()
	// Cleared at once and written in a later task: nothing is announced in the same task as the clearing.
	assert.equal(alert.textContent, '')
	assert.equal(ui.timers.size, 1)
	flushTimers()
	const first = alert.textContent
	assert.match(first, /Retry after checking the group/)
	await ui.button('Review and save').click()
	await settle()
	assert.equal(alert.textContent, '', 'the old identical text is cleared, so the repeat is a new announcement')
	flushTimers()
	assert.equal(alert.textContent, first)
})
test('O2: a success that follows an error cancels the pending announcement of the error', async () => {
	let fail = true
	const ui = await app((call) => call.method === 'POST' ? (fail ? [503, {}] : [200, preview]) : undefined)
	await ui.open()
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await settle()
	assert.equal(ui.timers.size, 1)
	fail = false
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.ids['mcp-group-alert'].textContent, '')
	assert.match(ui.ids['mcp-group-status'].textContent, /Review the impact/)
})

/** S7: a page of the directory with one group of each state. */
const directory = [
	{ groupId: 'staff', displayName: 'staff', trusted: true, exists: true, state: 'active', grants: { _system: ['eligible'], notes: ['read'] } },
	{ groupId: 'ti-interno', displayName: 'Equipe TI', trusted: true, exists: true, state: 'active', grants: [] },
	{ groupId: 'finance', displayName: 'Finance', trusted: true, exists: true, state: 'needs_confirm', grants: {} },
	{ groupId: 'sales-ldap', displayName: 'Sales', trusted: false, exists: true, state: 'active', grants: {} },
	{ groupId: 'gone', displayName: 'gone', trusted: true, exists: false, state: 'active', grants: {} },
	{ groupId: 'leaving', displayName: 'leaving', trusted: true, exists: true, state: 'pending_delete', grants: {} },
	{ groupId: 'remote', displayName: 'remote', trusted: null, exists: null, state: null, grants: {} },
]
const items = (ui) => ui.ids['mcp-group-list'].all('button')
test('S7: a row shows the ID only when it differs from the name, and its state in words next to a symbol', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? undefined : [200, { groups: directory, hasMore: false }])
	const [staff, ti, finance, sales, gone, leaving, remote] = items(ui).map((item) => item.textContent)
	assert.doesNotMatch(staff, /ID:/)
	assert.match(staff, /Grants: Can connect, Notes/)
	assert.match(ti, /Equipe TI/)
	assert.match(ti, /ID: ti-interno/)
	assert.match(ti, /Grants nothing/)
	assert.match(finance, /⚠ Awaiting confirmation/)
	assert.match(sales, /🔒 Cannot grant access/)
	assert.match(gone, /⊘ Deleted group/)
	assert.match(leaving, /⚠ Deletion pending/)
	assert.match(remote, /\? Source unavailable/)
	for (const item of items(ui)) {
		const symbol = item.all('span').find((span) => span.className === 'mcp-group-symbol')
		if (symbol) assert.equal(symbol.attributes['aria-hidden'], 'true', 'the symbol is decoration; the words carry the state')
	}
	assert.equal(ui.ids['mcp-group-count'].textContent, '7 groups')
})
test('S7: only the open group is marked with aria-current, and the list keeps the mark across pages of the detail', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? [200, stored({ groupId: 'ti-interno' })] : [200, { groups: directory, hasMore: false }])
	assert.deepEqual(items(ui).map((item) => item.attributes['aria-current']), directory.map(() => 'false'))
	await items(ui)[1].click()
	await tick()
	assert.deepEqual(items(ui).map((item) => item.attributes['aria-current']), ['false', 'true', 'false', 'false', 'false', 'false', 'false'])
	assert.equal(ui.document.activeElement.textContent, 'Equipe TI', 'the detail names the group as the list does')
	assert.match(ui.ids['mcp-group-detail'].textContent, /ID: ti-interno/)
})
test('S7: the counter tells the range when there are more pages, and nothing when the list failed', async () => {
	let fail = false
	const ui = await app((call) => fail ? [503, {}] : call.url.includes('/detail?') ? undefined : [200, { groups: directory.slice(0, 3), hasMore: true }])
	assert.equal(ui.ids['mcp-group-count'].textContent, 'Groups 1–3')
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-group-count'].textContent, 'Groups 51–53')
	fail = true
	await ui.ids['mcp-group-prev'].listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-group-count'].textContent, '')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Could not load groups/)
})
test('S7: the notices of a group live in its detail, with the action they ask for', async () => {
	const ui = await app(undefined, stored({ state: 'needs_confirm' }))
	await ui.open()
	const note = ui.ids['mcp-group-detail'].all('div').find((node) => node.className.includes('mcp-group-note'))
	assert.equal(note.hidden, false)
	assert.match(note.textContent, /awaiting confirmation/)
	assert.ok(note.all('button').some((button) => button.textContent === 'Confirm current policy'))
	const quiet = await app()
	await quiet.open()
	assert.equal(quiet.ids['mcp-group-detail'].all('div').find((node) => node.className.includes('mcp-group-note')).hidden, true)
})

/** S8: the catalog the server sends, with the matrix's tiers. */
const fullCatalog = {
	_system: ['eligible'],
	files: ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'],
	talk: ['read', 'reply', 'attach', 'quote', 'create'],
	people: ['read'],
	logs: ['read'],
}
const pressed = (ui, module) => ui.ids['mcp-group-detail'].all('button').filter((node) => node.name === 'mcp-group-level-' + module && node.attributes['aria-pressed'] === 'true').map((node) => node.value)
test('S8: each module shows the matrix levels, the chosen one pressed with a check mark, and "Not granted" first', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['create', 'edit', 'move', 'read', 'restore'], logs: ['read'] } }))
	await ui.open()
	const names = (module) => ui.ids['mcp-group-detail'].all('button').filter((node) => node.name === 'mcp-group-level-' + module).map((node) => node.value)
	assert.deepEqual(names('files'), ['none', 'read', 'edit', 'full'])
	assert.deepEqual(names('people'), ['none', 'read'])
	assert.deepEqual(pressed(ui, 'files'), ['edit'])
	assert.deepEqual(pressed(ui, 'talk'), ['none'])
	assert.deepEqual(pressed(ui, 'logs'), ['read'])
	const edit = ui.level('files', 'edit')
	const mark = edit.all('span').find((span) => span.className === 'mcp-pressed-mark')
	assert.equal(mark?.textContent, '✓', 'the pressed level never depends on colour')
	assert.equal(mark.attributes['aria-hidden'], 'true')
	assert.equal(ui.level('files', 'read').all('span').length, 0, 'only the pressed level has the mark')
	assert.match(ui.level('talk', 'none').textContent, /Not granted/)
	assert.match(ui.level('talk', 'none').className, /mcp-group-level-none/)
	assert.doesNotMatch(ui.level('files', 'edit').className, /mcp-group-level-none/)
	const text = ui.ids['mcp-group-detail'].textContent
	assert.match(text, /Members keep the default: Read/, 'a module the group does not grant shows what members get anyway')
	assert.doesNotMatch(ui.ids['mcp-group-detail'].all('div').find((row) => row.className === 'mcp-group-row' && row.textContent.startsWith('Server log'))?.textContent ?? '', /default/)
})
test('S8: choosing a level puts exactly its operations in the draft, lower or higher, and keeps the focus on it', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'], talk: ['read'] } }))
	await ui.open()
	await ui.level('files', 'read').click()
	assert.deepEqual(pressed(ui, 'files'), ['read'])
	assert.equal(ui.document.activeElement, ui.level('files', 'read'))
	await ui.level('talk', 'edit').click()
	await ui.level('people', 'none').click()
	await ui.button('Review and save').click()
	await tick()
	const body = ui.calls.find((call) => call.method === 'POST').body
	assert.deepEqual(body.grants, { files: ['read'], talk: ['attach', 'quote', 'read', 'reply'] })
	assert.equal(body.expectedRevision, '9007199254740993')
})
test('S8: "Operations" shows two-state boxes per operation, and a set that is no level reads as Custom with nothing pressed', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	ui.operations()
	assert.equal(ui.input('mcp-group-op-files', 'share').checked, false)
	assert.equal(ui.input('mcp-group-op-files', 'read').checked, true)
	assert.equal(ui.level('files', 'read'), undefined, 'the levels give way to the boxes')
	ui.check(ui.input('mcp-group-op-files', 'share'))
	assert.equal(ui.document.activeElement, ui.input('mcp-group-op-files', 'share'))
	ui.operations()
	assert.deepEqual(pressed(ui, 'files'), [])
	assert.match(ui.ids['mcp-group-detail'].textContent, /Custom: change it in Operations/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Files: Read → Custom \(read, share\)/)
})
test('S8: the save bar counts the changes, Discard restores the saved policy, and nothing is sent without a review', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	assert.equal(ui.button('Review and save'), undefined, 'no bar without changes')
	await ui.level('files', 'edit').click()
	ui.check(ui.input('mcp-group-eligible'))
	assert.match(ui.ids['mcp-group-detail'].textContent, /2 unsaved changes/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Can connect: No → Yes; Files: Read → Edit/)
	await ui.button('Discard').click()
	assert.equal(ui.button('Review and save'), undefined)
	assert.deepEqual(pressed(ui, 'files'), ['read'])
	assert.equal(ui.input('mcp-group-eligible').checked, false)
	assert.equal(ui.calls.filter((call) => call.method !== 'GET').length, 0)
})
test('S8: Review and save uses the group preview, shows a short review, Back returns, and Save sends the receipt once', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, { ...preview, current: { files: ['read'] }, grants: { files: ['create', 'edit', 'move', 'read', 'restore'] } }]
		: call.method === 'PUT' ? [200, { revision: '9007199254740994' }] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	await ui.level('files', 'edit').click()
	await ui.button('Review and save').click()
	await tick()
	const region = ui.ids['mcp-group-detail'].all('div').find((node) => node.className === 'mcp-group-review')
	assert.equal(region.attributes.role, 'region')
	assert.match(region.textContent, /Review the changes to Staff/)
	assert.match(region.textContent, /Files: Read → Edit/)
	assert.match(region.textContent, /51 current members; 2 credential holders will be signed out/)
	assert.match(region.textContent, /Losing the last source of eligibility revokes connections/)
	assert.equal(ui.document.activeElement, ui.button('Save group policy'))
	await ui.button('Back').click()
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.document.activeElement, ui.button('Review and save'))
	await ui.button('Review and save').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	const puts = ui.calls.filter((call) => call.method === 'PUT')
	assert.equal(puts.length, 1)
	assert.equal(puts[0].body.previewId, 'receipt')
	assert.deepEqual(puts[0].body.grants, { files: ['create', 'edit', 'move', 'read', 'restore'] })
	assert.deepEqual(ui.events, ['mcp-group-policy-saved'])
})
test('S8: a change after the review drops the preview, so the old receipt can never be saved', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	await ui.level('files', 'edit').click()
	await ui.button('Review and save').click()
	await tick()
	assert.ok(ui.button('Save group policy'))
	ui.operations()
	ui.check(ui.input('mcp-group-op-files', 'share'))
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
})
test('S8: one sentence tells who wins over the group, unknown stays apart from zero, and the link filters the matrix', async () => {
	const changes = []
	const select = { value: '', options: [{ value: '' }, { value: 'staff' }], dispatchEvent: (event) => changes.push(event.type) }
	const matrix = { scrolled: false, scrollIntoView() { this.scrolled = true }, querySelector: () => null }
	const ui = await app(undefined, stored({ counts: { members: 24, overrides: 3, permissions: null } }))
	ui.ids['mcp-group'] = select
	ui.ids['mcp-block-matrix'] = matrix
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /24 members/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /3 members have individual settings that take precedence over this group/)
	assert.equal(ui.ids['mcp-group-detail'].all('table').length, 0, 'no table of counters')
	await ui.button('View in the matrix').click()
	assert.equal(select.value, 'staff')
	assert.deepEqual(changes, ['change'])
	assert.equal(matrix.scrolled, true)
	const none = await app(undefined, stored({ counts: { members: 1, overrides: 0, permissions: null } }))
	await none.open()
	assert.match(none.ids['mcp-group-detail'].textContent, /1 member\b/)
	assert.match(none.ids['mcp-group-detail'].textContent, /No member has individual settings/)
	assert.equal(none.button('View in the matrix'), undefined)
})
test('S8: "Not granted" pressed stays neutral, and only focus and selection take the theme colour', () => {
	const css = fs.readFileSync(path.join(__dirname, '../../css/admin.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
	const rule = (selector) => [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter(([, sel]) => sel.includes(selector))
	const none = rule('.mcp-group-level-none[aria-pressed="true"]')
	assert.ok(none.length > 0)
	for (const [, , body] of none) assert.doesNotMatch(body, /--color-primary/)
	assert.ok(rule('.mcp-group-level[aria-pressed="true"]').some(([, , body]) => /--color-primary-element-light/.test(body)))
})
