<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Templates;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Templates (style) tool category (`SiteStyles`/`AdminStyles`, both
 * client ids): tool parameters -> `StylesModel`/`StyleModel` state and save data, and the output field
 * whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are core's own Web Services API view for template styles
 * (`api/components/com_templates/src/View/Styles/JsonapiView.php`) minus `xml` (a `SimpleXMLElement`), plus
 * `inheritable`/`parent` (useful, non-sensitive columns of `#__template_styles` core's view happens to also
 * drop) — see `.plans/07-modules-templates.md` Part B.
 *
 * `home` is a **string** (`'0'`, `'1'`, or a language tag for a site style default-per-language) — never
 * int-cast it; see `.plans/07-modules-templates.md` Part B "Key facts" §2.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class StyleMapper
{
	public const ITEM_FIELDS = ['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params'];

	public const LIST_FIELDS = ['id', 'template', 'title', 'home', 'client_id', 'language_title', 'image', 'language_sef', 'assigned', 'e_id'];

	public const INT_FIELDS = ['client_id', 'inheritable', 'assigned', 'e_id'];

	public const JSON_FIELDS = ['params'];

	/**
	 * `StylesModel` state — `client_id` is always set explicitly since `populateState()` never runs for us
	 * (`ignore_request => true`); see `.plans/07-modules-templates.md` Part B "Key facts" §1.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(int $clientId, ?string $search, ?string $template): array
	{
		$data = [
			'client_id'      => $clientId,
			'list.ordering'  => 'a.template',
			'list.direction' => 'ASC',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		if ($template !== null)
		{
			$data['filter.template'] = $template;
		}

		return $data;
	}

	/**
	 * `$inheritable`/`$parent` are copied from an existing style of the same template (see the tool class's
	 * `inheritanceFor()`) — they are hidden form fields core itself only ever sets by duplicating an
	 * existing style, never by hand.
	 *
	 * @return  array<string, mixed>
	 */
	public static function createData(
		int $clientId,
		string $title,
		string $template,
		?bool $home,
		?array $params,
		int $inheritable,
		string $parent
	): array {
		return [
			'client_id'   => $clientId,
			'title'       => $title,
			'template'    => $template,
			'home'        => $home === true ? '1' : '0',
			'inheritable' => $inheritable,
			'parent'      => $parent,
			// #__template_styles.params is `text NOT NULL` with no column default, and Form::filter() only
			// ever produces a top-level "params" array when the incoming data already has one to recurse
			// into — an entirely missing key here leaves StyleTable::bind() with nothing to set, and
			// Table::store() then fails with a MySQL "Field 'params' doesn't have a default value" error on
			// INSERT. Found by this plan's own E2E suite; always present, even when empty — see
			// ModuleMapper::createData()'s identical fix.
			'params'      => $params ?? [],
		];
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 * `template` is deliberately not a parameter here — the tool layer rejects an attempt to change it
	 * before this is ever called (see `.plans/07-modules-templates.md` Part B deviations §1). `assigned` is
	 * likewise not set here — the tool layer adds it itself, from a fresh read of the current menu-item
	 * assignments, so a partial update never silently unassigns the style from every menu item (see Part B
	 * "Key facts" §3).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?string $title, ?bool $home, ?array $params): array
	{
		$data = [];

		if ($title !== null)
		{
			$data['title'] = $title;
		}

		if ($home !== null)
		{
			$data['home'] = $home ? '1' : '0';
		}

		if ($params !== null)
		{
			$data['params'] = $params;
		}

		return $data;
	}
}
