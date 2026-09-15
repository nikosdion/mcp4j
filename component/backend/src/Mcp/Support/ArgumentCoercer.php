<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\InvalidToolArgumentsException;

/**
 * Binds a `tools/call` request's associative `arguments` array to the positional argument list a tool
 * method's `\ReflectionMethod::invokeArgs()` expects, coercing loosely-typed JSON values (LLMs routinely
 * send an integer as `"20"`, a JSON object as a raw JSON string, etc. — this is a port of the original
 * `joomla-mcp-php` project's `JsonInputCompatibilityTrait` idea, generalised to every scalar/array type
 * rather than one field at a time) into the parameter's declared PHP type wherever that is unambiguous.
 *
 * A note on union member order: `.plans/01-tool-framework.md` describes coercion as trying union members
 * "in declaration order". In practice, PHP's own `\ReflectionUnionType::getTypes()` does not preserve the
 * order scalar/array types were written in source — the engine normalises every union to a fixed internal
 * canonical order (empirically: `array`, `string`, `int`, `float`, `bool` take priority in that order, no
 * matter how the union was authored; e.g. both `float|int` and `int|float` are reflected as `int|float`).
 * "Declaration order" here therefore really means "whatever order PHP's reflection reports", which is at
 * least consistent and deterministic — see `ArgumentCoercerTest::testUnionMemberCoercionOrderIsPhpsCanonicalOrderNotSourceOrder()`.
 *
 * Pure: no Joomla, no I/O, only `\Reflection*`.
 *
 * @since  0.0.1
 */
final class ArgumentCoercer
{
	/**
	 * @param   array<string, mixed>  $arguments  The `tools/call` request's `arguments` member, keyed by
	 *                                             parameter name. Unknown keys (no matching parameter)
	 *                                             are silently ignored.
	 *
	 * @return  list<mixed>  Ready for `$method->invokeArgs($instance, $bound)`.
	 * @throws  InvalidToolArgumentsException
	 */
	public static function bind(\ReflectionMethod $method, array $arguments): array
	{
		$bound = [];

		foreach ($method->getParameters() as $parameter)
		{
			$name        = $parameter->getName();
			$type        = $parameter->getType();
			$allowsNull  = $type === null || $type->allowsNull();
			$hasDefault  = $parameter->isDefaultValueAvailable();
			$hasArgument = \array_key_exists($name, $arguments);

			if (!$hasArgument)
			{
				if ($hasDefault)
				{
					$bound[] = $parameter->getDefaultValue();

					continue;
				}

				if ($allowsNull)
				{
					$bound[] = null;

					continue;
				}

				throw new InvalidToolArgumentsException(sprintf('Missing required argument: %s', $name));
			}

			$value = $arguments[$name];

			if ($value === null)
			{
				if ($allowsNull)
				{
					$bound[] = null;

					continue;
				}

				if ($hasDefault)
				{
					$bound[] = $parameter->getDefaultValue();

					continue;
				}

				throw new InvalidToolArgumentsException(sprintf('Missing required argument: %s', $name));
			}

			$bound[] = self::coerce($value, $type, $name);
		}

		return $bound;
	}

	/**
	 * @throws  InvalidToolArgumentsException
	 */
	private static function coerce(mixed $value, ?\ReflectionType $type, string $name): mixed
	{
		// No declared type, or an explicit `mixed`: pass through untouched.
		if ($type === null || (($type instanceof \ReflectionNamedType) && strtolower($type->getName()) === 'mixed'))
		{
			return $value;
		}

		$memberTypes = self::memberTypeNames($type);

		if ($memberTypes === [])
		{
			// Nothing left to coerce against (e.g. a bare "?null" — not realistic, but harmless).
			return $value;
		}

		// Exact match wins first, in declaration order — e.g. a string that happens to look like JSON is
		// NOT decoded into an array when the parameter is declared `array|string`.
		foreach ($memberTypes as $memberType)
		{
			if (self::isExactType($value, $memberType))
			{
				return $value;
			}
		}

		// No exact match: try coercion, in declaration order, first success wins.
		foreach ($memberTypes as $memberType)
		{
			$coerced = self::tryCoerce($value, $memberType);

			if ($coerced !== null)
			{
				return $coerced;
			}
		}

		throw new InvalidToolArgumentsException(sprintf(
			'Invalid type for argument %s: expected %s, got %s',
			$name,
			(string) $type,
			gettype($value)
		));
	}

	/**
	 * @return  list<string>  Lower-cased member type names of a (possibly union) type, excluding `null`.
	 */
	private static function memberTypeNames(\ReflectionType $type): array
	{
		if ($type instanceof \ReflectionUnionType)
		{
			$names = [];

			foreach ($type->getTypes() as $innerType)
			{
				if ($innerType instanceof \ReflectionNamedType && strtolower($innerType->getName()) !== 'null')
				{
					$names[] = strtolower($innerType->getName());
				}
			}

			return $names;
		}

		if ($type instanceof \ReflectionNamedType)
		{
			$name = strtolower($type->getName());

			return $name === 'null' ? [] : [$name];
		}

		// ReflectionIntersectionType or anything else: not a JSON-representable scalar/array type.
		return [];
	}

	private static function isExactType(mixed $value, string $memberType): bool
	{
		return match ($memberType)
		{
			'int' => is_int($value),
			'float' => is_float($value),
			'string' => is_string($value),
			'bool' => is_bool($value),
			'array' => is_array($value),
			default => false,
		};
	}

	/**
	 * @return  mixed  The coerced value, or `null` as a "could not coerce" sentinel — safe because none
	 *                 of these coercions ever legitimately produce PHP `null` from a non-null input.
	 */
	private static function tryCoerce(mixed $value, string $memberType): mixed
	{
		return match ($memberType)
		{
			'int' => self::tryCoerceInt($value),
			'float' => self::tryCoerceFloat($value),
			'bool' => self::tryCoerceBool($value),
			'string' => self::tryCoerceString($value),
			'array' => self::tryCoerceArray($value),
			default => null,
		};
	}

	private static function tryCoerceInt(mixed $value): ?int
	{
		if (is_float($value) && floor($value) === $value)
		{
			return (int) $value;
		}

		if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
		{
			return (int) $value;
		}

		return null;
	}

	private static function tryCoerceFloat(mixed $value): ?float
	{
		if (is_int($value))
		{
			return (float) $value;
		}

		if (is_string($value) && is_numeric($value))
		{
			return (float) $value;
		}

		return null;
	}

	private static function tryCoerceBool(mixed $value): ?bool
	{
		if ($value === 0 || $value === 1)
		{
			return (bool) $value;
		}

		if (is_string($value))
		{
			$lower = strtolower($value);

			if ($lower === 'true' || $lower === '1')
			{
				return true;
			}

			if ($lower === 'false' || $lower === '0')
			{
				return false;
			}
		}

		return null;
	}

	private static function tryCoerceString(mixed $value): ?string
	{
		if (is_int($value) || is_float($value))
		{
			return (string) $value;
		}

		// Arrays and booleans are deliberately not coercible to string.
		return null;
	}

	private static function tryCoerceArray(mixed $value): ?array
	{
		if (!is_string($value))
		{
			return null;
		}

		$decoded = json_decode($value, true);

		return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
	}
}
