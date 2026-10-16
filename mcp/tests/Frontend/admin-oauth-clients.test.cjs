/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of the OAuth clients block of js/admin-grants.js (F2 of the review of PR #8) with Node's built-in
 * runner and a minimal DOM: every save carries the revision of the page, the saves of the block go one at a time, and a
 * 409 (another window saved first) rebuilds the cards from the server instead of resending the stale list.
 *
 * The block is one function of the admin script, so it is cut out of the source and run against stubs of the helpers it
 * shares with the rest of the page (api, el, flash, notifyError, load).
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.listeners = {}
		this.classes = new Set()
		this.disabled = false
		this.checked = false
		this.hidden = false
		this.value = ''
		this._text = ''
		this.parent = null
	}
	get classList() {
		return { add: (...names) => names.forEach((name) => this.classes.add(name)), remove: (...names) => names.forEach((name) => this.classes.delete(name)), contains: (name) => this.classes.has(name) }
	}
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	append(...children) { for (const child of children) { if (typeof child !== 'string') child.parent = this; this.children.push(child) } }
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	replaceWith(node) { const siblings = this.parent.children; siblings[siblings.indexOf(this)] = node; node.parent = this.parent }
	remove() { this.parent.children = this.parent.children.filter((child) => child !== this) }
	setAttribute() {}
	addEventListener(name, action) { (this.listeners[name] ||= []).push(action) }
	async fire(name) { for (const action of this.listeners[name] || []) await action({}) }
	focus() {}
	set textContent(value) { this._text = String(value); this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	matches(selector) {
		const [tag, ...classes] = selector.split('.')
		return (tag === '' || tag === this.tag) && classes.every((name) => this.classes.has(name))
	}
	querySelectorAll(selector) {
		return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)])
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null }
}

const source = fs.readFileSync(path.join(__dirname, '../../js/admin-grants.js'), 'utf8')
const start = source.indexOf('\tasync function initOauthClients() {')
const block = source.slice(start, source.indexOf('\n\t}\n', start) + 4)
assert.ok(start > 0 && block.length > 500, 'the OAuth clients block was found in js/admin-grants.js')

const REDIRECT = 'https://harness.example.com/oauth/callback'
const client = (id, name, enabled = true) => ({ id, clientId: 'nextcloud-mcp-custom-' + id, name, enabled, redirectUris: [REDIRECT] })
const state = (revision, customClients, extra = {}) => ({
	revision, hosts: ['claude.ai'], hostsDefault: false, nativeClientEnabled: false, nativeClientId: 'nextcloud-mcp-native',
	nativeRedirectUris: ['http://localhost/oauth/callback'], customClients, ...extra,
})

/**
 * Runs the block against a fake server. `serve({method, path, body})` answers [status, body]; a function result is
 * awaited first, so a test can hold an answer back.
 */
async function page(serve) {
	const elements = {}
	const document = {
		getElementById: (id) => elements[id] ??= new Element('div'),
		createElement: (tag) => new Element(tag),
	}
	const log = { calls: [], errors: [], matrixReloads: 0 }
	const context = {
		document,
		window: { confirm: () => true },
		navigator: { clipboard: { writeText: async () => {} } },
		t: (app, text) => text,
		el: (tag, props, children) => {
			const node = document.createElement(tag)
			Object.assign(node, props || {})
			for (const child of children || []) node.append(child)
			return node
		},
		flash: () => {},
		notifyError: (message) => log.errors.push(message),
		load: () => { log.matrixReloads++ },
		api: async (method, apiPath, body) => {
			log.calls.push({ method, path: apiPath, body: body === undefined ? undefined : JSON.parse(JSON.stringify(body)) })
			const [status, data] = await serve({ method, path: apiPath, body })
			if (status >= 400) throw Object.assign(new Error(String(status)), { data })
			return JSON.parse(JSON.stringify(data))
		},
	}
	vm.createContext(context)
	vm.runInContext(block + '\nthis.run = initOauthClients', context)
	await context.run()
	const cards = () => elements['mcp-custom-clients'].children
	return { elements, log, cards, context }
}

const puts = (log) => log.calls.filter((call) => call.method === 'PUT')

test('every save carries the revision of the page and adopts the one the server answers with', async () => {
	let revision = 5
	const { log, cards, elements } = await page(({ method }) => method === 'GET'
		? [200, state('5', [client('aaaaaaaaaaaa', 'A')])]
		: [200, state(String(++revision), [client('aaaaaaaaaaaa', 'A', false)])])
	assert.equal(cards().length, 1)
	const enabled = cards()[0].querySelector('input.checkbox')
	enabled.checked = false
	await enabled.fire('change')
	elements['mcp-native-client'].checked = true
	await elements['mcp-native-client'].fire('change')
	elements['mcp-oauth-hosts'].value = 'claude.ai'
	await elements['mcp-oauth-hosts-save'].fire('click')
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '6', '7'])
	assert.equal(log.matrixReloads, 3, 'the policy revision moved, so the matrix is reloaded')
	assert.deepEqual(puts(log)[0].body.customClients.map(({ enabled: on }) => on), [false])
})

