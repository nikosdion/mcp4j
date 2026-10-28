# MCP4j integration tests

PHPUnit tests that drive a real, disposable Joomla + pkg_mcp4j install running in Docker, over real
HTTP — exactly as an MCP client (or an attacker) would see it. Nothing here boots Joomla in-process;
see `../../phpunit.xml` / `../unit` for the pure, framework-free unit suite instead.

## Why this exists

Two of the real bugs found while building MCP4j only ever showed up against a live site, never in the
unit suite:

- `Joomla\CMS\Application\ApiApplication::close()` is a bare `exit()` — it never runs the
  `respond()`/`sendHeaders()` pipeline, so every `setHeader()` call from within a controller task was
  silently discarded until `ServerController::send()` was fixed to flush headers first.
- Session expiry was compared with PHP's `strtotime()`, which reads a naive datetime string in the
  *process's default timezone* rather than UTC. On a server whose default timezone isn't UTC, a
  session minted seconds ago read back as already expired.

The unit suite guards the second bug at the logic level (`SessionClockTest`, run under a non-UTC
default timezone). This suite guards *both*, at the only level that would actually have caught the
first one: a real Joomla install, in Docker, in a container whose PHP default timezone is also
deliberately non-UTC (see `docker/php.ini`) — keep that in sync with `SessionClockTest` if it ever
changes.

## Requirements

- Docker and Docker Compose (`docker compose` or `docker-compose`).
- `phpunit` installed **globally** via Composer on the host (`composer global require
  phpunit/phpunit`), resolvable on `PATH`. Never added as a project dependency — see the repo's
  `composer.json`.
