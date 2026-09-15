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
tests/integration/run-tests.sh          # Joomla 4, 5 and 6 (the declared support matrix)
tests/integration/run-tests.sh 6        # Joomla 6 only
tests/integration/run-tests.sh 6 --keep # leave the Docker stack running afterwards
tests/integration/run-tests.sh 6 -- --filter=SessionLifecycleTest
```

The first run creates `docker/.env` from `docker/env.dist` — edit it if you need different ports or
image versions. Downloaded Joomla packages are cached under `tests/integration/inbox/` (gitignored)
so repeated runs don't re-download them.

## What `run-tests.sh` actually does, per Joomla major

1. Resolves and downloads (or reuses a cached) Joomla release for that major.
2. Resets the database and extracts a fresh Joomla into the `web` container's docroot.
3. Runs Joomla's own `installation/joomla.php` CLI installer.
4. Runs `phing git` on the host to build `release/pkg_mcp4j-*.zip`, then installs it via
   `cli/joomla.php extension:install`. `script.mcp4j.php`'s postflight enables
   `plg_webservices_mcp4j` and `plg_task_mcp4j` automatically — no manual enable step needed here, and
   this is itself covered indirectly (`InitializeTest` would 404 immediately if it weren't).
5. Mints an API token for the installer's admin user via `docker/provision-token.php` — a standalone
   script that replicates what `plugins/user/token` writes to `#__user_profiles` when a site owner
   ticks "Enable Token Based Authentication" on their profile, since Joomla has no CLI command for
   this. See that file's docblock for exactly which core classes it's mirroring.
6. Runs `phpunit -c phpunit-integration.xml` inside the container, with `MCP4J_BASE_URL` and
   `MCP4J_API_TOKEN` set.
7. Tears the whole stack down (`--keep` to skip this and iterate quickly — re-running just step 6
   against a kept-up stack takes seconds).

A run that fails or is interrupted never leaves a stack behind in an unknown state: the database is
dropped and recreated, and the web root is wiped, at the start of every major's iteration — not only
on success.

## Why Joomla 4, 5 and 6

`script.mcp4j.php` declares `$minimumJoomla = '4.0.0'` and `$maximumJoomla = '6.3'` (exclusive) — the
matrix floor and ceiling come directly from there, so they can't silently drift out of sync with what
the package's own installer actually enforces. PHP 8.3 (the default `PHP_VERSION` in `env.dist`) is
compatible across all three majors. Re-run `audit-e2e-matrix` periodically to keep this matrix current
as new Joomla releases ship or the declared floor moves.

A green run against a single Joomla version proves less than it looks — the `<api>` route-registration
path (`plg_webservices_mcp4j`'s `onBeforeApiRoute`) and the content-negotiation workaround
(`ensureAcceptHeaderForOurRoute()`) both depend on Joomla's own `ApiRouter`/`ApiApplication`
internals, which are exactly the kind of thing that shifts between major versions without changing any
documented, stable API. Testing only the latest major would leave that risk essentially unmeasured.

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