test('a 409 rebuilds the cards from the server and never resends the stale list', async () => {
	let reads = 0
	const { log, cards } = await page(({ method }) => {
		if (method === 'PUT') return [409, { error: 'Conflict', revision: '9', state: null }]
		return [200, reads++ === 0 ? state('5', [client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
			: state('9', [client('aaaaaaaaaaaa', 'A', false), client('bbbbbbbbbbbb', 'B')])]
	})
	assert.equal(cards().length, 2)
	assert.equal(cards()[0].querySelector('input.checkbox').checked, true)
	const second = cards()[1].querySelector('input.checkbox')
	second.checked = false
	await second.fire('change')
	assert.equal(puts(log).length, 1, 'the conflict is not retried')
	assert.equal(log.calls.filter((call) => call.method === 'GET').length, 2, 'the current state was read again')
	assert.equal(cards()[0].querySelector('input.checkbox').checked, false, 'the other administrator\'s change is on screen')
	assert.equal(cards()[1].querySelector('input.checkbox').checked, true, 'the unsaved change of this page was dropped')
	assert.match(log.errors[0], /nothing was overwritten/)
})

test('a save of this page after a 409 uses the revision of the reload', async () => {
	let reads = 0
	let conflict = true
	const { log, cards } = await page(({ method }) => {
		if (method === 'GET') return [200, state(reads++ === 0 ? '5' : '9', [client('aaaaaaaaaaaa', 'A')])]
		if (conflict) { conflict = false; return [409, { error: 'Conflict' }] }
		return [200, state('10', [client('aaaaaaaaaaaa', 'A', false)])]
	})
	for (let attempt = 0; attempt < 2; attempt++) {
		const box = cards()[0].querySelector('input.checkbox')
		box.checked = false
		await box.fire('change')
	}
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '9'])
})

test('428 and an uncertain 503 also reload; any other failure keeps the card message', async () => {
	for (const [status, data, reloads] of [[428, { error: 'PreconditionRequired' }, 2], [503, { error: 'Unavailable', uncertain: true }, 2], [503, { error: 'Unavailable', uncertain: false }, 1], [400, { error: 'Invalid request' }, 1]]) {
		const { log, cards } = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', 'A')])] : [status, data])
		const box = cards()[0].querySelector('input.checkbox')
		box.checked = false
		await box.fire('change')
		assert.equal(log.calls.filter((call) => call.method === 'GET').length, reloads, `${status} ${JSON.stringify(data)}`)
	}
})

test('saves of the block go one at a time, the second with the revision the first answered', async () => {
	let release
	const gate = new Promise((resolve) => { release = resolve })
	let revision = 5
	const { log, elements } = await page(async ({ method, body }) => {
		if (method === 'GET') return [200, state('5', [])]
		if (body.nativeClientEnabled === true) await gate
		return [200, state(String(++revision), [])]
	})
	const native = elements['mcp-native-client']
	native.checked = true
	const first = native.fire('change')
	elements['mcp-oauth-hosts'].value = 'claude.ai, example.org'
	const second = elements['mcp-oauth-hosts-save'].fire('click')
	await new Promise(setImmediate)
	assert.equal(puts(log).length, 1, 'the second save waits for the first')
	release()
	await Promise.all([first, second])
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '6'])
})

/**
 * A fake server with real revision checks: a PUT with a revision other than the current one answers 409, an accepted
 * one stores the list it carries. `hold` delays the answer of the first PUT until released.
 */
function revisionServer(initial) {
	const server = { revision: 5, clients: initial, puts: [] }
	let release
	server.held = new Promise((resolve) => { release = resolve })
	server.release = () => release()
	server.serve = async ({ method, body }) => {
		if (method === 'GET') return [200, state(String(server.revision), server.clients)]
		server.puts.push(body)
		if (server.puts.length === 1) await server.held
		if (body.expectedRevision !== String(server.revision)) return [409, { error: 'Conflict' }]
		server.revision++
		server.clients = body.customClients.map((c) => ({ ...c, clientId: 'nextcloud-mcp-custom-' + c.id }))
		return [200, state(String(server.revision), server.clients)]
	}
	return server
}

test('R1: two quick toggles build the second list from the first answer and never turn A back on', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
	const { cards } = await page(server.serve)
	const [a, b] = cards().map((card) => card.querySelector('input.checkbox'))
	a.checked = false
	const first = a.fire('change')
	b.checked = false
	const second = b.fire('change')
	await new Promise(setImmediate)
	server.release()
	await Promise.all([first, second])
	assert.equal(server.puts.length, 2)
	assert.deepEqual(server.puts.map((body) => body.expectedRevision), ['5', '6'])
	assert.deepEqual(server.puts[1].customClients.map((c) => c.enabled), [false, false], 'the second save must not re-enable A')
	assert.deepEqual(server.clients.map((c) => c.enabled), [false, false])
})

test('R1: the intents queued behind a refused save are dropped, not sent on the reloaded page', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
	const { cards, log } = await page(server.serve)
	server.revision = 9 // another administrator saved after this page loaded
	const [a, b] = cards().map((card) => card.querySelector('input.checkbox'))
	a.checked = false
	const first = a.fire('change')
	b.checked = false
	const second = b.fire('change')
	await new Promise(setImmediate)
	server.release()
	await Promise.all([first, second])
	assert.equal(server.puts.length, 1, 'only the refused save was sent; the one behind it was dropped')
	assert.deepEqual(server.clients.map((c) => c.enabled), [true, true])
	assert.ok(cards().every((card) => card.querySelector('input.checkbox').checked), 'the page shows what is stored')
	assert.equal(log.errors.length, 1)
})
