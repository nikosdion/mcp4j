# MCP session handling over the Joomla API application

## The problem

The MCP Streamable HTTP transport (2025-03-26 spec revision onward) expects the
server to optionally hand out an `Mcp-Session-Id` header on `initialize` and then
have the client send that same header on every subsequent request, so the server
can correlate a sequence of JSON-RPC calls into one logical MCP session
(negotiated protocol version, client capabilities, any server-side subscriptions).

Joomla's `api` application (the one that serves `/api/index.php?/v1/...`) does not
give us that for free the way the `site` application's cookie session does.
Web Services API requests are authenticated per-request with a bearer token, and
the framework spins up a throwaway `Joomla\Session\Session` object for the
duration of the request and tears it down at the end — it is not persisted to
`#__session` the way a browser session is, and even if it were, we cannot assume
the MCP client will send back a Joomla session cookie (it won't; it only knows
about the `Mcp-Session-Id` header and the `Authorization: Bearer <token>` header).

So: **do not try to piggyback MCP session state on Joomla's PHP session
mechanism (cookies, `#__session`, session GC).** But we *do* want to reuse the
in-request `Joomla\Session\Session` object itself as the working store, because
plenty of existing Joomla MVC code (list model state, pagination limits, etc.)
reads and writes through it via `Model::getState()`/`Model::setState()` and
`Application::getUserStateFromRequest()`. Bridging our own persistence into that
object, rather than inventing a parallel state bag, means MCP tools built on top
of ordinary Joomla list models get working pagination across calls for free.

## The approach

Store MCP session state ourselves, explicitly, in a database table owned by
`com_mcp4j`, keyed by an opaque session ID we mint (not the Joomla session ID):

```
#__mcp4j_sessions
  session_id       CHAR(43)     -- random, e.g. base64url(random_bytes(32))
  user_id          INT UNSIGNED -- the Joomla user resolved from the API token
  protocol_version VARCHAR(16)
  client_info      TEXT         -- JSON: name/version the client sent at initialize
  session_data     LONGTEXT     -- serialized snapshot of the whole Joomla\Session\Session
  created          DATETIME
  last_active      DATETIME     -- refreshed on every request that uses this session
  expires          DATETIME     -- last_active + TTL (component option, default 3600s)
```

Flow, per request:

1. Client calls `initialize` with no `Mcp-Session-Id` header. The API token in
   `Authorization` is resolved to a Joomla user the normal Web Services way. We
   mint a session row (`session_data` starts empty/fresh), return `Mcp-Session-Id`
   in the response headers.
2. Every subsequent call includes that header.
   - **At the start of the request**, the API controller looks the row up by
     `session_id`, checks `expires`, checks that `user_id` matches the user
     resolved from the bearer token this request (defence in depth — a session ID
     is not itself an auth credential, the token still is), and — if valid —
     unserializes `session_data` and replays it into the current request's
     `Joomla\Session\Session` object (`$session->set($key, $value)` for every
     key). This makes the *ephemeral* per-request session look, to any Joomla
     code that touches it during this request, like a continuation of the
     previous MCP call.
   - Normal Joomla code runs (JSON-RPC method dispatch, list model state
     read/write, etc.), using `$session` as it always would.
   - **At the end of the request**, we read the *entire* session back out
     (`$session->all()`), serialize it, and `UPDATE` the same row's
     `session_data`, `last_active` (= now), and `expires` (= now + TTL). This is
     a full snapshot-replace, not a merge — whatever the request left in the
     session is what gets persisted.
