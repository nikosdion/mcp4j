<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Shapes a normalised item array into the whitelisted, tidily-typed record a tool actually returns.
 *
 * This is the security boundary that stops a direct Joomla model call from leaking fields a real Joomla
 * API view would never render (`password`, `otpKey`, `otep`, `activation`, unrelated `params` secrets,
 * ...) — see `.plans/00-conventions.md` §5.9. Every tool that returns a record MUST pass it through here
 * with an explicit field whitelist.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class OutputShaper
{
	/**
	 * Keeps only `$fields` (in that order; missing keys are simply omitted rather than filled with null),
	 * then normalises `$intFields` and `$jsonFields` in place.
	 *
	 * @param   array<string, mixed>  $item        A normalised item (see {@see ItemNormalizer::toArray()}).
	 * @param   list<string>          $fields      The output field whitelist, in the desired output order.
	 * @param   list<string>          $intFields   Fields (plus `id`, always) to coerce to `int`.
	 * @param   list<string>          $jsonFields  Fields holding a JSON-encoded string to decode to an array.
	 *
	 * @return  array<string, mixed>
	 */
	public static function shape(array $item, array $fields, array $intFields = [], array $jsonFields = []): array
	{
		$shaped = [];

		foreach ($fields as $field)
		{
			if (!array_key_exists($field, $item))
			{
				continue;
			}

			$shaped[$field] = $item[$field];
		}

		$intFieldSet = array_fill_keys([...$intFields, 'id'], true);

		foreach ($intFieldSet as $field => $true)
		{
			if (!array_key_exists($field, $shaped))
			{
				continue;
			}

			$shaped[$field] = self::coerceInt($shaped[$field]);
		}

		foreach ($jsonFields as $field)
		{
			if (!array_key_exists($field, $shaped))
			{
				continue;
			}

			$shaped[$field] = self::coerceJson($shaped[$field]);
		}

		return $shaped;
	}

	/**
	 * Normalises each item with {@see ItemNormalizer::toArray()}, then {@see self::shape()}s it.
	 *
	 * @return  list<array<string, mixed>>
	 */
	public static function shapeList(iterable $items, array $fields, array $intFields = [], array $jsonFields = []): array
	{
		$shaped = [];

		foreach ($items as $item)
		{
			$normalised = is_array($item) ? $item : ItemNormalizer::toArray($item);

			$shaped[] = self::shape($normalised, $fields, $intFields, $jsonFields);
		}

		return $shaped;
	}

	private static function coerceInt(mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		if (is_int($value))
		{
			return $value;
		}

		if (is_float($value) || (is_string($value) && is_numeric($value)))
		{
			return (int) $value;
		}

		// Non-numeric strings (e.g. media adapter ids like "local-images") are left untouched.
		return $value;
	}

	private static function coerceJson(mixed $value): mixed
	{
		if (!is_string($value))
		{
			return $value;
		}

		if ($value === '')
		{
			return [];
		}

		$decoded = json_decode($value, true);

		return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $value;
	}
}
