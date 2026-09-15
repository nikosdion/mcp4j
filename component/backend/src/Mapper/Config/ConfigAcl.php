<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Config;

defined('_JEXEC') || die;

/**
 * Pure mirror of `administrator/components/com_config/src/Helper/ConfigHelper::canChangeComponentConfig()`
 * (present, unchanged, since Joomla 4.2.9 — i.e. in every supported version): `com_joomlaupdate` and
 * `com_privacy` require a bare `core.admin` (checked against the root asset, since the real helper calls
 * `$user->authorise('core.admin')` with no asset argument); every other component accepts either
 * `core.admin` or `core.options` on the component's own asset.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ConfigAcl
{
	/**
	 * Components whose configuration is gated by a bare, global `core.admin` rather than the usual
	 * per-component `core.admin`/`core.options` pair — see `ConfigHelper::canChangeComponentConfig()`.
	 */
	private const ROOT_ADMIN_ONLY_COMPONENTS = ['com_joomlaupdate', 'com_privacy'];

	/**
	 * Returns the list of `[action, asset]` pairs of which *any one* being authorised grants access to a
	 * component's configuration — mirrors `ConfigHelper::canChangeComponentConfig()` exactly.
	 *
	 * @return  list<array{0: string, 1: string}>
	 */
	public static function componentConfigRequirement(string $option): array
	{
		if (in_array(strtolower($option), self::ROOT_ADMIN_ONLY_COMPONENTS, true))
		{
			return [['core.admin', 'root.1']];
		}

		return [
			['core.admin', $option],
			['core.options', $option],
		];
	}

	/**
	 * A component "name" (i.e. asset name / `#__extensions.element`) shape check: `com_` followed by one or
	 * more lowercase letters, digits or underscores. Used to reject anything that isn't plausibly a
	 * component option before it's used to build a filesystem path
	 * (`JPATH_ADMINISTRATOR/components/<option>/config.xml`).
	 */
	public static function isValidComponentName(string $option): bool
	{
		return (bool) preg_match('/^com_[a-z0-9_]+$/', $option);
	}
}