3. `DELETE /server` (or a `notifications/cancelled`-driven end, per spec) or
   simple TTL expiry ends the session. An unknown/expired `Mcp-Session-Id` gets a
   `404` per the spec, which well-behaved clients treat as "re-initialize." A
   `DELETE` is not guaranteed to ever arrive (crashes, dropped connections,
   clients that don't bother) — TTL expiry is the real backstop, not the
   explicit delete.
4. Expired rows need pruning eventually. Two mechanisms, both hitting the same
   `SessionManager::pruneExpired()` (which wraps the delete in
   `$db->lockTable()` / `unlockTables()` — portable across the MySQL, MySQLi,
   PostgreSQL, SQLite and SQL Server drivers Joomla ships):
   - **Opportunistic, on-request pruning** — gated behind the `prune_on_request`
     component option (default **off**). When on, some fraction of MCP requests
     also runs the prune before/after their own work. This costs a table lock on
     every triggering request, which is the tradeoff the option name and its
     on-screen help text call out explicitly.
   - **`plg_task_mcp4j`, a Joomla Scheduled Task plugin** (task ID
     `mcp4j.pruneSessions`) that just calls the same
     `SessionManager::pruneExpired()` on a cron-driven schedule. **This is the
     recommended approach** — a single, predictable, request-independent sweep
     instead of extra locking on the request hot path. Document this
     recommendation in both `README.md` and the component Options page's
     description text for `prune_on_request`, so a site owner who cares about
     request latency knows to enable the scheduled task and leave the on-request
     option off.

This makes MCP session lifecycle entirely our own state machine, independent of
whatever Joomla's api application does with its ephemeral session per request.
It also means horizontal scaling / multiple PHP-FPM workers is fine, since state
lives in the DB, not in-process.

## Things this sidesteps

- **No SSE / server-initiated push.** The Streamable HTTP transport makes the
  `GET` (server-to-client SSE stream) optional. Skip it. Shared hosting is
  frequently hostile to long-lived connections (php-fpm timeouts, buffering
  reverse proxies), and a synchronous JSON response per JSON-RPC call is
  sufficient for a tool-calling MCP server that doesn't need to push unsolicited
  server notifications. If we ever want progress notifications for long-running
  tools, that's a later problem (likely: client polls, or short-poll pattern) —
  not solved by session handling.
- **No in-memory anything.** Every worker process is stateless and disposable,
  same as any other Joomla API request. This is the correct model for shared
  hosting (no worker affinity guarantees) and costs us nothing since MCP calls
  are inherently request/response (JSON-RPC over HTTP, one call per HTTP
  request).

## Auth model (decided)

Per the user's framing: Joomla already hands out API tokens for free via the Web
Services API (user's personal token issued from the Users backend). We are
**not** implementing MCP's OAuth 2.1 dance. Every MCP request authenticates as
whichever Joomla user issued the bearer token, and all authorization is simply
"what that Joomla user is allowed to do" — reuse Joomla's ACL, not a separate
permission layer. This is a deliberate scope reduction: it's simpler, it matches
how Joomla admins already think about API tokens, and it avoids standing up an
OAuth authorization server inside a Joomla extension. Document this clearly for
users: an MCP client configured with a given token acts as that Joomla user,
full stop.

## Why we need sessions at all (decided)

Considered dropping `Mcp-Session-Id` support altogether (the spec permits a
server to never hand one out, treating every request as independent) to avoid
all of the above. Rejected: MCP4j will very likely need to paginate long listings
(e.g. "list articles" against a large site), and Joomla's own list model
pagination state is naturally session-scoped. Implementing sessions now avoids
a breaking rework later.

## Status

- `#__mcp4j_sessions` schema, the `session_ttl` and `prune_on_request` component
  options, `Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager`, and
  `plg_task_mcp4j` exist.
- **Not yet wired up**: `component/api/src/Controller/ServerController.php` does
  not call `SessionManager::resume()`/`persist()`/`create()` yet — it still
  replies with a fixed "not implemented" JSON-RPC error for every method. That
  wiring belongs together with implementing `initialize`, since `initialize` is
  what decides whether to mint a new row versus resume an existing one, and the
  request-envelope-level code (read `Mcp-Session-Id` header in, write it out)
  needs a real method dispatch table to sit around.

## Open questions to resume on

- Whether `prune_on_request`, when on, should run on every request or only
  probabilistically (e.g. 1-in-N) to bound the lock cost further. Currently
  undecided — `SessionManager::pruneExpired()` itself doesn't care, this is a
  call-site decision for whatever wires it into `ServerController`.
