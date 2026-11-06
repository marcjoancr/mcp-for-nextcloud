// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export function createPresentation({ t }) {
	function moduleLabels() {
		return {
			files: t('mcp', 'Files'),
			notes: t('mcp', 'Notes'),
			deck: t('mcp', 'Deck'),
			calendar: t('mcp', 'Calendar'),
			contacts: t('mcp', 'Contacts'),
			tasks: t('mcp', 'Tasks'),
			talk: t('mcp', 'Talk'),
			people: t('mcp', 'People'),
			logs: t('mcp', 'Server log'),
			mail: t('mcp', 'Mail'),
			forms: t('mcp', 'Forms'),
		}
	}
	function operationLabels() {
		return {
			description_read: t('mcp', 'read file descriptions'),
			description_edit: t('mcp', 'edit file descriptions'),
			tags_read: t('mcp', 'read file tags'),
			tags_create: t('mcp', 'create file tags'),
			tags_edit: t('mcp', 'assign file tags'),
			upload_link: t('mcp', 'create upload-only links'),
			read: t('mcp', 'read'),
			search: t('mcp', 'search'),
			read_message: t('mcp', 'read mail content'),
			read_attachment: t('mcp', 'read mail attachments'),
			save_attachment: t('mcp', 'save mail attachments'),
			read_drafts: t('mcp', 'read mail drafts'),
			write_drafts: t('mcp', 'save mail drafts'),
			delete_drafts: t('mcp', 'delete mail drafts'),
			read_comments: t('mcp', 'read card comments'),
			read_responses: t('mcp', 'read form responses'), summarize_responses: t('mcp', 'summarize form responses'), export_responses: t('mcp', 'export form responses'), availability: t('mcp', 'check calendar availability'),
			publish_summary: t('mcp', 'publish Talk summaries'),
			comment: t('mcp', 'post card comments'),
			edit: t('mcp', 'edit'),
			create: t('mcp', 'create'),
			move: t('mcp', 'move'),
			delete: t('mcp', 'delete'),
			restore: t('mcp', 'restore'),
			transfer: t('mcp', 'transfer'),
			reply: t('mcp', 'reply'),
			attach: t('mcp', 'attach'),
			quote: t('mcp', 'quote'),
			share: t('mcp', 'share'),
			link: t('mcp', 'link'),
		}
	}
	function operationHint(module, operation) {
		if (module === 'deck' && operation === 'search') return t('mcp', 'Search accessible cards by text, assignee, labels and due date')
		if (module === 'talk' && operation === 'search') return t('mcp', 'Search conversation messages without marking them as read')
		if (module === 'mail' && operation === 'read') return t('mcp', 'List own mail accounts and cached folders')
		if (module === 'forms' && operation === 'read') return t('mcp', 'Read accessible forms, questions and options')
		if (module === 'talk' && operation === 'create') {
			return t('mcp', 'Open new conversations, which invites people')
		}
		if (module === 'files' && operation === 'create') {
			return t('mcp', 'Create folders, copies and new files')
		}
		if (module === 'logs' && operation === 'read') {
			return t('mcp', 'Read and analyze the server log; starts off')
		}
		if (module === 'calendar' && operation === 'transfer') {
			return t('mcp', 'Move an event to another person’s calendar')
		}
		const hints = {
			description_read: t('mcp', 'Read descriptions of accessible files, including embedded image descriptions'),
			description_edit: t('mcp', 'Edit descriptions visible in Files after preview and confirmation'),
			tags_read: t('mcp', 'List visible categories and tags on accessible files'),
			tags_create: t('mcp', 'Create public categories when native Nextcloud policy permits'),
			tags_edit: t('mcp', 'Assign or remove permitted tags after preview and confirmation'),
			upload_link: t('mcp', 'Create password-protected upload-only folder links'),
			read: t('mcp', 'List, search and read'),
			search: t('mcp', 'Search cached message headers in own mailboxes'),
			read_message: t('mcp', 'Read mail content without marking the message as read'),
            read_attachment: t('mcp', 'Read attachments from own mail without marking messages as read'),
            save_attachment: t('mcp', 'Save mail attachments to Files after preview and confirmation'),
            read_drafts: t('mcp', 'Read own unsent mail drafts'),
            write_drafts: t('mcp', 'Create or update mail drafts after preview and confirmation'),
            delete_drafts: t('mcp', 'Delete mail drafts after preview and confirmation'),
			read_comments: t('mcp', 'Read comments on accessible Deck cards'),
			read_responses: t('mcp', 'Read responses with native form results permission'),
			summarize_responses: t('mcp', 'Summarize responses with native form results permission'),
			export_responses: t('mcp', 'Export responses to Files after preview and confirmation; requires file creation permission'),
			availability: t('mcp', 'Check busy times, conflicts and free intervals in accessible calendars'),
			publish_summary: t('mcp', 'Publish a summary after preview and confirmation'),
			comment: t('mcp', 'Post a confirmed comment visible to readers of the board'),
			edit: t('mcp', 'Change existing items'),
			create: t('mcp', 'Create new items'),
			move: t('mcp', 'Move or rename items'),
			delete: t('mcp', 'Delete items'),
			restore: t('mcp', 'Restore a file to a stored version'),
			reply: t('mcp', 'Send messages and replies'),
			attach: t('mcp', 'Share a file into a conversation'),
			quote: t('mcp', 'Quote part of a file in a message'),
			share: t('mcp', 'Share own files with people and groups'),
			link: t('mcp', 'Create public links to own files'),
		}
		return hints[operation] || ''
	}
	function rowUnavailableReason(user) {
		if (!user.enabled) {
			return t('mcp', 'Account disabled: no MCP access.')
		}
		if (user.eligible === null) return t('mcp', 'Permission unknown')
		if (!user.eligible) {
			return t('mcp', 'No MCP access: turn on “Can connect”.')
		}
		return null
	}
	function unavailableReason(user, module) {
		if (!user.enabled) {
			return t('mcp', 'The account is disabled.')
		}
		if (module === null) {
			return null
		}
		if (user.eligible === null) return t('mcp', 'Permission unknown')
		if (!user.eligible) {
			return t('mcp', 'This user may not connect: “Can connect” is off.')
		}
		if (!user.appsEnabled[module]) {
			return module === 'logs'
				? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
				: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
		}
		return null
	}
	function levelLabels() {
		return {
			none: t('mcp', 'None'),
			read: t('mcp', 'Read'),
			edit: t('mcp', 'Edit'),
			full: t('mcp', 'Full'),
			custom: t('mcp', 'Custom'),
		}
	}

	return { levelLabels, moduleLabels, operationLabels, operationHint, rowUnavailableReason, unavailableReason }
}
