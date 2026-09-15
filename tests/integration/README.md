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
- `curl`, `jq`, `gunzip`, `unzip` on the host (used to resolve/download Joomla releases).

## Quick start

```
tests/integration/run-tests.sh                     # the whole JOOMLA_MATRIX: 4.4, 5.4, latest 6
tests/integration/run-tests.sh 6                    # latest Joomla 6 only
tests/integration/run-tests.sh 4.4 5.4              # Joomla 4.4 and 5.4 only
tests/integration/run-tests.sh 6 --keep             # leave the Docker stack running afterwards
tests/integration/run-tests.sh 6 -- --filter=SessionLifecycleTest
```

The first run creates `docker/.env` from `docker/env.dist` — edit it if you need different ports,
image versions, or to change `JOOMLA_MATRIX` itself. Downloaded Joomla packages are cached under
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
6. Mints an API token for the installer's admin user via `docker/provision-token.php` — a standalone
   script that replicates what `plugins/user/token` writes to `#__user_profiles` when a site owner
   ticks "Enable Token Based Authentication" on their profile, since Joomla has no CLI command for
   this. See that file's docblock for exactly which core classes it's mirroring.
7. Runs `phpunit -c phpunit-integration.xml` inside the container, with `MCP4J_BASE_URL` and
   `MCP4J_API_TOKEN` set.
8. Tears the whole stack down after the last entry (`--keep` to skip this and iterate quickly against
   a single version — re-running just step 7 against a kept-up stack takes seconds).

Each matrix entry runs in a subshell, so one entry failing (or hitting an internal `die()`, e.g. a
Docker or download failure) reports that entry as FAILED and moves on to the next one rather than
aborting the whole run — the final summary and exit code still reflect every entry that failed. A run
that fails or is interrupted never leaves a stack behind in an unknown state: the database is dropped
and recreated, and the web root is wiped, at the start of every entry — not only on success.

## Why these versions

`JOOMLA_MATRIX` in `docker/env.dist` (`4.4:8.1 5.4:8.1 6:8.5`) is the full range MCP4j declares support
for, not a spread chosen to exercise a specific version-gated branch — there isn't one yet (no code
in this repo currently branches on `JVERSION`). `script.mcp4j.php` declares `$minimumJoomla = '4.0.0'`
and `$maximumJoomla = '6.3'` (exclusive); 4.4 and 5.4 are the final, longest-lived minor release of
each of the two closed majors in that range, and bare `6` always resolves to the latest Joomla 6
release — so the matrix is "one representative version per supported major," re-resolved to whatever
is actually current for the open-ended entries every time it runs.

The PHP pairing follows the same "what real users are actually running" logic rather than testing
every major against the same PHP version: Joomla 4.4 and 5.4 are paired with PHP 8.1 (comfortably
within both majors' supported PHP range and the oldest version this project's own `composer.json`
still declares support for — see `require.php`), while latest Joomla 6 is paired with PHP 8.5, the
newest PHP release that exists at the time of writing (`composer.json` permits up to, but excluding,
8.7 — 8.6 simply hasn't shipped yet). Update the `6:...` entry's PHP side once 8.6 ships and this
project has confirmed compatibility with it; don't just bump it reflexively the day it's released.

A green run against a single Joomla version still proves less than it looks — the `<api>`
route-registration path (`plg_webservices_mcp4j`'s `onBeforeApiRoute`) and the content-negotiation
workaround (`ensureAcceptHeaderForOurRoute()`) both depend on Joomla's own `ApiRouter`/`ApiApplication`
internals, which are exactly the kind of thing that shifts between major versions without changing any
documented, stable API — the login()/`AuthenticationFailed` bug this suite already found (see below)
is a direct example. Re-run `audit-e2e-matrix` periodically to keep `JOOMLA_MATRIX` current as new
Joomla releases ship, the declared floor/ceiling in `script.mcp4j.php` moves, or PHP 8.6 ships.

## What's covered

- `InitializeTest` — `initialize`: the result shape, session minting, one session per call,
  unauthenticated/garbage-token refusal, and that an incoming `Mcp-Session-Id` on `initialize` itself
  is ignored rather than "resumed".
- `SessionLifecycleTest` — `tools/list`, `tools/call` (currently always "unknown tool" — there are no
  real tools yet), `ping`, notifications, and the full `Mcp-Session-Id` lifecycle: missing/garbage
  session refusal, `DELETE` termination, and — the part a fake-looking pass could hide — that a
  *refused* `DELETE` (wrong token) provably leaves the session usable afterward, not just that the
  `DELETE` itself reported an error.
- `ProtocolErrorsTest` — malformed JSON, batching (rejected — MCP dropped it in the 2025-06-18
  revision), a missing `jsonrpc` member, the `id` being echoed back on an otherwise-invalid request,
  and a request sent with no `Accept` header at all (curl always sends `Accept: */*` by default, so
  this deliberately strips it).

Not yet covered, because the feature doesn't exist yet: any real MCP tool. Once `ServerController`'s
`handleToolsList()`/`handleToolsCall()`/`dispatchMethod()` grow actual tools, this suite is the right
place for their end-to-end coverage.
