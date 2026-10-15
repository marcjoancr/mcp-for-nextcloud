/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of js/admin-inspector.js, using only Node's built-in test runner and a minimal DOM. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.value = ''
		this.hidden = false
		this._text = ''
	}
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = children; this._text = '' }
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
}
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const IDS = ['', '-form', '-user', '-status', '-alert', '-access', '-resources', '-path-form', '-path', '-path-result',
	'-calendar-form', '-calendar-module', '-calendar', '-calendar-operation', '-calendar-result']
const row = (module, operation, allowed, gate, source, extra = {}) => ({ module, operation, allowed, reasonCode: gate ?? 'allowed',
	blockingGate: gate, grant: { allowed, source, groupIds: [] }, scope: { mode: 'unrestricted', count: null }, ...extra })
const matrix = { revision: '7', operations: [
	row('notes', 'read', true, null, 'default'),
	row('notes', 'edit', false, 'grant', 'default'),
	row('calendar', 'read', null, 'grant_unknown', 'unknown', { grant: { allowed: null, source: 'unknown', groupIds: ['ldap-team'] } }),
	row('calendar', 'edit', false, 'service', 'individual_allow', { grant: { allowed: true, source: 'individual_allow', groupIds: [] } }),
] }

async function app(handler) {
	const ids = Object.fromEntries(IDS.map((suffix) => ['mcp-inspector' + suffix, new Element('div')]))
	ids['mcp-inspector-calendar-module'].value = 'calendar'
	const document = { getElementById: (id) => ids[id], createElement: (tag) => new Element(tag) }
	const calls = []
	const context = {
		document, URLSearchParams,
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		fetch: async (url, options) => {
			calls.push({ url, options })
			const [status, body] = (await handler?.(url)) || (url.startsWith('/apps/mcp/api/inspector/access') ? [200, matrix]
				: url.includes('/candidates') ? [200, { items: [{ id: '11', name: 'Personal' }] }] : [200, {}])
			return { ok: status < 400, status, json: async () => body }
		},
	}
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/admin-inspector.js'), 'utf8'), context)
	const submit = async (id) => { await ids[id].listeners.submit({ preventDefault() {} }); await tick() }
	return { ids, calls, submit }
}

test('T9: every operation shows its result, first gate and grant; unknown is never shown as allowed', async () => {
	const ui = await app()
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	const rows = ui.ids['mcp-inspector-access'].all('tr').slice(1).map((tr) => tr.all('th').concat(tr.all('td')).map((cell) => cell.textContent))
	assert.deepEqual(rows, [
		['Notes: read', 'Allowed', '—', 'Default'],
		['Notes: edit', 'Blocked', 'Not granted.', 'Default'],
		['Calendar: read', 'Unknown', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.', 'Group awaiting confirmation: ldap-team'],
		['Calendar: edit', 'Blocked', 'The MCP service is switched off.', 'Individual: allowed'],
	])
	assert.match(ui.calls[0].url, /^\/apps\/mcp\/api\/inspector\/access\?uid=alice$/)
	assert.equal(ui.calls[0].options.headers.requesttoken, 'token', 'the CSRF token travels with every question')
	assert.equal(ui.ids['mcp-inspector-resources'].hidden, false)
	assert.deepEqual(ui.ids['mcp-inspector-calendar-operation'].all('option').map((o) => o.value), ['read', 'edit'])
})

test('A5: a path answer names the folder an exclusion is inherited from, for the administrator', async () => {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/path')
		? [200, { reason: 'excluded', gate: 'exclusion', exclusion: 'tag_folder', folder: '/Secret' }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	ui.ids['mcp-inspector-path'].value = 'Secret/plan.md'
	await ui.submit('mcp-inspector-path-form')
	assert.equal(ui.ids['mcp-inspector-path-result'].textContent, 'Not reached: it is inside the excluded folder /Secret.')
	assert.match(ui.calls.at(-1).url, /path=%2FSecret%2Fplan\.md/)
})

test('S13: a gate that could not be evaluated is named, never reported as reached', async () => {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/calendar') ? [200, { reason: 'not_evaluated', gate: 'native_acl' }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	// A browser selects the first option; the minimal DOM needs it set.
	ui.ids['mcp-inspector-calendar'].value = '11'
	ui.ids['mcp-inspector-calendar-operation'].value = 'read'
	await ui.submit('mcp-inspector-calendar-form')
	assert.equal(ui.ids['mcp-inspector-calendar-result'].textContent, 'Not confirmed: the Nextcloud permissions could not be checked, so access is not known.')
	assert.match(ui.calls.at(-1).url, /module=calendar&calendarId=11&operation=read/)
})

test('a refused or unavailable inspection shows no stale matrix', async () => {
	for (const [status, message] of [[403, 'Only administrators can inspect access.'], [503, 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.']]) {
		const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [status, { error: 'x' }] : undefined)
		ui.ids['mcp-inspector-user'].value = 'alice'
		await ui.submit('mcp-inspector-form')
		assert.equal(ui.ids['mcp-inspector-alert'].textContent, message)
		assert.equal(ui.ids['mcp-inspector-access'].children.length, 0)
		assert.equal(ui.ids['mcp-inspector-resources'].hidden, true)
	}
})
