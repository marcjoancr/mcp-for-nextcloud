<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# People capability migration execution report

Execution: 2026-10-16–17. Implementation baseline: reviewed plan commit `43c00d0`; production
matches planning baseline `9afd64c`. Scope: `users_search` and its direct composition
and test consumers. No dependency, schema/data, authentication or public API change.
The validated `docs/talk-capability-plan` branch/worktree was reused and preserved.
No push, PR, deployment or real-account enumeration was performed.

## Ordered phase evidence

Each phase's full gate completed and passed before its commit and the next phase.
New capability/publisher tests failed for the missing final types before extraction.
Frozen fixtures were captured before production changes and never regenerated.

| Phase | Focused tests / assertions | Full tests / assertions | Full runtime | Commit |
| --- | --- | --- | --- | --- |
| 0: frozen contracts and privacy | 115 / 6,494 | 5,205 / 39,501 | 40.338 s | `c4102e5` |
| 1: search capability | 117 / 6,502 | 5,207 / 39,521 | 36.830 s | `3319251` |
| 2: final publisher and retirement | 126 / 7,027 | 5,212 / 39,563 | 30.915 s | `b388f59` |
| 3: composition, boundaries and package | 129 / 8,476 | **5,215 / 41,017** | 30.866 s | Closing migration commit |

Runtime: PHP 8.4.26 / PHPUnit 10.5.65. Phases 0–2 used
`devbox run test --cwd "$TASK_REPO" --json`; phase 3 used
`devbox run check --cwd "$TASK_REPO" --json`, including production package validation.
The phase 2 focused selection was expanded to include VisibilityContractTest after
its indirect constructor was discovered by the full gate. Counts are not identical
selections across that boundary.

Final additional checks:

| Check | Tests / assertions | Runtime |
| --- | ---: | ---: |
| Isolated People feature | 19 / 70 | 0.026 s |
| Architecture | 28 / 8,817 | 0.521 s |
| Nextcloud API contracts | 2,129 / 4,126 | 14.863 s |

Composer optimized autoload generation succeeded. Existing PSR-4 notices concern
explicitly loaded external test stubs; the production archive contains no such
classes. The native API suite retains the same 2,129 cases / 4,126 assertions as
before the move. No fixture/scanner changes were needed for ISearch/IShare discovery.

## Final ownership and compatibility

```text
mcp/lib/Modules/People/
  PeopleModule.php              # guide, static publication and lazy dispatch
  PeopleToolCatalog.php         # one ordered read Tool and raw metadata projection
  Tools/SearchUsers.php         # schema and MCP result encoding
  Search/PeopleSearch.php       # validation, native search, privacy and projection
```

The old `lib/Tools/People/` and `tests/Unit/Tools/People/` directories are absent.
There are no alias constants/classes, forwarding facades, loaders or alternate
publishers. Application is the only production consumer outside People importing
its implementation. Public `users_search` references in other modules stay intact.
No speculative Contracts, Execution, Presentation or Infrastructure folders exist.

The tool remains app-less `people.read`, eighth in Application's nine-module order,
with the same raw/published definition and guide. Public validation and grants remain
in the registry; static discovery no longer constructs People's ISearch/logger.
No write, confirmation or saved-plan interface was introduced.

Search still delegates to the host's sharing collaborator service with lookup=false,
offset=0 and the requested limit; groups are requested only when enabled by the
argument. Native session identity is unchanged. Projection retains exact/partial
ranking, typed first-occurrence deduplication, ID `0`, combined limit, label fallbacks
and the existing e-mail heuristic. ToolResult alone owns final JSON encoding.

Native failures still become the fixed localized error and a class-only warning.
The native-call catch does not absorb mapping TypeErrors or logger exceptions.
Tests pin Unicode bounds, direct coercion versus schema validation, invalid-UTF-8
output substitution, same-ID users/groups and repeated host-session/locale changes.
Mocks establish MCP delegation and absence of caching; they do not establish native
sharing policy or authenticate a real request.

