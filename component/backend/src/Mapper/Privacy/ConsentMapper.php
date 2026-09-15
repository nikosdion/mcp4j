<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the `privacy_consents_*` tools.
 *
 * `ConsentsModel::getListQuery()` selects `a.*`, so the raw row carries `token` — the value that
 * authenticates a consent-management link core itself renders in `View/Consents/JsonapiView.php`. We
 * deliberately never expose it: `ITEM_FIELDS`/`LIST_FIELDS` omit it entirely — see
 * `.plans/12-fields-history-privacy.md` deviation 3 and "Sensitive output".
 *
 * There is no singular "consent" model in `com_privacy` (only `ConsentsModel`, a `ListModel`) — a single
 * consent is read the same way core's own item view does, by filtering the list to one row via
 * `filter.search` (`"id:<n>"`, supported by `ConsentsModel::getListQuery()`) — see {@see self::readState()}.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ConsentMapper
{
	public const ITEM_FIELDS = ['id', 'user_id', 'username', 'name', 'state', 'created', 'subject', 'body', 'remind'];

	public const LIST_FIELDS = self::ITEM_FIELDS;

	public const INT_FIELDS = ['user_id', 'state', 'remind'];

	/**
	 * @return  array<string, mixed>
	 */
	public static function listState(?int $state): array
	{
		return [
			'filter.state'   => $state !== null ? $state : '',
			'filter.search'  => '',
			'filter.subject' => '',
			'list.ordering'  => 'a.created',
			'list.direction' => 'DESC',
		];
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function readState(int $id): array
	{
		$data                   = self::listState(null);
		$data['filter.search']  = 'id:' . $id;

		return $data;
	}
}
