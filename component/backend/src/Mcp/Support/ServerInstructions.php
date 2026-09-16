<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * Builds the `initialize` result's `instructions` string: a header explaining how MCP4j's tools relate
 * to the connecting Joomla user's own permissions, followed by one line per tool category that is
 * actually exposed (after `#[ToolRequirements]` and the `category_mode`/`categories`/`tool_mode`/`tools`
 * component options have been applied), so an LLM with a large tool count can decide which categories are worth
 * searching without having to look at every tool.
 *
 * The per-category lines are copied verbatim from the original `joomla-mcp-php` project's
 * `withInstructions(...)` block (`mcp4joomla.php` ~458-483) — see `.plans/01-tool-framework.md` §"13.
 * ServerInstructions.php". The header is adapted: the original talks about calling the Web Services API
 * over HTTP, which does not apply here (tools call Joomla MVC models directly).
 *
 * Pure: no Joomla, no I/O.
 *
 * @since  0.0.1
 */
final class ServerInstructions
{
	private const HEADER = <<<'TEXT'
MCP4j exposes this Joomla site's content and administration features as tools. Every tool runs as the Joomla user who owns the API token used to connect, with exactly that user's permissions. Use these tools whenever the user asks about this Joomla site.

Tool categories and when to search for them:
TEXT;

	/**
	 * Verbatim from the original project's `withInstructions(...)` block. Keyed by the exact category
	 * (`Tool/<Category>`) folder name, per `.plans/00-conventions.md` §3.
	 */
	private const CATEGORY_DESCRIPTIONS = [
		'Content'        => 'articles and content categories (content_articles_*, content_categories_*). Use for creating, listing, reading, updating, or deleting articles and their categories.',
		'Banners'        => 'banners, banner categories, and banner clients (banners_*, banners_categories_*, banners_clients_*). Use for managing advertising banners.',
		'Config'         => 'application and component configuration (config_application_*, config_component_*). Use for reading or changing Joomla settings.',
		'Contact'        => 'contacts and contact categories (contact_*, contact_categories_*). Use for managing contact entries and submitting contact forms.',
		'ContentHistory' => 'content version history (contenthistory_*). Use for viewing or managing revision history of content items.',
		'Fields'         => 'custom fields and field groups (fields_*, fields_groups_*). Use for managing custom fields on articles, contacts, etc.',
		'Installer'      => 'installed extensions (installer_extensions_*). Use for listing Joomla extensions (components, modules, plugins).',
		'JoomlaUpdate'   => 'Joomla core updates (joomlaupdate_*). Use for checking, preparing, and applying Joomla core updates.',
		'Languages'      => 'content languages, language overrides, and language packages (languages_content_*, languages_overrides_*, languages_packages_*). Use for managing multilingual content and translation overrides.',
		'Media'          => 'media files and adapters (media_files_*, media_adapters_*). Use for uploading, listing, or deleting images and other media files.',
		'Menus'          => 'site and administrator menus and menu items (menus_sitemenus_*, menus_siteitems_*, menus_adminmenus_*, menus_adminitems_*). Use for managing navigation menus.',
		'Messages'       => 'private messages (messages_*). Use for sending and managing private messages between Joomla users.',
		'Modules'        => 'site and administrator modules (modules_site_*, modules_admin_*). Use for managing sidebar widgets, footers, and other module positions.',
		'Newsfeeds'      => 'newsfeeds and newsfeed categories (newsfeeds_*, newsfeeds_categories_*). Use for managing RSS/Atom feed aggregation.',
		'Panopticon'     => 'Panopticon Connector tools for remote site management (panopticon_*). Use for extension updates, backups, security scans, and update site management via Akeeba Panopticon.',
		'Tickets'        => 'Akeeba Ticket System support tickets, posts, attachments, and manager notes (tickets_*). Use for managing ATS support tickets, reading ticket conversations, and inspecting attachments. Attachment and manager-note tools require ATS Pro.',
		'Plugins'        => 'Joomla plugins (plugins_*). Use for listing, reading, or updating plugin settings and state.',
		'Privacy'        => 'privacy requests and consents (privacy_requests_*, privacy_consents_*). Use for GDPR privacy management.',
		'Redirects'      => 'URL redirects (redirects_*). Use for managing 301/302 URL redirects.',
		'Tags'           => 'content tags (tags_*). Use for managing tags applied to articles and other content.',
		'Templates'      => 'site and administrator template styles (templates_sitestyles_*, templates_adminstyles_*). Use for managing template style configurations.',
		'Users'          => 'users, user groups, and viewing access levels (users_*, users_groups_*, users_levels_*). Use for managing user accounts and permissions.',
	];

	/**
	 * @param   list<string>  $exposedCategories  The categories actually exposed by the current filter,
	 *                                             e.g. from `ToolDispatcher::exposedCategories()`. Empty
	 *                                             yields the header only (no category list at all).
	 */
	public static function build(array $exposedCategories): string
	{
		if ($exposedCategories === [])
		{
			return self::HEADER;
		}

		$lines = [self::HEADER];

		foreach ($exposedCategories as $category)
		{
			$lines[] = self::lineFor($category);
		}

		return implode("\n", $lines);
	}

	private static function lineFor(string $category): string
	{
		$description = self::CATEGORY_DESCRIPTIONS[$category] ?? null;

		if ($description === null)
		{
			return sprintf('- %s: (no description)', $category);
		}

		return sprintf('- %s: %s', $category, $description);
	}
}
