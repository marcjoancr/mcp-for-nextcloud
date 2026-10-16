/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the access levels (S2 of the admin redesign): the level ↔ operations mapping of js/admin-levels.js against the
 * catalog of GrantPolicy, and the level cell of js/admin-grants.js, cut out of the source and run against stubs as in
 * admin-revision.test.cjs. A level is presentation only: the server keeps receiving operations.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const context = {}
vm.runInNewContext(fs.readFileSync(path.join(root, 'js/admin-levels.js'), 'utf8'), context)
const Levels = context.McpAdminLevels
const plain = (value) => JSON.parse(JSON.stringify(value))

/** GrantPolicy::CATALOG as PHP declares it: module => list of operations. */
function catalog() {
	const policy = fs.readFileSync(path.join(root, 'lib/Service/GrantPolicy.php'), 'utf8')
	const body = policy.slice(policy.indexOf('public const CATALOG = ['), policy.indexOf('];', policy.indexOf('public const CATALOG = [')))
	const out = {}
	for (const [, module, operations] of body.matchAll(/'(\w+)' => \[([^\]]*)\]/g)) {
		out[module] = [...operations.matchAll(/'(\w+)'/g)].map((match) => match[1])
	}
	assert.ok(Object.keys(out).length >= 9, 'the catalog was read from GrantPolicy.php')
	return out
}

test('every operation of the catalog sits in exactly one tier, and the tiers name nothing else', () => {
	const modules = catalog()
	assert.deepEqual(Object.keys(Levels.TIERS).sort(), Object.keys(modules).sort())
	for (const [module, operations] of Object.entries(modules)) {
		const listed = Levels.TIERS[module].flat()
		assert.deepEqual([...listed].sort(), [...operations].sort(), module)
		assert.equal(new Set(listed).size, listed.length, module + ' has no operation twice')
		assert.deepEqual(plain(Levels.TIERS[module][0]), ['read'], module + ' starts with Read')
	}
})

test('levels are None, Read, Edit and Full, or None and Read for a module with one operation', () => {
	const modules = catalog()
	for (const module of ['files', 'notes', 'deck', 'calendar', 'contacts', 'tasks', 'talk']) {
		assert.deepEqual(plain(Levels.levels(module, modules[module])), ['none', 'read', 'edit', 'full'], module)
	}
	assert.deepEqual(plain(Levels.levels('people', modules.people)), ['none', 'read'])
	assert.deepEqual(plain(Levels.levels('logs', modules.logs)), ['none', 'read'])
})

test('the documented mapping: each level adds its tier to the one below', () => {
	const modules = catalog()
	const expect = {
		files: { read: ['read'], edit: ['read', 'edit', 'create', 'move', 'restore'], full: modules.files },
		notes: { read: ['read'], edit: ['read', 'create', 'edit', 'move'], full: modules.notes },
		calendar: { read: ['read'], edit: ['read', 'create', 'edit', 'move'], full: modules.calendar },
		contacts: { read: ['read'], edit: ['read', 'create', 'edit'], full: modules.contacts },
		talk: { read: ['read'], edit: ['read', 'reply', 'attach', 'quote'], full: modules.talk },
	}
	for (const [module, byLevel] of Object.entries(expect)) {
		assert.deepEqual(plain(Levels.operationsFor(module, modules[module], 'none')), [], module + ' none')
		for (const [level, operations] of Object.entries(byLevel)) {
			assert.deepEqual([...Levels.operationsFor(module, modules[module], level)].sort(), [...operations].sort(), module + ' ' + level)
		}
	}
	assert.throws(() => Levels.operationsFor('logs', modules.logs, 'edit'), /Unknown level/)
})

test('a level read back from its own operations is that level, for every module', () => {
	for (const [module, operations] of Object.entries(catalog())) {
		for (const level of Levels.levels(module, operations)) {
			const allowed = Levels.operationsFor(module, operations, level)
			const values = Object.fromEntries(operations.map((operation) => [operation, allowed.includes(operation)]))
			assert.equal(Levels.levelOf(module, operations, values), level, module + ' ' + level)
		}
	}
})

