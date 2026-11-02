// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createPresentation } from './access/presentation.js'

/** The selector represents the assignment, independently of the effective decision. */
export function ruleSelector(t, kind, label, value) {
	const select = document.createElement('select')
	select.setAttribute('aria-label', label)
	if (value === '') select.add(new Option(t('mcp', 'Choose a rule'), ''))
	const neutral = kind === 'group' ? t('mcp', 'Not assigned') : kind === 'user' ? t('mcp', 'Inherit') : t('mcp', 'Remove rule')
	select.add(new Option(neutral, 'inherit'))
	select.add(new Option(t('mcp', 'Allow'), 'allow'))
	select.add(new Option(t('mcp', 'Block'), 'deny'))
	select.value = value
	return select
}

/** Render selected group and person assignments alongside their saved decisions. */
export function renderComparison(host, {
	columns = [], query = '', differences = false, exceptions = false, expanded = new Set(),
	rowsSelected = new Set(), activeColumn = null, bulkMode = false, compact = false, t,
	onCell = () => {}, onRule = () => {}, onRow = () => {}, onExpand = () => {}, onMobileColumn = () => {},
	onPick = () => {}, onPersonGroups = () => {}, onInspect = () => {}, onScope = () => {}, onRemove = () => {},
}) {
	const el = (tag, props = {}, children = []) => {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props)) {
			if (key.startsWith('aria-') || key.startsWith('data-')) node.setAttribute(key, String(value))
			else node[key] = value
		}
		node.append(...children)
		return node
	}
	const icon = (state) => {
		const mark = state === true ? '✓' : state === false ? '×' : state === 'neutral' ? '—' : state === 'unavailable' ? '⊘' : '?'
		return el('span', { className: 'mcp-compare-status-icon', textContent: mark, 'aria-hidden': 'true' })
	}
	const moduleNames = createPresentation({ t }).moduleLabels()
	const names = new Map()
	const byColumn = new Map()
	const tools = new Map()
	for (const column of columns) {
		if (column.kind === 'group' && column.snapshot?.subject) names.set(column.snapshot.subject, column.label)
		for (const group of column.snapshot?.groups || []) names.set(group.id, group.displayName || group.id)
		const index = new Map()
		byColumn.set(column.key, index)
		for (const tool of column.snapshot?.tools || []) {
			index.set(tool.name, tool)
			if (!tools.has(tool.name)) tools.set(tool.name, tool)
		}
	}
	const catalogs = new Map()
	for (const tool of tools.values()) {
		if (!catalogs.has(tool.module)) catalogs.set(tool.module, [])
		catalogs.get(tool.module).push(tool)
	}
	for (const module of catalogs.keys()) catalogs.get(module).sort((a, b) => (a.title || a.name).localeCompare(b.title || b.name))

	const selectedKey = columns.some(column => column.key === activeColumn) ? activeColumn : columns[0]?.key
	const shownColumns = compact ? columns.filter(column => column.key === selectedKey) : columns
	const filterText = String(query || '').trim().toLocaleLowerCase()
	function snapshotTool(column, name) {
		return byColumn.get(column.key).get(name)
	}
	function draftValue(column, name) {
		return column.draft?.[name]
	}
	function explicitException(row) {
		return row?.state === 'allow' || row?.state === 'deny'
	}
	function effective(row, column) {
		if (!row) return null
		if (column.kind === 'user' && column.snapshot?.service?.effective !== true) return column.snapshot?.service?.effective ?? null
		return row.effective ?? null
	}
	function signature(column, name) {
		const row = snapshotTool(column, name)
		if (!row) return 'unavailable'
		if (column.kind === 'group') return row.state === 'allow' ? 'true' : row.state === 'deny' ? 'false' : 'inherit'
		return String(effective(row, column))
	}
	function passesFilters(tool) {
		if (filterText && ![tool.name, tool.title, tool.description, moduleNames[tool.module] || tool.module].join(' ').toLocaleLowerCase().includes(filterText)) return false
		if (differences && new Set(columns.map(column => signature(column, tool.name))).size < 2) return false
		if (exceptions && !columns.some(column => column.kind === 'user' && explicitException(snapshotTool(column, tool.name)))) return false
		return true
	}
	function provenance(row, column) {
		const gated = column.kind === 'user' && column.snapshot.service.effective !== true
		if (gated) row = column.snapshot.service
		const source = row?.source || row?.origin || ''
		const groupIds = row?.groupIds || []
		const groupNames = groupIds.map(id => names.get(id) || id).join(', ')
		if (gated && !groupNames) return row.origin || resultText(row.effective)
		if (groupNames && ['group_deny', 'group_allow', 'group', 'legacy_group'].includes(source)) return t('mcp', 'By {groups}', { groups: groupNames })
		if (row?.effective === false && ['group', 'group_allow', 'legacy_group', 'individual_allow', 'legacy_individual', 'default'].includes(source)) return row.origin || t('mcp', 'Could not be checked')
		if (source === 'group_deny' || source === 'group_allow' || source === 'group' || source === 'legacy_group') {
			return source === 'group_deny' ? t('mcp', 'Group rule: denied') : t('mcp', 'Group rule: allowed')
		}
		if (source === 'unknown') return groupNames ? t('mcp', 'Group awaiting confirmation: {groups}', { groups: groupNames }) : t('mcp', 'Could not be checked')
		if (source === 'individual_allow' || source === 'individual_deny' || source === 'individual' || source === 'legacy_individual') return t('mcp', 'Individual assignment')
		if (source === 'default') return t('mcp', 'Default')
		return row?.origin || t('mcp', 'Could not be checked')
	}
	function stateText(state, kind) {
		if (state === 'allow') return t('mcp', 'Allow')
		if (state === 'deny') return t('mcp', 'Block')
		return kind === 'group' ? t('mcp', 'Not assigned') : t('mcp', 'Inherit')
	}
	function resultText(value) {
		return value === true ? t('mcp', 'Allowed') : value === false ? t('mcp', 'Blocked') : t('mcp', 'Could not be checked')
	}
	function makeAction(label, callback, disabled = false) {
		const button = el('button', { type: 'button', className: 'mcp-compare-action', textContent: label, disabled })
		button.addEventListener('click', callback)
		return button
	}
	function header(column) {
		const cell = el('th', { scope: 'col', className: 'mcp-compare-identity' })
		const pick = el('button', { type: 'button', className: 'mcp-compare-pick', 'data-focus-action': 'pick', 'data-column-key': column.key }, [
			el('span', { className: 'mcp-compare-kind', textContent: column.kind === 'group' ? t('mcp', 'Group') : t('mcp', 'Person') }),
			el('strong', { className: 'mcp-compare-label', textContent: column.label }),
		])
		pick.setAttribute('aria-label', t('mcp', 'Choose comparison for {name}', { name: column.label }))
		pick.setAttribute('aria-haspopup', 'dialog')
		pick.setAttribute('aria-controls', 'mcp-tools-picker')
		pick.setAttribute('aria-expanded', 'false')
		pick.addEventListener('click', () => onPick(column.key, pick))
		cell.dataset.columnKey = column.key
		const bar = el('div', { className: 'mcp-compare-heading' }, [pick])
		cell.append(bar)
		if (column.kind === 'user' || columns.length > 1) {
			const details = el('details', { className: 'mcp-compare-menu' })
			const label = t('mcp', 'Actions for {name}', { name: column.label })
			details.append(el('summary', { 'aria-label': label, title: label, 'data-focus-action': 'actions', 'data-column-key': column.key }, [el('span', { 'aria-hidden': 'true', textContent: '…' })]))
			const actions = el('div', { className: 'mcp-compare-actions' })
			if (column.kind === 'user') {
				actions.append(makeAction(t('mcp', 'View groups'), () => onPersonGroups(column.key), !column.snapshot))
				actions.append(makeAction(t('mcp', 'Inspect access'), () => onInspect(column.key), !column.snapshot))
				actions.append(makeAction(t('mcp', 'Open Files scope'), () => onScope(column.key), !column.snapshot))
			}
			if (columns.length > 1) actions.append(makeAction(t('mcp', 'Remove {name}', { name: column.label }), () => onRemove(column.key)))
			details.append(actions)
			bar.append(details)
		}
		if (!column.snapshot) cell.append(el('span', { className: 'mcp-compare-load-error', role: 'status', textContent: t('mcp', 'Could not load this entry’s permissions.') }))
		if (column.kind === 'user' && column.snapshot?.service?.effective === false) cell.append(el('span', { className: 'mcp-compare-header-service-block', textContent: t('mcp', 'MCP service is blocked') }))
		return cell
	}
	function resultCell(column, name, title) {
		const service = name === '_service'
		const item = service ? column.snapshot?.service : snapshotTool(column, name)
		const cell = el('td', { className: 'mcp-compare-cell', 'data-column-key': column.key, 'data-tool': name })
		if (!item) {
			cell.classList.add('mcp-compare-unavailable')
			cell.setAttribute('aria-disabled', 'true')
			cell.append(column.snapshot ? t('mcp', 'Unavailable') : t('mcp', 'Could not load this entry’s permissions.'))
			return cell
		}
		const pending = draftValue(column, name)
		const args = { tool: title, name: column.label }
		const select = ruleSelector(t, column.kind, t('mcp', 'Rule for {tool} · {name}', args), pending ?? item.state)
		select.dataset.assignment = select.value
		select.dataset.focusAction = 'rule'
		select.dataset.tool = name
		select.dataset.columnKey = column.key
		select.addEventListener('change', () => onRule(column.key, name, select.value))
		const label = t('mcp', 'Details for {tool} · {name}', args)
		const info = el('button', {
			type: 'button', className: 'mcp-rule-info', title: label, 'aria-label': label,
			'aria-expanded': 'false', 'aria-controls': 'mcp-tools-detail',
			'data-focus-action': 'info', 'data-column-key': column.key, 'data-tool': name,
		}, [el('span', { 'aria-hidden': 'true', textContent: 'ⓘ' })])
		info.addEventListener('click', () => onCell(column.key, name, info))
		cell.append(el('div', { className: 'mcp-rule-controls' }, [select, info]))

		const person = column.kind === 'user'
		const decision = person ? effective(item, column) : item.state === 'allow' ? true : item.state === 'deny' ? false : 'neutral'
		const style = decision === 'neutral' ? 'neutral' : decision === true ? 'allowed' : decision === false ? 'blocked' : 'unknown'
		const summary = el('div', { className: 'mcp-compare-value mcp-compare-' + style })
		summary.append(el('span', { className: 'mcp-compare-mark' }, [icon(decision)]))
		summary.append(el('span', { className: 'mcp-compare-word', textContent: person ? resultText(decision) : stateText(item.state, column.kind) }))
		if (person) summary.append(el('span', { className: 'mcp-compare-origin', textContent: provenance(item, column) }))
		else if (item.effective === null) summary.append(el('span', { className: 'mcp-compare-origin', textContent: t('mcp', 'Could not be checked') }))
		if (person && !service && explicitException(item)) summary.append(el('span', { className: 'mcp-compare-exception', textContent: t('mcp', 'Individual exception') }))
		if (person && !service && column.snapshot.service.effective === false) summary.append(el('span', { className: 'mcp-compare-service-block', textContent: t('mcp', 'Service blocked') }))
		if (person || item.effective === null) cell.append(summary)
		if (pending !== undefined && pending !== item.state) cell.append(el('span', { className: 'mcp-compare-pending', textContent: t('mcp', 'Pending: {state}', { state: stateText(pending, column.kind) }) }))
		return cell
	}
	function serviceRow() {
		const row = el('tr', { className: 'mcp-compare-service-row' })
		const title = t('mcp', 'MCP service')
		const heading = el('th', { scope: 'row', className: 'mcp-compare-tool' }, [el('strong', { textContent: title })])
		if (bulkMode) {
			const checkbox = el('input', { type: 'checkbox', checked: rowsSelected.has('_service') })
			checkbox.dataset.focusAction = 'row-select'
			checkbox.dataset.tool = '_service'
			checkbox.setAttribute('aria-label', t('mcp', 'Select MCP service'))
			checkbox.addEventListener('change', () => onRow('_service', checkbox.checked))
			heading.prepend(checkbox)
		}
		row.append(heading)
		for (const column of shownColumns) row.append(resultCell(column, '_service', title))
		return row
	}
	function toolRow(tool) {
		const row = el('tr', { className: 'mcp-compare-tool-row', 'data-tool': tool.name })
		const name = el('th', { scope: 'row', className: 'mcp-compare-tool' })
		if (bulkMode) {
			const checkbox = el('input', { type: 'checkbox', checked: rowsSelected.has(tool.name) })
			checkbox.dataset.focusAction = 'row-select'
			checkbox.dataset.tool = tool.name
			checkbox.setAttribute('aria-label', t('mcp', 'Select {tool}', { tool: tool.title || tool.name }))
			checkbox.addEventListener('change', () => onRow(tool.name, checkbox.checked))
			name.append(checkbox)
		}
		name.append(el('span', { className: 'mcp-compare-tool-name', textContent: tool.title || tool.name }))
		row.append(name)
		for (const column of shownColumns) row.append(resultCell(column, tool.name, tool.title || tool.name))
		return row
	}

	const oldFocus = host.contains(document.activeElement) ? {
		action: document.activeElement.dataset?.focusAction || '',
		module: document.activeElement.dataset?.module || '',
		columnKey: document.activeElement.dataset?.columnKey || '',
		tool: document.activeElement.dataset?.tool || '',
	} : null
	const oldScroll = host.querySelector('.mcp-compare-table-wrap')
	const scrollLeft = oldScroll?.scrollLeft || 0
	const scrollTop = oldScroll?.scrollTop || 0
	const container = el('section', { className: 'mcp-comparison' })
	if (columns.length > 1) {
		const select = el('select', { className: 'mcp-compare-mobile-column', 'data-focus-action': 'mobile-column', 'aria-label': t('mcp', 'Column shown on small screens') })
		for (const column of columns) select.append(el('option', { value: column.key, textContent: t('mcp', '{kind}: {name}', { kind: column.kind === 'group' ? t('mcp', 'Group') : t('mcp', 'Person'), name: column.label }) }))
		select.value = selectedKey || ''
		select.addEventListener('change', () => onMobileColumn(select.value))
		container.append(el('label', { className: 'mcp-compare-mobile-picker' }, [el('span', { textContent: t('mcp', 'Compare with') }), select]))
	}
	const wrap = el('div', { className: 'mcp-compare-table-wrap', role: 'region', 'aria-label': t('mcp', 'Permission comparison'), tabIndex: 0 })
	const table = el('table', { className: 'mcp-compare-table' })
	const caption = el('caption', { className: 'mcp-sr-only', textContent: t('mcp', 'Permission comparison for selected groups and people') })
	const thead = el('thead')
	const headerRow = el('tr')
	headerRow.append(el('th', { scope: 'col', className: 'mcp-compare-name-heading', textContent: t('mcp', 'Tool') }))
	for (const column of shownColumns) headerRow.append(header(column))
	thead.append(headerRow)
	table.style.setProperty('--mcp-subject-count', String(shownColumns.length))
	const tracks = el('colgroup', {}, [el('col', { className: 'mcp-compare-tool-track' })])
	for (const column of shownColumns) tracks.append(el('col', { 'data-column-key': column.key }))
	table.append(caption, tracks, thead)
	const serviceBody = el('tbody')
	serviceBody.append(serviceRow())
	table.append(serviceBody)
	let visibleCatalogCount = 0
	for (const [module, entries] of catalogs) {
		const visible = entries.filter(passesFilters)
		if (!visible.length) continue
		visibleCatalogCount++
		const body = el('tbody')
		const open = Boolean(filterText) || expanded.has(module)
		const groupHead = el('tr', { className: 'mcp-compare-catalog-row' })
		const heading = el('th', { scope: 'rowgroup', colSpan: shownColumns.length + 1 })
		const toggle = el('button', { type: 'button', className: 'mcp-compare-catalog-toggle' })
		toggle.setAttribute('aria-expanded', String(open))
		toggle.dataset.module = module
		toggle.append(el('span', { className: 'mcp-compare-disclosure-mark', 'aria-hidden': 'true' }), el('span', { textContent: moduleNames[module] || module }), el('span', { className: 'mcp-compare-count', textContent: t('mcp', '{count} tools', { count: visible.length }) }))
		toggle.addEventListener('click', () => onExpand(module))
		heading.append(toggle)
		groupHead.append(heading)
		body.append(groupHead)
		if (open) for (const tool of visible) body.append(toolRow(tool))
		table.append(body)
	}
	if (!catalogs.size) {
		const body = el('tbody')
		const empty = el('tr')
		empty.append(el('td', { colSpan: shownColumns.length + 1, className: 'mcp-compare-empty', textContent: t('mcp', 'No tools are available in the selected comparisons.') }))
		body.append(empty)
		table.append(body)
	} else if (visibleCatalogCount === 0) {
		const body = el('tbody')
		const empty = el('tr')
		empty.append(el('td', { colSpan: shownColumns.length + 1, className: 'mcp-compare-empty', textContent: t('mcp', 'No tools match these filters.') }))
		body.append(empty)
		table.append(body)
	}
	for (const cell of table.querySelectorAll('[data-column-key]')) {
		cell.dataset.mobileActive = String(cell.dataset.columnKey === selectedKey)
	}
	wrap.append(table)
	container.append(wrap)
	host.replaceChildren(container)
	const newWrap = host.querySelector('.mcp-compare-table-wrap')
	newWrap.scrollLeft = scrollLeft
	newWrap.scrollTop = scrollTop
	if (oldFocus) {
		const candidates = [...host.querySelectorAll('button, input, select, summary, .mcp-compare-table-wrap')]
		const target = candidates.find(node => {
			if (oldFocus.action) return node.dataset.focusAction === oldFocus.action && (!oldFocus.tool || node.dataset.tool === oldFocus.tool) && (!oldFocus.columnKey || node.dataset.columnKey === oldFocus.columnKey)
			if (oldFocus.module) return node.dataset.module === oldFocus.module
			if (oldFocus.columnKey) return node.dataset.columnKey === oldFocus.columnKey && (!oldFocus.tool || node.dataset.tool === oldFocus.tool)
			return false
		})
		target?.focus()
	}
	return table
}
