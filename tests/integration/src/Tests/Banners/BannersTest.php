<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Banners;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `banners_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Banners\Banners}). See `.plans/08-banners.md` Tests.
 */
class BannersTest extends AbstractToolTestCase
{
	private function createCategory(string $prefix = 'MCP4j Banner Cat'): int
	{
		$cat = $this->callToolOk('banners_categories_create', ['title' => $this->uniqueName($prefix)]);
		$this->registerCleanup(fn () => $this->callTool('banners_categories_delete', ['id' => $cat['id']]));

		return $cat['id'];
	}

	private function createClient(string $prefix = 'MCP4j Banner Client'): int
	{
		$client = $this->callToolOk('banners_clients_create', ['name' => 'Contact', 'contactName' => $this->uniqueName($prefix)]);
		$this->registerCleanup(fn () => $this->callTool('banners_clients_delete', ['id' => $client['id']]));

		return $client['id'];
	}

	public function testListShapeAndFilters(): void
	{
		$catA    = $this->createCategory('MCP4j Banner Cat A');
		$catB    = $this->createCategory('MCP4j Banner Cat B');
		$clientA = $this->createClient('MCP4j Banner Client A');
		$clientB = $this->createClient('MCP4j Banner Client B');

		$needle = $this->uniqueName('MCP4j Banner Needle');
		$hay    = $this->uniqueName('MCP4j Banner Hay');

		$needleBanner = $this->callToolOk('banners_create', ['name' => $needle, 'catId' => $catA, 'cid' => $clientA]);
		$this->registerCleanup(fn () => $this->callTool('banners_delete', ['id' => $needleBanner['id']]));

		$hayBanner = $this->callToolOk('banners_create', ['name' => $hay, 'catId' => $catB, 'cid' => $clientB]);
		$this->registerCleanup(fn () => $this->callTool('banners_delete', ['id' => $hayBanner['id']]));

		$all = $this->callToolOk('banners_list');
		$this->assertListShape($all);

		$bySearch = $this->callToolOk('banners_list', ['filterSearch' => $needle]);
		$names    = array_column($bySearch['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		$byCategory = $this->callToolOk('banners_list', ['filterCategory' => $catA]);
		$names      = array_column($byCategory['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		$byClient = $this->callToolOk('banners_list', ['filterClient' => $clientA]);
		$names    = array_column($byClient['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);
	}

	public function testFullLifecycle(): void
	{
		$catId    = $this->createCategory();
		$clientId = $this->createClient();

		$title = $this->uniqueName('MCP4j Banner Lifecycle');

		$created = $this->callToolOk('banners_create', [
			'name'             => $title,
			'catId'            => $catId,
			'type'             => '1',
			'customBannerCode' => '<p>Hello</p>',
			'clickUrl'         => 'https://example.com/',
			'cid'              => $clientId,
			'trackClicks'      => 1,
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('banners_delete', ['id' => $id]));

		$this->assertSame($title, $created['name']);
		$this->assertSame($catId, $created['catid']);
		$this->assertSame(1, $created['type']);
		$this->assertSame('<p>Hello</p>', $created['custombannercode']);
		$this->assertSame('https://example.com/', $created['clickurl']);
		$this->assertSame($clientId, $created['cid']);
		$this->assertSame(1, $created['track_clicks']);

		$read = $this->callToolOk('banners_read', ['id' => $id]);
		$this->assertSame($title, $read['name']);
		$this->assertSame('<p>Hello</p>', $read['custombannercode']);
		$this->assertSame('https://example.com/', $read['clickurl']);
		$this->assertSame($clientId, $read['cid']);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation']);

		// Partial update: only "name" changes — custombannercode, clickurl, cid must be unaffected.
		$newName = $this->uniqueName('MCP4j Banner Renamed');
		$updated = $this->callToolOk('banners_update', ['id' => $id, 'name' => $newName]);
		$this->assertSame($newName, $updated['name']);
		$this->assertSame('<p>Hello</p>', $updated['custombannercode'], 'A partial update must not touch fields it was not given.');
		$this->assertSame('https://example.com/', $updated['clickurl']);
		$this->assertSame($clientId, $updated['cid']);

		$trashed = $this->callToolOk('banners_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['state']);

		$deleted = $this->callToolOk('banners_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('banners_read', ['id' => $id]));
	}

	public function testCreateWithNonexistentCategoryIsNotFoundAndDoesNotAutoCreateACategory(): void
	{
		$result = $this->callTool('banners_create', ['name' => $this->uniqueName('MCP4j Banner BadCat'), 'catId' => 999999]);

		$this->assertNotFound($result, 'Expected "Not found: category #999999": ' . $result['text']);
		$this->assertStringContainsString('999999', $result['text']);

		// BannerModel::save() would otherwise silently auto-create a category named "999999" — prove it did not.
		$categories = $this->callToolOk('banners_categories_list', ['filterSearch' => '999999']);
		$this->assertSame([], $categories['items'], 'No banners category must have been auto-created from the invalid catId.');
	}

	public function testCreateWithTagsFails(): void
	{
		$title  = $this->uniqueName('MCP4j Banner Tags');
		$catId  = $this->createCategory();
		$result = $this->callTool('banners_create', ['name' => $title, 'catId' => $catId, 'tags' => [1]]);

		$this->assertTrue($result['isError'], 'tags must be refused for banners: ' . $result['text']);
		$this->assertStringContainsString('tags', $result['text']);

		// Prove nothing was created.
		$search = $this->callToolOk('banners_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	public function testCreateWithoutAliasGeneratesOne(): void
	{
		$catId = $this->createCategory();
		$title = $this->uniqueName('MCP4j Banner NoAlias');

		$created = $this->callToolOk('banners_create', ['name' => $title, 'catId' => $catId]);
		$this->registerCleanup(fn () => $this->callTool('banners_delete', ['id' => $created['id']]));

		$read = $this->callToolOk('banners_read', ['id' => $created['id']]);
		$this->assertIsString($read['alias']);
		$this->assertNotSame('', $read['alias']);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('banners_list');
		$this->assertPermissionDenied($readResult);

		$catId       = $this->createCategory();
		$title       = $this->uniqueName('MCP4j Banner LowPriv');
		$writeResult = $this->callToolAsLowPriv('banners_create', ['name' => $title, 'catId' => $catId]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('banners_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
