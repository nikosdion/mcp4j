<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the two `panopticon_updates_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\Updates}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 */
class UpdatesTest extends AbstractToolTestCase
{
	private const TOOLS = ['panopticon_updates_refresh', 'panopticon_updates_apply'];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testApplyWithoutEidErrors(): void
	{
		$text = $this->callToolError('panopticon_updates_apply');

		$this->assertStringContainsString('No extensions to update', $text);
	}

	public function testApplyOnMcp4jItselfReportsNoUpdateAvailable(): void
	{
		$this->requireToolsOrSkip(['panopticon_extensions_list']);

		$list = $this->callToolOk('panopticon_extensions_list', ['filterSearch' => 'mcp4j']);

		$mcp4j = null;

		foreach ($list['items'] as $item)
		{
			if (($item['element'] ?? null) === 'com_mcp4j')
			{
				$mcp4j = $item;

				break;
			}
		}

		if ($mcp4j === null)
		{
			$this->markTestSkipped('com_mcp4j was not found via panopticon_extensions_list.');
		}

		$result = $this->callToolOk('panopticon_updates_apply', ['eid' => (string) $mcp4j['extension_id']]);

		$this->assertArrayHasKey('results', $result);
		$this->assertCount(1, $result['results']);
		$this->assertFalse($result['results'][0]['status']);
	}

	public function testRefreshSucceeds(): void
	{
		$result = $this->callToolOk('panopticon_updates_refresh');

		$this->assertTrue($result['success']);
	}

	public function testLowPrivilegeIsDeniedForEveryTool(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updates_refresh'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updates_apply', ['eid' => '1']));
	}
}
