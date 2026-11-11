// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

for (const locale of ['de', 'es', 'pt_BR']) {
    test(`locale ${locale} executes one complete MCP registration`, () => {
        const json = JSON.parse(readFileSync(new URL(`../../../l10n/${locale}.json`, import.meta.url), 'utf8'))
        const registrations = []
        runInNewContext(readFileSync(new URL(`../../../l10n/${locale}.js`, import.meta.url), 'utf8'), {
            OC: { L10N: { register: (...args) => registrations.push(args) } },
        })
        expect(JSON.parse(JSON.stringify(registrations))).toEqual([['mcp', json.translations, json.pluralForm]])
        expect(registrations[0][1]['Snooze one mail message']).toBeTruthy()
        expect(registrations[0][1]['Mail: accounts and folders']).toBeTruthy()
    })
}