- `phing`, to build the `pkg_mcp4j-*.zip` the harness installs — requires the sibling
  `~/Projects/dionysopoulos/buildfiles` checkout `build.xml` imports (already the case for anyone
  working in this repo's normal layout).
- `unzip` and `getcms` from the sibling `../buildfiles` checkout (run `composer install` there), used to resolve/download Joomla releases.

## Quick start

```
tests/integration/run-tests.sh                     # the whole JOOMLA_MATRIX: 4.4, 5.4, latest 6
tests/integration/run-tests.sh 6                    # latest Joomla 6 only
tests/integration/run-tests.sh 4.4 5.4              # Joomla 4.4 and 5.4 only
tests/integration/run-tests.sh 6 --keep             # leave the Docker stack running afterwards
tests/integration/run-tests.sh 6 -- --filter=SessionLifecycleTest
```

The first run creates `docker/.env` from `docker/env.dist` — edit it if you need different ports,
image versions. `JOOMLA_MATRIX` itself is committed (`docker/matrix.env`, `buildfiles/docker/e2e/matrix`).
Downloaded Joomla packages are cached under
`tests/integration/inbox/` (gitignored) so repeated runs don't re-download them. `pkg_mcp4j` itself is
built once per `run-tests.sh` invocation and reused across every entry in the run, since the package
doesn't vary by Joomla/PHP version.

## What `run-tests.sh` actually does, per `joomla:php` matrix entry

1. Resolves and downloads (or reuses a cached) Joomla release matching that entry's version.
2. Rebuilds and restarts the `web` container for that entry's PHP version, since PHP 8.1 and PHP 8.5
   need genuinely different `php:*-apache` base images, not just a different `php.ini` setting.
3. Resets the database and extracts a fresh Joomla into the `web` container's docroot.
4. Runs Joomla's own `installation/joomla.php` CLI installer.
5. Installs the (once-per-run) `pkg_mcp4j-*.zip` via `cli/joomla.php extension:install`.
   `script.mcp4j.php`'s postflight enables `plg_webservices_mcp4j` and `plg_task_mcp4j`
   automatically — no manual enable step needed here, and this is itself covered indirectly
   (`InitializeTest` would 404 immediately if it weren't).
6. Installs any optional third-party packages dropped into `inbox/extensions/` — see "Third-party
   extensions" below.
7. Fixes web root ownership (`chown -R www-data:www-data`) — Joomla and every extension above were
   installed as root via `DC exec`, but Apache serves as `www-data`; any tool that writes a file
   (config, media, language overrides, ...) would fail otherwise on a Linux Docker host.
8. Mints an API token for the installer's admin user via `docker/provision-token.php` — a standalone
   script that replicates what `plugins/user/token` writes to `#__user_profiles` when a site owner
   ticks "Enable Token Based Authentication" on their profile, since Joomla has no CLI command for
   this. See that file's docblock for exactly which core classes it's mirroring.
9. Provisions a low-privilege API user and mints its token — see "Low-privilege user" below.
10. Writes both tokens to `docker/.tokens` (`chmod 600`, gitignored) for `quick.sh` to reuse.
11. Runs `phpunit -c phpunit-integration.xml` inside the container, with `MCP4J_BASE_URL`,
    `MCP4J_API_TOKEN` and `MCP4J_LOWPRIV_TOKEN` set.
12. Tears the whole stack down after the last entry (`--keep` to skip this and iterate quickly against
    a single version — see "Quick re-run" below).

Each matrix entry runs in a subshell, so one entry failing (or hitting an internal `die()`, e.g. a
Docker or download failure) reports that entry as FAILED and moves on to the next one rather than
aborting the whole run — the final summary and exit code still reflect every entry that failed. A run
that fails or is interrupted never leaves a stack behind in an unknown state: the database is dropped
and recreated, and the web root is wiped, at the start of every entry — not only on success.

## Why these versions

`JOOMLA_MATRIX` is committed, not configured: the shared `docker/e2e/matrix` of the sibling `buildfiles`
repository supplies `5.4:8.1 6:8.5`, and `docker/matrix.env` here adds `4.4:8.1`, giving `4.4:8.1 5.4:8.1 6:8.5`.
Setting it in `docker/.env` has no effect (a value in the process environment still does). It is the full
range MCP4j declares support for, not a spread chosen to exercise a specific version-gated branch — there isn't one yet (no code
in this repo currently branches on `JVERSION`). `script.mcp4j.php` declares `$minimumJoomla = '4.0.0'`
and `$maximumJoomla = '6.3'` (exclusive); 4.4 and 5.4 are the final, longest-lived minor release of
each of the two closed majors in that range, and bare `6` always resolves to the latest Joomla 6
release — so the matrix is "one representative version per supported major," re-resolved to whatever
is actually current for the open-ended entries every time it runs.

The PHP pairing follows the same "what real users are actually running" logic rather than testing
every major against the same PHP version: Joomla 4.4 and 5.4 are paired with PHP 8.1 (comfortably
within both majors' supported PHP range and the oldest version this project's own `composer.json`
still declares support for — see `require.php`; this is why 4.4 is not paired with its own PHP 7.4 floor
as Akeeba Backup and Admin Tools do), while latest Joomla 6 is paired with PHP 8.5, the
newest PHP release that exists at the time of writing (`composer.json` permits up to, but excluding,
8.7 — 8.6 simply hasn't shipped yet). Update the shared matrix's `6:...` entry once 8.6 ships and this
project has confirmed compatibility with it; don't just bump it reflexively the day it's released.

A green run against a single Joomla version still proves less than it looks — the `<api>`
route-registration path (`plg_webservices_mcp4j`'s `onBeforeApiRoute`) and the content-negotiation
workaround (`ensureAcceptHeaderForOurRoute()`) both depend on Joomla's own `ApiRouter`/`ApiApplication`
internals, which are exactly the kind of thing that shifts between major versions without changing any
documented, stable API — the login()/`AuthenticationFailed` bug this suite already found (see below)
is a direct example. Re-run `audit-e2e-matrix` periodically to keep `JOOMLA_MATRIX` current as new
Joomla releases ship, the declared floor/ceiling in `script.mcp4j.php` moves, or PHP 8.6 ships.

## Parallel runs from git worktrees

Several `pkg_mcp4j` git worktrees (one per plan branch) can run this harness **at the same time**
without colliding, as long as each uses its own Docker Compose project name and host port:

```
COMPOSE_PROJECT_NAME=mcp4j-itest-p04 WEB_PORT=8184 tests/integration/run-tests.sh 6 --keep
```

Both `run-tests.sh` and `quick.sh` let a `COMPOSE_PROJECT_NAME`/`WEB_PORT` (also `JOOMLA_MATRIX` /
`PHP_VERSION`) already set in the *process* environment win over the same name's value in `docker/.env`
— the file's own defaults (`mcp4j-itest` / `8180`) are only ever a fallback for a bare invocation with
nothing set. A worktree's `docker/.env` may also just set the first two directly (`COMPOSE_PROJECT_NAME=...` /
`WEB_PORT=...` appended to the file; `JOOMLA_MATRIX` and `PHP_VERSION` are committed and ignored there) if you'd rather not repeat them on every invocation; either way
works, the environment always wins.

The database and web containers, the bind-mounted `www/` docroot, and `docker/.tokens` are all scoped
per Compose project, so nothing is shared between worktrees except the read-only Joomla/extension
package cache (`inbox/`, symlinked from a common location — see its target).

## Low-privilege user

Every provisioned site also gets a second, deliberately low-privilege API user (username `lowpriv`,
"Registered" group only, no elevated ACL), provisioned by `docker/provision-lowpriv-user.php` and
token-minted the same way as the admin user. This exists so tool tests can prove ACL enforcement for
real — call a tool as `lowpriv` and assert it's refused (`assertPermissionDenied()`), rather than only
ever exercising the happy path as a Super User for whom every check trivially passes.

A stock Joomla install refuses this user at the Web Services API layer entirely (no `core.login.api`,
and the token-authentication plugin doesn't allow the group by default) — see the script's own docblock
for exactly what it grants and why, and why it only ever *adds* to existing grants rather than replacing
them.

The token is exposed to the integration suite as `MCP4J_LOWPRIV_TOKEN` and, in `AbstractToolTestCase`,
as `self::$lowPrivToken` / `callToolAsLowPriv()`. It's empty (and dependent tests skip via
`markTestSkipped()`, not fail) when running PHPUnit directly without going through `run-tests.sh` /
`quick.sh`.

## Third-party extensions

Some tool categories (Panopticon, Tickets, Admin Tools, Akeeba Backup, ...) only exist when the
matching third-party extension is installed. Drop their release zips into
`tests/integration/inbox/extensions/` (already gitignored, via `inbox/`) — e.g.
`pkg_panopticon-*.zip`, `pkg_ats-*.zip`, `pkg_admintools-*.zip`, `pkg_akeebabackup-*.zip`. Every `*.zip`
found there is installed, sorted by filename (prefix with `10-`, `20-`, ... to control install order
when one package depends on another). A failed install of one package is logged as a warning, not a
fatal error — the tests that depend on it just skip (via `requireToolsOrSkip()`) instead of failing the
whole run.

Override the directory with `MCP4J_EXTRA_PACKAGES_DIR` if you'd rather keep packages somewhere else.
When the directory doesn't exist or is empty, this step is a no-op.

## Quick re-run (`quick.sh`)

Once a stack is up (`run-tests.sh 6 --keep`), re-running PHPUnit against it directly (skipping the
entire provision) takes seconds:

```
tests/integration/quick.sh                                  # re-run PHPUnit as-is
tests/integration/quick.sh -- --filter=SomeTest              # ...filtered
tests/integration/quick.sh --reinstall                       # pick up code changes first
tests/integration/quick.sh --reinstall -- --filter=SomeTest
```

`--reinstall` runs `phing git`, upgrade-installs the freshly built `release/pkg_mcp4j-*.zip` over the
kept stack's existing install, and fixes web root ownership again — use it whenever you've changed
anything under `component/` or `plugins/` and want the running site to reflect it. It does **not**
reinstall the third-party packages from `inbox/extensions/` (those don't change between quick.sh runs).

`quick.sh` reads `docker/.env` and `docker/.tokens` (both written by `run-tests.sh`) and refuses to run
if the `web` service isn't up — it's meant purely as the fast half of the edit-test loop, not a
replacement for `run-tests.sh`.

## Writing tool tests (`AbstractToolTestCase`)

Once real MCP tools exist, one test class per tool class extends
`Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase` (`tests/integration/src/AbstractToolTestCase.php`)
instead of `AbstractE2ETestCase` directly. It adds one-line helpers for calling tools and asserting on
their `{"content":[{"type":"text","text":"..."}],"isError":bool}` result envelope, session handling
(sessions are bound to the user that created them — a different token needs a different session, handled
for you), and fixture cleanup:

```php
namespace Akeeba\Mcp4j\Tests\Integration\Tests\Content;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

class ArticlesTest extends AbstractToolTestCase
{
    public function testCreateReadUpdateTrashDelete(): void
    {
        $title = $this->uniqueName('MCP4j Test Article');

        $created = $this->callToolOk('content_articles_create', ['title' => $title]);
        $id      = $created['id'];

        $this->registerCleanup(fn () => $this->callTool('content_articles_delete', ['id' => $id]));

        $read = $this->callToolOk('content_articles_read', ['id' => $id]);
        $this->assertSame($title, $read['title']);
        $this->assertKeysAbsent($read, ['password', 'otpKey']);

        $updated = $this->callToolOk('content_articles_update', ['id' => $id, 'title' => $title . ' v2']);
        $this->assertSame($title . ' v2', $updated['title']);

        $this->assertNotFound($this->callTool('content_articles_read', ['id' => 999999999]));
    }

    public function testWriteIsRefusedForTheLowPrivilegeUser(): void
    {
        $result = $this->callToolAsLowPriv('content_articles_create', ['title' => $this->uniqueName('x')]);
        $this->assertPermissionDenied($result);
    }
}
```

See `AbstractToolTestCase`'s own docblocks for the full helper list (`callTool()`, `callToolOk()`,
`callToolError()`, `callToolAsLowPriv()`, `callToolRpcError()`, `assertListShape()`,
`assertKeysAbsent()`/`assertKeysPresent()`, `uniqueName()`/`uniqueAlias()`, `registerCleanup()`,
`requireToolsOrSkip()`). `tests/integration/src/Tests/HarnessSelfTest.php` is a working example of using
the low-privilege token and session isolation directly, and doubles as the harness's own regression
test.

## What's covered

### Protocol / harness (not tool-specific)

- `InitializeTest` — `initialize`: the result shape, session minting, one session per call,
  unauthenticated/garbage-token refusal, and that an incoming `Mcp-Session-Id` on `initialize` itself
  is ignored rather than "resumed".
- `SessionLifecycleTest` — `tools/list`, `tools/call`, `ping`, notifications, and the full
  `Mcp-Session-Id` lifecycle: missing/garbage session refusal, `DELETE` termination, and — the part a
  fake-looking pass could hide — that a *refused* `DELETE` (wrong token) provably leaves the session
  usable afterward, not just that the `DELETE` itself reported an error.
- `ProtocolErrorsTest` — malformed JSON, batching (rejected — MCP dropped it in the 2025-06-18
  revision), a missing `jsonrpc` member, the `id` being echoed back on an otherwise-invalid request,
  and a request sent with no `Accept` header at all (curl always sends `Accept: */*` by default, so
  this deliberately strips it).
- `HarnessSelfTest` — regression guard for the harness pieces themselves (not a real tool): the
  low-privilege user can `initialize`, and a session minted by one user is refused (404) when replayed
  with a different user's token, in both directions; `AbstractToolTestCase::uniqueName()`/
  `uniqueAlias()` produce the expected shapes. Skips (not fails) its low-privilege assertions when
  `MCP4J_LOWPRIV_TOKEN` isn't set.
- `Options/ComponentOptionsTest` — the `tool_categories`/`tool_allowlist`/`read_only`/`minimal_schema`
  filters on `tools/list`/`tools/call`, applied in the documented order (allow-list overrides
  categories; read-only narrows whatever those already selected).

### Per tool category

One test class per tool class (`tests/integration/src/Tests/<Category>/<Class>Test.php`), each covering
at minimum: list shape + a working filter, full create→read→update→trash→delete lifecycle (partial
update leaves untouched fields alone), read/update of a nonexistent id → `Not found`, ACL refusal for
the low-privilege user (read and write) with proof nothing was written, and absence of every sensitive
field from that category's whitelist — see `.plans/00-conventions.md` §7 for the exact contract every
class follows. Notable extras beyond the baseline:

- **Tags, Content, Banners, Contact, Newsfeeds** — nested-set category lifecycles (parent/child
  `level`/`path`), cross-extension isolation (a Banners category id is `Not found` through the Content
  category tools and vice versa), Markdown→HTML conversion for article `introtext`/`fulltext`.
- **Users, Groups, Levels** — self-protection refusals (can't delete/block your own account), Super
  User/root-group protections, weak-password rejection, and that no response ever contains
  `password`/`otpKey`/`otep`/`activation`/`params`.
- **Menus, MenuItems (site + admin)** — reserved menutype refusal, component-link building
  (`request[]` merged into `link`), URL-scheme validation (`javascript:` rejected), the default home
  item protected from deletion, cross-client isolation (site vs. administrator).
- **Modules, Templates styles (site + admin)** — module-type immutability on update, template-style
  default-switching semantics and menu-item-assignment preservation, cross-client isolation.
- **Config** — secret redaction on every read (`password`/`secret`/... always `********`), redaction
  placeholders never overwriting a live secret on update.
- **Languages (overrides, packages, content languages)** — override search/refresh, language-tag
  validation, relative (never absolute) file paths in search results.
- **Fields / ContentHistory / Privacy** — context validation, version-keep/delete semantics,
  export/removal request mapping; `token` never present in consent output.
- **Media** — folder vs. file creation semantics, path traversal / disallowed-extension refusal, no
  absolute server paths in any output.
- **JoomlaUpdate** — health-check/getupdate shape on Joomla ≥ 5.4 (tools absent entirely on 4.4); the
  destructive `prepare`/`download`/`finalize` steps are exercised only for their refusal/validation
  paths, never actually run against the shared Docker site.
- **Panopticon** (`requireToolsOrSkip` — skips entirely without the connector zip in
  `inbox/extensions/`) — core-update status/changesource/checksum flow, extension updates/update
  sites, template overrides, Admin Tools WAF/scanner controls, Akeeba Backup status; every class also
  proves Super-User-only enforcement. Irreversible/network-heavy actions (core download/activate,
  live extension updates) are asserted only for their safe/refusal paths.
- **Tickets** (`requireToolsOrSkip`, and skips entirely on Joomla 4.4 since ATS refuses to install
  there) — categories, ticket/post CRUD with custom-field (`com_fields`) merging, manager-only field
  protections, attachment upload/download/ZIP-inlining with size limits, manager notes; Pro-only tools
  report a clear tool error on ATS Core instead of failing.

Every category's E2E class also asserts the write-path secret-leak guard on at least one call (a tool
argument containing the super user's own API token is refused before the tool runs) where plan 03
didn't already cover it directly.
