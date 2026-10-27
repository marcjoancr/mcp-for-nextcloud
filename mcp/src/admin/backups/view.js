// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export function backupView(root, t) {
    const get = id => root.querySelector('#mcp-backup-' + id)
    const form = get('form'), folder = get('folder'), copies = get('copies')
    return {
        form, folder, copies, reload: get('reload'),
        draft: () => ({ folder: folder.value, copies: copies.checked }),
        fill(data) { folder.value = data.folder; copies.checked = data.copies },
        status(message) { get('status').textContent = message },
        error(value) { folder.setAttribute('aria-invalid', String(value)); if (value) folder.focus() },
        effective(data) {
            get('effective').textContent = !data.versionsAvailable
                ? t('mcp', 'Files writes remain unavailable without the Versions app. MCP copies remain required.')
                : data.effectiveCopies
                    ? t('mcp', 'MCP pre-write copies are required for your account.')
                    : t('mcp', 'MCP pre-write copies are suspended for your account. Native versioning follows its own policy.')
        },
        render({ busy, uncertain, settings }) {
            form.setAttribute('aria-busy', String(busy))
            folder.disabled = busy || !settings
            // If a stored suspension outlives Versions, administrators can still enable copies.
            copies.disabled = busy || !settings || (!settings.versionsAvailable && copies.checked)
            get('save').disabled = busy || uncertain || !settings
            get('reload').disabled = busy
        },
    }
}
