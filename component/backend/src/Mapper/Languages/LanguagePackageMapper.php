<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Languages;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Languages Packages tool category (`administrator/components
 * /com_installer/src/Model/LanguagesModel.php` — the site's configured language accredited-update-site
 * feed, NOT the installed content languages in `#__languages`, see {@see ContentLanguageMapper} for that).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class LanguagePackageMapper
{
	/**
	 * Copied from core's own Web Services API view for language packages
	 * (`api/components/com_installer/src/View/Languages/JsonapiView.php`).
	 */
	public const LIST_FIELDS = ['name', 'element', 'version', 'type', 'detailsurl'];

	/**
	 * Finds a package by its identifier, matched case-insensitively against the `element` field — either
	 * directly (e.g. `pkg_fr-FR`) or, since the language pack update XML always names elements
	 * `pkg_<lang-code>`, against `'pkg_' . $package` (so a caller can pass the bare `fr-FR` too, as
	 * `languages_packages_install`'s original parameter description promises: "the language package
	 * identifier, e.g. 'en-GB'").
	 *
	 * @param   list<array<string, mixed>>  $items    Already-shaped list items (see {@see self::LIST_FIELDS}).
	 * @param   string                       $package  The identifier to search for.
	 *
	 * @return  ?array<string, mixed>
	 */
	public static function findPackage(array $items, string $package): ?array
	{
		$needle   = strtolower($package);
		$prefixed = 'pkg_' . $needle;

		foreach ($items as $item)
		{
			$element = strtolower((string) ($item['element'] ?? ''));

			if ($element === $needle || $element === $prefixed)
			{
				return $item;
			}
		}

		return null;
	}
}
