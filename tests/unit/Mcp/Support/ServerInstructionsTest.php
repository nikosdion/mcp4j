<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ServerInstructions;
use PHPUnit\Framework\TestCase;

class ServerInstructionsTest extends TestCase
{
	public function testEmptyListReturnsHeaderOnly(): void
	{
		$instructions = ServerInstructions::build([]);

		$this->assertStringContainsString('MCP4j exposes this Joomla site', $instructions);
		$this->assertStringNotContainsString("\n-", $instructions);
	}

	public function testOnlyExposedCategoriesAreListed(): void
	{
		$instructions = ServerInstructions::build(['Content', 'Users']);

		$this->assertStringContainsString('- Content:', $instructions);
		$this->assertStringContainsString('- Users:', $instructions);
		$this->assertStringNotContainsString('- Banners:', $instructions);
		$this->assertStringNotContainsString('- Tags:', $instructions);
	}

	public function testUnknownCategoryGetsFallbackText(): void
	{
		$instructions = ServerInstructions::build(['SomeThirdPartyCategory']);

		$this->assertStringContainsString('- SomeThirdPartyCategory: (no description)', $instructions);
	}

	public function testKnownCategoryTextIsCopiedVerbatimFromTheOriginal(): void
	{
		$instructions = ServerInstructions::build(['Content']);

		$this->assertStringContainsString(
			'- Content: articles and content categories (content_articles_*, content_categories_*). '
			. 'Use for creating, listing, reading, updating, or deleting articles and their categories.',
			$instructions
		);
	}

	public function testAllTwentyTwoOriginalCategoriesAreKnown(): void
	{
		$categories = [
			'Content', 'Banners', 'Config', 'Contact', 'ContentHistory', 'Fields', 'Installer',
			'JoomlaUpdate', 'Languages', 'Media', 'Menus', 'Messages', 'Modules', 'Newsfeeds',
			'Panopticon', 'Tickets', 'Plugins', 'Privacy', 'Redirects', 'Tags', 'Templates', 'Users',
		];

		$this->assertCount(22, $categories);

		$instructions = ServerInstructions::build($categories);

		foreach ($categories as $category)
		{
			$this->assertStringNotContainsString("- $category: (no description)", $instructions);
		}
	}
}
