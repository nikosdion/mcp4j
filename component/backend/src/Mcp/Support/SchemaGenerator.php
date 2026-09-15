<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;

/**
 * Builds a JSON Schema (draft 2020-12-ish, as far as the tool `#[Schema(...)]` attribute goes) for a
 * tool method's parameters, purely from PHP reflection and the `#[Schema]` attribute on each parameter.
 *
 * This is a deliberately small, self-contained re-implementation of the idea behind
 * `PhpMcp\Server\Utils\SchemaGenerator` in the original `joomla-mcp-php` project — not a port of its
 * algorithm. The original also consults docblocks (`@param` tags) as a secondary source of type
 * information; this one does not; every tool parameter in this codebase is expected to carry an
 * accurate native PHP type declaration and, where useful, an explicit `#[Schema(...)]` attribute, so
 * there is nothing for docblock parsing to add. See `.plans/01-tool-framework.md` §7 for the exact rules
 * this class implements.
 *
 * A note on union member order: this class lists a union type's JSON Schema `type` array in whatever
 * order `\ReflectionUnionType::getTypes()` reports, which is PHP's own fixed internal canonical order for
 * scalar/array types, not necessarily the order the union was written in source — see
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ArgumentCoercer}'s class docblock for the
 * empirically-determined ordering and why it cannot be recovered from reflection.
 *
 * Pure: no Joomla, no I/O, only `\Reflection*` and the `Schema` attribute.
 *
 * @since  0.0.1
 */
final class SchemaGenerator
{
	private const COPIED_ATTRIBUTE_KEYS = [
		'description',
		'enum',
		'format',
		'minLength',
		'maxLength',
		'pattern',
		'minimum',
		'maximum',
		'multipleOf',
		'items',
		'minItems',
		'maxItems',
		'uniqueItems',
		'properties',
		'required',
		'additionalProperties',
	];

	/**
	 * @return  array{type: string, properties: array<string, array>, required: list<string>}
	 * @throws  \LogicException  If the method declares a variadic parameter.
	 */
	public static function forMethod(\ReflectionMethod $method): array
	{
		$properties = [];
		$required   = [];

		foreach ($method->getParameters() as $parameter)
		{
			if ($parameter->isVariadic())
			{
				throw new \LogicException(sprintf(
					'Variadic parameters are not supported by the MCP tool framework: %s::%s($%s)',
					$method->getDeclaringClass()->getName(),
					$method->getName(),
					$parameter->getName()
				));
			}

			$properties[$parameter->getName()] = self::forParameter($parameter);

			if (!$parameter->isOptional())
			{
				$required[] = $parameter->getName();
			}
		}

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		];
	}

	private static function forParameter(\ReflectionParameter $parameter): array
	{
		$attribute = self::schemaAttribute($parameter);

		$typeList = self::jsonTypesFromReflectionType($parameter->getType());

		// Rule 2: an object-shaped Schema attribute promotes an inferred "array" type to "object".
		if ($attribute !== null && ($attribute->properties !== null || $attribute->additionalProperties !== null))
		{
			$typeList = array_map(static fn (string $type): string => $type === 'array' ? 'object' : $type, $typeList);
		}

		$schema = [];

		if (\count($typeList) === 1)
		{
			$schema['type'] = $typeList[0];
		}
		elseif (\count($typeList) > 1)
		{
			$schema['type'] = $typeList;
		}

		// Rule 3: an explicit Schema::$type always overrides the inferred type.
		if ($attribute !== null && $attribute->type !== null)
		{
			$schema['type'] = $attribute->type;
		}

		if ($attribute !== null)
		{
			// Rule 4: copy every other non-null attribute value verbatim.
			foreach (self::COPIED_ATTRIBUTE_KEYS as $key)
			{
				$value = $attribute->{$key};

				if ($value !== null)
				{
					$schema[$key] = $value;
				}
			}

			// Rule 5: exclusiveMinimum/Maximum true + a numeric bound -> JSON Schema 2020-12 numeric form.
			// false, or true without a bound to convert, simply emits nothing.
			if ($attribute->exclusiveMinimum === true && \array_key_exists('minimum', $schema))
			{
				$schema['exclusiveMinimum'] = $schema['minimum'];
				unset($schema['minimum']);
			}

			if ($attribute->exclusiveMaximum === true && \array_key_exists('maximum', $schema))
			{
				$schema['exclusiveMaximum'] = $schema['maximum'];
				unset($schema['maximum']);
			}

			// Rule 6: an explicit $definition replaces the whole property schema built so far.
			if ($attribute->definition !== null)
			{
				$schema = $attribute->definition;
			}
		}

		// Rule 7: a non-null default value is always recorded, even under an explicit $definition.
		if ($parameter->isDefaultValueAvailable())
		{
			$default = $parameter->getDefaultValue();

			if ($default !== null)
			{
				$schema['default'] = $default;
			}
		}

		return $schema;
	}

	private static function schemaAttribute(\ReflectionParameter $parameter): ?Schema
	{
		$attributes = $parameter->getAttributes(Schema::class);

		if ($attributes === [])
		{
			return null;
		}

		return $attributes[0]->newInstance();
	}

	/**
	 * Maps a parameter's declared PHP type to a list of JSON Schema type names, ignoring `null` (per
	 * Rule 1: nullability never adds `"null"` to the JSON Schema type — it is expressed purely by whether
	 * the argument may be omitted/absent, handled separately by {@see ArgumentCoercer}).
	 *
	 * @return  list<string>  Empty for no type declaration, `mixed`, or a type this framework does not
	 *                        map to a JSON Schema primitive.
	 */
	private static function jsonTypesFromReflectionType(?\ReflectionType $type): array
	{
		if ($type === null)
		{
			return [];
		}

		if ($type instanceof \ReflectionUnionType)
		{
			$result = [];

			foreach ($type->getTypes() as $innerType)
			{
				foreach (self::jsonTypesFromReflectionType($innerType) as $jsonType)
				{
					if (!\in_array($jsonType, $result, true))
					{
						$result[] = $jsonType;
					}
				}
			}

			return $result;
		}

		if (!$type instanceof \ReflectionNamedType)
		{
			// Intersection types are not meaningful for a JSON Schema primitive; treat as untyped.
			return [];
		}

		return match (strtolower($type->getName()))
		{
			'int' => ['integer'],
			'float' => ['number'],
			'string' => ['string'],
			'bool' => ['boolean'],
			'array' => ['array'],
			default => [],
		};
	}
}
