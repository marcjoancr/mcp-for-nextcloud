/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Match PHP trim and the server's ASCII extension grammar. */
export function extensions(text) {
    const trim = value => value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
    if (/[^\x00-\x7f]/.test(text)) throw new Error('extensions')
    const raw = trim(text)
    const tokens = raw === '' ? [] : raw.split(/[ \t\n\r\v\f]*(?:,|\r\n|[\n\r\v\f])[ \t\n\r\v\f]*/)
    if (tokens.at(-1) === '') tokens.pop()
    const canonical = tokens.map(token => trim(token).toLowerCase().replace(/^\./, ''))
    if (canonical.some(token => !/^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))) throw new Error('extensions')
    const result = [...new Set(canonical)].sort()
    if (result.length > 128) throw new Error('extensions')
    return result
}

/** A malformed response never supplies a default or confirms a save. */
export function validPolicy(value) {
    return value !== null && typeof value === 'object' && Object.keys(value).length === 4
        && value.version === 1 && ['off', 'allowlist'].includes(value.mode)
        && typeof value.allowExtensionless === 'boolean' && Array.isArray(value.extensions)
        && value.extensions.every(token => typeof token === 'string' && /^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))
        && value.extensions.length <= 128
        && JSON.stringify([...new Set(value.extensions)].sort()) === JSON.stringify(value.extensions)
}
