<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Fields;

defined('_JEXEC') || die;

/**
 * Parses a custom-fields "context" string (e.g. `com_content.article`) into its component and section
 * parts, the same shape `\Joomla\Component\Fields\Administrator\Helper\FieldsHelper::extract()` produces,
 * without needing Joomla booted.
 *
 * Every `Fields`/`FieldGroups` tool method uses this first: a syntactically invalid context is rejected
 * before any `com_fields` model is even created, and the parsed component name is what
 * `\Joomla\CMS\Component\ComponentHelper::isEnabled()` and every ACL check are performed against — see
 * `.plans/12-fields-history-privacy.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class FieldContext
{
	/**
	 * @return  array{component: string, section: string}
	 *
	 * @throws  \InvalidArgumentException  `$context` does not match `com_<component>.<section>`.
	 */
	public static function parse(string $context): array
	{
		if (!preg_match('/^com_[a-z0-9_]+\.[a-z0-9_]+$/', $context))
		{
			throw new \InvalidArgumentException(sprintf('Invalid context: %s', $context));
		}

		[$component, $section] = explode('.', $context, 2);

		return ['component' => $component, 'section' => $section];
	}
}
