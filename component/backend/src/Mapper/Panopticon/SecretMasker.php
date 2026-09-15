<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Masks a credential-shaped string for tool output — the Panopticon Connector's Api-namespace models hand
 * back several secrets verbatim (extension update site "extra query" download keys, Admin Tools secret
 * words, ...) that a direct model call would otherwise leak straight into MCP tool output; see
 * `.plans/14-panopticon-core.md` "Sensitive output".
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class SecretMasker
{
	/**
	 * `null`/`''` are returned unchanged (nothing to mask). A value of 8 characters or fewer is masked
	 * completely (`'********'`) rather than partially, because keeping any suffix visible would leak a
	 * meaningful fraction of a short secret. Anything longer keeps its last four characters, which is
	 * enough for a human to recognise "this is the key I expect" without exposing the key itself.
	 */
	public static function mask(?string $value): ?string
	{
		if ($value === null || $value === '')
		{
			return $value;
		}

		if (strlen($value) <= 8)
		{
			return '********';
		}

		return '****' . substr($value, -4);
	}
}
