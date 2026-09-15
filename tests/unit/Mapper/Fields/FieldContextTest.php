<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Fields;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldContextTest extends TestCase
{
	public function testParseSimpleContext(): void
	{
		$this->assertSame(
			['component' => 'com_content', 'section' => 'article'],
			FieldContext::parse('com_content.article')
		);
	}

	public function testParseContextWithUnderscoresInBothParts(): void
	{
		$this->assertSame(
			['component' => 'com_some_thing', 'section' => 'sub_type'],
			FieldContext::parse('com_some_thing.sub_type')
		);
	}

	public function testParseUsersContext(): void
	{
		$this->assertSame(
			['component' => 'com_users', 'section' => 'user'],
			FieldContext::parse('com_users.user')
		);
	}

	#[DataProvider('invalidContextProvider')]
	public function testParseRejectsInvalidContext(string $context): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid context: ' . $context);

		FieldContext::parse($context);
	}

	public static function invalidContextProvider(): iterable
	{
		yield 'no dot' => ['nonsense'];
		yield 'not com_ prefixed' => ['content.article'];
		yield 'empty section' => ['com_content.'];
		yield 'empty component' => ['.article'];
		yield 'uppercase' => ['Com_Content.Article'];
		yield 'trailing dot segment' => ['com_content.article.extra'];
		yield 'empty string' => [''];
		yield 'spaces' => ['com_content article'];
	}
}
