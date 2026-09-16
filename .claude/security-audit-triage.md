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

### A URL/redirect-target field is stored with no validation, matching a core Joomla component's own behavior

If the *real* Joomla core component this tool mirrors already stores the equivalent field with no
validation of its own (e.g. `com_redirect`'s `new_url` on `redirects_create`/`update`, later used verbatim
by `plg_system_redirect` to issue real 30x redirects), reproducing that is not a port-introduced weakness
— it's the component's intended purpose (SEO/link management here) and matches the stock backend UI's own
(lack of) validation, gated by the same ACL the real controller enforces. Don't re-flag this shape without
first confirming the real core component/controller does something stricter.

### Ticket `com_fields` values merged without per-field access filtering

Not an issue, for two independent, compounding reasons: (1) the whole tool is already gated behind
`requireAtsManager()`; (2) see "Actors out of scope" above — this project doesn't expose ticket editing
to non-manager owners, and has no plans to. Re-evaluate only if that scope decision ever changes.

### A secondary/coarse ACL check in this project's own code is redundant because a deeper layer already enforces the correct one

Distinct from the "matches core" patterns above: sometimes a tool's own secondary permission check uses a
coarser or slightly wrong asset (e.g. `Panopticon/AdminTools.php`'s several methods skipping the class's
usual secondary `core.manage` check, `Users/Groups.php`'s escalation guard evaluating current rather than
prospective admin status), but the *actual* protection comes from a different layer entirely — a primary
gate already covers it (`requireSuperUser()`), or the underlying Joomla/ATS model independently re-checks
the correct thing on save (`GroupModel::save()`). When you find a coarse/inconsistent secondary check,
first trace whether some other layer in the same call chain already enforces the tighter rule before
treating the coarseness itself as the vulnerability — it's usually a consistency nit, not a gap.

### A proposed fixed resource-consumption cap doesn't fit a feature with legitimately wide input-size variance

Don't propose a fixed byte-size/entry-count cap as "the fix" for a resource-exhaustion concern on a
feature whose whole purpose is handling inputs of unpredictable, legitimately wide size — e.g. installing
Joomla extension packages, which range from single-digit kilobytes to hundreds of megabytes in real-world
use. Any fixed limit is either too high to help or too low and breaks legitimate use. This class of
finding is usually accepted as an inherent property of the feature (see "How to classify hardening vs
vulnerability" below) rather than fixed with an arbitrary number. Contrast with a genuinely fixable
resource-consumption bug — e.g. iterating an unbounded result set with no pagination at all — which *is*
fixable with a real cap; the test is whether the legitimate input range is itself unbounded, not whether
a cap is theoretically possible to add.

### "An MCP client might mishandle/render returned raw data unsafely"

Not this server's concern, by design. MCP4j's entire role is to provide a mechanism for retrieving and
setting Joomla's raw data (article HTML, custom field values, etc.) — whatever a downstream MCP client
does with that data (e.g. naively rendering returned HTML into a DOM) is the client's responsibility, the
same way it would be for Joomla's own JSON:API or any other API returning rich-text fields. Don't flag
"the client could misuse this data" as an XSS/output-sanitization finding against the server; the server
already isn't sanitizing/escaping on the way out, on purpose, and that's correct for a raw-data-access
tool.

## Coding convention preference surfaced during fixes

- **Prefer Joomla Framework utility classes over ad hoc PHP for URL/path parsing, even in "pure"
  framework-free classes.** When fixing a URL-scheme check, the operator's explicit direction was to use
  `(new \Joomla\Uri\UriImmutable($url))->getScheme())` rather than hand-rolled `parse_url()`/regex/
  `filter_var(FILTER_VALIDATE_URL)` — described as "the One True Joomla Way." `joomla/uri` (like
  `joomla/filesystem`'s `Path`, already used elsewhere in this codebase) is a standalone, CMS-bootstrap-
  free Joomla Framework package suitable for a "Pure: no Joomla CMS" Mapper class — it doesn't require a
  running Joomla application, so using it doesn't compromise a Mapper's unit-testability, it's just vendored
  as an explicit Composer dependency like `league/commonmark` already is. Apply this preference proactively
  to any future ad hoc URL/path/URI parsing this codebase might add, not just when flagged in an audit.

## Findings verified invalid by a real integration test (not just static reasoning)

When a finding's validity hinges on an assumption about Joomla's runtime behavior rather than something
directly readable in this codebase's own source (e.g. "would Joomla's model layer stop this?", "does a
blocked user's token still work?"), the audit repeatedly found it faster and more reliable to write a
real integration test proving the answer than to reason about it from framework knowledge alone —
framework-knowledge assumptions turned out right in every case checked so far, but the *specific
mechanism* was sometimes different from what was assumed (see the `content_articles_create` entry below),
which matters for writing an accurate finding and an effective fix. Default to building the test.

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
