/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of js/admin-scope.js and js/personal-scope.js (issue #4, B7: S13 display, S14), with Node's test runner and a minimal DOM. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

let active = null
class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.parent = null
		this.value = ''
		this.hidden = false
		this.disabled = false
		this._text = ''
	}
	append(...children) {
		for (const child of children) {
			if (typeof child !== 'string') child.parent = this
			this.children.push(child)
		}
	}
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	replaceWith(other) {
		const siblings = this.parent.children
		siblings[siblings.indexOf(this)] = other
		other.parent = this.parent
	}
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	focus() { active = this }
	scrollIntoView() {}
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return [this._text, ...this.children.map((child) => typeof child === 'string' ? child : child.textContent)].filter((s) => s !== '').join(' ') }
	all(predicate) {
		return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(predicate(child) ? [child] : []), ...child.all(predicate)])
	}
	find(predicate) { return this.all(predicate)[0] }
	button(text) { return this.find((node) => node.tag === 'button' && node.textContent === text) }
}
const tick = async () => { for (let i = 0; i < 20; i++) await new Promise(setImmediate) }

const show = {
	files: { uid: 'alice', module: 'files', active: false, mode: 'unrestricted', items: [], limit: 200, revision: '7' },
	calendar: { uid: 'alice', module: 'calendar', active: true, mode: 'restricted', items: [{ id: '11', found: true, name: 'Personal', path: null, excluded: false }], limit: 200, revision: '7' },
	tasks: { uid: 'alice', module: 'tasks', active: true, mode: 'unrestricted', items: [], limit: 200, revision: '7' },
}
const candidates = { items: [{ id: '11', name: 'Personal', path: null, excluded: false }, { id: '12', name: 'Team', path: null, excluded: false }], page: 1, pageSize: 50, total: 2, hasMore: false, parent: null }

async function app(handler = () => undefined) {
	const page = new Element('div')
	const ids = Object.fromEntries(['mcp-scope', 'mcp-scope-form', 'mcp-scope-user', 'mcp-scope-status', 'mcp-scope-alert', 'mcp-scope-modules'].map((id) => {
		const node = new Element('div')
		node.id = id
		page.append(node)
		return [id, node]
	}))
	const document = {
		getElementById: (id) => ids[id] || page.find((node) => node.id === id) || null,
		createElement: (tag) => new Element(tag),
		get activeElement() { return active },
	}
	const calls = []
	const events = []
	const timers = []
	const context = {
		document, URLSearchParams,
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		setTimeout: (action) => { timers.push(action) },
		Event: class { constructor(type) { this.type = type } },
		fetch: async (url, options) => {
			const body = options.body === undefined ? undefined : JSON.parse(options.body)
			calls.push({ url, method: options.method, body, headers: options.headers })
			const custom = await handler(url, options.method, body)
			if (custom) {
				const [status, data] = custom
				return { ok: status < 400, status, json: async () => data }
			}
			const module = (url.match(/\/api\/scope\/[^/]+\/(files|calendar|tasks)/) || [])[1]
			const data = url.includes('/candidates') ? candidates : options.method === 'GET' && module ? show[module] : {}
			return { ok: true, status: 200, json: async () => data }
		},
	}
	context.window = { dispatchEvent: (event) => events.push(event.type) }
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/admin-scope.js'), 'utf8'), context)
	const open = async (uid = 'alice') => {
		ids['mcp-scope-user'].value = uid
		await ids['mcp-scope-form'].listeners.submit({ preventDefault() {} })
		await tick()
	}
	const module = (name) => document.getElementById('mcp-scope-module-' + name)
	const click = async (node) => { assert.ok(node, 'the control exists'); assert.equal(node.disabled, false, 'the control is enabled'); node.listeners.click(); await tick() }
	return { ids, calls, events, timers, open, module, click, document, ui: context.window.McpScopeUi }
}

