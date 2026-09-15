<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Menus;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the site/administrator *menu items* (`#__menu`) tool category: tool
 * parameters -> `ItemsModel`/`ItemModel` state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied from Joomla core's own Web Services API view
 * (`api/components/com_menus/src/View/Items/JsonapiView.php::$fieldsToRenderItem`/`$fieldsToRenderList`),
 * minus `typeAlias` (not present on `ItemModel::getItem()`'s output) from the item fields, and minus the
 * bogus `a.published` key and `language_image` from the list fields — see `.plans/06-menus.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class MenuItemMapper
{
	public const ITEM_FIELDS = [
		'id', 'parent_id', 'level', 'lft', 'rgt', 'alias', 'menutype', 'title', 'note', 'path', 'link', 'type',
		'published', 'component_id', 'checked_out', 'checked_out_time', 'browserNav', 'access', 'img', 'template_style_id',
		'params', 'home', 'language', 'client_id', 'publish_up', 'publish_down', 'request', 'associations', 'menuordering',
	];

	public const LIST_FIELDS = [
		'id', 'menutype', 'title', 'alias', 'note', 'path', 'link', 'type', 'parent_id', 'level',
		'component_id', 'checked_out', 'checked_out_time', 'browserNav', 'access', 'img', 'template_style_id', 'params',
		'lft', 'rgt', 'home', 'language', 'client_id', 'enabled', 'publish_up', 'publish_down', 'published',
		'language_title', 'language_sef', 'editor', 'componentname', 'access_level', 'menutype_id', 'menutype_title',
		'association', 'name',
	];

	public const INT_FIELDS = [
		'parent_id', 'level', 'lft', 'rgt', 'published', 'component_id', 'checked_out', 'browserNav',
		'access', 'template_style_id', 'home', 'client_id', 'enabled', 'menutype_id', 'menuordering',
	];

	public const JSON_FIELDS = ['params'];

	/**
	 * URL schemes `ItemController::save()` allows for a `type: 'url'` menu item's `link` — copied verbatim.
	 */
	private const ALLOWED_URL_SCHEMES = [
		'http', 'https', 'ftp', 'ftps', 'gopher', 'mailto',
		'news', 'prospero', 'telnet', 'rlogin', 'tn3270', 'wais',
		'mid', 'cid', 'nntp', 'tel', 'urn', 'ldap', 'file', 'fax',
		'modem', 'git', 'sms',
	];

	/**
	 * `ItemsModel::getListQuery()` state.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(int $clientId, ?string $search, ?int $state, ?string $language, ?string $menutype, ?int $level): array
	{
		$data = [
			'filter.client_id' => $clientId,
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['filter.published'] = $state !== null ? (string) $state : '';

		if ($language !== null)
		{
			$data['filter.language'] = $language;
		}

		$data['filter.menutype'] = $menutype !== null ? $menutype : '';

		if ($level !== null)
		{
			$data['filter.level'] = $level;
		}

		$data['list.ordering']  = 'a.lft';
		$data['list.direction'] = 'ASC';

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		int $clientId,
		string $title,
		?string $alias,
		string $menutype,
		string $type,
		string $link,
		?int $parentId,
		int $published,
		?int $access,
		string $language,
		?string $note,
		?string $browserNav,
		?bool $home,
		?array $request
	): array {
		$data = [
			'title'     => $title,
			'menutype'  => $menutype,
			'type'      => $type,
			'link'      => $link,
			// A menu item's nested set root is item id 1 ("JGLOBAL_ROOT_PARENT" in the parent_id field's
			// option list) — see forms/item.xml's `parent_id` field. 0 is not a valid parent.
			'parent_id' => ($parentId !== null && $parentId > 0) ? $parentId : 1,
			'published' => $published,
			'access'    => $access ?? 1,
			'language'  => $language,
			'client_id' => $clientId,
			// #__menu.alias has no DB column default (unlike e.g. note/browserNav/home), and
			// Table\Menu::store() reads it with a bare trim($this->alias) with no null-guard (unlike its
			// own null-safe handling of img/params/path) — omitting the key entirely, like the other
			// optional fields below, leaves the freshly-constructed Table's alias property at PHP null and
			// fatals with "Field 'path' doesn't have a default value" (path is derived from alias in
			// check()). Always passing '' when the caller gave none, exactly like a real HTML form field
			// would, lets Table\Menu::store() derive one from the title itself — this is not "generating an
			// alias in PHP" (the AbstractTool convention forbids computing a slug ourselves), only passing
			// through what the caller gave, same as every other create path in this codebase. Found by this
			// plan's own E2E suite — see the final report's deviations list.
			'alias'     => $alias ?? '',
		];

		if ($note !== null)
		{
			$data['note'] = $note;
		}

		if ($browserNav !== null)
		{
			$data['browserNav'] = (int) $browserNav;
		}

		if ($home !== null)
		{
			$data['home'] = $home ? 1 : 0;
		}

		if ($request !== null)
		{
			$data['request'] = $request;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6);
	 * an explicit `parentId` of `0` (or negative) is normalised to `1` for the same nested-set reason as
	 * {@see self::createData()}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $title,
		?string $alias,
		?string $menutype,
		?string $type,
		?string $link,
		?int $parentId,
		?int $published,
		?int $access,
		?string $language,
		?string $note,
		?string $browserNav,
		?bool $home,
		?array $request
	): array {
		if ($parentId !== null && $parentId <= 0)
		{
			$parentId = 1;
		}

		return NullFilter::withoutNulls([
			'title'       => $title,
			'alias'       => $alias,
			'menutype'    => $menutype,
			'type'        => $type,
			'link'        => $link,
			'parent_id'   => $parentId,
			'published'   => $published,
			'access'      => $access,
			'language'    => $language,
			'note'        => $note,
			'browserNav'  => $browserNav !== null ? (int) $browserNav : null,
			'home'        => $home !== null ? ($home ? 1 : 0) : null,
			'request'     => $request,
		]);
	}

	/**
	 * Pure port of `ItemController::save()`'s "preprocess request fields" step (core lines ~356-388): a
	 * component menu item's `request[]` arguments (e.g. `{"id": 5}` for a Single Article) are merged into
	 * the query string of `link` itself, because that's the only place Joomla core actually reads them back
	 * from ({@see \Joomla\Component\Menus\Administrator\Model\ItemModel::getItem()} re-derives `request`
	 * from the stored `link`'s query string on every read) — see `.plans/06-menus.md` "Key facts" §7.
	 *
	 * Deliberately does not consult the target component's form (unlike core, which only clears request
	 * arguments the form's `request` field group actually declares) — a link that already carries an
	 * argument (e.g. `&id=5`) also satisfies a required `request.id` this way, and an explicitly empty/null
	 * request value is always dropped rather than left for the (unavailable, here) form to decide.
	 *
	 * @param   array<string, mixed>  $request
	 *
	 * @return  array{link: string, request: array<string, mixed>}
	 */
	public static function buildComponentLink(string $link, array $request): array
	{
		parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $args);

		$request = array_merge(
			array_diff_key($args, ['option' => 1, 'view' => 1, 'layout' => 1]),
			$request
		);

		$request = array_filter($request, static function ($value) {
			return $value !== '' && $value !== null;
		});

		$args = array_merge($args, $request);

		return [
			'link'    => 'index.php?' . urldecode(http_build_query($args, '', '&')),
			'request' => $request,
		];
	}

	/**
	 * Pure port of `ItemController::save()`'s URL scheme check for `type: 'url'` menu items (core lines
	 * ~330-352): strips `"`, `<`, `>`, then — only if the (stripped) link contains a `:` — requires the
	 * part before the first `:`, lower-cased, to be one of {@see self::ALLOWED_URL_SCHEMES}. A link with no
	 * `:` at all (e.g. a site-relative path) is always allowed.
	 */
	public static function isAllowedUrlLink(string $link): bool
	{
		$link = str_replace(['"', '<', '>'], '', $link);

		if (!str_contains($link, ':'))
		{
			return true;
		}

		$segments = explode(':', $link);
		$scheme   = strtolower($segments[0]);

		return in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
	}
}
