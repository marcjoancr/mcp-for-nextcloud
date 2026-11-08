# Native Mail flag guard dependency

This is a custom, reviewable source overlay for **Nextcloud Mail 5.12.5**. It is not an upstream release or a replacement IMAP implementation. It adds a closed flag operation inside Mail's existing Horde client and a native service that updates the exact cache identity. MCP's normal package does not install or modify Mail.

The original archive must have SHA256 `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`. The build tool verifies that digest before reading fixed source members. It uses bounded streaming reads and writes only an overlay under this checkout's `mcp/build`; no full archive is expanded into the host temporary filesystem.

From the repository root, with that verified archive available:

```sh
mkdir -p mcp/build/native-mail-guard
php native/mail-guard/build.php /path/to/mail-5.12.5.tar.gz mcp/build/native-mail-guard/overlay
cmp mcp/build/native-mail-guard/overlay/NativeGuardManifest.php mcp/lib/Modules/Mail/Integration/NativeGuardManifest.php
tar -czf mcp/build/native-mail-guard/mail-guard-overlay.tar.gz -C mcp/build/native-mail-guard/overlay mail
```

`overlay/mail` contains seven native source files: five additions and two minimal modifications to Mail's client and cache listener. The manifest also fingerprints the unchanged native factory, cache mappers and bundled Horde Base/Socket implementation. The generated `NativeGuardManifest.php` must match MCP's committed boundary manifest; source changes require reviewed regeneration and renewed tests. A native version/method name or configuration flag cannot override a mismatch.

The native `MutationFence` adapter exchanges only approved identifiers and DB stage transitions. It never passes a raw client to MCP. `setFlagGuarded` validates the actual selected generation and connection before each command, permits one UID STORE, does not swallow tagged NO responses, and verifies the desired state on the same generation. Logout or reconnect invalidates the permit. The native cache stage locks immutable mailbox/message rows, revalidates their identity and synchronization tokens, commits the flag, then dispatches one webhook-compatible guarded flag event. The ordinary native cache listener skips the already-applied event. An uncertain listener/crash outcome is retained and never automatically redispatched.

`move` and `reconcileCache` deliberately refuse: complete command-bound COPYUID proof and safe event reconciliation are not implemented by this artifact. There is no SMTP, outbox, MDN, schedule or draft synchronization path.

The only installation performed by this repository is into its own labelled disposable integration fixture when explicitly running:

```sh
MCP_IT_MAIL_GUARD=1 MCP_IT_PHPUNIT_ARGS='--filter McpMail' composer --working-dir=mcp test:integration -- 35 mariadb fresh --mail
```

The default fixture still installs stock Mail. A shared or production installation requires a separately reviewed deployment; it is not performed by the builder or MCP startup. This custom overlay changes upstream signed application bytes and must be treated as a custom native build. Mail updates invalidate the admitted boundary until requalified. Stock Mail and unknown builds retain existing A/D behavior and refuse C1 writes.

Tests cover Dovecot flags/no-op/reconnect/generation checks, real HTTP approval/replay/status/cache/permission behavior, native cache generation changes and scripted native-protocol faults. The scripted IMAP endpoint is a test server on loopback, not product transport code. See the [delivery evidence](../../docs/superpowers/specs/2026-11-08-mail-c1-guard-evidence.md) for actual results and limits.
