<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Administration JavaScript

The administration and personal settings pages each register one classic script
through `OCP\Util::addScript`. Authored code uses native JavaScript modules; esbuild
produces two self-contained IIFEs without runtime imports, chunks or dependencies.
Nextcloud does not need Node.js to serve the packaged application.

```text
mcp/src/
  entries/                 admin.js, personal.js: root guard and host wiring
  platform/                nextcloud.js, http.js, lifecycle.js
  settings/                connections.js: explicit admin/personal scope
  admin/
    bootstrap.js           page composition and explicit sibling callbacks
    tabs.js                navigation, history and keyboard focus
    picker.js              searchable selection, confirmation and rollback
    status-feedback.js     transient save feedback
    overview/              service.js, checkout-limit.js, endpoint-copy.js
    clients/               oauth-clients.js
    protection/            excluded-tags.js
    logs/                  access-groups.js, privacy.js
    access/
      controller.js        server snapshot, load and single-permission save
      view.js              table, permission states, focus and header observer
      presentation.js      translated labels and availability explanations
      filters.js           query controls, hash, pagination and density
      bulk-plan.js         pure before-images and grouped inverse requests
      bulk-change.js       serialized bulk writes and acknowledged-prefix undo
      undo-notice.js       one undo slot, busy state and expiration
mcp/js/
  admin.js                 generated, ignored
  personal.js              generated, ignored
```

Importing feature modules does not mount a page or read the Nextcloud host. Each
mount receives its root and dependencies, returns `destroy()`, and exposes `ready`
when it has an initial asynchronous load. The lifecycle owner removes listeners,
timers and late-response UI effects; it does not cancel writes accepted by the
server. Keep DOM ownership inside the feature, and put cross-section composition
in `bootstrap.js` rather than adding a shared mutable state object.

For example, the log-access picker receives `reloadMatrix: matrix.reload`; it calls
that hook after success or a version conflict. Connections receive an `onCounts`
callback that updates the overview only when the response contains an integer
connection count. Personal connections have no administration module dependency.
The picker is imported directly; there is no global picker facade.

```js
const host = createNextcloud()
const matrix = mountMatrix({ root, ...host, request: createRequest(host) })
await matrix.ready
matrix.destroy()
```

Preserve transport differences: settings requests send JSON headers; connection
requests keep their existing header contract. Bulk operations remain serial and
stop on the first failed response. Undo covers only acknowledged writes and restores
the saved before-images. It remains last-write-wins: it can overwrite another
administrator's intervening grant, and cannot restore revoked login sessions.
Log-group version conflicts retain their separate compare-and-swap behavior.

## Build and verification

Use the pinned prerequisites in [setup](setup.md). Composer is the public task
entry point; npm owns frontend installation and implementation commands.

```sh
npm --prefix mcp ci
composer --working-dir=mcp assets:build
composer --working-dir=mcp test:js
composer --working-dir=mcp check
```

`test`, `test:js` and `package` always build fresh assets. `assets:build` rejects
missing inputs, import cycles, unreachable authored modules, non-app dependencies
and any administration graph in the personal entry. It records the input graph in
ignored `mcp/build/assets-metafile.json`. Never edit or commit generated bundles.

The package contains the two generated entries and existing styles/translations,
plus their license notices. Sources, development dependencies, scripts, tests,
configuration and source maps stay outside the production archive. A source checkout
is the development artifact; the checked tarball is the deployment artifact.

Browser cases under `tests/JavaScript/` render the real PHP templates and intercept
synthetic API requests. Bare-template module mounting is a permanent isolation aid,
not an alternative production loader. The temporary old/new matrix comparison was
removed with the original four scripts. See [testing](testing.md) and the
[execution report](plans/2026-10-17-admin-javascript-responsibilities-report.md) for
coverage, negative gates and outstanding real-host validation.
