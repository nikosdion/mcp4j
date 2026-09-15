# MCP4j

Turn a Joomla site into a remote, HTTP-based [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server.

## What does it do?

MCP4j exposes a Joomla site as an MCP server reachable over plain HTTP, so hosted/web chatbots (Claude.ai, ChatGPT,
and similar) which can only speak HTTP — not stdio — can use it as a tool provider. Unlike a typical MCP server,
it does not run its own listening process; it's served by whatever already handles the site's HTTP requests, which
makes it deployable on ordinary commercial/shared hosting.

It is made of three extensions, packaged together:

- **`com_mcp4j`** — owns the database schema, the 249 MCP tools, and the MCP JSON-RPC dispatch logic, reachable at
  `/api/index.php?/v1/mcp4j/server`.
- **`plg_webservices_mcp4j`** — a `webservices` plugin that registers that endpoint with Joomla's Web Services API
  router.
- **`plg_task_mcp4j`** — a Scheduled Task plugin that prunes expired MCP session records. **Install and enable
  this** (System → Scheduled Tasks → New → "MCP4j - Prune expired sessions"). It's the recommended way to keep
  `#__mcp4j_sessions` tidy. The component also has an on-request pruning option, off by default, which briefly
  locks the sessions table on every MCP request instead — only turn that on if you genuinely cannot use Scheduled
  Tasks on your hosting.

Authentication reuses Joomla's own API tokens (issued per-user from the Users backend). There is no separate OAuth
2.1 authorization server: an MCP client configured with a given token simply acts as whichever Joomla user issued
it, authorised through that user's normal Joomla ACL.

See [CLAUDE.md](CLAUDE.md) and [.claude/mcp-session-handling.md](.claude/mcp-session-handling.md) for the design
rationale, in particular how MCP session state is kept independent of Joomla's own ephemeral API session.

## What MCP4j exposes

249 tools (of the 250 in the sibling stdio project `joomla-mcp-php` — one, `tickets_attachments_save_to_file`, is
intentionally dropped; see "Differences from joomla-mcp-php" below), grouped into 22 categories. The category
names below are exactly the ones you tick on the component's Options → Tools page and the ones every tool name is
prefixed with (`tags_list`, `content_articles_create`, `panopticon_coreupdate_status`, ...):

| Category | Tools | Covers |
|---|---:|---|
| Banners | 18 | Banners, banner clients, banner categories (`com_banners`) |
| Config | 4 | Global and per-component configuration, with secret redaction |
| Contact | 13 | Contacts, contact categories, the public contact form submit action (`com_contact`) |
| Content | 12 | Articles, content categories (`com_content`) |
| ContentHistory | 3 | Version history for articles/contacts/... (`com_contenthistory`) |
| Fields | 10 | Custom fields and field groups, any context (`com_fields`) |
| Installer | 1 | Installed-extensions listing (`com_installer`) |
| JoomlaUpdate | 6 | Joomla core automated update API (Joomla ≥ 5.4 only) |
| Languages | 19 | Language overrides, language packages, content languages |
| Media | 7 | Media manager files/folders/adapters (`com_media`) |
| Menus | 22 | Site and administrator menus and menu items |
| Messages | 6 | Private messages (`com_messages`) |
| Modules | 14 | Site and administrator modules |
| Newsfeeds | 12 | Newsfeeds, newsfeed categories (`com_newsfeeds`) |
| Panopticon | 33 | Akeeba Panopticon Connector: core update, extension updates, Admin Tools, Akeeba Backup info |
| Plugins | 3 | Plugin listing/read/enable-disable |
| Privacy | 6 | GDPR consents and export/removal requests (`com_privacy`) |
| Redirects | 6 | URL redirects (`com_redirect`) |
| Tags | 6 | Tags (`com_tags`) |
| Templates | 10 | Site and administrator template styles |
| Tickets | 23 | Akeeba Ticket System: categories, tickets, posts, attachments, manager notes |
| Users | 15 | Users, user groups, viewing access levels |

Run `php tests/parity/compare-tool-surface.php` for the authoritative, always-current version of this table (it
prints it from the actual `#[McpTool]` attributes, not from this hand-maintained copy) and a full tool-by-tool
comparison against the original `joomla-mcp-php` project.

## How to connect a client

1. In the Joomla backend, as the user you want the MCP client to act as: **Users → Manage → (your user) → Assign
   User Group** must include `core.login.api` (granted to Super Users by default; see "Security model" below for
   how to grant it to a non-Super-User group), and **Users → Options → Enable Token Based Authentication** must be
   ticked. Generate the token on the user's Edit Profile page.
2. Configure your MCP client with:
   - URL: `https://your-site.example/api/index.php?/v1/mcp4j/server`
   - Header: `Authorization: Bearer <the Joomla API token>`
3. Nothing else to configure client-side — the client discovers every enabled tool via the standard MCP
   `initialize` → `tools/list` handshake. `Mcp-Session-Id` is handled automatically by any spec-compliant client.

By default a token can only be issued to a Super User. To let a non-Super-User group use MCP4j, two separate
Joomla settings both need to allow it (both default to Super-Users-only, independently of each other):

- **`core.login.api`** on the root asset (System → Global Configuration → Permissions, or per user group) — Web
  Services API login is refused entirely without it.
- **`plg_user_token`'s `allowedUserGroups`** parameter (Plugins → User - Token, default `[8]`, Super Users only)
  — a user can have `core.login.api` and a perfectly valid token and still be refused if their group isn't listed
  here.

Grant both narrowly (add the group, don't replace the list) — see
`tests/integration/docker/provision-lowpriv-user.php` for the exact mechanics the E2E harness uses to provision
its own low-privilege test user this way.

## Options (Options → Tools)

- **Tool Categories** — tick the categories to expose. Leave every box unticked to expose all of them (the
  default). Ignored entirely when Tool Allow-List (below) is not empty.
- **Tool Allow-List** — one exact tool name per line. When not empty, completely overrides the category
  selection: only the tools named here (that also meet their own requirements, e.g. a third-party component
  being installed) are exposed.
- **Read-Only Mode** — when enabled, only tools annotated `readOnlyHint: true` are exposed. Applied last, after
  the category/allow-list filters, so it only ever narrows what they already selected.
- **Minimal Tool Schemas** — when enabled, every advertised tool's input schema is reduced to each parameter's
  type and default only, dropping descriptions/enums/constraints. Meaningfully shrinks the `tools/list` response
  for LLM clients with a limited context window, at the cost of fewer hints about how to call each tool
  correctly (see "Performance notes" below for the actual size difference this makes).
- **Forbidden Secrets** — one value per line, checked as a substring against every non-read-only tool call's
  arguments before the tool runs; a match is refused ("Secrets leak prevented; potential prompt injection")
  instead of executed. The current request's own API token and, when at least 8 characters long, the site's
  Secret Word and mail/FTP/proxy/Redis passwords are always protected in addition to anything listed here.
- **Debug Log** — logs every tool call's name and (secret-redacted) arguments to
  `administrator/logs/mcp4j.php`. Tool call **results** are never logged even when this is on, since some tools
  legitimately return credentials to the caller (e.g. a freshly generated temporary Super User password).
- **Session Lifetime** / **Prune Sessions on Every Request** — see
  [.claude/mcp-session-handling.md](.claude/mcp-session-handling.md).

## Security model

- **Acts as the token's user.** There is no MCP-specific permission layer: every tool call runs with the ACL of
  whichever Joomla user issued the bearer token, full stop.
- **ACL enforced per tool**, matching (or exceeding) what the equivalent backend controller/Web Services API
  controller enforces — `core.manage` to read/list, `core.create`/`core.edit`/`core.edit.state`/`core.delete` to
  write, checked explicitly in every tool (Joomla's MVC models do **not** check ACL on `save()`/`publish()` on
  their own). Verified per-class in the parity/static audit — see "Tool-surface parity audit" in
  `.plans/17-finalize.md` for the methodology and the ACL-coverage table it produces.
- **Output whitelists.** Every tool that returns a record passes it through an explicit field whitelist before
  it reaches the response — direct Joomla model access returns *everything*, including `password`, `otpKey`,
  API tokens, and similar. Values such as user passwords, OTP secrets, and private tokens are never present in
  any tool output, by construction (unit-tested per category, e.g. `array_intersect(SENSITIVE, ITEM_FIELDS)` is
  asserted empty), not by hoping nobody asks for them.
- **Secret redaction in config reads.** `config_application_read`/`config_component_read` always redact
  `password`, `secret`, mail/FTP/proxy credentials, etc. (`********`), and any component params key matching a
  "looks like a secret" pattern (`update_token`, `forbidden_secrets`, `private_key`, ...). A redaction
  placeholder sent back on `config_*_update` is ignored rather than overwriting the live secret with the literal
  placeholder string.
- **Secret-leak guard** (Forbidden Secrets, above) — a prompt-injection defence: a tool argument that contains
  the caller's own bearer token, the site's Secret Word, or an operator-listed value is refused before the tool
  runs, so an LLM can't be tricked into embedding a secret in a write tool's arguments and having it echoed back
  or forwarded on.
- **Dropped tools.** `tickets_attachments_save_to_file` is the only tool intentionally not ported: in the
  original it wrote to the MCP *client's* local disk; running inside the web server, the same tool would let any
  caller write arbitrary bytes to any path the PHP process can write to — a remote file-write primitive with no
  safe server-side equivalent.

## Third-party integrations

- **Panopticon** (33 tools, category `Panopticon`) — requires the
  [Akeeba Panopticon Connector](https://www.akeebabackup.com/products/akeeba-panopticon.html) (`com_panopticon`)
  installed and enabled. Covers Joomla core update, extension updates/update sites, template overrides, and (when
  Admin Tools / Akeeba Backup are also installed) their WAF/scanner controls and backup status. Every Panopticon
  tool requires the calling user to be a Super User, regardless of the ACL a lower-privileged token might
  otherwise have (see the class docblocks for why — the connector itself assumes a Super-User-only API token).
- **Akeeba Ticket System** (23 tools, category `Tickets`) — requires ATS (`com_ats`) installed. Attachment and
  manager-note tools (the "Pro" subset) return a clear tool error on ATS Core instead of being hidden, since
  `ToolRequirements` can only test that `com_ats` itself is enabled, not its edition.
- Tools from a category whose backing extension is missing/disabled simply don't appear in `tools/list` — no
  configuration needed to hide them.

## Performance notes

Measured against a stock Joomla 6 install with every category enabled (`tests/integration/run-tests.sh 6 --keep`
stack), `initialize` already completed:

| Measurement | Result |
|---|---:|
| `tools/list`, all 22 categories (249 tools), normal schemas | 138,052 bytes, ~19–35 ms |
| `tools/list`, all 22 categories (249 tools), Minimal Tool Schemas on | 75,377 bytes (−45%), ~26–29 ms |
| `initialize` → `tools/list` → `tools/call tags_list` round-trip (fresh session each run) | ~78–97 ms |

Measured over plain HTTP (no TLS) against the Docker E2E harness's Joomla 6.1.3/PHP 8.5 stack on the
development machine — absolute numbers will vary by host and network, but the *relative* effect of Minimal
Tool Schemas (roughly halving the `tools/list` payload) should hold generally. No performance thresholds
are enforced anywhere in the codebase; these numbers are recorded for future reference, not as a regression
gate.

## Differences from joomla-mcp-php

MCP4j calls Joomla's MVC models directly instead of the original's approach of calling the site's own Web
Services JSON:API over HTTP. Interface fidelity (tool names, parameters, annotations) is preserved verbatim
wherever possible — see `.plans/00-conventions.md` §2 for the two narrowly-permitted exceptions (moving an
optional parameter after a required one; a plan-identified parameter-type bug) and
`tests/parity/compare-tool-surface.php`'s allow-list for the authoritative, exhaustive list with citations. The
recurring *categories* of behavioural difference, compiled from every domain plan's "Deviations from the
original" section:

- **List filters that the original sent but the core Web Services API controller silently ignored now actually
  work** — e.g. `tags_list`/`content_categories_list`/`banners_categories_list` `filter[published]`/
  `filter[language]`/`filter[level]`, `users_list.filterGroup`/`filterRange`, `menus_*` filters, `redirects`
  ordering, `tickets_list.filterCategory`/`filterUser`/`orderBy`.
- **Two parameter types were bugs in the original, fixed here** (call sites unaffected — the tool name and every
  other parameter are identical): `content_categories_list.filterSearch` (`?int` → `?string`),
  `modules_*_list.filterPosition`/`modules_*_create.position`/`modules_*_update.position` (`?int` → `?string`),
  `media_adapters_read.id` (`int` → `string`).

Additional notable differences, per category (see the cited plan for the exact reasoning):

- **Content/Categories** (04): refusals instead of silent drops for publishing-state changes without
  `core.edit.state` and for a nonexistent `catId`, instead of Joomla auto-creating a category; aliases are
  Joomla-generated, not client-supplied logic.
- **Users** (05): `users_list.filterState` filters Joomla's `block` column (0=not blocked, 1=blocked), matching
  Joomla's actual `filter.state` semantics rather than the original's "0=disabled, 1=enabled" description text;
  `users_create` without `groups` assigns the New User Registration default group instead of failing; explicit
  refusals replace Joomla's silent pruning for self-delete/Super-User/root-group protections.
- **Menus** (06): menu item tools reject a non-empty `tags` array (the original silently lost them — the menu
  item form has no tags field); delete trashes first (a published item can't be deleted directly);
  `request[]` is actually merged into `link` server-side.
- **Modules/Templates** (07): a module's `module` type and a style's `template` can't change on update (the
  backend never allowed it either); style updates preserve existing menu-item assignments (the original
  silently dropped them).
- **Banners/Contact/Newsfeeds** (08, 09): `tags`/`metadesc` fields the original's form silently discarded are
  now refused with an explicit error instead of being dropped; `contact_form_submit` returns a small outcome
  object instead of a JSON:API response shape.
- **Config/Plugins/Installer/Languages** (10): `config_application_update` refuses to change `secret` (would
  invalidate every API token); `plugins_update` refuses to disable the plugins MCP4j's own connection depends
  on; `languages_packages_install` works at all (the original's route didn't exist server-side).
- **Overrides/Redirects/Messages** (11): override delete returns a `key`, not a numeric `id`; `redirects`'
  `statusCode` is correctly stored in the `header` column (the original wrote it to `referer`, a bug);
  `messages_update` only ever changes `state` — subject/message/recipient are immutable after sending, matching
  the backend (which has no edit UI at all and would otherwise re-send the notification e-mail).
- **Fields/History/Privacy** (12): `fields_delete`/`fields_groups_delete` trash first (the model refuses to
  delete an untrashed record); `privacy_consents_*` never return `token`.
- **Media/JoomlaUpdate** (13): Joomla Update tools are authorised by `core.admin` rather than an
  automated-update-specific token, and additionally require automated updates to be enabled on the site.
- **Panopticon** (14, 15): every tool requires Super User explicitly (see "Third-party integrations" above);
  resumable core-update state lives in the MCP session instead of being echoed back and forth by the caller;
  `panopticon_akeebabackup_info` is a genuinely read-only reimplementation (the connector's own equivalent
  silently *creates* a Secret Word as a side effect of a "read").
- **Tickets** (16a, 16b): custom field values are returned under `com_fields` rather than as top-level keys;
  nonexistent and invisible tickets both answer `Not found` (ATS's own API returns two different HTTP statuses,
  leaking existence); `tickets_attachments_download` returns everything as one JSON text block (base64 for
  binary content) since the framework doesn't support MCP `image`/multi-block results; `tickets_attachments_save_to_file`
  is dropped entirely (see "Security model" above).

## Copyright

MCP4j — Remote, HTTP-based Model Context Protocol server for Joomla

Copyright (C) 2026 Nicholas K. Dionysopoulos

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with this program. If not, see
[http://www.gnu.org/licenses/](http://www.gnu.org/licenses/).
