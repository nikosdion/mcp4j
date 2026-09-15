MCP4j 0.0.1
================================================================================
! Fixed ATS list tools leaking tickets/posts/attachments/categories from ACL-denied departments
+ Initial project scaffolding: package, component, and webservices plugin skeletons
+ MCP session persistence design: #__mcp4j_sessions schema, SessionManager helper, session_ttl and
  prune_on_request component options, plg_task_mcp4j scheduled task for pruning expired sessions
+ MCP tool framework: #[McpTool]/#[Schema]/#[ToolRequirements] attributes, schema generation, tool
  registry (auto-discovers Tool/*/*.php, no registration file), JSON-RPC dispatch, per-request Options
  filtering (categories, allow-list, read-only mode, minimal schemas), secret-leak guard
+ Joomla MVC model bridge (AbstractTool): listItems/loadItem/saveItem/changeState/deleteItem helpers
  that drive Joomla's own AdminModel/ListModel the way a backend controller would, without HTTP
+ Tags tools (6): tags_list, _read, _create, _update, _trash, _delete
+ Content tools (12): articles and content categories, with Markdown-to-HTML conversion
  (league/commonmark) for introtext/fulltext
+ Users tools (15): users, user groups, viewing access levels — explicit sensitive-field whitelisting
  (password/otpKey/otep/activation/params never leave the component)
+ Menus tools (22): site and administrator menus and menu items, including component-link building and
  URL-scheme validation
+ Config/Plugins/Installer/Languages tools (15): application/component configuration with secret
  redaction, plugin listing/enable-disable, installed-extensions listing, language package installation,
  content languages
+ Modules/Templates tools (24): site and administrator modules, site and administrator template styles
+ Banners tools (18): banners, banner clients, banner categories
+ Contact/Newsfeeds tools (25): contacts (+ public form submit), newsfeeds, their categories
+ Language overrides, redirects, private messages tools (24)
+ Fields/ContentHistory/Privacy tools (19): custom fields and field groups (any context), version
  history, GDPR consents and export/removal requests
+ Media/JoomlaUpdate tools (13): media manager files/folders/adapters, Joomla core automated update API
  (Joomla 5.4+)
+ Akeeba Panopticon Connector tools (33): core update, extension updates/update sites, template
  overrides, Admin Tools WAF/scanner controls, Akeeba Backup status — every tool requires Super User
+ Akeeba Ticket System tools (23): categories, tickets, posts, attachments, manager notes
  (tickets_attachments_save_to_file intentionally dropped — server-side arbitrary file write)
+ Tool-surface parity audit (tests/parity/compare-tool-surface.php): every one of the 249 ported tools
  verified against the original joomla-mcp-php project's name/parameters/annotations, with a
  plan-sourced allow-list for the handful of deliberate, documented deviations