test('S14: each module shows its saved scope; a module the tools do not scope yet says so and cannot be saved', async () => {
	const ui = await app()
	await ui.open()
	assert.deepEqual(ui.calls.map((c) => c.url), ['/apps/mcp/api/scope/alice/files', '/apps/mcp/api/scope/alice/calendar', '/apps/mcp/api/scope/alice/tasks'])
	assert.equal(ui.calls[0].headers.requesttoken, 'token', 'the CSRF token travels with every request')
	const files = ui.module('files')
	assert.match(files.textContent, /Not available yet: the tools of this module do not apply a resource scope/)
	assert.equal(files.button('Save').disabled, true)
	const calendar = ui.module('calendar')
	assert.match(calendar.textContent, /1 of at most 200 selected/)
	assert.ok(calendar.find((node) => node.attributes['aria-label'] === 'Remove Personal'), 'each item has a labelled remove button')
	assert.equal(ui.module('calendar').attributes['aria-labelledby'], 'mcp-scope-heading-calendar', 'the module region is named by its heading')
	assert.equal(ui.ids['mcp-scope-status'].textContent, 'Resource scope of alice.')
})

test('S14: choosing resources and saving sends the revision, offers undo for ten seconds and refreshes the matrix', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 2 } }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	assert.match(ui.module('calendar').textContent, /Team added\. Save to apply it\./)
	await ui.click(ui.module('calendar').button('Save'))
	const put = ui.calls.find((c) => c.method === 'PUT')
	assert.deepEqual([put.url, put.body], ['/apps/mcp/api/scope/alice/calendar', { expectedRevision: '7', mode: 'restricted', items: ['11', '12'] }])
	assert.match(ui.module('calendar').textContent, /Saved\. It applies from the next MCP call of the user; sessions are not ended\./)
	assert.deepEqual(ui.events, ['mcp-scope-saved'])
	assert.equal(ui.ids['mcp-scope-status'].textContent, 'Calendar: calendars: Saved. It applies from the next MCP call of the user; sessions are not ended.', 'announced in the live region that exists from the start')
	assert.ok(ui.module('calendar').button('Undo'))
	ui.timers.forEach((action) => action())
	assert.equal(ui.module('calendar').button('Undo'), undefined, 'undo is offered for ten seconds only')
})

test('S14: undo goes back through the receipt of the save', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'unrestricted', count: null } }]
		: url.includes('/api/grants/undo') ? [200, { revision: '9', changed: 1 }] : undefined)
	await ui.open()
	const restricted = ui.module('tasks').find((node) => node.id === 'mcp-scope-mode-tasks-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	assert.match(ui.module('tasks').textContent, /Nothing selected: the Tasks tools of this user reach no task list\./)
	await ui.click(ui.module('tasks').button('Save'))
	assert.deepEqual(ui.calls.find((c) => c.method === 'PUT').body, { expectedRevision: '7', mode: 'restricted', items: [] }, 'restricted to nothing is not unrestricted')
	await ui.click(ui.module('tasks').button('Undo'))
	assert.deepEqual(ui.calls.find((c) => c.url === '/apps/mcp/api/grants/undo').body, { receiptId: '41', expectedRevision: '8' })
	assert.match(ui.module('tasks').textContent, /Change undone\./)
})

test('S14: a conflict never repeats the write; it shows the saved scope again', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [409, { error: 'Conflict', revision: '9' }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Remove'))
	await ui.click(ui.module('calendar').button('Save'))
	assert.equal(ui.calls.filter((c) => c.method === 'PUT').length, 1)
	assert.equal(ui.calls.at(-1).url, '/apps/mcp/api/scope/alice/calendar', 'the scope is read again')
	assert.match(ui.module('calendar').textContent, /The policy changed after this scope was loaded, so nothing was saved\./)
	assert.match(ui.module('calendar').textContent, /1 of at most 200 selected/, 'the saved scope is back on screen')
	assert.match(ui.ids['mcp-scope-alert'].textContent, /^Calendar: calendars: The policy changed after this scope was loaded/, 'a refusal is an alert')
})

test('S14: refusals of the server are explained, and an inaccessible resource is marked', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [422, { error: 'Inaccessible', items: ['11'] }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	await ui.click(ui.module('calendar').button('Save'))
	const text = ui.module('calendar').textContent
	assert.match(text, /Not saved: the user cannot reach the marked resources in Nextcloud\./)
	assert.match(text, /Personal the user cannot reach it in Nextcloud/)
	assert.doesNotMatch(text, /Team the user cannot reach it/)
})

