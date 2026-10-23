/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Own only the policy form; shared protection markers are composed by the admin owner. */
export function policyView(root, t) {
    const get = id => root.querySelector('#mcp-file-policy-' + id)
    const form = get('form'), mode = get('mode'), list = get('extensions'), extensionless = get('extensionless')
    const fields = { mode, extensions: list, allowExtensionless: extensionless }
    const messages = {
        mode: t('mcp', 'Choose no restriction or only listed extensions.'),
        extensions: t('mcp', 'Enter valid extensions, separated by commas or new lines, without paths or wildcards.'),
        allowExtensionless: t('mcp', 'Choose whether files without an extension are allowed.'),
    }
    return {
        form, mode, list, extensionless, reload: get('reload'), fields,
        draft: () => ({ mode: mode.value, extensions: list.value, allowExtensionless: extensionless.checked }),
        fill(policy) { mode.value = policy.mode; list.value = policy.extensions.join('\n'); extensionless.checked = policy.allowExtensionless },
        status(message) { get('status').textContent = message },
        issue(value, message) { get('invalid').hidden = !value; if (message) get('invalid').textContent = message },
        error(field) {
            for (const [name, control] of Object.entries(fields)) {
                const error = get(name + '-error')
                error.hidden = name !== field
                error.textContent = name === field ? messages[name] : ''
                control.setAttribute('aria-invalid', String(name === field))
            }
            fields[field]?.focus()
        },
        render({ busy, uncertain }) {
            form.setAttribute('aria-busy', String(busy))
            mode.disabled = busy
            list.disabled = busy || mode.value !== 'allowlist'
            extensionless.disabled = busy || mode.value !== 'allowlist'
            get('save').disabled = busy || uncertain
            get('reload').disabled = busy
            get('empty').hidden = mode.value !== 'allowlist' || list.value.trim() !== ''
        },
    }
}
