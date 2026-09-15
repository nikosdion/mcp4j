# pkg_mcp4j

A Joomla 4+ extension package implementing a **remote, HTTP-based MCP server**
that runs *inside* a Joomla site, as opposed to the sibling project
`~/Projects/dionysopoulos/joomla-mcp-php`, which is a local stdio MCP server
that coding assistants run and which talks *out* to a remote Joomla site's JSON
API. This package is for the opposite direction: making a Joomla site itself
reachable as an MCP server by hosted/web chatbots (Claude.ai, ChatGPT, etc.)
that can only speak HTTP, not stdio, and that the site owner cannot run a
persistent process for (target audience is commercial/shared hosting).

## Why this shape

MCP over HTTP (Streamable HTTP transport, 2025-03-26 spec onward) does **not**
require the server to run its own listening process — it's a normal
request/response HTTP endpoint, servable by whatever already handles the
site's HTTP requests. So instead of a standalone server, this is:

- **`com_mcp4j`** — the component. Owns the DB schema, business logic for
  exposing Joomla data/actions as MCP tools/resources, and the actual MCP
  JSON-RPC dispatch.
- **`plg_webservices_mcp4j`** — a `webservices` plugin that registers the
  component's API routes with Joomla's Web Services API router, so the MCP
  endpoint is reachable at:

  ```
  /api/index.php?/v1/mcp4j/server
  ```

- **Auth**: the Joomla API token (issued per-user from the Users backend,
  `Authorization: Bearer <token>`) that Joomla's Web Services API already
  supports natively. **No separate OAuth 2.1 authorization server** — an MCP
  client using a given token simply acts as whichever Joomla user issued it,
  authorized via that user's normal Joomla ACL. This is a deliberate scope
  decision, not an oversight — see
  [.claude/mcp-session-handling.md](.claude/mcp-session-handling.md) for the
  reasoning.

## The hard part: MCP session state vs. Joomla's ephemeral API session

Joomla's `api` application creates a throwaway session per request and does not
persist it the way the `site` application's cookie session works — and even if
it did, an MCP HTTP client doesn't send Joomla session cookies, only the
`Authorization` bearer token and (per MCP) an `Mcp-Session-Id` header. The two
session concepts must **not** be conflated. Full writeup, chosen approach (a
dedicated `#__mcp4j_sessions` table, decoupled from Joomla's session entirely),
and open questions are in
**[.claude/mcp-session-handling.md](.claude/mcp-session-handling.md)** — read
that before touching anything session-related.

## Status

Repository just initialized. No code yet. Next steps (not yet started):

1. Component skeleton (`com_mcp4j`) — manifest, `admin/`, `api/`, `services/`
   provider, DB install SQL for `#__mcp4j_sessions` and whatever tool-config
   tables end up being needed.
2. `plg_webservices_mcp4j` — route registration for `v1/mcp4j/server`.
3. MCP JSON-RPC dispatch: `initialize`, `tools/list`, `tools/call`, at minimum.
   No SSE / server push in v1 (see session-handling doc for why).
4. Decide and implement the actual Joomla-facing tool surface (what MCP tools
   the component exposes — likely modeled after the tool set already proven
   out in `joomla-mcp-php`, adapted to run server-side against the local DB
   instead of over the JSON API).

## Conventions

This is a Joomla extension, so the global Joomla conventions in
`~/.claude/CLAUDE.md` apply in full — most importantly:
`Factory::getContainer()->get(DatabaseInterface::class)` instead of
`getDbo()`/`getDatabase()`, `insertObject()`/`updateObject()`/`bind()`
by-reference pitfalls, and `addfieldprefix` for any custom XML form field
types. Grep for `getDbo` before reporting any DB-touching work finished.