## Fixtures and test reconciliation

`tests/Support/People/Compatibility` was captured at `43c00d0` with deterministic,
synthetic data. Definitions include raw/published metadata, guide and module order
by short class name, deliberately allowing namespace changes without changing order.
Results include users, mixed exact/partial groups, capped/empty output and errors in
English, Brazilian Portuguese and Spanish. No field normalization was required.

All **17 original People test methods** remain, in the same order, under
`tests/Unit/Modules/People/PeopleModuleTest.php`. Expanded discovery normalized only
the retired People test namespace: all **5,199 baseline cases** remain, **16 cases
were added**, and zero were removed. Additions comprise six compatibility tests,
two capability tests, five catalog tests, two architecture tests and one actual
production-registration test. Translator state is reset by multilingual fixtures.

## Production-only package probe

The actual `build/mcp-1.0.8.tar.gz` was extracted to ignored
`build/people-production-probe`. A fresh PHP process loaded only its production
autoloader, with minimal host App/IBootstrap declarations. It loaded all four People
types, compared their bytes and Application with the worktree, and matched raw and
published metadata, full guide output and module order against frozen fixtures.
The metadata factory throws if invoked. Classmaps contain no retired People class,
PHPUnit/test class or path outside the extracted package. The archive contains no
old People tree or tests. No development vendor or test bootstrap was loaded.

Actual Application closures were also exercised with explicit host doubles through
the registry: discovery/denial never construct the search capability, optional-app
checks are irrelevant, and a permitted call reaches native search with exact args.

## Deviations and final review

- Execution scratch stayed outside the repository because server guidance forbids
  harness files inside it. This committed report and the fixtures retain evidence;
  raw temporary logs are removed after completion.
- The phase 2 full gate found a third reflective builder in VisibilityContractTest,
  beyond the two listed in the reviewed plan. It supplied null to the new factory-map
  constructor. Its People branch was updated, the focused selection and plan were
  corrected, and the full gate passed before committing/advancing. The existing
  failing contract supplies regression coverage; no assertions or cases were removed.
  Omitting this correction would leave global visibility coverage unable to construct
  the module. Production visibility behavior did not change.
- Self-review and the single independent review identified missing scanner coverage
  for bare `OC` imports and fully qualified `\class_alias` calls. Negative examples
  reproduced both gaps; token handling was corrected without production changes.
  The boundary tests then passed (2 tests / 1,439 assertions), followed by the
  focused, architecture and full/package gates recorded above. The freshly built
  production archive probe passed again.
- Independent review found no critical or minor issues and no actionable production
  findings. Its one important finding was the scanner gap resolved above. Verdict:
  ready with that correction and passing final gates; both conditions are satisfied.
  The reviewer separately passed People/registration contracts (31 / 131), controller
  tests (26 / 72) and the production-only package probe. Declined to judge: none.
- Final documentation validation found all 76 local link targets across the six
  changed documents; `git diff --check` passed. Frozen compatibility files remain
  byte-identical to phase 0.

## Remaining work

People is locally migrated. **72 tools** across Files, Deck, Talk, Calendar, Contacts,
Tasks, Notes and People now use capabilities. Logs is the remaining feature module
under `Tools`; its plan must preserve RestrictedModule authorization, mandatory audit
(including refusals), redaction and read-grant defaults. No Logs migration is included.
Administration scripts, shared MCP policy/registry relocation, developer commands,
static analysis and README/docs i18n/navigation remain separately scoped work.

Before release, run PHP 8.2–8.5 CI and disposable Nextcloud 32–35 checks for Basic/Bearer
identity, enumeration disabled/exact-only search, group sharing disabled, same-group
restrictions and hidden groups. Current unit/API/package evidence does not prove
native plugin policy or directory behavior. Use synthetic accounts on disposable
hosts; do not infer release readiness from local migration completion.
