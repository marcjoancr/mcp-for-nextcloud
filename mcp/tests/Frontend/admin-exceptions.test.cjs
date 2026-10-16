/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of js/admin-exceptions.js (S4 of the admin redesign): of the individual values on the page, which are
 * real exceptions and which only repeat what the person inherits, read from the read-only preview the reset uses. The
 * script runs unchanged against a stub of window.McpAdminMatrix; it never writes, and the review goes through the reset.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const REVISION = '9007199254740993'

const o = (source, groupIds = []) => ({ source, groupIds })
/** A row of the API with Files read and edit only; `origins` maps an operation ("eligible" for Can connect) to a source. */
function user(uid, values, origins = {}) {
	return {
		uid,
		eligible: values.eligible ?? true,
		grants: { files: { read: values.read ?? true, edit: values.edit ?? false } },
		origins: { eligible: origins.eligible || o('default'), grants: { files: { read: origins.read || o('default'), edit: origins.edit || o('default') } } },
	}
}

function setup({ users, preview, popoverOpen = false } = {}) {
	const listeners = {}
	const element = (hidden = false) => ({ hidden, textContent: '', listeners: {}, addEventListener(name, action) { (this.listeners[name] ||= []).push(action) } })
	const elements = { 'mcp-redundant-notice': element(true), 'mcp-redundant-notice-text': element(), 'mcp-redundant-review': element(), 'mcp-level-popover': element(!popoverOpen) }
	const document = { activeElement: null, getElementById: (id) => elements[id] ?? null }
	const calls = []
	const reviews = []
	let refreshes = 0
	let data = { revision: REVISION, users: users || [] }
	const matrix = {
		data: () => data,
		api: async (method, url, body) => {
			calls.push({ method, url, body: plain(body) })
			if (preview instanceof Error) throw preview
			return preview(body)
		},
		refresh: () => { refreshes++; fire('mcp-matrix-rendered') },
	}
	const window = {
		McpAdminMatrix: matrix,
		McpAdminOverrides: { reviewCells: (cells, trigger) => reviews.push({ cells: plain(cells), trigger }) },
		addEventListener: (name, action) => { (listeners[name] ||= []).push(action) },
	}
	function fire(name) { for (const action of listeners[name] || []) action() }
	const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', count)
	vm.runInNewContext(fs.readFileSync(path.join(root, 'js/admin-exceptions.js'), 'utf8'), { window, document, n })
	return {
		api: window.McpAdminExceptions,
		elements,
		calls,
		reviews,
		refreshes: () => refreshes,
		rendered: async () => { fire('mcp-matrix-rendered'); await tick() },
		popoverClosed: async () => { elements['mcp-level-popover'].hidden = true; fire('mcp-level-popover-closed'); await tick() },
		setData: (next) => { data = next },
	}
}

/** A preview whose "after" per cell comes from `after(uid, module, operation)`. */
const previewOf = (after, revision = REVISION) => (body) => ({
	revision,
	cells: body.commands.map((command) => ({ ...command, before: { allowed: true }, after: { allowed: after(command.uid, command.module, command.operation), source: 'default', groupIds: [] } })),
})

test('asks the preview once for exactly the individual cells of the page, Can connect included, as inherit', async () => {
	const users = [
		user('ana', { edit: true }, { eligible: o('individual_allow'), edit: o('individual_allow') }),
		user('bruno', {}, {}),
	]
	const kit = setup({ users, preview: previewOf(() => true) })
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'pending', 'nothing is known before the page is drawn')
	await kit.rendered()
	assert.deepEqual(kit.calls, [{ method: 'POST', url: '/api/grants/commands/preview', body: { commands: [
		{ uid: 'ana', module: null, operation: 'eligible', state: 'inherit' },
		{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' },
	] } }])
})

test('an individual value equal to what the person inherits is redundant; a different one, an invalid one or an unknown result is an exception', async () => {
	const users = [user('ana', { read: true, edit: false, eligible: true }, { read: o('individual_allow'), edit: o('individual_deny'), eligible: o('invalid') })]
	// Without her values Ana would read (same: redundant), edit (different: exception) and connect (the same value as
	// shown, but the stored value is invalid: still an exception).
	const kit = setup({ users, preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.api.kindOf('ana', 'files', 'read').kind, 'redundant')
	assert.deepEqual(plain(kit.api.kindOf('ana', 'files', 'read').after), { source: 'default', groupIds: [] })
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'exception')
	assert.equal(kit.api.kindOf('ana', null, 'eligible').kind, 'exception', 'an invalid stored value is always an exception')
	assert.equal(kit.api.kindOfAnswer({ before: true, source: 'individual_allow' }, { after: { allowed: null } }), 'exception')
	assert.equal(kit.api.kindOfAnswer({ before: true, source: 'individual_allow' }, undefined), 'exception')
	assert.equal(kit.api.kindOfAnswer({ before: null, source: 'individual_allow' }, { after: { allowed: true } }), 'exception')
})

