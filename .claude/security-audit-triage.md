# Security audit triage knowledge

Standing knowledge accumulated from `security.md` audit passes: actors/scenarios out of scope for this
project, finding classes already ruled invalid and why, controls already in place, and how to classify
"hardening" versus "vulnerability" for pkg_mcp4j specifically. Read this before starting any security
audit, security review, or `audit-*` skill run, and before reporting any finding from one — several
finding classes below have already been reviewed and ruled invalid; don't re-report them as new findings
without first checking whether they match a pattern here.

## Actors out of scope

- **Low-privileged users whose only access is ticket creation** (a stock Joomla "Author"-shaped ATS
  customer/reporter) are explicitly out of this project's threat model. The intended audience for MCP4j
  is Super Users, Administrators, and at most partial-admin special cases (e.g. a ticket-category
  manager) — there are no plans to expose ticket editing/management to lower-privileged owners. A finding
  premised on "what if a non-manager ticket owner could reach this tool" should be treated as informational
  at most, not a vulnerability, unless the tool is *already* reachable by such a user today.

## Finding classes ruled invalid (with reasoning)

### "This read/list tool gates on `core.manage`, not per-item `core.edit`"

Verified against real Joomla core (`ApiController::displayItem()`/`displayList()`, the classes this
port's read path is explicitly modeled on): core itself has **no** per-item `authorise()`/`allowEdit()`
call on the read path either — `allowEdit()` is only ever invoked from `save()`/`delete()`. A tool that
only checks `core.manage` (or the component-level equivalent) before returning full record content is
reproducing Joomla's own Web Services API semantics exactly, not introducing a new gap. Ruled invalid for:
Content/Categories/Banners/Newsfeeds/Modules/Templates/Menu-items reads, Module `doRead()`'s raw
`content`/`params`. Do not re-flag this shape as High/Medium without first checking whether the
equivalent real Joomla core `ApiController`/`JsonApiView` class does anything stricter — it almost
certainly doesn't.

### "A Joomla core XML form field has no ACL/filter restriction on some field"

If the *real* Joomla core XML form for that model (e.g. `com_content`'s `article.xml`, `com_menus`'s
menu-item form) places no `filter`/ACL restriction on a given field, this component reproducing that gap
is not a port-introduced weakness — it's interface fidelity with core, and "fixing" it would actually be
a *deviation* from documented parity requirements (see `tests/parity/compare-tool-surface.php`). Ruled
invalid for: `content_articles_create`/`update`'s `createdBy`/`createdByAlias` reassignment, menu items'
`home` flag being settable with plain `core.edit` (core's `ItemModel::getForm()` only ever disables
`menuordering`/`published` when `canEditState()` is false, never `home`), category reparenting not
checking `core.create` on the destination parent (`CategoryModel::save()` has no such check; only the
separate, unused-here `batchMove()` path does), `com_contact`'s linked-user field being mass-assignable
to any user id (core's own Contact edit form has no ownership/consent concept over that foreign key
either).

**How to verify this class of claim before ruling on it:** read the actual Joomla core source for the
equivalent model/form (a local Joomla install under `~/Sites/boot4`/`boot5`/`boot6`, or the vendored copy
under `tests/integration/docker/www/`), don't just trust a docblock or comment claiming parity. Several
sub-audits in the initial full pass over-flagged findings in this class as High before this verification
step was applied — always do the verification, every time.

### Ticket `com_fields` values merged without per-field access filtering

Not an issue, for two independent, compounding reasons: (1) the whole tool is already gated behind
`requireAtsManager()`; (2) see "Actors out of scope" above — this project doesn't expose ticket editing
to non-manager owners, and has no plans to. Re-evaluate only if that scope decision ever changes.

## Findings verified invalid by a real integration test (not just static reasoning)

- **"A blocked user might exploit a self-unblock ACL gap"** (any variant): a Joomla bearer token is
  authentication, standing in for a username/password. Joomla's login chain
  (`ServerController::getAuthenticatedUserId()` → `$this->app->login(..., ['action' => 'core.login.api'])`)
  refuses a blocked account regardless of credential type — confirmed empirically with
  `tests/integration/src/Tests/Users/BlockedUserAuthTest.php` (initialize() returns HTTP 200 before
  blocking the account, then HTTP 401 / JSON-RPC `-32001` after, with the identical unchanged token). A
  blocked user's token can never reach *any* tool, not just the one where the ACL gap was found. Don't
  speculate about this class of scenario without an integration test proving the authentication-layer
  behavior first — the framework-knowledge assumption ("it probably fails safe") turned out to be
  correct here, but was worth verifying rather than asserting.
- **"content_articles_create might let an under-privileged caller publish via the default state"**: the
  actual protection was a *different* mechanism than initially assumed (`ArticleModel::getForm()` →
  `canEditState()` → `filter="unset"` on the `state` field → `AbstractTool::saveItem()`'s `validate()`
  step strips it before `Table::bind()`), not an `AdminModel::save()`-level ACL check (core's own
  `save()` has none). The lesson: "Joomla's own layer would stop it" is often true, but the *specific*
  mechanism matters and should be traced, not assumed, when writing up the finding — see
  `tests/integration/src/Tests/Content/ArticlesAuthorPublishAclTest.php`.

## Controls already in place (cite these rather than re-flagging as findings)

- **PHP object-injection**: `SessionDataCodec` deliberately uses JSON, never PHP `serialize()`/
  `unserialize()`, specifically to avoid a client-supplied `Mcp-Session-Id`-keyed deserialization gadget.
- **Secret leakage**: `SecretLeakGuard` cross-checks tool output against the current request's own
  bearer token, `X-Joomla-Token`, and every global-config secret field before returning it. `SecretMasker`
  partial-masks (last 4 chars) update-site/extension download keys — a deliberate UX trade-off, not a
  weakness, since in practice these are only ever used with secrets longer than 16 characters, making the
  4-character tail exposure immaterial. Session-ID lookups use a bound SQL equality against a primary-key
  index rather than `hash_equals()` — a theoretical timing side-channel, but not a practical one: network/
  HTTP jitter is far larger than any signal a B-tree lookup with 256 bits of entropy could leak, and this
  matches how every DB-backed session store works.
- **Supply-chain**: `panopticon_updatesites_update` explicitly refuses to let a caller change an update
  site's `name`/`location`, closing a bug in the underlying Panopticon connector that would otherwise
  allow redirecting an extension's update feed to a hostile server — the project's own code comment there
  already identifies the "prompt-injected LLM redirects updates" threat model.
- **Zip extraction**: `ZipArchive::extractTo()` has had built-in Zip Slip/symlink-escape protection since
  PHP 5.6.19/7.0.4 (covering the whole Joomla 4/5 PHP support range) — empirically verified, not just
  assumed, against this project's PHP 8.5 runtime with both a `../`-style renamed entry and a real Unix
  symlink entry. No further belt-and-braces containment check is required for this use case (installing
  vetted core and third-party extension updates) — Joomla's own extension installer relies on the same
  runtime guarantee and nothing more.
- **Package hygiene**: the actual built release ZIP (not just the source tree) has been directly
  enumerated and found clean of dev files, `.git`, credentials, and root `composer.json`/`composer.lock`.
  `composer audit --locked` should be re-run at every future release as a standing gate (advisory DBs
  change over time) — this is process commentary, not a code finding.

## How to classify "hardening" vs "vulnerability" here

A gap is a **vulnerability** only if it lets an authenticated MCP caller do something a real, equivalent
Joomla backend action (as a user with the same ACL) could not already do. A gap that merely reproduces
an existing, documented Joomla core behavior/inconsistency is **not** a vulnerability in this project —
it's either invalid (if core doesn't protect against it anywhere) or, at most, an informational note for
awareness. Features whose entire purpose is server-side-URL-fetching or arbitrary-package-installation
(the Panopticon "Install from URL"/"Update Source" tools) carry an *inherent* risk the operator has
chosen to accept and defer to a future MCP tool-domain/endpoint gating phase (Panopticon disabled by
default under that scheme) rather than mitigate via ad hoc per-tool code — don't re-flag that class of
risk as newly discovered; check `security.md`'s H1/M1 for the standing decision first.
