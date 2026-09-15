<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute;

defined('_JEXEC') || die;

/**
 * Describes the JSON Schema of a single tool method parameter.
 *
 * The constructor signature deliberately mirrors `PhpMcp\Server\Attributes\Schema` from the original
 * `joomla-mcp-php` project **exactly** (same parameter names, order and types) so that, when porting a
 * tool method, the `#[Schema(...)]` attribute text on each parameter can be pasted unchanged — only the
 * `use` import line needs to change. See `.plans/00-conventions.md` §2.
 *
 * @since  0.0.1
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Schema
{
	public function __construct(
		public readonly ?array $definition = null,
		public readonly ?string $type = null,
		public readonly ?string $description = null,
		public readonly ?array $enum = null,
		public readonly ?string $format = null,
		public readonly ?int $minLength = null,
		public readonly ?int $maxLength = null,
		public readonly ?string $pattern = null,
		public readonly int|float|null $minimum = null,
		public readonly int|float|null $maximum = null,
		public readonly ?bool $exclusiveMinimum = null,
		public readonly ?bool $exclusiveMaximum = null,
		public readonly int|float|null $multipleOf = null,
		public readonly ?array $items = null,
		public readonly ?int $minItems = null,
		public readonly ?int $maxItems = null,
		public readonly ?bool $uniqueItems = null,
		public readonly ?array $properties = null,
		public readonly ?array $required = null,
		public readonly bool|array|null $additionalProperties = null,
	) {
	}
}
