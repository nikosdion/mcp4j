# AGENTS.md — pkg_mcp4j

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
  authorised via that user's normal Joomla ACL. This is a deliberate scope
  decision, not an oversight — see
  [.claude/memory/mcp-session-handling.md](.claude/memory/mcp-session-handling.md) for the
  reasoning.

## The hard part: MCP session state vs. Joomla's ephemeral API session

Joomla's `api` application creates a throwaway session per request and does not
persist it the way the `site` application's cookie session works — and even if
it did, an MCP HTTP client doesn't send Joomla session cookies, only the
`Authorization` bearer token and (per MCP) an `Mcp-Session-Id` header. The two
session concepts must **not** be conflated. Full writeup, chosen approach (a
dedicated `#__mcp4j_sessions` table, decoupled from Joomla's session entirely),
and open questions are in
**[.claude/memory/mcp-session-handling.md](.claude/memory/mcp-session-handling.md)** — read
that before touching anything session-related.

## Status

All 249 of the original `joomla-mcp-php` project's 250 MCP tools are ported and gated green (one,
`tickets_attachments_save_to_file`, is intentionally dropped — see README.md "Security model"). The
architecture, bottom to top:

- **`component/backend/src/Mcp/`** — the framework: `Attribute/McpTool`, `Attribute/Schema`,
  `Attribute/ToolRequirements`; `Support/ToolRegistry` (discovers tool classes by scanning
  `src/Tool/*/*.php` — there is no registration file to edit), `Support/SchemaGenerator`,
  `Support/ArgumentCoercer`, `Support/SecretLeakGuard`; `ToolContext`/`ToolDispatcher` for JSON-RPC
  dispatch. Pure PHP, no Joomla, unit-tested.
- **`component/backend/src/Bridge/`** — `AbstractTool`, the base every `Tool/<Category>/<Class>.php`
  class extends: `listItems()`/`loadItem()`/`saveItem()`/`changeState()`/`deleteItem()` drive Joomla's
  own `AdminModel`/`ListModel` the way a backend controller would, minus anything HTTP/JSON:API-shaped.
  `Bridge/Support/` holds the pure helpers (`DataMerger`, `ItemNormalizer`, `OutputShaper`, `Pagination`,
  `TagIds`, ...), also unit-tested.
- **`component/backend/src/Tool/<Category>/<Class>.php`** — the 249 `#[McpTool]` methods themselves,
  one file per original `src/Server/<Category>/<Class>.php` class, same tool names/parameters/
  annotations (see "Interface fidelity" under Conventions, below). Thin: argument mapping via a
  `Mapper`, then one or two bridge calls.
- **`component/backend/src/Mapper/<Category>/*.php`** — pure, framework-free helpers per category
  (parameter → Joomla model state/save-data mapping, Joomla item → whitelisted output array). No Joomla
  types touched here; this is where the interesting logic lives and where it's unit-tested.
- **`component/backend/src/Shared/`** — Joomla-coupled code shared by several categories that all drive
  the same kind of Joomla subsystem the same way (e.g. `AbstractCategoriesTool` for every `com_*`
  categories tree, `AbstractAtsTool` for the Ticket System). Owned by whichever plan created it; extend
  by subclassing/composition, never edit in place from an unrelated category.

Testing: a pure unit suite (`phpunit -c phpunit.xml`, no Joomla, `tests/unit/Mapper/<Category>/...`) plus
a Docker-based E2E suite (`tests/integration/run-tests.sh`, real Joomla over real HTTP, one test class per
tool class under `tests/integration/src/Tests/<Category>/`) across the full Joomla/PHP support matrix.
`tests/parity/compare-tool-surface.php` cross-checks every tool's name/parameters/annotations against the
original `joomla-mcp-php` project and fails on any undocumented drift.

See `README.md` for the user-facing feature list, connection instructions, security model, and the
"Differences from joomla-mcp-php" section. See `.claude/memory/mcp-session-handling.md` for MCP session-vs-
Joomla-session handling specifically.

## Security audits

Before any security audit, security review, or `audit-*` skill run — and before reporting any
finding from one — you MUST read `.claude/memory/security-audit-triage.md` — the actors that are out
of scope, finding classes already ruled invalid, controls already in place, and how to
classify hardening versus vulnerabilities.

## Conventions

