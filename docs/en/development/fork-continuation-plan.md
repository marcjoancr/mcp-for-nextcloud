<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Independent fork continuation plan

Status: F0 closed by user confirmation on 2026-10-23; F1 not started. The user
approved the development baseline, branch policy and ongoing maintenance
accountability recorded below. The separate request to remove the local original
repository remote has been carried out. Runtime implementation, publication,
branch promotion, releases and deployment have not been authorized by this
documentation task. F3 decisions, including public identity, initial version and
platform scope, remain pending, as do human reviewer assignments.

## Purpose and baseline

Maintain an independently useful fork with small capability owners, explicit shared
infrastructure, unified development commands, automated contracts and canonical
English documentation. Code and comments remain English. User-facing documentation
retains the existing translation provenance and human-review workflow.

The starting candidate is commit
`14a76df26817fb8a4540eb9c9d1d0043e3e87506` on
`refactor/complete-upstream-pr`, incorporating upstream 1.1.2 at
`27da5e107ca06d92b7af9b8a80ad9c77c12ba91e`.
[PR #14](https://github.com/sintax-tech/mcp-for-nextcloud/pull/14)
is the architectural proposal and historical qualification reference.
On 2026-10-23, the maintainer
[announced 1.2.0 and their intended capability reorganization for 1.3.0](https://github.com/sintax-tech/mcp-for-nextcloud/pull/14#issuecomment-6355529655).
This is not evidence that our candidate contains 1.2.0 or that upstream accepted
our implementation. The release references are now verified in the
[F0 record](#f0-reference-verification-and-decision-record). Inventory its behavior
in F2 before selecting changes.

Existing architecture and test rules remain owned by
[the architecture specification](architecture-specification.md),
[testing](testing.md), [packaging](packaging.md) and
[documentation authoring](documentation.md). This is a fork governance and
transition plan, not a duplicate architecture specification or a host runbook.
Keep private infrastructure details and raw campaign evidence outside the repository.

## Alternatives and recommended direction

| Approach | Benefit | Cost or reason not selected |
| --- | --- | --- |
| Wait for upstream 1.3.0 and return to its architecture | Lowest long-term divergence | Does not preserve control over our existing architecture and delivery priorities |
| Independent fork with selective upstream integration | Keeps the implemented design and allows local priorities | Requires ownership of security, compatibility and releases; recommended for the requested direction |
| Repeated full upstream merges into the fork | Preserves upstream ancestry mechanically | Increasingly divergent ownership boundaries can reintroduce large services and conflicting behavior |

Recommend checking upstream releases and dependency/security advisories weekly and
before each fork release, with immediate triage of any known relevant security
report. The maintenance owner must confirm this cadence; it is not an automatic
monitor or a promised response SLA. Record assessed revisions, affected supported
versions, decisions and any required urgent patch release.

Selective integration does not exclude a reviewed merge when appropriate. Choose
per change, retain provenance, and never treat conflict resolution alone as parity.
Do not create compatibility wrappers merely to accommodate upstream file paths.

## Branches and relationship with upstream

| Reference | Proposed role | Rules |
| --- | --- | --- |
| `origin/refactor/complete-upstream-pr` | Existing PR #14 reference | Preserve its name and history; no fork-only development, force-push or automatic retargeting |
| `fork/main` | Approved long-lived fork integration branch | Create from the exact candidate above when F1 is requested; initially local; protect before accepting remote contributions |
| `feat/*`, `fix/*`, `docs/*`, `test/*` | Short-lived changes from `fork/main` | One reviewable objective, relevant checks and explicit compatibility impact |
| `fix/upstream-<release>-<topic>` | Selected upstream ports | Record original commit, behavior and validation; no automatic upstream merges |
| `release/fork-<major>.<minor>` | Optional supported maintenance line | Create only if more than one release line is actually supported |
| Original repository URLs and pinned commit IDs | Read-only source and advisory references | No configured `upstream` remote; no automatic merges or new PRs to the original repository |

Keep the existing `main` unchanged during preparation. Switching the fork's default
branch to `fork/main` is a later, explicit repository administration action. It
must update contribution instructions, CI assumptions and branch protection
without renaming or replacing the head of PR #14. Do not maintain two competing
fork integration branches. The documentation branch `docs/fork-continuation-plan`
is based on the frozen candidate; incorporate its reviewed documentation commit
into `fork/main` during F1 so this plan and its index link are not lost. Never
merge that fork-only documentation into the PR reference branch.

Preserve ancestry: translation provenance and old-baseline integration tests refer
to historical revisions. Do not squash the existing architecture into an unrelated
root or discard upstream commits. Freeze the candidate by its full SHA; a future
annotated `fork-baseline-1.1.2` tag may make it easier to find, but creating or
publishing that tag is outside this documentation task.

Leave PR #14 open and unchanged as a reference unless its maintainer or the user
chooses otherwise. Its eventual closure does not remove the fork baseline.
The user has chosen to stop submitting PRs to the original repository. Do not
re-add `upstream`, retarget the GitHub CLI default, or open another original-project
PR without a new explicit request. This does not prohibit reading releases,
security advisories and source by their explicit URLs or importing selected
changes under their licenses. F2 can retrieve a pinned commit from an explicit
source URL when requested, without configuring a persistent remote. Such a
retrieval does not authorize publication or contribution to the original.

## Versions, identity and support

Recommend an independent release sequence beginning with **2.0.0**, subject to the
release owner's approval and native upgrade qualification. This communicates a
new maintenance line; it does not claim upstream 2.0.0 parity or an MCP protocol
break. Record actual compatibility changes rather than deriving them from the
number alone. Define compatibility for MCP tool/resource contracts, authorization,
configuration, native data upgrades and documented extension points. Internal
class moves are not automatically public protocol breaks, but changed extension
points must be identified.

- Use numeric `major.minor.patch` in `mcp/appinfo/info.xml`; propose Git tags such
  as `fork-v2.0.0` and distinct release titles identifying the fork.
- Increment patch for compatible fixes, minor for compatible capabilities, and
  major for incompatible supported contracts or upgrade requirements. State the
  affected contract and migration path explicitly.
- Keep the upstream baseline and selectively incorporated changes in release
  notes separately from the fork version. Do not claim full 1.2.0 parity until
  every relevant capability and security change has been accounted for.
- Do not use build metadata, a large version number or a `-fork` suffix as a
  substitute for controlling update sources. Validate Nextcloud comparison and
  package metadata behavior on the actual supported versions.
- Retain app ID `mcp`, namespace `OCA\Mcp` and existing migration identities for
  an in-place continuation. Preserve the installation directory `mcp/`. The fork
  and original cannot be installed side by side under the same ID.
- Give the display name, README and release descriptions an explicit independent
  fork identity, retaining legal attribution. The final public name is pending;
  do not imply endorsement or ownership of upstream/Nextcloud trademarks.
- Initially support one stable fork line and a documented Nextcloud/PHP/database
  matrix. Adopt the existing NC 32-35 test matrix as a qualification starting
  point, not a permanent support promise. Verify the actual intended host before
  announcing a supported release.

## Controlled updates and recovery

Recommend operator-controlled installation of verified fork artifacts for the
initial line, without publishing under upstream's App Store identity. Keep the
existing app ID for data continuity, but prove that routine platform maintenance
cannot silently replace fork code with the official `mcp` package.

Before deployment, inventory every update entry point on the intended host:
Nextcloud UI, App Store/background behavior, `occ` commands, updater automation,
configuration management and image rebuilds. Select and test a supported way to
exclude `mcp` from official automatic updates while keeping other apps maintained.
Do not invent a per-app configuration key or assume that disabling one UI action
covers all update paths. A future private update feed needs its own design and
qualification. If no reliable exclusion is available, stop the rollout and decide
whether to implement one or design a separate app-ID/data migration.

Each approved release needs an immutable source revision, complete source access,
artifact digest, dependency inventory, supported platform matrix, compatibility
notes and verification results. Install only after comparing the artifact against
that record. Distinguish upstream app signatures from our own artifact checksums;
never disable Nextcloud integrity checks globally to hide fork modifications.
Define a supported signing or explicitly documented custom-app integrity procedure
before rollout and assess any warnings rather than suppressing them.

Back up the app code, database, relevant files and configuration as a coordinated
set before an upgrade. Test restoration in isolation; replacing PHP files is not
a rollback after database migrations. Capture migration IDs and schema effects;
rehearse upgrade from the actual installed version and recovery to the matching
backup. Cover sessions, OAuth tokens and credentials in the recovery procedure,
including revocation/reconnection when restored credentials are involved. Do not
promise downgrade from a fork release to upstream: assess it as a separate data
migration. No production backup or restore is requested by this plan.

## License and source availability

The project declares AGPL-3.0-or-later in [NOTICE](../../../NOTICE) and its package
metadata. The [license text](../../../LICENSE) and
[AGPLv3 sections 4-7 and 13](https://opensource.org/license/agpl-3-0)
permit modification and continuation subject to their conditions. This is an
engineering compliance plan, not a legal opinion on unusual combinations or
trademark rights.

| Requirement | Planned evidence |
| --- | --- |
| License the covered derivative under AGPL and retain notices | Root and app license/copyright files preserved; modifications carry the applicable AGPL terms; REUSE and package checks pass |
| Preserve Sintax's section 7(b) attribution | Visible, legible attribution in administration/settings and user-facing README/documentation, plus copyright and NOTICE files; translations preserve its meaning |
| Mark modifications and dates | Change notices, release notes and source history distinguish our work without removing upstream authorship |
| Preserve third-party terms | Review [dependency notices](../../../mcp/THIRD-PARTY-NOTICES.md), per-file SPDX identifiers and packaged licenses for each dependency change |
| Offer corresponding source to remote users | Prominent, usable source offer for all users interacting with the modified service, linked to the exact deployed version and available without charge |
| Supply the preferred source for modifications | PHP, original JavaScript modules, build/install scripts and required non-system dependency source accompany the source offer; the production archive alone is insufficient |
| Preserve recipients' license rights | No extra prohibition on modifying or redistributing covered code; operational authentication is separate from source-license conditions |

The source-offer design must cover MCP users as well as administrators; an admin-only
settings link is insufficient evidence of access for everyone. Determine an
appropriate discovery/onboarding mechanism and verify it with a non-admin user.
A public fork release page is the recommended source destination. Link to an
immutable version and test access without maintainer credentials; a link merely
to upstream or to a moving branch is insufficient.

The repository's packaging workflow currently needs historical Git objects for
translation provenance. Supply a reproducible source retrieval procedure with
those objects available, or a sanitized full-history source bundle, and verify a
clean rebuild. Do not claim GitHub's automatic source ZIP alone can run that
workflow. Retain each released source offer while its version is served and meet
any additional source-delivery obligations of the chosen distribution method.

Source availability concerns the covered software, not users' documents, tokens,
passwords or backups. Do not include such data in release evidence or source
bundles. A source bundle must preserve the required commit objects and notices;
sanitization means excluding credentials and unrelated refs, not rewriting the
provenance history. If a required object contains sensitive material, stop and
resolve source availability and provenance together before publication.

Private development and distribution/network service have different
obligations; calling an installation internal is not by itself a reason to skip
assessment of section 13. Keep the source offer usable by its actual users.

## Execution phases and acceptance criteria

Execute phases in order after the plan is approved. Each phase records its result
and unresolved issues before the next begins. Failures remain visible; do not
weaken checks, add analysis suppressions or leave transitional architecture behind.

### F0: Freeze references and approve ownership

Record the PR candidate, upstream 1.1.2 baseline and current upstream 1.2.0 release
commit/tag. Approve the baseline, branch policy and maintenance ownership before
F1. Record an owner and decision deadline for public identity, initial version,
intended host/platform scope and support-review assignments; resolve the first
three before F3 metadata and host qualification work. An explicit decision to
exclude a client from the initial support scope is permitted, but is not evidence
that its pending acceptance passed. The user has now explicitly accepted ongoing
fork maintenance and release/security decision ownership; this is recorded in the
F0 decision table rather than inferred from prior temporary staging duties.

Acceptance: immutable references are recorded, PR head remains unchanged, an
accountable maintenance/security owner accepts the role, and unresolved naming or
host decisions have owners and F3 deadlines. F0 can close with those decisions
pending; F3 cannot start dependent work until they are resolved. No infrastructure
or remote changes are required.

### F1: Establish fork development locally

Create `fork/main` from the frozen candidate, incorporate this reviewed plan commit
and document the fork development policy. Defer runtime identity/version changes
to F3. Preserve the source history, architecture boundaries and complete
existing tool inventory. Propose protection rules and default-branch changes for
later authorization; do not publish them as part of local preparation.

Acceptance: PR ancestry and head are unchanged; no duplicate legacy implementation
or parallel canonical documentation; development commands and all architecture
checks pass. Documentation describes actual local state and proposed remote state
separately.

### F2: Audit upstream 1.2.0 and port selected changes

Create a capability inventory containing source commits, security implications,
public contract changes, dependencies, migration effects and one disposition per
item: already present, port, intentionally defer, or not applicable with rationale.
Include authentication, Guests restrictions, resource visibility and authorization.
Inventory database migrations as well as runtime changes: retain existing IDs and
classify equivalent, new and conflicting schema changes before porting. Newly
added migrations need unique IDs and tested ordering; never renumber already
applied migrations or copy an upstream migration that duplicates a fork change.
Prioritize relevant security fixes before optional capabilities; a relevant known
vulnerability cannot be deferred while declaring the affected release ready.

Port into existing capability owners, retain attribution and use focused regression
tests. Run focused tests before the next port, then the complete suite. Do not
assume upstream's new handler organization maps directly onto our class boundaries.

Acceptance: all upstream changes have a disposition, selected behavior is tested,
known relevant security issues are resolved, and parity claims match the inventory.
Remaining optional omissions are release notes, not concealed test exclusions.

### F3: Qualify identity, licensing and upgrade control

Implement the approved version/identity and source-offer design. Audit visible
attribution, corresponding-source completeness and dependency licenses. Rebuild
from the prepared source distribution in a clean environment. Verify the offer
and download using a non-admin test user on an isolated local service; this is not
public publication. Keep a mapping from the staged source destination to the
proposed immutable public URL. F5 must verify that final public URL after
authorized publication and before any deployment. Use an isolated representative
Nextcloud host to test official-update exclusion, supported integrity behavior,
in-place upgrade and coordinated restore.

Acceptance: source is accessible to an ordinary remote user, original attribution
is visible, source and artifact revisions agree, the fork survives each identified
routine update path, data/migration identities are preserved and recovery works.
Failure to prevent unintended replacement blocks deployment; it is not fixed by
increasing the version number. Any need for public HTTPS or infrastructure changes
requires its own authorization.

### F4: Qualify a release candidate

Adapt version-sensitive qualification explicitly before the first fork release.
The current `replace-upstream` cell requires the candidate version to equal the
1.1.2 baseline; it is not valid unchanged for a 2.0.0 candidate. Preserve the pinned
1.1.2 source and cover that path as an actual upgrade with `occ upgrade`. Keep a
separate same-version replacement case only where its preconditions hold. Update
the matrix, runner, assertions and descriptions together; do not silently remove
coverage or weaken equality/upgrade checks to obtain a pass.

Run `composer --working-dir=mcp check`, REUSE validation and the native matrix for
the declared supported platforms using the final artifact. Cover the actual
installed version as an upgrade source; use supported historical and same-version
replacement cases where relevant. Verify protocol contracts, permissions, assets,
translations, package contents and upgrade/recovery behavior. Record skipped cells
and applicability reasons without claiming they passed.

Acceptance: automated gates pass at an exact commit, release evidence is accessible,
no known relevant security failure remains, and supported-client claims match
actual results. Separate local merge readiness from authorization to deploy or
publish a release. Documentation-only preparation does not rerun or certify this
release qualification. Bind F3 recovery/update-control evidence to the same final
artifact digest used in F4; repeat affected checks after any candidate change.
Any adjustment needed for public source URLs is part of the candidate and must
precede its final qualification, not be patched into the released package later.

### F5: Authorize publication and operation separately

Present the candidate, source offer, artifact digest, release notes, support scope,
recovery evidence and outstanding acceptance limitations to the owner. Obtain
explicit authorization for pushing branches/tags, repository settings or release
publication, and separately for deployment. Do not merge or close upstream PR #14
as a side effect.

Acceptance: the authorized action and target are explicit, published artifacts
match the qualified candidate, the published source URL is verified without
maintainer credentials and source access remains available, and installation
uses only the qualified update/recovery procedure. Ownership of ongoing upstream
security monitoring is recorded.

## Outstanding decisions and acceptance limits

| Item | Recommendation or current status | Consequence |
| --- | --- | --- |
| Fork identity and first version | Independent display identity; propose 2.0.0 | Owner approval before metadata changes |
| Long-term owner and support scope | User confirmed maintenance/security and operation/recovery ownership; platform scope and reviewers remain pending | Resolve platform scope before F3 and accountable release review before deployment |
| Upstream 1.2.0 | Release tag and commit verified in F0; behavior not inventoried or integrated | No claim of 1.2.0 parity |
| Update exclusion and integrity handling | Mechanism not yet qualified on target Nextcloud | Deployment blocker |
| Public HTTPS | Still pending | Do not claim public-ingress acceptance |
| Inspector | Public-ingress journey pending; earlier encrypted-storage isolation result was 14/15 with nonzero exit, an external client finding | Keep evidence and limits explicit; assess relevance to the supported client path |
| ChatGPT | Real consent/tool/revocation journey pending | Block a claim of supported ChatGPT production use until verified or explicitly exclude it from release scope |
| Human review | Technical/security, accessibility, documentation languages and rendered German UI remain pending | No claim of reviewed translations/accessibility; accountable security and release review required before deployment |

Earlier native/unit/browser results justify using the candidate as a development
baseline. They do not transfer automatically to a new fork artifact. Earlier
closed-host UI/private-TLS evidence from 1.1.0 does not certify a 1.1.2 or later
artifact. HTTPS, Inspector and ChatGPT acceptance need not block local fork work;
they must block unsupported claims and any deployment that depends on those
unverified paths. Do not describe the external Inspector result as an observed
server disclosure without additional evidence.

## F0 reference verification and decision record

Checked and confirmed on 2026-10-23. Technical reference verification is complete
and the user explicitly confirmed all three F0 decisions. F0 is closed; no F1
branch, tag, runtime change or release has been created. The separate local remote
configuration change is recorded below. F0 closure does not approve F3 decisions
or complete any human review.

### Verified immutable inputs

| Input | Exact identity | Result |
| --- | --- | --- |
| Fork candidate / PR #14 head | `14a76df26817fb8a4540eb9c9d1d0043e3e87506` | Local reference, remote fork branch and PR head agree; PR remains open |
| Reviewed plan commit | `8e44a40e0bfae9bc5d3aab8039c2f784250d4982` | Documentation-only child of the candidate; on `docs/fork-continuation-plan` |
| Integrated upstream 1.1.2 | `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` | Ancestor of the candidate; annotated `v1.1.2` tag resolves here |
| Upstream 1.1.2 tag object | `883dbe13f0098eecbc57a9f47144d7e72f1184c6` | Annotated, reported unsigned by GitHub |
| Released upstream 1.2.0 | `02526119b845ccc1d8399be002da3e30b5527df6` | Annotated `v1.2.0` tag resolves here; manifest declares 1.2.0 |
| Upstream 1.2.0 tag object | `909773d86d5f2c0761c4d0f44de8b2710c1ac86d` | Annotated, reported unsigned by GitHub |
| Observed upstream `main` | `7c5ef6d6e183cfacc5626609857631f84f7c8b2a` | One commit after 1.2.0, changing only reporter credits in `CHANGELOG.md` |

The [1.2.0 release](https://github.com/sintax-tech/mcp-for-nextcloud/releases/tag/v1.2.0)
was published on 2026-10-23 at 01:40:28 UTC. Its `target_commitish` is the mutable
name `main`; use the dereferenced tag commit above, not that name, for F2 inputs.
The [post-release comparison](https://github.com/sintax-tech/mcp-for-nextcloud/compare/02526119b845ccc1d8399be002da3e30b5527df6...7c5ef6d6e183cfacc5626609857631f84f7c8b2a)
accounts separately for the later credit correction. Preserve accurate attribution
when incorporating the relevant changes; do not silently expand the release scope.
Unsigned tag status is recorded honestly: GitHub reference resolution verifies
which source was selected, not a cryptographic publisher signature or artifact
integrity. No release archive was downloaded or qualified during F0.

Both the fork candidate and released upstream 1.2.0 declare app ID `mcp`, PHP
minimum 8.2 and Nextcloud 32-35. This metadata agreement does not prove runtime
parity or identify the user's actual production platform. The current local
`main` remains `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92`; no local `fork/main`
exists. GitHub's PR response still reports base SHA `27da5e1`, whereas the direct
upstream branch reference reports `7c5ef6d`; this API discrepancy is not used to
select a source baseline. The PR head is independently verified against the fork
branch, and the release is independently verified through its annotated tag.

### Confirmed decisions for F1

| Decision | Confirmed choice | Decision owner / gate | Current state |
| --- | --- | --- | --- |
| Development baseline | Continue from `14a76df` with the existing capability architecture, preserve source history, and carry the reviewed plan plus this F0 record into the fork line | User / F0 | Confirmed 2026-10-23 |
| Branch policy | Use `fork/main` for local development, retain `main` and `refactor/complete-upstream-pr` unchanged, and keep PR #14 as a reference | User / F0 | Confirmed 2026-10-23 |
| Ongoing maintenance accountability | User owns release/security decisions, upstream monitoring and supported operation/recovery; the assistant performs requested engineering and validation | User / F0 | Explicitly confirmed 2026-10-23 |

Accepting maintenance ownership does not assign the user as every specialist
reviewer, assert expertise they have not claimed, or close a review requirement.
Engineering assistance is session-based; no scheduled monitoring or response SLA
has been configured. Reviewers remain unassigned.

### Decisions that may remain open after F0

These substantive choices remain pending at the user's explicit request. The
user retains decision ownership and coordinates reviewer assignments; the
assistant prepares technical options and evidence. The phase deadlines below
remain in effect. F0 closure does not prematurely approve deployment.

| Decision | Recommendation, still pending | Decision owner / deadline |
| --- | --- | --- |
| Public display identity | Clearly identify an independent fork while retaining Sintax attribution; choose the final name before changing runtime identity | User / before F3 |
| Initial release version | Independent `2.0.0`, numeric app version and `fork-v2.0.0` release tag; subject to native upgrade checks | User / before F3 |
| Intended host and support scope | Start with the user's Nextcloud; record its Nextcloud/PHP/database versions, relevant app versions and installed MCP version before selecting the qualification matrix; do not request secrets | User supplies scope, assistant verifies available evidence / before F3 |
| Supported clients | ChatGPT is the stated intended client; qualify its real journey before claiming support or deploying a path that depends on it | User defines release scope / before F3; acceptance before dependent deployment |
| Reviewer assignments | Technical/security, accessibility, document languages and German UI remain unassigned; the user coordinates assignment or a documented scope decision | User coordinates; reviewers pending / record disposition before F4 release readiness; accountable security and release review before deployment |
| Update exclusion, integrity and source-offer mechanism | Engineering investigation and an isolated reproducible test, within the approved identity/platform scope | Assistant prepares evidence / F3; no mechanism is asserted to work yet |

The assistant can resolve Git object selection, source comparisons, inventory
structure, focused test selection and implementation details within approved scope.
Those do not authorize changing the default branch, publishing artifacts or
exposing HTTPS. The user's long-term responsibilities are limited to the explicit
F0 confirmation, not additional duties inferred by the assistant.

### F0 result and next gate

F0 is closed: the technical references are verified and the three owner decisions
are confirmed. F1 is eligible to start when requested, but has not begun in this
task. A later F1 implementation must incorporate the reviewed documentation commit
and this F0 closure commit; neither belongs on the PR head.

HTTPS, Inspector (including the previously recorded 14/15 encrypted-storage
finding), ChatGPT and human reviews retain the acceptance limits already listed
above. No runtime, client or production qualification was repeated in F0. Do not
turn these pending checks into a claim that all tests or reviews have passed.

### Local remote policy applied at F0 closure

At the user's explicit request, the local remote `upstream`, which pointed to
`git@github.com:sintax-tech/mcp-for-nextcloud.git`, was removed. Its only local
tracking reference was `refs/remotes/upstream/main` at the already integrated
1.1.2 commit; that tracking reference was removed with the remote. The same commit
remains reachable through the preserved fork/PR history. No branch or tag on
GitHub was deleted or changed.

The retained remote is `origin`, pointing to
`git@github.com:marcjoancr/mcp-for-nextcloud.git`. GitHub CLI's default repository
was explicitly changed to `marcjoancr/mcp-for-nextcloud`; previously it selected
the original repository through `remote.upstream.gh-resolved`. Both the remaining
remote list and `gh repo set-default --view` were checked after the change.

These local settings are shared by the linked worktrees of this checkout. They
are not part of the documentation commit and are not automatically applied to
other clones. They do not detach the GitHub fork relationship or impose an access
control: an explicit repository argument could still target the original. Our
workflow policy is therefore also explicit: no new original-project PRs, no
pushes or publication without the user's corresponding request. PR #14 remains
open at `14a76df` as a historical reference, not a branch for ongoing fork work.

## Validation of this documentation change

Run the following from the repository root with the locked Node/npm toolchain:

```sh
composer --working-dir=mcp docs:check
composer --working-dir=mcp docs:translations
composer --working-dir=mcp test:docs
git diff --check
```

These checks cover documentation, provenance and tooling, not runtime release
acceptance or production package qualification. This English development plan stays outside
the operational package allowlist and does not require duplicate translations.
The development index links this plan. No application behavior, version, legal
text, deployment update configuration or PR state changes are part of this task.
The only non-documentation change is the expressly requested local Git/GitHub CLI
remote configuration recorded above.
