<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy;

defined('_JEXEC') || die;

/**
 * Shapes the result of `ExportModel::collectDataForExportRequest()` — a list of
 * `\Joomla\Component\Privacy\Administrator\Export\Domain` objects, each holding a *protected* `Item[]`
 * (reachable only via `getItems()`), each `Item` holding a *protected* `Field[]` (reachable only via
 * `getFields()`) — into a plain, JSON-encodable array.
 *
 * Deliberately duck-typed rather than importing those `com_privacy` classes by name: this stays a pure
 * `Mapper/` class (see `.plans/00-conventions.md` §3), and {@see
 * \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer} would silently drop each `Domain`'s
 * `items`/`Item`'s `fields` entirely (protected properties with no `toArray()`/`getProperties()` method),
 * which is exactly why this dedicated mapper exists — see `.plans/12-fields-history-privacy.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ExportMapper
{
	/**
	 * @param   iterable<object>  $domains  Each element duck-typed as `{name: string, description: string,
	 *                                      getItems(): iterable<object{id: mixed, getFields(): iterable<object{name:
	 *                                      string, value: mixed}>}>}`.
	 *
	 * @return  list<array{name: string, description: string, items: list<array{id: mixed, fields: array<string, mixed>}>}>
	 */
	public static function domainsToArray(iterable $domains): array
	{
		$result = [];

		foreach ($domains as $domain)
		{
			$items = [];

			foreach ($domain->getItems() as $item)
			{
				$fields = [];

				foreach ($item->getFields() as $field)
				{
					$fields[(string) $field->name] = self::scalarize($field->value);
				}

				$items[] = [
					'id'     => $item->id,
					'fields' => $fields,
				];
			}

			$result[] = [
				'name'        => (string) $domain->name,
				'description' => (string) $domain->description,
				'items'       => $items,
			];
		}

		return $result;
	}

	private static function scalarize(mixed $value): mixed
	{
		if ($value === null || is_scalar($value))
		{
			return $value;
		}

		return json_encode($value);
	}
}