test('the table is drawn again once with the answer, and never asked again for the same revision and users', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.refreshes(), 1)
	assert.equal(kit.calls.length, 1, 'the redraw it caused does not ask again')
	await kit.rendered()
	assert.equal(kit.calls.length, 1)
	kit.setData({ revision: '9007199254740994', users: [user('ana', {}, { read: o('individual_allow') })] })
	assert.equal(kit.api.kindOf('ana', 'files', 'read').kind, 'pending', 'a new revision is unknown until read')
	await kit.rendered()
	assert.equal(kit.calls.length, 2)
})

test('an error or an answer read at another revision marks every individual value as an exception, never fewer', async () => {
	const failed = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: new Error('503') })
	await failed.rendered()
	assert.equal(failed.api.status(), 'failed')
	assert.equal(failed.api.kindOf('ana', 'files', 'read').kind, 'exception')
	assert.equal(failed.elements['mcp-redundant-notice'].hidden, true)
	assert.equal(failed.refreshes(), 1, 'the marks are drawn')

	const stale = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true, '1') })
	await stale.rendered()
	assert.equal(stale.api.kindOf('ana', 'files', 'read').kind, 'exception')
})

test('the notice counts the redundant values of the page and the review opens the reset for exactly those cells', async () => {
	const users = [
		user('ana', { edit: false }, { read: o('individual_allow'), edit: o('individual_deny') }),
		user('bruno', { edit: true }, { edit: o('individual_allow') }),
	]
	// Inherited: read allowed, edit denied for both. Ana's two values repeat it; Bruno's Allow of edit is an exception.
	const kit = setup({ users, preview: previewOf((uid, module, operation) => operation === 'read') })
	await kit.rendered()
	assert.equal(kit.elements['mcp-redundant-notice'].hidden, false)
	assert.match(kit.elements['mcp-redundant-notice-text'].textContent, /^On this page, 2 individual values repeat what the people would inherit\./)
	assert.deepEqual(plain(kit.api.redundantCells()), [{ uid: 'ana', module: 'files', operation: 'read' }, { uid: 'ana', module: 'files', operation: 'edit' }])
	for (const action of kit.elements['mcp-redundant-review'].listeners.click) action()
	assert.deepEqual(kit.reviews.map((review) => review.cells), [[{ uid: 'ana', module: 'files', operation: 'read' }, { uid: 'ana', module: 'files', operation: 'edit' }]])
	assert.equal(kit.reviews[0].trigger, kit.elements['mcp-redundant-review'])

	const one = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true) })
	await one.rendered()
	assert.match(one.elements['mcp-redundant-notice-text'].textContent, /^On this page, 1 individual value repeats/)
	const none = setup({ users: [user('ana', { edit: true }, { edit: o('individual_allow') })], preview: previewOf(() => false) })
	await none.rendered()
	assert.equal(none.elements['mcp-redundant-notice'].hidden, true, 'no notice without a redundant value')
	assert.equal(none.calls.length, 1)
})

test('a page without individual values asks nothing', async () => {
	const kit = setup({ users: [user('ana', {})], preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.calls.length, 0)
	assert.equal(kit.api.status(), 'done')
	assert.equal(kit.refreshes(), 0)
})

test('the redraw waits while the level popover is open and comes when it closes', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), popoverOpen: true })
	await kit.rendered()
	assert.equal(kit.refreshes(), 0, 'the answer does not close the popover in use')
	await kit.popoverClosed()
	assert.equal(kit.refreshes(), 1)
})

test('cells are split so that no preview has more than 50 users or 2,500 cells', () => {
	const kit = setup({ users: [], preview: previewOf(() => true) })
	const cells = (count) => Array.from({ length: count }, () => ({}))
	assert.deepEqual(plain(kit.api.chunks(Array.from({ length: 51 }, () => cells(1))).map((chunk) => chunk.length)), [50, 1])
	assert.deepEqual(plain(kit.api.chunks([cells(1500), cells(1500)]).map((chunk) => chunk.length)), [1500, 1500])
	assert.deepEqual(plain(kit.api.chunks([cells(0), cells(3)]).map((chunk) => chunk.length)), [3], 'users without individual values do not count')
})
