// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** A verified server snapshot is required before offering a writable draft. */
export function validSettings(data) {
    return data?.version === 1 && typeof data.folder === 'string' && data.folder.length > 0
        && Array.isArray(data.reserved) && data.reserved.every(name => typeof name === 'string' && name.length > 0)
        && data.reserved.includes(data.folder) && data.reserved.includes('MCP backups')
        && ['copies', 'effectiveCopies', 'versionsAvailable', 'versionsForActor'].every(key => typeof data[key] === 'boolean')
        && typeof data.revision === 'string' && /^(0|[1-9][0-9]*)$/.test(data.revision)
}
