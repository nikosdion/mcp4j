<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Banners;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for `Tool/Banners/Clients.php`.
 *
 * `ITEM_FIELDS` is Joomla core's own Web Services API view for banner clients
 * (`api/components/com_banners/src/View/Clients/JsonapiView.php`) minus `typeAlias`. `LIST_FIELDS` is the
 * same view's list whitelist, again minus `typeAlias` — `email` is deliberately **not** added to it: core's
 * own `ClientsModel::getListQuery()` select list never includes `a.email` (only `id, name, contact,
 * checked_out, checked_out_time, state, metakey, purchase_type` plus the `nbanners`/`editor` joins), so a
 * list row simply never has that key to whitelist — see `.plans/08-banners.md`.
 *
 * The original `joomla-mcp-php` project's parameter naming is famously swapped for this resource: its
 * `name` parameter ("Client contact name") maps to the `contact` form field, and its `contactName`
 * parameter ("Client company/display name") maps to the `name` form field — {@see self::createData()} and
 * {@see self::updateData()} preserve that swap verbatim (see `.plans/00-conventions.md` §2 — interface
 * fidelity, not our choice to make).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ClientMapper
{
	public const ITEM_FIELDS = [
		'id', 'name', 'contact', 'email', 'extrainfo', 'state', 'metakey', 'own_prefix',
		'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out', 'checked_out_time',
	];

	public const LIST_FIELDS = [
		'id', 'name', 'contact', 'checked_out', 'checked_out_time', 'state', 'metakey',
		'purchase_type', 'nbanners', 'editor', 'count_published', 'count_unpublished', 'count_trashed', 'count_archived',
	];

	public const INT_FIELDS = [
		'state', 'own_prefix', 'purchase_type', 'track_clicks', 'track_impressions',
		'checked_out', 'nbanners', 'count_published', 'count_unpublished', 'count_trashed', 'count_archived',
	];

	/**
	 * `ClientsModel::getListQuery()` state. **Note the key is `filter.state`, not `filter.published`** —
	 * unlike every other publish-state filter in this package, `ClientsModel` names its own state key
	 * differently from `BannersModel`'s (see the Gotchas section of `.plans/08-banners.md`). Always set, to
	 * `''` when `$state` is null, so the model shows published + unpublished (states 0 and 1) exactly like
	 * the backend list.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state): array
	{
		$data = [
			'list.ordering'  => 'a.name',
			'list.direction' => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		$data['filter.state'] = $state !== null ? (string) $state : '';

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $name,
		string $contactName,
		?string $email,
		?string $extraInfo,
		int $state,
		?int $purchaseType,
		?int $trackImpressions,
		?int $trackClicks,
		?string $metakey
	): array {
		$data = [
			// Swapped naming — see this class's docblock.
			'contact' => $name,
			'name'    => $contactName,
			'state'   => $state,
			// #__banner_clients.extrainfo/metakey are `text NOT NULL` with NO column default — same trap
			// as BannerMapper::createData()'s description/custombannercode/metakey/params (see that
			// method's docblock for the full mechanism: Form::filter() never emits a key we didn't send,
			// the Table property is then left NULL, and DatabaseDriver::insertObject() silently skips any
			// NULL property, producing a raw MySQL "doesn't have a default value" error). Always present.
			'extrainfo' => $extraInfo ?? '',
			'metakey'   => $metakey ?? '',
		];

		if ($email !== null)
		{
			$data['email'] = $email;
		}

		if ($purchaseType !== null)
		{
			$data['purchase_type'] = $purchaseType;
		}

		if ($trackImpressions !== null)
		{
			$data['track_impressions'] = $trackImpressions;
		}

		if ($trackClicks !== null)
		{
			$data['track_clicks'] = $trackClicks;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $name,
		?string $contactName,
		?string $email,
		?string $extraInfo,
		?int $state,
		?int $purchaseType,
		?int $trackImpressions,
		?int $trackClicks,
		?string $metakey
	): array {
		return NullFilter::withoutNulls([
			// Swapped naming — see this class's docblock.
			'contact'           => $name,
			'name'              => $contactName,
			'email'             => $email,
			'extrainfo'         => $extraInfo,
			'state'             => $state,
			'purchase_type'     => $purchaseType,
			'track_impressions' => $trackImpressions,
			'track_clicks'      => $trackClicks,
			'metakey'           => $metakey,
		]);
	}

	/**
	 * Clients have no `metadesc` field either (absent from `forms/client.xml`) — see
	 * {@see BannerMapper::unsupported()}'s docblock for why this is refused outright rather than silently
	 * discarded.
	 *
	 * @return  list<string>
	 */
	public static function unsupported(?string $metadesc): array
	{
		return ($metadesc !== null && $metadesc !== '') ? ['metadesc'] : [];
	}
}
