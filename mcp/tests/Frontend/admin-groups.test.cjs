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
	const ids = Object.fromEntries(['editor', 'search', 'list', 'detail', 'status', 'alert', 'prev', 'next'].map((id) => ['mcp-group-' + id, new Element('div', document)]))
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
		setTimeout: (fn, ms) => { if (ms === 300) { fn(); return 1 } timers.set(++timerId, fn); return timerId }, clearTimeout: (id) => { timers.delete(id) },
		fetch: async (url, options) => {
			const call = { url, method: options.method, body: options.body ? JSON.parse(options.body) : undefined }
			calls.push(call)
			const result = await handler?.(call)
			const [status, body] = result || [200, url.includes('/detail?') ? group : { groups: [{ displayName: 'Staff', ...group }], hasMore: true }]
			return { ok: status < 400, status, json: async () => { if (body instanceof Error) throw body; return body } }
		},
	}
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/admin-groups.js'), 'utf8'), context)
	await tick()
	const open = async () => { await ids['mcp-group-list'].all('button')[0].click(); await tick() }
	const button = (text) => ids['mcp-group-detail'].all('button').find((node) => node.textContent === text)
	return { ids, calls, events, document, open, button, confirmations, timers }
}
const preview = { previewId: 'receipt', revision: '9007199254740993', members: 51, revokes: 2, withdraws: true }

test('T17: unknown counters stay distinct from zero and the heading receives focus', async () => {
	const ui = await app()
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /Members: Unknown count/)
	assert.equal(ui.document.activeElement.tag, 'h3')
	assert.equal(ui.button('Save group policy').disabled, true)
})
test('T16: edits from an empty PHP array produce an object payload; saving requires a preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined)
	await ui.open()
	const checkbox = ui.ids['mcp-group-detail'].all('input')[0]
	checkbox.checked = true
	checkbox.listeners.change()
	await ui.button('Preview group changes').click()
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
	const checkbox = ui.ids['mcp-group-detail'].all('input')[2]
	checkbox.checked = true
	checkbox.listeners.change()
	await ui.button('Preview group changes').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 1)
	assert.equal(ui.ids['mcp-group-detail'].all('input')[2].checked, true)
	assert.equal(ui.button('Save group policy').disabled, true)
	assert.match(ui.ids['mcp-group-alert'].textContent, /Your draft is kept/)
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
	assert.equal(ui.document.activeElement.attributes['aria-describedby'], 'mcp-group-alert mcp-group-diagnosis')
	assert.match(ui.ids['mcp-group-detail'].textContent, /Only in draft, not saved: Notes: edit/)
})
test('T17: non-native and orphaned groups expose diagnosis, disabled grants and a clearing preview', async () => {
	const ui = await app(undefined, stored({ trusted: false, exists: false, grants: { notes: ['edit'] } }))
	await ui.open()
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].disabled, true)
	assert.equal(ui.button('Preview group changes').disabled, true)
	assert.equal(ui.button('Preview clearing group policy').disabled, false)
	assert.match(ui.ids['mcp-group-detail'].textContent, /no longer exists/)
})
test('T17: 503 refuses the save, preserves draft and demands an explicit new preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, { error: 'Unavailable' }] : undefined)
	await ui.open()
	await ui.button('Preview group changes').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 1)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
	assert.equal(ui.button('Save group policy').disabled, true)
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
	const checkbox = ui.ids['mcp-group-detail'].all('input')[2]
	checkbox.checked = true
	checkbox.listeners.change()
	assert.match(ui.ids['mcp-group-detail'].textContent, /Only in draft, not saved: Notes: edit/)
	ui.confirmations.allow = false
	await ui.button('Confirm current policy').click()
	await ui.button('Preview clearing group policy').click()
	await ui.open()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 0)
	assert.equal(ui.ids['mcp-group-detail'].all('input')[2].checked, true)
	assert.equal(ui.confirmations.messages.length, 3)
})
test('M3/M8: an HTML 503 retains its status, reloads the policy and gives a generic error', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, new SyntaxError('HTML error page')] : undefined)
	await ui.open()
	await ui.button('Preview group changes').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.url.includes('/detail?')).length, 2)
	assert.match(ui.ids['mcp-group-alert'].textContent, /The change could not be confirmed/)
	assert.equal(ui.button('Save group policy').disabled, true)
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
	assert.equal(ui.button('Preview group changes').attributes['aria-describedby'], 'mcp-group-diagnosis')
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
	await ui.button('Preview group changes').click()
	await settle()
	// Cleared at once and written in a later task: nothing is announced in the same task as the clearing.
	assert.equal(alert.textContent, '')
	assert.equal(ui.timers.size, 1)
	flushTimers()
	const first = alert.textContent
	assert.match(first, /Retry after checking the group/)
	await ui.button('Preview group changes').click()
	await settle()
	assert.equal(alert.textContent, '', 'the old identical text is cleared, so the repeat is a new announcement')
	flushTimers()
	assert.equal(alert.textContent, first)
})
test('O2: a success that follows an error cancels the pending announcement of the error', async () => {
	let fail = true
	const ui = await app((call) => call.method === 'POST' ? (fail ? [503, {}] : [200, preview]) : undefined)
	await ui.open()
	await ui.button('Preview group changes').click()
	await settle()
	assert.equal(ui.timers.size, 1)
	fail = false
	await ui.button('Preview group changes').click()
	await tick()
	assert.equal(ui.ids['mcp-group-alert'].textContent, '')
	assert.match(ui.ids['mcp-group-status'].textContent, /Review the impact/)
})
