# MCP4j

An HTTP [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for Joomla.

**Quick facts**

* Runs on: your site
* MCP transport: Streamable HTTP
* Usable with local AI Tools (e.g. Claude Code): ✅ Yes
* Usable with hosted AI Tools (e.g. ChatGPT): ✅ Yes

> [!TIP]
> If you're looking for an STDIO MCP server running on your computer, please see [`joomla-mcp-php`](https://github.com/nikosdion/joomla-mcp-php).

## What does it do?

MCP4j exposes an MCP server reachable over plain HTTP for your Joomla site.

The MCP server can be used by hosted/web chatbots (Claude.ai, ChatGPT, and similar) which can only speak the HTTP MCP protocol — not stdio — allowing them to query data from and take actions on your site. Unlike a typical MCP server, it does not run its own listening process; it's served through Joomla's API application. This allows it to run on commercial / shared web hosting; this kind of hosting does not allow the user to install arbitrary server processes.

Authentication reuses Joomla's own API tokens (issued per-user from the Users backend). There is no separate OAuth 2.1 authorisation server: an MCP client configured with a given token simply acts as whichever Joomla user issued it, authorised through that user's normal Joomla ACL.

For more information about the design rationale, in particular how MCP session state is kept independent of Joomla's own ephemeral API session, please consult [AGENTS.md](AGENTS.md). I opted to add the architecture information there as I am using generative AI code assistants to aid in testing, bug hunting, and security auditing. These are exactly the kind of tasks needing access to the architectural decisions made when building this extension.

### Packaging

MCP4j is delivered as an installable Joomla `package`-type extension consisting of three extensions:

**MCP4j** (`com_mcp4j`). The component which handles the actual work: manages the database tables, provides the MCP tool business logic, and the MCP JSON-RPC dispatch logic.

**Web Services - MCP4j** (`plg_webservices_mcp4j`). The `webservices` plugin which registers the MCP server's `v1/mcp4j/server` endpoint with Joomla's API Application.

**Scheduled Tasks - MCP4j** (`plg_task_mcp4j`). The Joomla Scheduled Task plugin to prune expired MCP session
  records.

### Quick Start

> [!CAUTION]
> Make sure nothing on your site blocks or breaks the Joomla API application (the `/api/index.php` URL). This is the most common reason for “it just doesn't work” complaints. 

All three MCP4j extensions must be enabled, including both plugins.

Go to System → Scheduled Tasks → New → "MCP4j - Prune expired sessions". Set it to run at least once every day.

If you cannot set up Joomla Scheduled Tasks, or they end up not running on your site, you can instead go to Components, MCP4j, click the Options button, Set _Prune Sessions on Every Request_ to Yes.

### How to connect a client

Make sure the **User - Joomla API Token** and **API Authentication - Web Services Joomla Token** plugins are enabled.

1. In the Joomla backend, as the user you want the MCP client to act as: **Users → Manage → (your user) → Assign User Group** must include `core.login.api` (granted to Super Users by default; see "Security model" below for how to grant it to a non-Super-User group), and **Users → Options → Enable Token Based Authentication** must be ticked. Generate the token on the user's Edit Profile page.
2. Configure your MCP client with:
  - URL: `https://your-site.example/api/index.php/v1/mcp4j/server`
  - Header: `Authorization: Bearer <the Joomla API token>`
3. Nothing else to configure client-side — the client discovers every enabled tool via the standard MCP `initialize` → `tools/list` handshake. `Mcp-Session-Id` is handled automatically by any spec-compliant client.

Some MCP servers may not allow you to set up an Authorization header, but let you set up an HTTP header name and value. In this case you can use the alternative Joomla API application authentication: HTTP header name `X-Joomla-Token` and your Joomla API token as its value.

#### Creating non-Super-User API tokens

By default, a token can only be issued to a Super User. To let a non-Super-User group use MCP4j, you need to first create a special group called **API Access**. This will allow us to give Joomla API application access to non-Super-User accounts. This must be carried out be a Super User.

1. Log into your site as a Super User.
1. Go to Users Groups and create a new user group with the name **API Access**. Make sure the Group Parent is set to Public.
1. Go to System, Setup, Global Configuration.
1. Click the Permissions tab.
1. Click on the API Access user group.
1. Set the following permissions:
  * **Web Services Login**: Allowed.

Now, we need to tell Joomla it is allowed to mint API tokens for our API Access user group. Again, this must be carried out by a Super User.

1. Log into your site as a Super User.
2. Go to System, Manage, Plugins.
3. Search for the "User - Joomla API Token" plugin.
4. Click on the plugin
5. In the "Allowed User Groups" section, add our "API Access" user group.
6. Click on Save & Close.

You can now edit your non-Super-User user accounts who need access to MCP4j and add them to the API Access user group _in addition to_ their normal user group (e.g. Author).

Your users will now be able to log into your site, go to their profile, edit it, and enable (and see) their Joomla! API Token. They can use that token in MCP4j.

#### How non-Super-User API tokens work (developer information)

There are two separate Joomla settings both need to allow it (both default to Super-Users-only, independently of each other):

- **`core.login.api`** on the root asset (System → Global Configuration → Permissions, or per user group) — Web Services API login is refused entirely without it.
- **`plg_user_token`'s `allowedUserGroups`** parameter (Plugins → User - Token, default `[8]`, Super Users only) — a user can have `core.login.api` and a perfectly valid token and still be refused if their group isn't listed here.

Grant both narrowly (add the group, don't replace the list) — see `tests/integration/docker/provision-lowpriv-user.php` for the exact mechanics the E2E harness uses to provision its own low-privilege test user this way.

## What MCP4j exposes

249 tools (out of the 250 in the sibling `stdio` MCP server, grouped into 22 categories.

> [!NOTE]
> This is one less tool than what the sibling STDIO MCP server project [`joomla-mcp-php`](https://github.com/nikosdion/joomla-mcp-php) provides. One tool, `tickets_attachments_save_to_file`, is intentionally missing. Please see "Differences from joomla-mcp-php" below.

The category names below are exactly the ones you tick on the component's Options → Tools page, and the ones every tool name is prefixed with (`tags_list`, `content_articles_create`, `panopticon_coreupdate_status`, ...):

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

> [!TIP]
> Run `php tests/parity/compare-tool-surface.php` for the authoritative, always-current version of this table (it prints it from the actual `#[McpTool]` attributes, not from this hand-maintained copy), and a full tool-by-tool comparison against the original `joomla-mcp-php` project.

## Options (Options → Tools)

**Category Mode** / **Categories**. Which MCP tool categories will be exposed.

This can run in either of two modes: Exclude or Include.

- Include: only the categories selected in Categories are exposed. 
- Exclude (the default): every category is exposed except the ones selected. With nothing selected (the default), the mode has no effect and every category is exposed.

**Tool Mode** / **Tools**. Which individual MCP tools will be exposed.

This can be used with or instead of Category Mode.

> [!NOTE]
> Category Mode and Tool Mode are applied separately. Both must allow a tool for that tool to be available at all. 

Like Category Mode, it has two modes: Exclude or Include.

- Include: only the individual tools selected in Tools are exposed.
- Exclude (the default): every Tool is exposed except the ones selected. With nothing selected (the default), the mode has no effect and every Tool in an allowed Category is exposed.

**Read-Only Mode**. When enabled, only the read-only tools (annotated `readOnlyHint: true` in the source code) are exposed. Applied last, after the category/tool filters, so it only ever narrows what they already selected.

**Minimal Tool Schemas**. When enabled, every advertised tool's input schema is reduced to each parameter's
  type and default only, dropping descriptions/enums/constraints. Meaningfully shrinks the `tools/list` response for LLM clients with a limited context window, at the cost of fewer hints about how to call each tool correctly (see "Performance notes" below for the actual size difference this makes).

**Forbidden Secrets**. One value per line, checked as a substring against every non-read-only tool call's arguments before the tool runs; a match is refused ("Secrets leak prevented; potential prompt injection")instead of executed. The current request's own API token and, when at least 8 characters long, the site's Secret Word and mail/FTP/proxy/Redis passwords are always protected in addition to anything listed here.

**Debug Log**. Logs every tool call's name and (secret-redacted) arguments to the `mcp4j.php` file under Joomla's configured logs directory (default: `administrator/logs/mcp4j.php`). Tool call results are never logged even when this is on, since some tools legitimately return credentials to the caller (e.g. a freshly generated temporary Super User password).

**Session Lifetime** / **Prune Sessions on Every Request**. The MCP protocol calls for MCP-specific sessions. We cannot use Joomla session management directly because the Joomla API application creates an ephemeral session for the duration of a request, then removes it. As a result, MCP4j uses its own session persistence in the `#__mcp4j_sessions` table, allowing MCP sessions to survive multiple requests (necessary for getting, for example, paginated results of very long lists of articles). However, this table would grow forever in size unless, like Joomla's own session management, we implemented a session expiration mechanism, and a way to prune (delete) expired sessions.

The Session Lifetime defines the time after which an inactive session is considered expired. This value is given in seconds, the default being 3600 (one hour). Please note that this setting is independent of your Joomla session expiration.

Regarding pruning obsolete sessions, the recommended way to do it is using a Joomla Scheduled Task as described in the Quick Start section above. If you cannot do that for any reason, enable the “Prune Sessions on Every Request” option. Every time there's an MCP request, MCP4j will look for expired sessions and prune them. This has a small performance impact, especially when there are concurrent MCP requests, or MCP requests in quick succession.

For more information about MCP session management, please refer to the architectural description in [.claude/mcp-session-handling.md](.claude/mcp-session-handling.md).

## Security model

- **Acts as the token's user.** There is no MCP-specific permission layer: every tool call runs with the ACL of
  whichever Joomla user issued the bearer token, full stop.
- **ACL enforced per tool**, matching (or exceeding) what the equivalent backend controller/Web Services API
  controller enforces — `core.manage` to read/list, `core.create`/`core.edit`/`core.edit.state`/`core.delete` to write, checked explicitly in every tool (Joomla's MVC models do **not** check ACL on `save()`/`publish()` on their own). Verified per-class in the parity/static audit — see "Tool-surface parity audit" in
  `.plans/17-finalize.md` for the methodology and the ACL-coverage table it produces.
- **Output whitelists.** Every tool that returns a record passes it through an explicit field whitelist before it reaches the response — direct Joomla model access returns *everything*, including `password`, `otpKey`, API tokens, and similar. Values such as user passwords, OTP secrets, and private tokens are never present in any tool output, by construction (unit-tested per category, e.g. `array_intersect(SENSITIVE, ITEM_FIELDS)` is asserted empty), not by hoping nobody asks for them.
- **Secret redaction in config reads.** `config_application_read`/`config_component_read` always redact `password`, `secret`, mail/FTP/proxy credentials, etc. (`********`), and any component params key matching a "looks like a secret" pattern (`update_token`, `forbidden_secrets`, `private_key`, ...). A redaction placeholder sent back on `config_*_update` is ignored rather than overwriting the live secret with the literal placeholder string.
- **Secret-leak guard** (Forbidden Secrets, above) — a prompt-injection defence: a tool argument that contains the caller's own bearer token, the site's Secret Word, or an operator-listed value is refused before the tool runs, so an LLM can't be tricked into embedding a secret in a write tool's arguments and having it echoed back or forwarded on.
- **Dropped tools.** `tickets_attachments_save_to_file` is the only tool intentionally not ported: in the original it wrote to the MCP *client's* local disk; running inside the web server, the same tool would let any caller write arbitrary bytes to any path the PHP process can write to — a remote file-write primitive with no safe server-side equivalent.

## Third-party integrations

- **Panopticon** (33 tools, category `Panopticon`) — requires the [Akeeba Panopticon Connector](https://www.akeebabackup.com/products/akeeba-panopticon.html) (`com_panopticon`) installed and enabled. Covers Joomla core update, extension updates/update sites, template overrides, and (when Admin Tools / Akeeba Backup are also installed) their WAF/scanner controls and backup status. Every Panopticon tool requires the calling user to be a Super User, regardless of the ACL a lower-privileged token might otherwise have (see the class docblocks for why — the connector itself assumes a Super-User-only API token).
- **Akeeba Ticket System** (23 tools, category `Tickets`) — requires ATS (`com_ats`) installed. Attachment and manager-note tools (the "Pro" subset) return a clear tool error on ATS Core instead of being hidden, since `ToolRequirements` can only test that `com_ats` itself is enabled, not its edition.
- Tools from a category whose backing extension is missing/disabled simply don't appear in `tools/list` — no configuration needed to hide them.

## Performance notes

Measured against a stock Joomla 6 installation with every category enabled (`tests/integration/run-tests.sh 6 --keep` stack), `initialize` already completed:

| Measurement | Result |
|---|---:|
| `tools/list`, all 22 categories (249 tools), normal schemas | 138,052 bytes, ~19–35 ms |
| `tools/list`, all 22 categories (249 tools), Minimal Tool Schemas on | 75,377 bytes (−45%), ~26–29 ms |
| `initialize` → `tools/list` → `tools/call tags_list` round-trip (fresh session each run) | ~78–97 ms |

Measured over plain HTTP (no TLS) against the Docker E2E harness's Joomla 6.1.3/PHP 8.5 stack on a base model MacBook Pro M5 — absolute numbers will vary by host and network, but the *relative* effect of Minimal Tool Schemas (roughly halving the `tools/list` payload) should hold generally. No performance thresholds are enforced anywhere in the codebase; these numbers are recorded for future reference, not as a regression gate.

## Differences from joomla-mcp-php

As you probably know, I have been maintaining another MCP server for Joomla, [joomla-mcp-php](https://github.com/nikosdion/joomla-mcp-php), which runs on your local machine and offers an `stdio` interface – most useful for things like AI code assistants such as Claude Code, OpenAI Codex, Qwen Code, OpenCode, etc. The design goal of MCP4j is to have tool parity – both naming, and functionally – to the extent possible.

MCP4j calls Joomla's MVC models directly instead of the original's approach of calling the site's own Web Services JSON:API over HTTP. Interface fidelity (tool names, parameters, annotations) is preserved verbatim wherever possible and `tests/parity/compare-tool-surface.php`'s allow-list for the authoritative, exhaustive list with citations. The recurring *categories* of behavioural difference, compiled from every domain plan's "Deviations from the original" section:

- **List filters that the original sent, but the core Web Services API controller silently ignored now actually work** — e.g. `tags_list`/`content_categories_list`/`banners_categories_list` `filter[published]`/ `filter[language]`/`filter[level]`, `users_list.filterGroup`/`filterRange`, `menus_*` filters, `redirects` ordering, `tickets_list.filterCategory`/`filterUser`/`orderBy`.
- **Two parameter types were bugs in the original, fixed here** (call sites unaffected — the tool name and every other parameter are identical): `content_categories_list.filterSearch` (`?int` → `?string`),
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

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received [a copy of the GNU General Public License](LICENSE.txt) along with this program. If not, see [http://www.gnu.org/licenses/](http://www.gnu.org/licenses/).
