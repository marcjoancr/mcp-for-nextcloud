// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

const port = Number(process.env.MCP_JS_TEST_PORT || 18461)
if (!Number.isInteger(port) || port < 1024 || port > 65535) {
    throw new Error('MCP_JS_TEST_PORT must be an integer from 1024 to 65535.')
}
const testUrl = `http://127.0.0.1:${port}`
const outputDir = process.env.MCP_JS_TEST_PORT ? `./build/browser-results-${port}` : './build/browser-results'

export default defineConfig({
    testDir: './tests/JavaScript',
    outputDir,
    forbidOnly: true,
    retries: 0,
    workers: 2,
    timeout: 15000,
    expect: { timeout: 5000 },
    reporter: [['line'], ['./tests/JavaScript/support/required-tests-reporter.js']],
    use: { baseURL: testUrl, trace: 'retain-on-failure' },
    projects: ['chromium', 'firefox', 'webkit'].map(browserName => ({ name: browserName, use: { browserName } })),
    webServer: {
        command: 'node tests/JavaScript/support/server.mjs',
        url: testUrl + '/health',
        reuseExistingServer: false,
        timeout: 10000,
    },
})
