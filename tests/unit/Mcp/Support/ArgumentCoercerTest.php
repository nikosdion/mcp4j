<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\InvalidToolArgumentsException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ArgumentCoercer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArgumentCoercerTest extends TestCase
{
	private function bind(string $method, array $arguments): array
	{
		return ArgumentCoercer::bind(new \ReflectionMethod(ArgumentCoercerFixture::class, $method), $arguments);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Missing / null handling
	// -----------------------------------------------------------------------------------------------------------

	public function testMissingRequiredArgumentThrows(): void
	{
		$this->expectException(InvalidToolArgumentsException::class);
		$this->expectExceptionMessage('Missing required argument: p');

		$this->bind('bindRequiredNoDefault', []);
	}

	public function testAbsentArgumentUsesDefaultWhenAvailable(): void
	{
		$this->assertSame(['def'], $this->bind('bindOptionalWithDefault', []));
	}

	public function testAbsentArgumentBecomesNullWhenNullableAndNoDefault(): void
	{
		$this->assertSame([null], $this->bind('bindNullableInt', []));
	}

	public function testExplicitNullAcceptedWhenNullable(): void
	{
		$this->assertSame([null], $this->bind('bindNullableInt', ['p' => null]));
	}

	public function testExplicitNullFallsBackToDefaultWhenNonNullableWithDefault(): void
	{
		$this->assertSame(['def'], $this->bind('bindOptionalWithDefault', ['p' => null]));
	}

	public function testExplicitNullThrowsWhenNonNullableAndNoDefault(): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindRequiredNoDefault', ['p' => null]);
	}

	public function testExtraUnknownArgumentsAreIgnored(): void
	{
		$this->assertSame(['x'], $this->bind('bindRequiredNoDefault', ['p' => 'x', 'unexpected' => 'ignored']));
	}

	public function testMixedParameterPassesThroughUnchanged(): void
	{
		$this->assertSame([['a' => 1]], $this->bind('bindMixed', ['p' => ['a' => 1]]));
	}

	public function testUntypedParameterPassesThroughUnchanged(): void
	{
		$this->assertSame([false], $this->bind('bindUntyped', ['p' => false]));
	}

	// -----------------------------------------------------------------------------------------------------------
	// int
	// -----------------------------------------------------------------------------------------------------------

	public static function intAcceptedProvider(): array
	{
		return [
			'native int'             => [5, 5],
			'integral float'         => [5.0, 5],
			'numeric string'         => ['5', 5],
			'negative numeric string' => ['-5', -5],
		];
	}

	#[DataProvider('intAcceptedProvider')]
	public function testIntCoercionAccepted(mixed $input, int $expected): void
	{
		$this->assertSame($expected, $this->bind('bindInt', ['p' => $input])[0]);
	}

	public static function intRejectedProvider(): array
	{
		return [
			'non-integral float'  => [5.5],
			'bool'                => [true],
			'array'               => [[1]],
			'non-numeric string'  => ['abc'],
			'decimal string'      => ['5.5'],
		];
	}

	#[DataProvider('intRejectedProvider')]
	public function testIntCoercionRejected(mixed $input): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindInt', ['p' => $input]);
	}

	public function testInvalidTypeMessageNamesTheArgumentAndType(): void
	{
		$this->expectException(InvalidToolArgumentsException::class);
		$this->expectExceptionMessage('Invalid type for argument p: expected int, got string');

		$this->bind('bindInt', ['p' => 'abc']);
	}

	// -----------------------------------------------------------------------------------------------------------
	// float
	// -----------------------------------------------------------------------------------------------------------

	public static function floatAcceptedProvider(): array
	{
		return [
			'native int'      => [5, 5.0],
			'native float'    => [5.5, 5.5],
			'numeric string'  => ['5.5', 5.5],
			'integral string' => ['5', 5.0],
		];
	}

	#[DataProvider('floatAcceptedProvider')]
	public function testFloatCoercionAccepted(mixed $input, float $expected): void
	{
		$this->assertSame($expected, $this->bind('bindFloat', ['p' => $input])[0]);
	}

	public static function floatRejectedProvider(): array
	{
		return [
			'bool'               => [true],
			'array'              => [[1]],
			'non-numeric string' => ['abc'],
		];
	}

	#[DataProvider('floatRejectedProvider')]
	public function testFloatCoercionRejected(mixed $input): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindFloat', ['p' => $input]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// bool
	// -----------------------------------------------------------------------------------------------------------

	public static function boolAcceptedProvider(): array
	{
		return [
			'native true'    => [true, true],
			'native false'   => [false, false],
			'int 1'          => [1, true],
			'int 0'          => [0, false],
			'string "true"'  => ['true', true],
			'string "false"' => ['false', false],
			'string "1"'     => ['1', true],
			'string "0"'     => ['0', false],
			'string "TRUE"'  => ['TRUE', true],
			'string "FALSE"' => ['FALSE', false],
		];
	}

	#[DataProvider('boolAcceptedProvider')]
	public function testBoolCoercionAccepted(mixed $input, bool $expected): void
	{
		$this->assertSame($expected, $this->bind('bindBool', ['p' => $input])[0]);
	}

	public static function boolRejectedProvider(): array
	{
		return [
			'int 2'            => [2],
			'array'            => [[1]],
			'non-boolean word' => ['yes'],
			'float'            => [2.5],
		];
	}

	#[DataProvider('boolRejectedProvider')]
	public function testBoolCoercionRejected(mixed $input): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindBool', ['p' => $input]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// string
	// -----------------------------------------------------------------------------------------------------------

	public static function stringAcceptedProvider(): array
	{
		return [
			'native string' => ['x', 'x'],
			'int'           => [5, '5'],
			'float'         => [5.5, '5.5'],
		];
	}

	#[DataProvider('stringAcceptedProvider')]
	public function testStringCoercionAccepted(mixed $input, string $expected): void
	{
		$this->assertSame($expected, $this->bind('bindString', ['p' => $input])[0]);
	}

	public static function stringRejectedProvider(): array
	{
		return [
			'bool'  => [true],
			'array' => [[1]],
		];
	}

	#[DataProvider('stringRejectedProvider')]
	public function testStringCoercionRejected(mixed $input): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindString', ['p' => $input]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// array (including the JSON-string compatibility rule)
	// -----------------------------------------------------------------------------------------------------------

	public static function arrayAcceptedProvider(): array
	{
		return [
			'native array'      => [[1, 2], [1, 2]],
			'JSON array string' => ['[1,2]', [1, 2]],
			'JSON object string' => ['{"a":1}', ['a' => 1]],
		];
	}

	#[DataProvider('arrayAcceptedProvider')]
	public function testArrayCoercionAccepted(mixed $input, array $expected): void
	{
		$this->assertSame($expected, $this->bind('bindArray', ['p' => $input])[0]);
	}

	public static function arrayRejectedProvider(): array
	{
		return [
			'plain string'        => ['not json'],
			'int'                 => [5],
			'bool'                => [true],
			'JSON scalar string'  => ['"just a string"'],
		];
	}

	#[DataProvider('arrayRejectedProvider')]
	public function testArrayCoercionRejected(mixed $input): void
	{
		$this->expectException(InvalidToolArgumentsException::class);

		$this->bind('bindArray', ['p' => $input]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Union types: exact match first, then declaration-order coercion
	// -----------------------------------------------------------------------------------------------------------

	public function testUnionExactMatchWinsOverCoercion(): void
	{
		// A JSON-array-looking string stays a string when "string" is itself an exact match.
		$this->assertSame('[1]', $this->bind('bindUnionArrayString', ['p' => '[1]'])[0]);
	}

	public function testUnionExactMatchArrayStaysArray(): void
	{
		$this->assertSame([1, 2], $this->bind('bindUnionArrayString', ['p' => [1, 2]])[0]);
	}

	/**
	 * PHP's own {@see \ReflectionUnionType::getTypes()} does not preserve the order scalar/array types
	 * were written in source for a union type declaration — it normalises to a fixed internal canonical
	 * order (empirically: array, string, int, float, bool take priority in that order, regardless of how
	 * the union was authored). `bindUnionFloatInt()` and `bindUnionIntFloat()` below declare `float`/`int`
	 * in opposite source order specifically to demonstrate this: both are reflected identically as
	 * `int|float`, so both coerce a numeric string the same way (int wins, not whichever the source typed
	 * first). See {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ArgumentCoercer} class docblock.
	 */
	public function testUnionMemberCoercionOrderIsPhpsCanonicalOrderNotSourceOrder(): void
	{
		$this->assertSame(5, $this->bind('bindUnionFloatInt', ['p' => '5'])[0]);
		$this->assertSame(5, $this->bind('bindUnionIntFloat', ['p' => '5'])[0]);
	}
}

/**
 * Fixture used only by {@see ArgumentCoercerTest}. Every method isolates one binding scenario.
 */
class ArgumentCoercerFixture
{
	public function bindInt(int $p): void
	{
	}

	public function bindFloat(float $p): void
	{
	}

	public function bindBool(bool $p): void
	{
	}

	public function bindString(string $p): void
	{
	}

	public function bindArray(array $p): void
	{
	}

	public function bindMixed(mixed $p): void
	{
	}

	public function bindUntyped($p): void
	{
	}

	public function bindNullableInt(?int $p): void
	{
	}

	public function bindRequiredNoDefault(string $p): void
	{
	}

	public function bindOptionalWithDefault(string $p = 'def'): void
	{
	}

	public function bindUnionArrayString(array|string $p): void
	{
	}

	public function bindUnionFloatInt(float|int $p): void
	{
	}

	public function bindUnionIntFloat(int|float $p): void
	{
	}
}
