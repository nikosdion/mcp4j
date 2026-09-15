<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `panopticon_updatesites_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\UpdateSites}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 *
 * Deliberately does NOT call `_rebuild`, nor a `_delete` expected to succeed, as the super user: both drop
 * real update sites/download keys on the shared Docker test site.
 */
class UpdateSitesTest extends AbstractToolTestCase
{
	private const TOOLS = [
		'panopticon_updatesites_list',
		'panopticon_updatesites_read',
		'panopticon_updatesites_update',
		'panopticon_updatesites_delete',
		'panopticon_updatesites_rebuild',
	];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testListShape(): void
	{
		$list = $this->callToolOk('panopticon_updatesites_list');

		$this->assertListShape($list);
	}

	public function testListSearchFindsFirstSiteByName(): void
	{
		$list = $this->callToolOk('panopticon_updatesites_list');

		if ($list['items'] === [])
		{
			$this->markTestSkipped('No update sites on this install.');
		}

		$name = (string) $list['items'][0]['name'];

		$filtered = $this->callToolOk('panopticon_updatesites_list', ['filterSearch' => $name]);

		$this->assertGreaterThanOrEqual(1, count($filtered['items']));
		$this->assertContains($name, array_column($filtered['items'], 'name'));
	}

	public function testReadRoundTripAndMasking(): void
	{
		$list = $this->callToolOk('panopticon_updatesites_list');

		if ($list['items'] === [])
		{
			$this->markTestSkipped('No update sites on this install.');
		}

		$id   = (int) $list['items'][0]['update_site_id'];
		$item = $this->callToolOk('panopticon_updatesites_read', ['id' => $id]);

		$this->assertSame($id, $item['update_site_id']);
		$this->assertKeysAbsent($item, ['downloadIdPrefix', 'downloadIdSuffix']);

		$extraQuery = (string) ($item['extra_query'] ?? '');
		$this->assertTrue($extraQuery === '' || str_starts_with($extraQuery, '****'));
	}

	public function testReadNonexistentIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('panopticon_updatesites_read', ['id' => 999999]));
	}

	private function findNonCoreUpdateSite(): ?array
	{
		$list = $this->callToolOk('panopticon_updatesites_list', ['pageLimit' => 100]);

		foreach ($list['items'] as $item)
		{
			if (($item['element'] ?? null) !== 'joomla')
			{
				return $item;
			}
		}

		return null;
	}

	public function testUpdateEnabledToggle(): void
	{
		$site = $this->findNonCoreUpdateSite();

		if ($site === null)
		{
			$this->markTestSkipped('No non-core update site found to toggle.');
		}

		$id             = (int) $site['update_site_id'];
		$originalEnabled = (int) $site['enabled'];

		$this->registerCleanup(fn () => $this->callTool('panopticon_updatesites_update', ['id' => $id, 'enabled' => $originalEnabled]));

		$disabled = $this->callToolOk('panopticon_updatesites_update', ['id' => $id, 'enabled' => 0]);
		$this->assertSame(0, $disabled['enabled']);

		$reRead = $this->callToolOk('panopticon_updatesites_read', ['id' => $id]);
		$this->assertSame(0, $reRead['enabled']);

		$enabled = $this->callToolOk('panopticon_updatesites_update', ['id' => $id, 'enabled' => 1]);
		$this->assertSame(1, $enabled['enabled']);
	}

	public function testUpdateLocationIsRefused(): void
	{
		$site = $this->findNonCoreUpdateSite();

		if ($site === null)
		{
			$this->markTestSkipped('No non-core update site found.');
		}

		$id            = (int) $site['update_site_id'];
		$originalLocation = (string) $site['location'];

		$text = $this->callToolError('panopticon_updatesites_update', ['id' => $id, 'location' => 'https://evil.example/']);

		$this->assertStringContainsString('Changing the name or location', $text);

		$reRead = $this->callToolOk('panopticon_updatesites_read', ['id' => $id]);
		$this->assertSame($originalLocation, $reRead['location']);
	}

	public function testDeleteJoomlaCoreUpdateSiteIsRefused(): void
	{
		$list = $this->callToolOk('panopticon_updatesites_list', ['pageLimit' => 100]);

		$core = null;

		foreach ($list['items'] as $item)
		{
			if (($item['element'] ?? null) === 'joomla')
			{
				$core = $item;

				break;
			}
		}

		if ($core === null)
		{
			$this->markTestSkipped('No Joomla core update site found.');
		}

		$id = (int) $core['update_site_id'];

		$result = $this->callTool('panopticon_updatesites_delete', ['id' => $id]);
		$this->assertTrue($result['isError'], 'Deleting the Joomla core update site must fail.');

		// Still reads fine afterwards.
		$this->callToolOk('panopticon_updatesites_read', ['id' => $id]);
	}

	public function testLowPrivilegeIsDeniedForEveryTool(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updatesites_list'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updatesites_update', ['id' => 1, 'enabled' => 1]));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updatesites_delete', ['id' => 1]));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_updatesites_rebuild'));
	}
}
