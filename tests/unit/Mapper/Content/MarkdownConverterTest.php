<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Content;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Content\MarkdownConverter;
use PHPUnit\Framework\TestCase;

class MarkdownConverterTest extends TestCase
{
	public function testNullReturnsEmptyString(): void
	{
		$this->assertSame('', MarkdownConverter::toHtml(null));
	}

	public function testEmptyStringReturnsEmptyString(): void
	{
		$this->assertSame('', MarkdownConverter::toHtml(''));
	}

	public function testHeadingIsConverted(): void
	{
		$html = MarkdownConverter::toHtml('# Hello');

		$this->assertStringContainsString('<h1>Hello</h1>', $html);
	}

	public function testBoldIsConverted(): void
	{
		$html = MarkdownConverter::toHtml('**Bold** text');

		$this->assertStringContainsString('<strong>Bold</strong>', $html);
	}

	public function testItalicIsConverted(): void
	{
		$html = MarkdownConverter::toHtml('*Italic* text');

		$this->assertStringContainsString('<em>Italic</em>', $html);
	}

	public function testRawHtmlPassesThrough(): void
	{
		$html = MarkdownConverter::toHtml('<div class="foo">Raw HTML</div>');

		$this->assertStringContainsString('<div class="foo">Raw HTML</div>', $html);
	}

	public function testPlainTextIsWrappedInParagraph(): void
	{
		$html = MarkdownConverter::toHtml('Just plain text.');

		$this->assertStringContainsString('<p>Just plain text.</p>', $html);
	}

	public function testLinkWithUnsafeSchemeIsAllowed(): void
	{
		// allow_unsafe_links: true — the converter itself does not block javascript:/data: schemes; the
		// site's own Text Filter (filterText, applied by the article form) is the actual safety net — see
		// MarkdownConverter's docblock.
		$html = MarkdownConverter::toHtml('[click](javascript:alert(1))');

		$this->assertStringContainsString('href="javascript:alert(1)"', $html);
	}
}
