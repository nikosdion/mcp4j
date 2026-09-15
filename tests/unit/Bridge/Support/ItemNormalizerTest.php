<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use PHPUnit\Framework\TestCase;

class ItemNormalizerTest extends TestCase
{
	public function testNullBecomesEmptyArray(): void
	{
		$this->assertSame([], ItemNormalizer::toArray(null));
	}

	public function testFalseBecomesEmptyArray(): void
	{
		$this->assertSame([], ItemNormalizer::toArray(false));
	}

	public function testTopLevelScalarIsWrapped(): void
	{
		$this->assertSame(['value' => 42], ItemNormalizer::toArray(42));
		$this->assertSame(['value' => 'hi'], ItemNormalizer::toArray('hi'));
	}

	public function testPlainArrayIsRecursedIntoUnchanged(): void
	{
		$input = ['a' => 1, 'b' => ['c' => 2]];

		$this->assertSame($input, ItemNormalizer::toArray($input));
	}

	public function testObjectWithToArrayIsUsedDirectly(): void
	{
		$object = new class {
			public function toArray(): array
			{
				return ['from' => 'toArray'];
			}
		};

		$this->assertSame(['from' => 'toArray'], ItemNormalizer::toArray($object));
	}

	public function testDateTimeIsFormattedAsUtcString(): void
	{
		$date = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('America/New_York'));

		// 10:00 EST (UTC-5) == 15:00 UTC.
		$this->assertSame(['value' => '2026-01-15 15:00:00'], ItemNormalizer::toArray($date));
	}

	public function testObjectWithGetPropertiesIsUsedAndUnderscoreKeysAreDropped(): void
	{
		$object = new class {
			public function getProperties(bool $public = true): array
			{
				return ['id' => 5, '_errors' => ['oops'], 'title' => 'Hello'];
			}
		};

		$this->assertSame(['id' => 5, 'title' => 'Hello'], ItemNormalizer::toArray($object));
	}

	public function testJsonSerializableIsUsedWhenNoToArrayOrGetProperties(): void
	{
		$object = new class implements \JsonSerializable {
			public function jsonSerialize(): array
			{
				return ['from' => 'jsonSerialize'];
			}
		};

		$this->assertSame(['from' => 'jsonSerialize'], ItemNormalizer::toArray($object));
	}

	public function testGenericObjectUsesPublicPropertiesAndDropsUnderscoreKeys(): void
	{
		$object            = new \stdClass();
		$object->id        = 7;
		$object->title     = 'Hi';
		$object->_internal = 'hidden';

		$this->assertSame(['id' => 7, 'title' => 'Hi'], ItemNormalizer::toArray($object));
	}

	public function testSimpleXmlElementBecomesNull(): void
	{
		$xml            = new \stdClass();
		$xml->xml       = new \SimpleXMLElement('<root><child>1</child></root>');

		$this->assertSame(['xml' => null], ItemNormalizer::toArray($xml));
	}

	public function testClosureBecomesNull(): void
	{
		$holder          = new \stdClass();
		$holder->closure = static fn () => 1;

		$this->assertSame(['closure' => null], ItemNormalizer::toArray($holder));
	}

	public function testDepthBeyondLimitBecomesNull(): void
	{
		$leaf = new \stdClass();
		$leaf->value = 'bottom';

		$current = $leaf;

		// Build a chain deeper than the 10-level limit.
		for ($i = 0; $i < 15; $i++)
		{
			$next        = new \stdClass();
			$next->child = $current;
			$current     = $next;
		}

		$result = ItemNormalizer::toArray($current);

		// Walk down until we hit the null cutoff — it must appear before the actual leaf value.
		$cursor    = $result;
		$sawNull   = false;

		for ($i = 0; $i < 15; $i++)
		{
			if (!is_array($cursor) || !array_key_exists('child', $cursor))
			{
				break;
			}

			if ($cursor['child'] === null)
			{
				$sawNull = true;

				break;
			}

			$cursor = $cursor['child'];
		}

		$this->assertTrue($sawNull, 'Expected the depth limit to cut the chain off with a null.');
	}

	public function testSelfReferencingObjectIsCutOffByDepthLimitRatherThanLooping(): void
	{
		$object       = new \stdClass();
		$object->self = $object;

		// Must terminate (the depth limit stops infinite recursion) rather than exhausting memory/stack.
		$result = ItemNormalizer::toArray($object);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('self', $result);
	}

	public function testNestedArraysAndObjectsAreNormalisedRecursively(): void
	{
		$inner        = new \stdClass();
		$inner->value = 'nested';

		$input = ['items' => [$inner, $inner]];

		$this->assertSame(
			['items' => [['value' => 'nested'], ['value' => 'nested']]],
			ItemNormalizer::toArray($input)
		);
	}
}
