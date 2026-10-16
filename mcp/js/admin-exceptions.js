/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Exceptions of the permission matrix: of the individual values on the page, which differ from what the person would
 * inherit (a real exception) and which only repeat it (redundant).
 *
 * Every value 1.0.8 saved is an individual value in 1.1.0, so after an upgrade nearly every cell is individual and a mark
 * on each of them says nothing. The table only knows a cell's current source, never what the groups and defaults would
 * give without it, so once the page is drawn this asks the read-only preview (POST /apps/mcp/api/grants/commands/preview,
 * the one the reset uses) for every individual cell of the page and compares "before" with "after". Only a real exception
 * keeps the person icon; a redundant value is drawn like what it repeats, and a notice offers to review them.
 *
 * What the page may promise: until the answer comes no cell is marked as an exception, and when it cannot be read (an
 * error, or an answer read at another revision than the table) every individual value is marked, never fewer. An invalid
 * stored value is always an exception. Removing a redundant value changes nothing now, but it changes what happens when
 * the person leaves a group, so it is only ever done through the reset preview, never by itself.
 *
 * Plain browser JavaScript, no build step. js/admin-grants.js asks kindOf() while it draws a level cell; this file never
 * writes anything.
 */
(function () {
	'use strict'

	const matrix = window.McpAdminMatrix
	if (!matrix) {
		return
	}

	/** Most cells and users one preview accepts (PolicyWriter::MAX_CELLS and MAX_UIDS). */
	const MAX_CELLS = 2500
	const MAX_USERS = 50
	const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * The classification of the page on screen: key (revision and users of the page it was read for), status (pending,
	 * done or failed) and, per cell, {kind: exception|redundant, after}.
	 */
	let result = { key: null, status: 'pending', cells: new Map() }
	/** A redraw waits while the level popover is open, so the answer never closes what the administrator is using. */
	let refreshWaiting = false

	const notice = document.getElementById('mcp-redundant-notice')
	const noticeText = document.getElementById('mcp-redundant-notice-text')
	const reviewButton = document.getElementById('mcp-redundant-review')

	/** @return {string} key of one cell of one user */
	function cellKey(uid, module, operation) {
		return uid + '\0' + (module ?? '') + '\0' + operation
	}

	/** @return {string} key of the page on screen: a new revision or another set of users is another classification */
	function pageKey(data) {
		return data.revision + '\0' + data.users.map((user) => user.uid).join('\0')
	}

	/**
	 * @param {object} user row of the table
	 * @return {Array<{uid: string, module: string|null, operation: string, before: boolean|null, source: string}>} its
	 *     individual cells, "Can connect" included
	 */
	function individualCells(user) {
		const cells = []
		if (INDIVIDUAL.includes(user.origins?.eligible?.source)) {
			cells.push({ uid: user.uid, module: null, operation: 'eligible', before: user.eligible, source: user.origins.eligible.source })
		}
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (INDIVIDUAL.includes(origin?.source)) {
					cells.push({ uid: user.uid, module, operation, before: user.grants?.[module]?.[operation] ?? null, source: origin.source })
				}
			}
		}
		return cells
	}

	/** Splits the cells into requests the preview accepts: at most MAX_USERS users and MAX_CELLS cells each. */
	function chunks(byUser) {
		const out = []
		let current = []
		let users = 0
		for (const cells of byUser) {
			if (cells.length === 0) continue
			if (current.length > 0 && (users + 1 > MAX_USERS || current.length + cells.length > MAX_CELLS)) {
				out.push(current)
				current = []
				users = 0
			}
			current = current.concat(cells)
			users++
		}
		if (current.length > 0) out.push(current)
		return out
	}

	/**
	 * Whether an individual value is a real exception, given what the preview says the person would have without it.
	 *
	 * @param {{before: boolean|null, source: string}} cell an individual cell of the table
	 * @param {object|undefined} answer the preview's cell
	 * @return {string} exception or redundant
	 */
	function kindOfAnswer(cell, answer) {
		const after = answer?.after?.allowed
		if (cell.source === 'invalid' || cell.before === null || after === null || after === undefined) {
			return 'exception'
		}
		return after === cell.before ? 'redundant' : 'exception'
	}

	/** Reads the classification of the page on screen, once per revision and set of users. */
	async function classify() {
		const data = matrix.data()
		if (!data) {
			return
		}
		const key = pageKey(data)
		if (result.key === key) {
			renderNotice()
			return
		}
		const byUser = data.users.map(individualCells)
		const all = byUser.flat()
		result = { key, status: all.length === 0 ? 'done' : 'pending', cells: new Map() }
		renderNotice()
		if (all.length === 0) {
			return
		}
		const open = result
		try {
			const answers = new Map()
			for (const chunk of chunks(byUser)) {
				const reply = await matrix.api('POST', '/api/grants/commands/preview', {
					commands: chunk.map((cell) => ({ uid: cell.uid, module: cell.module, operation: cell.operation, state: 'inherit' })),
				})
				// Read at another revision than the table: what it says may not be about the values on screen.
				if (typeof reply.revision !== 'string' || reply.revision !== data.revision) {
					throw new Error('stale')
				}
				for (const answer of Array.isArray(reply.cells) ? reply.cells : []) {
					answers.set(cellKey(answer.uid, answer.module ?? null, answer.operation), answer)
				}
			}
			if (result !== open) return
			for (const cell of all) {
				const answer = answers.get(cellKey(cell.uid, cell.module, cell.operation))
				open.cells.set(cellKey(cell.uid, cell.module, cell.operation), {
					kind: kindOfAnswer(cell, answer),
					after: answer?.after ? { source: answer.after.source, groupIds: answer.after.groupIds || [] } : null,
				})
			}
			open.status = 'done'
		} catch (e) {
			if (result !== open) return
			open.status = 'failed'
		}
		renderNotice()
		refresh()
	}

	/** Draws the table again with the classification, keeping the focus on the cell that had it. */
	function refresh() {
		const popover = document.getElementById('mcp-level-popover')
		if (popover && !popover.hidden) {
			refreshWaiting = true
			return
		}
		refreshWaiting = false
		const active = document.activeElement
		const cell = active?.closest?.('td')
		const uid = cell?.parentElement?.dataset?.uid
		const levelKey = cell?.dataset?.levelKey
		matrix.refresh()
		if (uid === undefined || levelKey === undefined) {
			return
		}
		const table = document.getElementById('mcp-matrix')
		const row = [...(table?.tBodies?.[0]?.rows || [])].find((candidate) => candidate.dataset.uid === uid)
		const again = [...(row?.cells || [])].find((candidate) => candidate.dataset.levelKey === levelKey)
		again?.querySelector('button')?.focus()
	}

	/** @return {Array<{uid: string, module: string|null, operation: string}>} the redundant cells of the page */
	function redundantCells() {
		if (result.status !== 'done') {
			return []
		}
		return [...result.cells.entries()].filter(([, value]) => value.kind === 'redundant').map(([key]) => {
			const [uid, module, operation] = key.split('\0')
			return { uid, module: module === '' ? null : module, operation }
		})
	}

	/** Shows how many individual values on the page only repeat what the person inherits, with a way to review them. */
	function renderNotice() {
		if (!notice || !noticeText) {
			return
		}
		const count = redundantCells().length
		notice.hidden = count === 0
		noticeText.textContent = count === 0 ? '' : n('mcp',
			'On this page, %n individual value repeats what the person would inherit. It changes nothing now, but it keeps a change of the groups from reaching that person.',
			'On this page, %n individual values repeat what the people would inherit. They change nothing now, but they keep a change of the groups from reaching those people.', count)
	}

	reviewButton?.addEventListener('click', () => {
		const cells = redundantCells()
		if (cells.length > 0) {
			window.McpAdminOverrides?.reviewCells(cells, reviewButton)
		}
	})

	/**
	 * How js/admin-grants.js marks an individual value: exception (the person icon), redundant (drawn like what it repeats,
	 * with that origin), or pending (no mark yet).
	 *
	 * @param {string} uid user
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} operation operation, "eligible" for "Can connect"
	 * @return {{kind: string, after: object|null}}
	 */
	function kindOf(uid, module, operation) {
		const data = matrix.data()
		if (!data || result.key !== pageKey(data) || result.status === 'pending') {
			return { kind: 'pending', after: null }
		}
		if (result.status === 'failed') {
			return { kind: 'exception', after: null }
		}
		return result.cells.get(cellKey(uid, module, operation)) || { kind: 'exception', after: null }
	}

	window.addEventListener('mcp-matrix-rendered', classify)
	window.addEventListener('mcp-level-popover-closed', () => {
		if (refreshWaiting) refresh()
	})

	window.McpAdminExceptions = { kindOf, redundantCells, kindOfAnswer, chunks, status: () => result.status }
})()
