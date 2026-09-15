<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the two `panopticon_templateoverrides_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\TemplateOverrides}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 */
class TemplateOverridesTest extends AbstractToolTestCase
{
	private const TOOLS = ['panopticon_templateoverrides_list', 'panopticon_templateoverrides_read'];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testListShape(): void
	{
		// A fresh Docker site usually has zero changed template overrides — only the shape is asserted.
		$list = $this->callToolOk('panopticon_templateoverrides_list');

		$this->assertListShape($list);
	}

	public function testReadNonexistentIsNotFound(): void
	{
		$result = $this->callTool('panopticon_templateoverrides_read', ['id' => 999999]);

		$this->assertTrue($result['isError']);
		$this->assertSame('Not found: template override #999999', $result['text']);
	}

	public function testLowPrivilegeIsDeniedForList(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_templateoverrides_list'));
	}
}