test('a set that is no level is custom, and any unknown or missing value makes the module unknown', () => {
	const files = catalog().files
	const values = (allowed) => Object.fromEntries(files.map((operation) => [operation, allowed.includes(operation)]))
	assert.equal(Levels.levelOf('files', files, values(['read', 'share'])), 'custom')
	assert.equal(Levels.levelOf('files', files, values(['edit'])), 'custom', 'editing without reading is no level')
	assert.equal(Levels.levelOf('files', files, values(['read', 'edit'])), 'custom', 'part of a tier is no level')
	assert.equal(Levels.levelOf('files', files, { ...values(['read']), share: null }), 'unknown')
	const missing = values(['read'])
	delete missing.link
	assert.equal(Levels.levelOf('files', files, missing), 'unknown')
})

test('an operation the tiers do not name goes to the last tier, never to a lower level', () => {
	const operations = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link', 'archive']
	assert.ok(Levels.operationsFor('files', operations, 'full').includes('archive'))
	assert.ok(!Levels.operationsFor('files', operations, 'edit').includes('archive'))
	// A module the tiers do not know: Read for its read, Full for the rest.
	assert.deepEqual(plain(Levels.levels('maps', ['read', 'pin', 'route'])), ['none', 'read', 'full'])
	assert.deepEqual(plain(Levels.operationsFor('maps', ['read', 'pin', 'route'], 'read')), ['read'])
	assert.deepEqual(plain(Levels.levels('stamps', ['stamp'])), ['none', 'read'], 'one operation is one level')
})

test('the origin of a module: invalid, then individual, then unknown group, then group, then default', () => {
	const ops = ['read', 'edit']
	const at = (read, edit) => ({ read, edit })
	const o = (source, groupIds = []) => ({ source, groupIds })
	assert.deepEqual(plain(Levels.originOf(ops, at(o('default'), o('default')))), o('default'))
	assert.deepEqual(plain(Levels.originOf(ops, undefined)), o('default'), 'an API row without origins reads as default')
	assert.deepEqual(plain(Levels.originOf(ops, at(o('group', ['b', 'a']), o('group', ['a'])))), o('group', ['a', 'b']))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('group', ['a']), o('unknown', ['f'])))), o('unknown', ['f']))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('individual_deny'), o('group', ['a'])))), o('individual'))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('individual_allow'), o('invalid')))), o('invalid'))
})

/* The level cell of js/admin-grants.js. */

const grants = fs.readFileSync(path.join(root, 'js/admin-grants.js'), 'utf8')
function cut(declaration) {
	const start = grants.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration + ' was found in js/admin-grants.js')
	return grants.slice(start, grants.indexOf('\n\t}\n', start) + 4)
}

class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.attributes = {}; this.classes = new Set(); this.dataset = {}; this.title = ''; this._text = '' }
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	get classList() { return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name), contains: (name) => this.classes.has(name) } }
	append(...children) { this.children.push(...children) }
	setAttribute(key, value) { this.attributes[key] = value }
	set textContent(value) { this._text = String(value) }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	all() { return this.children.flatMap((child) => typeof child === 'string' ? [] : [child, ...child.all()]) }
	find(name) { return this.all().find((node) => node.classes.has(name)) ?? null }
}

function cellKit({ appsEnabled = true, scope = null } = {}) {
	const opened = []
	const sandbox = {
		window: { McpAdminLevels: Levels, McpLevelPopover: { open: (trigger, uid, module) => opened.push([module, uid, trigger.classes.has('mcp-level')]) }, McpScopeUi: { indicator: (uid, module, value) => Object.assign(new Node('button'), { className: 'mcp-scope-indicator', textContent: 'Restricted to ' + value.count }) } },
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		el: (tag, props = {}, children = []) => {
			const node = new Node(tag)
			for (const [key, value] of Object.entries(props)) node[key] = value
			node.append(...children)
			return node
		},
		moduleLabels: () => ({ files: 'Files', logs: 'Server log' }),
		operationLabels: () => ({ read: 'read', edit: 'edit', create: 'create', move: 'move', restore: 'restore', share: 'share', link: 'public link' }),
		unavailableReason: () => appsEnabled ? null : 'This app is unavailable for this user; the saved permission is kept.',
		reloadLocked: () => {},
	}
	vm.runInNewContext([cut('const PERMISSION_STATES = {'), cut('function paintState('), cut('function levelLabels('), cut('function originText('), cut('function levelCell('), cut('function levelButton(')].join('\n')
		+ '\nthis.levelCell = levelCell', sandbox)
	const files = catalog().files
	const user = (allowed, sources = {}) => ({
		uid: 'ana',
		displayName: 'Ana Souza',
		scope,
		grants: { files: Object.fromEntries(files.map((operation) => [operation, allowed === null ? null : allowed.includes(operation)])) },
		origins: { grants: { files: Object.fromEntries(files.map((operation) => [operation, sources[operation] || { source: 'default', groupIds: [] }])) } },
	})
	return { cell: (u) => sandbox.levelCell(u, 'files', files, 0), user, opened }
}

