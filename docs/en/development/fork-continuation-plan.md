<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Independent fork continuation plan

Status: F0, F1 and the selected F2 integration completed locally on 2026-10-23.
The [execution record](upstream-1.2.0-integration.md) records the ordered batches,
corrections, automated qualification and acceptance limits. The renewed review adds
D06 native file linking under its [follow-up plan](deck-file-link-integration.md);
implementation and local native qualification are complete.
The user approved the development baseline, branch policy and ongoing
maintenance accountability
recorded below. The separate request to remove the local original
repository remote has been carried out. On 2026-10-27 the user authorized
publication and default-branch promotion of `fork/main`, recorded in the
[development baseline record](fork-development-baseline.md). Releases and deployment
remain unauthorized. F1 local branch creation and validation were already authorized.
F3 decisions
for public identity, initial version and platform scope are now confirmed as
recorded in the [F3 execution record](fork-f3-preparation.md). Qualification,
actual-host facts and human reviewer assignments remain pending.

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
[F0 record](#f0-reference-verification-and-decision-record). The
[F2 inventory](upstream-1.2.0-inventory.md) records the selected and deferred
changes. Current implementation and validation status is recorded separately
in the [execution record](upstream-1.2.0-integration.md).

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
| `fork/main` | Established local fork integration branch | Created from the frozen candidate with the reviewed documentation; see the development baseline record for remote promotion and protection |
| `feat/*`, `fix/*`, `docs/*`, `test/*` | Short-lived changes from `fork/main` | One reviewable objective, relevant checks and explicit compatibility impact |
| `fix/upstream-<release>-<topic>` | Selected upstream ports | Record original commit, behavior and validation; no automatic upstream merges |
| `release/fork-<major>.<minor>` | Optional supported maintenance line | Create only if more than one release line is actually supported |
| Original repository URLs and pinned commit IDs | Read-only source and advisory references | No configured `upstream` remote; no automatic merges or new PRs to the original repository |

Keep the existing `main` unchanged during preparation. Switching the fork's default
branch to `fork/main` is now authorized as an explicit repository administration
action, tracked in the development baseline record. It must update contribution
instructions, CI assumptions and branch protection
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

The owner approved an independent release sequence beginning with **2.0.0**.
Native upgrade and release qualification remain required. This communicates a
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
  fork identity, retaining legal attribution. The owner selected **Nextcloud MCP**; describe it as an independent fork and
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

Inventory and selection completed on 2026-10-23: see the
[capability decisions and acceptance gates](upstream-1.2.0-inventory.md) and
[exhaustive source ledger](upstream-1.2.0-source-ledger.md). Only this analysis
was initially authorized. The user subsequently authorized the selected ports;
batches A-E and their local automated gates are now complete. Follow the
[execution record](upstream-1.2.0-integration.md), including all 16 native matrix
cells and the failures corrected before the final pass. F2 closes only the selected
scope. The subsequent D06 follow-up adds native file linking under the same native
sharing lifetime model as Files, with explicit audience confirmation and strict
policy gates. Its newer qualification is recorded separately. F3 and human reviews
remain pending, and installed-upstream-1.2.0 upgrades are not yet qualified.

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

Identity, version and qualification profiles are confirmed in the
[F3 execution record](fork-f3-preparation.md). These choices settle the design
scope; they do not satisfy the qualification gates below or declare a release.

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

Version-sensitive qualification has been adapted for 2.0.0: the pinned upstream
1.1.2 source now uses `upgrade-upstream-112` and a real `occ upgrade`. The old
same-version replacement and NC32 native cells remain historical evidence at
`5ff6672`, not runnable modes for a candidate whose version/minimums exclude them.
The candidate matrix now has 14 cells. Full final-artifact qualification, current
patch pins and the actual installed upstream version remain outstanding; see the
[F3 execution record](fork-f3-preparation.md). Do not weaken version guards or
force compatibility to obtain passing results.

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
| Fork identity and first version | Owner confirmed Nextcloud MCP and 2.0.0; development metadata updated | Qualification remains pending; no published release |
| Long-term owner and support scope | User confirmed maintenance/security and operation/recovery ownership; reference qualification profiles confirmed; partial host facts recorded in the [delivery inventory](fork-f3-delivery.md#intended-host-read-only-inventory), with host controls and reviewers still pending | Resolve host compatibility and accountable release review before deployment |
| Upstream 1.2.0 | Initial F2 selected ports qualified; D06 follow-up locally qualified with separate evidence; installed-upstream-1.2.0 upgrade and F3/F4 qualification pending | Do not equate source integration with release or host qualification |
| Update exclusion and integrity handling | [Local content verifier and filtered-catalog candidate](fork-f3-delivery.md) prepared; target-host mechanism remains unqualified | Deployment blocker |
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
and the user explicitly confirmed all three F0 decisions. At F0 closure no F1
branch, tag, runtime change or release had been created. The later F1 branch action
is recorded separately below. F0 closure does not approve F3 decisions or complete
any human review. The local remote configuration change is recorded below.

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
parity or identify the user's actual production platform. At F0 verification, local
`main` was `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` and no local `fork/main`
existed. GitHub's PR response still reports base SHA `27da5e1`, whereas the direct
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

The [F3 proposal](#f3-decision-proposal) below is retained as decision history.
The owner subsequently selected Nextcloud MCP and approved 2.0.0 plus the proposed
platform profiles. The [execution record](fork-f3-preparation.md) is authoritative
for the current status. Host facts and reviewer assignments remain pending.
The phase deadlines below remain in effect. F0 closure does not prematurely approve deployment.

| Decision | Current disposition | Decision owner / deadline |
| --- | --- | --- |
| Public display identity | **Nextcloud MCP**, selected by the user; retain technical identity and original attribution | Confirmed; implementation and qualification tracked in F3 |
| Initial release version | Independent `2.0.0` approved; `fork-v2.0.0` remains the planned tag, not created | Confirmed; release qualification pending |
| Intended host and support scope | NC 33–35 reference profiles approved; [initial host inventory](fork-f3-delivery.md#intended-host-read-only-inventory) identifies LinuxServer/Nginx, NC35.0.2/PHP CLI 8.5.12 and a PostgreSQL 16.15 binary in the apparent database container (active SQL-server version unverified). This differs from the approved Apache fixtures | Record the additional target-profile decision and qualify it; update/recovery facts and final acceptance remain pending / F3–F4 |
| Supported clients | ChatGPT is the stated intended client; qualify its real journey before claiming support or deploying a path that depends on it | User defines release scope / before F3; acceptance before dependent deployment |
| Reviewer assignments | Technical/security, accessibility, document languages and German UI remain unassigned; the user coordinates assignment or a documented scope decision | User coordinates; reviewers pending / record disposition before F4 release readiness; accountable security and release review before deployment |
| Update exclusion, integrity and source-offer mechanism | Local source offer and file-content verification implemented and tested; update exclusion and native host integrity remain unqualified. See the [delivery record](fork-f3-delivery.md) | Assistant prepares evidence / F3; local source acceptance does not qualify host update or recovery controls |

The assistant can resolve Git object selection, source comparisons, inventory
structure, focused test selection and implementation details within approved scope.
Those do not authorize changing the default branch, publishing artifacts or
exposing HTTPS. The user's long-term responsibilities are limited to the explicit
F0 confirmation, not additional duties inferred by the assistant.

### F3 decision proposal

Historical proposal, superseded by the owner confirmations and current status in
the [F3 execution record](fork-f3-preparation.md). The text below records the options
as presented; its pending language describes that earlier decision point.

Prepared and reviewed on 2026-10-23 against local revision `c7a7585`. This is a
recommendation for owner confirmation, not an accepted support policy or a release. Current
runtime metadata still says `MCP for Cloud`, version `1.1.2`, app ID `mcp`, PHP
minimum `8.2` and NC `32`–`35`. No metadata, infrastructure or publication changes
are part of this proposal. F0 ownership and branch decisions remain accepted;
PR #14 remains a historical reference.

#### Identity and release numbering

| Owner choice | Recommended value | Reason and consequence |
| --- | --- | --- |
| Public display name | **MCP for Nextcloud (marcjoancr fork)** | Makes the independent maintainer explicit without inventing a new organization or implying upstream endorsement. Keep the repository name; no repository rename is needed. |
| Technical identity | Retain `mcp`, `OCA\Mcp`, `mcp/` and all existing migration identities | Preserves the identifiers needed for an in-place upgrade; data/schema compatibility still requires qualification. Original and fork cannot coexist under this ID. Keeping it is conditional on proving update exclusion and integrity handling before deployment. |
| Project and support destination | Use `marcjoancr/mcp-for-nextcloud` for fork source, releases and issue links | Direct future users to the maintained fork while preserving original author/copyright notices, license terms and upstream provenance. Do not replace original authorship with sole fork authorship. |
| First stable release | **2.0.0**, tag **`fork-v2.0.0`**, title **MCP for Nextcloud (marcjoancr fork) 2.0.0** | Starts the independent sequence already proposed in this plan. The release notes must explain the maintenance boundary, selected upstream 1.2.0 behavior and actual compatibility changes (including any raised platform minimums); the number alone does not establish a protocol break or protect against official updates. |
| Maintenance lines | One stable line, developed on the already approved `fork/main` | Avoids promising multiple maintained branches. Apply the patch/minor/major rules above to subsequent releases. |

Recommend confirming these values as one identity/version decision. A separate
app ID is an alternative only if side-by-side installation becomes a requirement
or safe update control cannot be achieved; it would need a distinct data migration
plan. Continuing upstream-like `1.2.x` numbering is possible but would make the
maintenance boundary less clear. Neither alternative is selected here.

Keep candidate artifacts local until explicitly authorized to publish. Before
2.0.0 can be accepted, adapt the version-sensitive tests described in F4 and
rehearse actual upgrades, including the user's installed MCP version. Existing
1.1.2 artifact evidence does not qualify the proposed 2.0.0 artifact, and upgrade
from an installed upstream 1.2.0 remains unverified.

#### Proposed platform scope

Recommend the following initial qualification targets. These are bounded profiles,
not a promise that every combination of independently tested components works.
Use current security-maintained patches, pin exact images/digests when qualifying,
and record the tested versions with each release. During implementation, reconcile
manifest constraints, CI coverage and public support documentation with the approved
scope. Manifest ranges cannot express all allowed NC/PHP/database combinations:
document the qualified profiles explicitly, and do not equate installation being
allowed with support. Retain historical tests with their original applicability
rather than forcing a candidate onto a core version its manifest excludes.

| Profile | Proposed scope | Acceptance limit |
| --- | --- | --- |
| Primary server | Linux x86-64, Nextcloud 35, PHP 8.5, MariaDB 10.11, Apache | Preferred reference profile based on existing native evidence; qualify current patches and the final fork artifact before declaring support. This is not an instruction to upgrade the user's server. |
| Additional server profiles | NC 34 / PHP 8.5 / MariaDB 10.11 and NC 33 / PHP 8.4 / MariaDB 10.11 on the same reference platform | Retain compatibility while each Nextcloud line receives official maintenance. Recheck version-specific requirements before freezing the matrix. |
| Additional database | PostgreSQL 16 on the primary NC 35 profile | Existing native coverage supports retaining this target. Do not extrapolate it to every NC/PHP combination. |
| Local test database | SQLite | Keep isolated tests and recovery coverage; exclude production support from this initial proposal. |
| Historical compatibility | NC 32 and PHP 8.2/8.3 tests | Preserve useful regression and migration evidence without advertising these as first-release production profiles. PHP unit success alone does not qualify a native server combination. |
| Other configurations | Other database lines, CPU architectures, web servers and NC 36+ | No initial support claim. Add a profile only after checking core requirements and qualifying it; adapt the proposal if the user's real host needs one. |

The official [Nextcloud maintenance schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule),
checked on 2026-10-23, lists NC 33, 34 and 35 as maintained, with current patches
33.0.10, 34.0.5 and 35.0.2. The recorded native runs used 33.0.9, 34.0.4 and
35.0.1 respectively (plus 32.0.15); those runs cannot certify the newer patches.
NC 32 reached community end of life in September 2026. The listed maintenance
horizons for NC 33, 34 and 35 are February, June and September 2027 respectively;
recheck them at release time. The proposal does not include extended enterprise
support or an obligation to maintain upstream core security fixes ourselves.
Do not extend support automatically when a new major appears or silently drop a
promised profile: document the end-of-maintenance date, planned removal and upgrade
path before a release changes the supported scope.

The [NC 33 requirements](https://docs.nextcloud.com/server/33/admin_manual/installation/system_requirements.html)
allow PHP 8.4, MariaDB 10.11 and PostgreSQL 16. Version-specific NC 34/35 manuals
could not be retrieved during this review. The
[latest requirements page](https://docs.nextcloud.com/server/latest/admin_manual/installation/system_requirements.html)
currently identifies itself as NC 36, so it is not used to certify those profiles.
Their official requirement cross-check remains an engineering gate alongside the
existing native evidence. [PHP's lifecycle](https://www.php.net/supported-versions.php)
places PHP 8.2 security end of life on 2026-12-31; recommend no new production
commitment to that branch. The proposed 8.4/8.5 profiles still require maintained
patches and the corresponding Nextcloud runtime requirements. PHP 8.3 still has
security support through 2027-12-31: its omission from the initial native profiles
limits our qualification workload and is not an assertion that PHP 8.3 is unsafe
or incompatible. Reconsider it if the intended host needs it.

If the intended host uses NC 32 or another excluded profile, do not force-install
the proposed fork release or bypass version checks. First define and rehearse a
supported core/app upgrade sequence on an isolated copy, or return the platform
scope to the owner for revision. Retaining the old test evidence does not prove
that sequence works. Removing a previously allowed platform is a compatibility
change to explain in release notes, even if the MCP protocol stays compatible.

Native test evidence, optional-app version gates and failure history stay in the
[integration record](upstream-1.2.0-integration.md); this proposal does not duplicate
its results or claim a full matrix was rerun. In particular, Deck-dependent tools
remain gated by the supported Deck/API versions; selecting a server profile does
not imply every optional capability is available there. Keep protocol automation
as the baseline, with ChatGPT as the intended client pending real acceptance.
Inspector and ChatGPT are not declared fully qualified clients by this proposal.

#### What the owner needs to confirm

1. Confirm or amend the proposed display name, retained technical identity, fork
   project links and independent `2.0.0` / `fork-v2.0.0` numbering.
2. Confirm or amend the bounded platform profiles above, their maintenance policy
   and exclusions, or identify which additional profile the actual server needs.
3. Supply the intended server's Nextcloud, PHP, database engine/version, OS/CPU,
   web server/deployment type, installed MCP origin/version and relevant optional
   app versions (especially Deck, Talk, Notes and Teams/Circles). No credentials,
   tokens, user data or public exposure are needed. This is factual input, not a
   request to upgrade the server. If unknown, keep the actual-host profile pending.

The assistant can check current requirements, resolve exact package/image pins,
prepare the matching test matrix and implement approved metadata and source-offer
changes in a subsequent authorized implementation step. It can investigate and
validate update exclusion, integrity, upgrades and coordinated recovery in an
authorized isolated environment. A failed mechanism remains a blocker; it does
not permit silently changing the app ID or relaxing integrity controls.

These confirmations settle design choices only. F3 remains open until its source,
license, update-control and recovery acceptance criteria pass; F4 must qualify the
final candidate. Public HTTPS, Inspector's recorded encrypted-store limitation,
the ChatGPT consent/tool/revocation journey and all human reviewers remain pending.
Technical/security, accessibility, documentation-language and German UI reviewers
are still unassigned. Reviewing or committing this document does not accept any
proposed decision.
No new reviewer, monitoring SLA, publication, push, deployment or change to PR #14
is authorized by accepting this proposal.

### F0 result and next gate

F0 is closed: the technical references are verified and the three owner decisions
are confirmed. The user subsequently requested F1; its execution record follows.
The reviewed documentation and F0 closure commits belong on the fork line, not
on the PR head.

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

## F1 local development establishment

On 2026-10-23, `fork/main` was created from the exact agreed candidate
`14a76df26817fb8a4540eb9c9d1d0043e3e87506`, then fast-forwarded to
`3fbe2dba1ddc26a8eadf79b41493916b072aab12`. This incorporates both reviewed commits:
`8e44a40e0bfae9bc5d3aab8039c2f784250d4982` (plan and index) and
`3fbe2dba1ddc26a8eadf79b41493916b072aab12` (verified F0 references, owner decisions
and remote policy). No squash, cherry-pick or history rewrite was needed.

[Contributing](../../../CONTRIBUTING.md#fork-development-workflow) now directs
local development to `fork/main` and the fork repository. The `main` branch and
PR head remain unchanged. This phase changes documentation and local Git branch
state only; the complete `mcp/` runtime, tool inventory, dependency locks, migration
identities and CI workflows remain identical to the agreed candidate. No F2 port
or F3 identity/version change is included.

### Remote policy proposal, not applied

After explicit publication authorization, propose making `fork/main` the fork's
GitHub default branch and protecting it from deletion and force-pushes. Require
reviewed topic PRs, resolved review conversations and successful checks on the
candidate revision. Required checks should cover package validation, REUSE,
documentation/provenance, syntax, both static-analysis scopes, PHPUnit on PHP
8.2-8.5 and all three browser engines, using the actual emitted CI job names.
Confirm these names after the first authorized run rather than configuring
nonexistent checks. Native upgrade/platform qualification remains an additional
release gate for the declared support matrix.

Reviewer assignment and repository feature availability must be resolved before
claiming that this protection is active or allowing external contributions. No
branch protection, repository default, remote branch or automation setting was
changed in F1. No maintainer has been assigned or represented as having approved
the code. Development tools that still default to `main` need an explicit
`fork/main` starting point; their external configuration was not changed here.

### F1 validation and remaining scope

F1 is complete locally. The catalog `check` task, delegating to
`composer --working-dir=mcp check`, exited 0 using PHP 8.4.27, Composer 2.8.8,
Node 24.21.0 and npm 11.19.0. Locked dependencies and Composer platform requirements
were checked. Results:

| Gate | Result |
| --- | --- |
| Documentation and provenance | 145 Markdown files valid; 110 operational package sources; 96 translations still require human review |
| Documentation tooling / client runner safety | 29 / 10 tests passed, no failures or skips |
| PHP syntax and static analysis | 1,148 PHP files checked; both analysis scopes passed |
| Complete PHP suite, including architecture tests | 7,691 tests and 65,394 assertions passed |
| Browser suite | 654 tests passed across Chromium, Firefox and WebKit |
| Production packaging | `mcp-1.1.2.tar.gz` built and validated; 110 staged documentation sources verified |
| Scope and ancestry | `mcp/` and CI workflows identical to `14a76df`; both reviewed documentation commits are ancestors of `fork/main` |
| Reference preservation | Local `main` unchanged; PR #14 still open at `14a76df`; only `origin` retained and GitHub CLI still defaults to the fork |

The browser build test intentionally emits missing-module errors while asserting
that failed builds reject and remove stale outputs. All three instances passed;
these messages were not suppressed. Composer's existing PSR-4 warnings for
explicitly loaded foreign-namespace test doubles also remain visible, as explained
in the testing guide. No new runtime or test workaround was introduced.

The complete gate ran against the unchanged runtime tree and F1 documentation;
only this result record was then updated and the documentation checks repeated.
Raw command output and generated packages remain local build artifacts. No new
remote CI run was requested. The wider PHP version matrix, REUSE command, native
Nextcloud matrix and real-client/host qualification were not rerun in F1; earlier
candidate evidence is historical, not a new result for these scopes.

At F1 closure, F3 decisions and human reviewers were still pending, alongside
the documented HTTPS, Inspector and ChatGPT acceptance limitations. The later
[F3 record](fork-f3-preparation.md) distinguishes confirmed decisions from remaining
qualification and reviewer gates. The subsequent F2 inventory is
linked above. At F1 closure no upstream 1.2.0 runtime changes had been integrated.
Later selected ports and qualification are recorded in the
[execution record](upstream-1.2.0-integration.md); the F1 evidence above is historical.
Native/platform qualification and production deployment were outside that local
branch-establishment phase.

## Historical validation scope of the original plan

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
text, deployment update configuration or PR state changes were part of that
original documentation task. Later selected integration is tracked separately.
The non-documentation changes are the explicitly requested local Git/GitHub CLI
remote configuration at F0 and local branch establishment at F1, recorded above.
No remote repository setting was changed.