This is a Joomla extension, so the global Joomla conventions in
`~/.claude/CLAUDE.md` apply in full — most importantly:
`Factory::getContainer()->get(DatabaseInterface::class)` instead of
`getDbo()`/`getDatabase()`, `insertObject()`/`updateObject()`/`bind()`
by-reference pitfalls, and `addfieldprefix` for any custom XML form field
types. Grep for `getDbo` before reporting any DB-touching work finished.

The full rule set the port was built against lives in `.plans/00-conventions.md` (gitignored, so it isn't
linked here as a path future contributors can rely on existing — restate the essentials instead). The
ones most likely to bite in any future change to this codebase:

- **Models do not check ACL on save.** Every write tool must call `AbstractTool::requireAuthorised()` (or
  `requireEditAllowed()` for owner-or-permission checks) itself, matching what the equivalent backend
  controller enforces — `AdminModel::save()` happily lets a Registered user edit anything otherwise.
- **`ignore_request => true` means `populateState()` never runs.** `list.limit` silently defaults to `0`
  (= all rows) and every `filter.*` state key is unset unless the tool sets it explicitly — read the
  target model's `getListQuery()`/`populateState()` before trusting a default.
- **Output must never leak secrets.** Direct model access returns raw rows — `password`, `otpKey`, API
  tokens, `params` with secrets and all. Every tool that returns a record shapes it through an explicit
  field whitelist (a Mapper's `*_FIELDS` constant via `AbstractTool::shape()`/`listItems()`), never a bare
  `ItemNormalizer::toArray()` passthrough.
- **`AdminModel::delete(&$pks)`/`publish(&$pks)` take their array by reference** — assign to a variable
  first (`$pks = [$id]; $model->delete($pks);`), never pass a literal `[$id]`, and re-check `$pks`
  afterwards (the model may have pruned ids it refused).
- **`JPATH_COMPONENT`/`JPATH_COMPONENT_ADMINISTRATOR` point at `com_mcp4j`**, not at whichever
  component's model a tool is calling — `AbstractTool` already works around this for form loading; be
  aware of it if a new tool touches code that reads those constants directly.
- Interface fidelity: when porting or touching a tool, its name/description/parameters/annotations must
  match the original `joomla-mcp-php` project's `src/Server/<Category>/<Class>.php` verbatim, apart from
  the two narrow, explicitly-permitted exceptions (moving an optional parameter after a required one;
  a parameter type that's a documented bug in the original) — see `tests/parity/compare-tool-surface.php`
  for the enforced, exhaustive list.

## Git: commit and tag outside the sandbox

Commits and tags are always signed, with a key held in 1Password. The 1Password signing agent is reached
over a local socket that agent sandboxes do not expose, so a sandboxed `git commit` or `git tag` **always**
fails (e.g. `error: 1Password: Could not connect to socket. Is the agent running?`).

Run every `git commit` and `git tag` **outside the sandbox from the first attempt** — in Claude Code with
`dangerouslyDisableSandbox: true`, in other harnesses with their equivalent unsandboxed / escalated
execution. Do not try the sandboxed form first, do not diagnose the failure, and never work around it
with `--no-gpg-sign`, `-c commit.gpgsign=false` or unsigned tags.

## Project memory

Project memory lives in `.claude/memory/`, committed with the code, so that it is shared across machines
and across agentic harnesses (Claude Code, Codex, Qwen Code, Kimi Code, Junie, …). Read the relevant file
**before** starting work that matches its trigger:

| Before you… | Read |
|---|---|
| Touch MCP session handling, or anything about how `Mcp-Session-Id` relates to Joomla's own session | `.claude/memory/mcp-session-handling.md` |
| Run a security audit/review, or report a finding from one | `.claude/memory/security-audit-triage.md` |

### Recording new memories

This is the **default and only** place for project memory. Do not write memories for this project to a
harness's private memory store (such as Claude Code's auto-memory under `~/.claude/projects/`); write
them here instead:

- Add to the existing topic file when one fits; otherwise create a new kebab-case `.md` file named after
  the topic, and add a row for it to the table above with a concrete trigger.
- Plain Markdown, no frontmatter. State the rule, then **Why:** (the reason or incident behind it) and
  **How to apply:**. Link related files with relative Markdown links.
- Don't record what the code, Git history or an existing `AGENTS.md` already says — update that
  `AGENTS.md` instead when the rule belongs there. Remove or correct entries that turn out wrong.
- These files are committed: no secrets, credentials, customer data or personal details.
