<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * A pure, framework-free description of one discovered MCP tool: everything
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry} learned from reflecting a
 * `#[McpTool]`-annotated method, plus enough to invoke it later
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher} does the invoking; this class never
 * touches Joomla or reflection itself).
 *
 * @since  0.0.1
 */
final class ToolDefinition
{
	/**
	 * @param   string               $name              The MCP tool name, e.g. `content_articles_create`.
	 * @param   string               $description       The tool description shown to the LLM.
	 * @param   string               $category          The `Tool/<Category>` folder name this tool came from.
	 * @param   string               $className         Fully-qualified class name holding the tool method.
	 * @param   string               $methodName         Name of the method implementing the tool.
	 * @param   array                $inputSchema       JSON Schema (`type`, `properties`, `required`) for the
	 *                                                   tool's arguments, as produced by {@see SchemaGenerator}.
	 * @param   array                $annotations       Only the non-null MCP tool annotation hints, keyed
	 *                                                   `readOnlyHint`, `destructiveHint`, `idempotentHint`,
	 *                                                   `openWorldHint`.
	 * @param   array<int, string>   $requiredComponents Component option names that must be installed+enabled.
	 * @param   ?string              $minJoomla         Minimum Joomla version (inclusive), or null.
	 * @param   ?string              $maxJoomla         Maximum Joomla version (exclusive), or null.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly string $category,
		public readonly string $className,
		public readonly string $methodName,
		public readonly array $inputSchema,
		public readonly array $annotations = [],
		public readonly array $requiredComponents = [],
		public readonly ?string $minJoomla = null,
		public readonly ?string $maxJoomla = null,
	) {
	}

	/**
	 * Whether this tool is annotated `readOnlyHint: true`. Every other annotation state (not stated,
	 * explicitly `false`) is "not read-only" for the purposes of `--non-destructive`-style filtering.
	 */
	public function isReadOnly(): bool
	{
		return ($this->annotations['readOnlyHint'] ?? false) === true;
	}

	/**
	 * Renders this definition into the shape `tools/list` returns for a single tool, per the MCP spec:
	 * `{name, description, inputSchema, annotations}` (annotations omitted entirely when there are none).
	 *
	 * `inputSchema.properties` is deliberately an empty {@see \stdClass} rather than an empty PHP array
	 * when a tool takes no arguments — `json_encode([])` renders `[]`, which MCP clients reject where an
	 * object (`{}`) is expected. `required` is omitted entirely when empty, matching the original's
	 * behaviour.
	 *
	 * @param   bool  $minimalSchema  When true, every property in the schema is reduced to only its
	 *                                `type` and `default` keys (see the `minimal_schema` component
	 *                                option) — trades away LLM hints (descriptions, enums, constraints)
	 *                                for a smaller `tools/list` payload.
	 */
	public function toMcpArray(bool $minimalSchema = false): array
	{
		$inputSchema = $this->inputSchema;

		$properties = $inputSchema['properties'] ?? [];

		if ($minimalSchema && is_array($properties))
		{
			foreach ($properties as $propertyName => $propertySchema)
			{
				if (!is_array($propertySchema))
				{
					continue;
				}

				$minimal = [];

				if (array_key_exists('type', $propertySchema))
				{
					$minimal['type'] = $propertySchema['type'];
				}

				if (array_key_exists('default', $propertySchema))
				{
					$minimal['default'] = $propertySchema['default'];
				}

				$properties[$propertyName] = $minimal;
			}
		}

		$inputSchema['properties'] = empty($properties) ? new \stdClass() : $properties;

		if (empty($inputSchema['required']))
		{
			unset($inputSchema['required']);
		}

		$tool = [
			'name'        => $this->name,
			'description' => $this->description,
			'inputSchema' => $inputSchema,
		];

		if (!empty($this->annotations))
		{
			$tool['annotations'] = $this->annotations;
		}

		return $tool;
	}
}
