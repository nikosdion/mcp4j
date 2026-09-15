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

So: **do not try to piggyback MCP session state on Joomla's PHP session.** Treat
the two as completely unrelated concerns.

## The approach

Store MCP session state ourselves, explicitly, in a database table owned by
`com_mcp4j`, keyed by an opaque session ID we mint (not the Joomla session ID):

```
#__mcp4j_sessions
  session_id      CHAR(43)     -- random, e.g. base64url(random_bytes(32))
  user_id         INT UNSIGNED -- the Joomla user resolved from the API token
  protocol_version VARCHAR(16)
  client_info     TEXT         -- JSON: name/version the client sent at initialize
  created         DATETIME
  last_used       DATETIME
  expires         DATETIME     -- created + TTL, refreshed on each request
```

Flow:

1. Client calls `initialize` with no `Mcp-Session-Id` header. The API token in
   `Authorization` is resolved to a Joomla user the normal Web Services way. We
   mint a session row, return `Mcp-Session-Id` in the response headers.
2. Every subsequent call includes that header. The webservices plugin / component
   API controller looks the row up, checks `expires`, checks that `user_id`
   matches the user resolved from the bearer token this request (defence in depth
   — a session ID is not itself an auth credential, the token still is), and
   refreshes `last_used`/`expires`.
3. A background-eligible cleanup (Joomla scheduled task, or just a
   probability-based sweep on write) deletes expired rows. No persistent process,
   so this has to be opportunistic — there is no daemon to run a timer.
4. `DELETE /server` (or a `notifications/cancelled`-driven end, per spec) or
   simple TTL expiry ends the session. An unknown/expired `Mcp-Session-Id` gets a
   `404` per the spec, which well-behaved clients treat as "re-initialize."

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

## Open questions to resume on

- Exact TTL for `#__mcp4j_sessions` rows (proposal: 30 min sliding, configurable
  in component params).
- Whether `initialize` should be allowed to omit session creation entirely for
  clients that don't care about session semantics (spec allows a server to opt
  out of sessions altogether by never returning `Mcp-Session-Id` — simplest
  possible option, worth considering for v1: treat every request as independent
  and drop session tracking altogether unless a concrete feature needs it, e.g.
  tool pagination cursors or multi-step elicitation).
- Table prefix / install SQL lives under `admin/sql/install.mysql.utf8.sql` per
  Joomla component convention — not yet created.
