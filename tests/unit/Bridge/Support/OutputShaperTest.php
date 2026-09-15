<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use PHPUnit\Framework\TestCase;

class OutputShaperTest extends TestCase
{
	public function testOnlyWhitelistedFieldsAreKeptInFieldOrder(): void
	{
		$item = ['id' => 1, 'title' => 'Hello', 'password' => 'secret', 'otpKey' => 'topsecret'];

		$result = OutputShaper::shape($item, ['title', 'id']);

		$this->assertSame(['title' => 'Hello', 'id' => 1], $result);
		$this->assertArrayNotHasKey('password', $result);
		$this->assertArrayNotHasKey('otpKey', $result);
	}

	public function testMissingFieldsAreOmittedNotFilledWithNull(): void
	{
		$result = OutputShaper::shape(['id' => 1], ['id', 'title']);

		$this->assertSame(['id' => 1], $result);
		$this->assertArrayNotHasKey('title', $result);
	}

	public function testIdIsAlwaysCoercedToIntEvenWithoutBeingListedAsAnIntField(): void
	{
		$result = OutputShaper::shape(['id' => '42'], ['id']);

		$this->assertSame(42, $result['id']);
		$this->assertIsInt($result['id']);
	}

	public function testIntFieldsAreCoercedFromNumericStringsAndFloats(): void
	{
		$result = OutputShaper::shape(
			['id' => 1, 'parent_id' => '5', 'level' => 2.0],
			['id', 'parent_id', 'level'],
			['parent_id', 'level']
		);

		$this->assertSame(1, $result['id']);
		$this->assertSame(5, $result['parent_id']);
		$this->assertSame(2, $result['level']);
	}

	public function testNullIntFieldStaysNull(): void
	{
		$result = OutputShaper::shape(['id' => 1, 'parent_id' => null], ['id', 'parent_id'], ['parent_id']);

		$this->assertNull($result['parent_id']);
	}

	public function testNonNumericStringIntFieldIsLeftUntouched(): void
	{
		$result = OutputShaper::shape(['id' => 'local-images'], ['id'], ['id']);

		$this->assertSame('local-images', $result['id']);
	}

	public function testJsonFieldDecodesAJsonStringToAnArray(): void
	{
		$result = OutputShaper::shape(
			['params' => '{"show_title":1}'],
			['params'],
			[],
			['params']
		);

		$this->assertSame(['show_title' => 1], $result['params']);
	}

	public function testJsonFieldEmptyStringBecomesEmptyArray(): void
	{
		$result = OutputShaper::shape(['params' => ''], ['params'], [], ['params']);

		$this->assertSame([], $result['params']);
	}

	public function testJsonFieldInvalidJsonIsLeftUnchanged(): void
	{
		$result = OutputShaper::shape(['params' => 'not json'], ['params'], [], ['params']);

		$this->assertSame('not json', $result['params']);
	}

	public function testJsonFieldAlreadyAnArrayIsLeftUnchanged(): void
	{
		$result = OutputShaper::shape(['params' => ['a' => 1]], ['params'], [], ['params']);

		$this->assertSame(['a' => 1], $result['params']);
	}

	public function testShapeListNormalisesEachItemThenShapes(): void
	{
		$object        = new \stdClass();
		$object->id    = '3';
		$object->title = 'From object';

		$result = OutputShaper::shapeList([$object, ['id' => 4, 'title' => 'From array']], ['id', 'title']);

		$this->assertSame(
			[
				['id' => 3, 'title' => 'From object'],
				['id' => 4, 'title' => 'From array'],
			],
			$result
		);
	}

	public function testShapeListReturnsAListEvenWhenEmpty(): void
	{
		$this->assertSame([], OutputShaper::shapeList([], ['id']));
	}
}
