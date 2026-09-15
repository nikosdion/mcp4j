# MCP4j

Turn a Joomla site into a remote, HTTP-based [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server.

## What does it do?

MCP4j exposes a Joomla site as an MCP server reachable over plain HTTP, so hosted/web chatbots (Claude.ai, ChatGPT,
and similar) which can only speak HTTP — not stdio — can use it as a tool provider. Unlike a typical MCP server,
it does not run its own listening process; it's served by whatever already handles the site's HTTP requests, which
makes it deployable on ordinary commercial/shared hosting.

It is made of two extensions, packaged together:

- **`com_mcp4j`** — owns the database schema and the MCP JSON-RPC dispatch logic, reachable at
  `/api/index.php?/v1/mcp4j/server`.
- **`plg_webservices_mcp4j`** — a `webservices` plugin that registers that endpoint with Joomla's Web Services API
  router.

Authentication reuses Joomla's own API tokens (issued per-user from the Users backend). There is no separate OAuth
2.1 authorization server: an MCP client configured with a given token simply acts as whichever Joomla user issued
it, authorized through that user's normal Joomla ACL.

See [CLAUDE.md](CLAUDE.md) and [.claude/mcp-session-handling.md](.claude/mcp-session-handling.md) for the design
rationale, in particular how MCP session state is kept independent of Joomla's own ephemeral API session.

## Status

Early scaffolding. No MCP methods are implemented yet — see `component/api/src/Controller/ServerController.php`.

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
