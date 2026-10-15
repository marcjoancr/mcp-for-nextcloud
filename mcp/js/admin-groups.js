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
	let page = 1
	let listSeq = 0
	let detailSeq = 0
	let current = null
	let draft = {}
	let preview = null
	let busy = false
	let timer
	let sayTimer
	/** Milliseconds between clearing a live region and writing into it. */
	const SAY_DELAY = 60

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
	function names(keys) {
		return keys.map((key) => {
			const [module, operation] = key.split(':')
			return module === '_system' ? t('mcp', 'Can connect') : moduleName(module) + ': ' + operationName(operation)
		}).join(', ') || '—'
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
	async function loadList() {
		const seq = ++listSeq
		list.setAttribute('aria-busy', 'true')
		prev.disabled = next.disabled = true
		try {
			const data = await api('GET', '/api/group-directory?' + new URLSearchParams({ search: search.value.trim(), page: String(page) }))
			if (seq !== listSeq) return
			list.replaceChildren(...data.groups.map((group) => {
				const reason = diagnosis(group)
				const button = el('button', { type: 'button', textContent: group.groupId === null ? group.displayName : group.displayName + ' (' + group.groupId + ')', disabled: group.invalidId === true, onclick: () => open(group.groupId), })
				return el('li', {}, [button, el('span', { className: 'mcp-hint', textContent: reason })])
			}))
			if (!data.groups.length) list.append(el('li', { textContent: t('mcp', 'No groups found.') }))
			prev.disabled = page <= 1
			next.disabled = !data.hasMore
			say('')
		} catch (e) {
			if (seq !== listSeq) return
			list.replaceChildren(el('li', {}, [t('mcp', 'Could not load groups.'), retry(loadList)]))
			say(t('mcp', 'Could not load groups.'), true)
		} finally {
			if (seq === listSeq) list.setAttribute('aria-busy', 'false')
		}
	}
	function retry(action) {
		return el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
	}
	async function open(gid, preserve = false, keepMessage = false) {
		if (busy || (!preserve && !discardDraft())) return
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
			render()
			detail.querySelector('h3').focus()
		} catch (e) {
			if (seq !== detailSeq) return
			detail.replaceChildren(retry(() => open(gid, preserve)))
			say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
		}
	}
	function render() {
		const heading = el('h3', { tabIndex: -1, textContent: current.groupId })
		heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
		const fields = el('fieldset', { disabled: busy || current.trusted !== true })
		fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		fields.append(el('legend', { textContent: t('mcp', 'Group grants') }))
		const stats = current.counts
		const summary = el('p', { textContent: t('mcp', 'Members: {members}. Members with any individual override: {overrides}.', { members: count(stats.members), overrides: count(stats.overrides) }) })
		const comparison = el('div')
		function updateComparison() {
			const before = grantKeys(current.grants)
			const after = grantKeys(draft)
			comparison.replaceChildren()
			if (!dirty()) return
			comparison.append(el('p', { textContent: t('mcp', 'Only in draft, not saved: {permissions}.', { permissions: names(after.filter((key) => !before.includes(key))) }) }),
				el('p', { textContent: t('mcp', 'Saved, outside the draft: {permissions}.', { permissions: names(before.filter((key) => !after.includes(key))) }) }))
		}
		updateComparison()
		const table = el('table', { className: 'mcp-group-counts' })
		table.append(el('caption', { textContent: t('mcp', 'Individual overrides count even when they match inheritance. Different results are counted separately.') }),
			el('thead', {}, [el('tr', {}, [t('mcp', 'Permission'), t('mcp', 'Members with overrides'), t('mcp', 'Different results')].map((textContent) => el('th', { scope: 'col', textContent }))) ]))
		const body = el('tbody')
		for (const [module, operations] of Object.entries(current.catalog)) {
			const section = el('div', { className: 'mcp-group-permissions' })
			for (const operation of operations) {
				const name = module === '_system' ? t('mcp', 'Can connect') : moduleName(module) + ': ' + operationName(operation)
				const box = el('input', { type: 'checkbox', checked: (draft[module] || []).includes(operation) })
				box.addEventListener('change', () => {
					const values = new Set(draft[module] || [])
					box.checked ? values.add(operation) : values.delete(operation)
					if (values.size) draft[module] = [...values].sort()
					else delete draft[module]
					preview = null
					save.disabled = true
					impact.replaceChildren()
					updateComparison()
				})
				section.append(el('label', {}, [box, ' ' + name]))
				const values = stats.permissions?.[module]?.[operation]
				body.append(el('tr', {}, [el('th', { scope: 'row', textContent: name }), el('td', { textContent: count(values?.overrides) }), el('td', { textContent: count(values?.different) })]))
			}
			fields.append(section)
		}
		table.append(body)
		const impact = el('div', { className: 'mcp-group-impact' })
		if (preview) {
			const before = grantKeys(preview.current || current.grants)
			const after = grantKeys(preview.grants || draft)
			impact.append(el('p', { textContent: t('mcp', 'Permissions added: {permissions}.', { permissions: names(after.filter((key) => !before.includes(key))) }) }),
				el('p', { textContent: t('mcp', 'Permissions removed: {permissions}.', { permissions: names(before.filter((key) => !after.includes(key))) }) }),
				el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }))
			impact.append(el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: count(preview.members), revokes: count(preview.revokes) }) }))
			if (preview.withdraws) impact.append(el('p', { textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
		}
		const review = el('button', { type: 'button', textContent: t('mcp', 'Preview group changes'), disabled: busy || current.trusted !== true, onclick: () => run('preview') })
		const save = el('button', { type: 'button', textContent: t('mcp', 'Save group policy'), disabled: busy || !preview, onclick: () => run('save') })
		const confirm = el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: busy || current.trusted !== true, onclick: () => {
			if (!discardDraft()) return
			draft = copyGrants(current.grants)
			run('preview')
		} })
		confirm.hidden = !['needs_confirm', 'pending_delete'].includes(current.state)
		const clear = el('button', { type: 'button', textContent: t('mcp', 'Preview clearing group policy'), disabled: busy || Object.keys(current.grants).length === 0, onclick: () => {
			if (!discardDraft()) return
			draft = {}
			run('preview')
		} })
		for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		detail.replaceChildren(heading, el('p', { id: 'mcp-group-diagnosis', className: 'mcp-hint', textContent: diagnosis(current) }), summary, fields, comparison, table, review, confirm, clear, impact, save)
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
			else {
				render()
				const target = action === 'preview' && preview ? detail.querySelector('button:last-child') : detail.querySelector('h3')
				target?.focus()
			}
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