test('S14: an excluded folder is marked as having no effect, and folders are browsed level by level', async () => {
	const folders = (parent) => ({ items: parent === undefined ? [{ id: '5', name: 'Projects', path: '/Projects', excluded: false }, { id: '6', name: 'Secret', path: '/Secret', excluded: true }]
		: [{ id: '7', name: 'Plans', path: '/Projects/Plans', excluded: false }], page: 1, pageSize: 50, total: parent === undefined ? 2 : 1, hasMore: false, parent: null })
	const ui = await app((url) => url.includes('/files/candidates') ? [200, folders(new URL('http://x' + url).searchParams.get('parent') ?? undefined)] : undefined)
	await ui.open()
	const restricted = ui.module('files').find((node) => node.id === 'mcp-scope-mode-files-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	await ui.click(ui.module('files').button('Search'))
	assert.match(ui.module('files').textContent, /\/Secret excluded by a tag: choosing it has no effect/)
	await ui.click(ui.module('files').find((node) => node.attributes['aria-label'] === 'Open /Projects'))
	assert.match(ui.calls.at(-1).url, /files\/candidates\?search=&page=1&parent=5$/)
	assert.match(ui.module('files').textContent, /In folder \/Projects/)
	assert.match(ui.module('files').textContent, /\/Projects\/Plans/)
	await ui.click(ui.module('files').button('Up one folder'))
	assert.match(ui.module('files').textContent, /In the top folder of the user/)
})

test('S14 keyboard: removing an item moves the focus to the next one, not to the page', async () => {
	show.calendar.items.push({ id: '12', found: true, name: 'Team', path: null, excluded: false }, { id: '99', found: false, name: null, path: null, excluded: false })
	try {
		const ui = await app()
		await ui.open()
		assert.match(ui.module('calendar').textContent, /Resource no longer found \(99\) not found: it has no effect/)
		await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Remove Personal'))
		assert.equal(ui.document.activeElement.id, 'mcp-scope-remove-calendar-12')
	} finally {
		show.calendar.items.splice(1)
	}
})

test('S14: the matrix mark is text, not color alone, and opens the scope of that user', async () => {
	const ui = await app()
	const mark = ui.ui.indicator('bob', 'tasks', 3)
	assert.equal(mark.tag, 'button')
	assert.match(mark.textContent, /Restricted to 3/)
	assert.equal(mark.find((node) => node.attributes['aria-hidden'] === 'true').textContent, '◩', 'the icon is hidden from screen readers')
	mark.listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-scope-user'].value, 'bob')
	assert.equal(ui.calls[0].url, '/apps/mcp/api/scope/bob/files')
	assert.equal(ui.document.activeElement.id, 'mcp-scope-heading-tasks', 'the focus lands on the module the mark belongs to')
})

test('a refused or unavailable scope is said in the alert region, with nothing shown', async () => {
	for (const [status, message] of [[403, 'Only administrators can change the resource scope.'], [503, 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.']]) {
		const ui = await app(() => [status, { error: 'x' }])
		await ui.open()
		assert.equal(ui.ids['mcp-scope-alert'].textContent, message)
		assert.equal(ui.ids['mcp-scope-modules'].children.length, 0)
	}
})

async function personal(handler) {
	const ids = { 'mcp-my-scope-status': new Element('p'), 'mcp-my-scope-list': new Element('ul') }
	const context = {
		document: { getElementById: (id) => ids[id], createElement: (tag) => new Element(tag) },
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		fetch: async (url) => { const [status, data] = handler(url); return { ok: status < 400, status, json: async () => data } },
	}
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/personal-scope.js'), 'utf8'), context)
	await tick()
	return ids
}

test('personal settings: the user reads their own scope, and an unreadable scope is never shown as unrestricted', async () => {
	const ids = await personal(() => [200, { modules: {
		files: { mode: 'unrestricted', items: [] },
		calendar: { mode: 'restricted', items: [{ id: '11', found: true, name: 'Personal', path: null }, { id: '9', found: false, name: null, path: null }] },
		tasks: { mode: 'restricted', items: [] },
	} }])
	assert.deepEqual(ids['mcp-my-scope-list'].children.map((li) => li.textContent),
		['Files: every folder', 'Calendar: only Personal, a resource that no longer exists', 'Tasks: no task list'])
	const failed = await personal(() => [503, { error: 'Unavailable' }])
	assert.equal(failed['mcp-my-scope-status'].textContent, 'Your resource scope cannot be read right now. Try again later.')
	assert.equal(failed['mcp-my-scope-list'].children.length, 0)
})