test('a level cell names the level in words and its origin by an icon, never by colour alone', () => {
	const kit = cellKit()
	const edit = ['read', 'edit', 'create', 'move', 'restore']
	const individual = kit.cell(kit.user(edit, { edit: { source: 'individual_allow', groupIds: [] } }))
	const button = individual.find('mcp-level')
	assert.equal(button.find('mcp-level-word').textContent, 'Edit')
	assert.ok(button.classes.has('mcp-level-individual'))
	const icon = button.find('mcp-origin-icon')
	assert.ok(icon.classes.has('icon-user'))
	assert.equal(icon.attributes['aria-hidden'], 'true', 'the icon is decoration; the words carry the state')
	assert.equal(button.attributes['aria-label'], 'Ana Souza, Files: Edit. Set for this person.')
	assert.equal(button.attributes['aria-haspopup'], 'dialog')
	assert.equal(button.attributes['aria-expanded'], 'false')
	assert.equal(individual.title, 'Edit. Allows: read, edit, create, move, restore — Set for this person')
	assert.equal(individual.dataset.level, 'edit')
	assert.ok(individual.classes.has('mcp-state-allowed'))

	const group = kit.cell(kit.user(['read'], { read: { source: 'group', groupIds: ['comercial'] } })).find('mcp-level')
	assert.ok(group.find('mcp-origin-icon').classes.has('icon-group'))
	assert.match(group.attributes['aria-label'], /Read\. Granted by groups: comercial\./)

	const none = kit.cell(kit.user([]))
	assert.equal(none.find('mcp-level').find('mcp-level-word').textContent, 'None')
	assert.ok(none.find('mcp-level').classes.has('mcp-level-none'))
	assert.equal(none.find('mcp-origin-icon'), null, 'the default has no icon')
	assert.ok(none.classes.has('mcp-state-denied'))
	assert.match(none.title, /Allows nothing — Default/)

	assert.equal(kit.cell(kit.user(['read', 'share'])).find('mcp-level-word').textContent, 'Custom')
})

test('choosing a level cell opens its popover for that user and module', async () => {
	const kit = cellKit()
	kit.cell(kit.user(['read'])).find('mcp-level').onclick()
	assert.deepEqual(kit.opened, [['files', 'ana', true]])
})

test('an unavailable module keeps the no entry mark without a control, and an unknown one the dash with a retry', () => {
	const kit = cellKit({ appsEnabled: false })
	const cell = kit.cell(kit.user(['read']))
	assert.equal(cell.find('mcp-level'), null, 'no control: an unavailable cell never looks editable')
	const mark = cell.find('mcp-state-mark')
	assert.equal(mark.textContent, '⊘')
	assert.equal(mark.attributes['aria-hidden'], 'true')
	assert.equal(cell.find('mcp-level-word').textContent, 'Unavailable')
	assert.ok(cell.classes.has('mcp-state-unavailable'))
	assert.equal(cell.find('mcp-sr-only').textContent, 'Ana Souza — Files: Unavailable: This app is unavailable for this user; the saved permission is kept.')

	const unknown = cellKit().cell(kit.user(null))
	assert.equal(unknown.find('mcp-state-mark').textContent, '—')
	assert.equal(unknown.find('mcp-level-word').textContent, 'Unknown')
	assert.ok(unknown.all().some((node) => node.tag === 'button' && node.textContent === 'Retry'))
})

test('the resource scope mark stays in the module cell', () => {
	const kit = cellKit({ scope: { files: { mode: 'restricted', count: 3 } } })
	const u = kit.user(['read'])
	u.scope = { files: { mode: 'restricted', count: 3 } }
	assert.equal(kit.cell(u).find('mcp-scope-indicator').textContent, 'Restricted to 3')
})
