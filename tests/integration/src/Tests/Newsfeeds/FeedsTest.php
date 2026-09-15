<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Newsfeeds;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `newsfeeds_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Newsfeeds\Feeds}). See
 * `.plans/09-contact-newsfeeds.md` Tests for the coverage this class is meant to satisfy.
 */
class FeedsTest extends AbstractToolTestCase
{
	private function fixtureCategory(): array
	{
		$category = $this->callToolOk('newsfeeds_categories_create', ['title' => $this->uniqueName('MCP4j Feed Fixture Cat')]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $category['id']]));

		return $category;
	}

	private function fixtureTag(): array
	{
		$tag = $this->callToolOk('tags_create', ['title' => $this->uniqueName('MCP4j Feed Fixture Tag')]);
		$this->registerCleanup(fn () => $this->callTool('tags_delete', ['id' => $tag['id']]));

		return $tag;
	}

	public function testListShapeAndFilters(): void
	{
		$category = $this->fixtureCategory();

		$needle = $this->uniqueName('MCP4j Feed Needle');
		$feed   = $this->callToolOk('newsfeeds_create', [
			'name'  => $needle,
			'link'  => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId' => $category['id'],
		]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_delete', ['id' => $feed['id']]));

		$hay      = $this->uniqueName('MCP4j Feed Hay');
		$otherCat = $this->fixtureCategory();
		$feed2    = $this->callToolOk('newsfeeds_create', [
			'name'  => $hay,
			'link'  => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId' => $otherCat['id'],
		]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_delete', ['id' => $feed2['id']]));

		$unpublished = $this->callToolOk('newsfeeds_create', [
			'name'      => $this->uniqueName('MCP4j Feed Unpublished'),
			'link'      => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId'     => $category['id'],
			'published' => 0,
		]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_delete', ['id' => $unpublished['id']]));

		$all = $this->callToolOk('newsfeeds_list');
		$this->assertListShape($all);

		$byCategory = $this->callToolOk('newsfeeds_list', ['filterCategory' => $category['id']]);
		$names      = array_column($byCategory['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		$bySearch = $this->callToolOk('newsfeeds_list', ['filterSearch' => $needle]);
		$names    = array_column($bySearch['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		$byState = $this->callToolOk('newsfeeds_list', ['filterCategory' => $category['id'], 'filterState' => 0]);
		$names   = array_column($byState['items'], 'name');
		$this->assertContains($unpublished['name'], $names);
		$this->assertNotContains($needle, $names);
	}

	public function testFullLifecycle(): void
	{
		$category = $this->fixtureCategory();
		$tag      = $this->fixtureTag();

		$created = $this->callToolOk('newsfeeds_create', [
			'name'        => $this->uniqueName('MCP4j Feed Lifecycle'),
			'link'        => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId'       => $category['id'],
			'numArticles' => 5,
			'cacheTime'   => 3600,
			'tags'        => [$tag['id']],
		]);
		$id = $created['id'];

		$this->assertSame(5, $created['numarticles']);
		$this->assertSame(3600, $created['cache_time']);
		$this->assertSame([$tag['id']], $created['tags']);

		$read = $this->callToolOk('newsfeeds_read', ['id' => $id]);
		$this->assertSame($created['name'], $read['name']);
		$this->assertSame([$tag['id']], $read['tags']);
		$this->assertArrayNotHasKey('tags', $read['metadata'] ?? []);

		$updated = $this->callToolOk('newsfeeds_update', ['id' => $id, 'description' => 'Updated description']);
		$this->assertSame('Updated description', $updated['description']);
		$this->assertSame($created['link'], $updated['link'], 'Updating description only must leave link unchanged.');
		$this->assertSame($created['numarticles'], $updated['numarticles'], 'Updating description only must leave numarticles unchanged.');
		$this->assertSame([$tag['id']], $updated['tags'], 'Updating description only must leave tags unchanged.');

		$trashed = $this->callToolOk('newsfeeds_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['published']);

		$deleted = $this->callToolOk('newsfeeds_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('newsfeeds_read', ['id' => $id]));
		$this->assertNotFound($this->callTool('newsfeeds_delete', ['id' => $id]));
	}

	public function testCreateWithNonexistentCategoryIsNotFound(): void
	{
		$result = $this->callTool('newsfeeds_create', [
			'name'  => $this->uniqueName('MCP4j Feed BadCat'),
			'link'  => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId' => 999999,
		]);
		$this->assertNotFound($result, 'newsfeeds_create with a nonexistent catId must fail: ' . $result['text']);

		$search = $this->callToolOk('newsfeeds_categories_list', ['filterSearch' => '999999']);
		$this->assertSame([], $search['items'], 'NewsfeedModel::save() must never be allowed to auto-create a category named after the number.');
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('newsfeeds_list');
		$this->assertPermissionDenied($readResult);

		$category    = $this->fixtureCategory();
		$name        = $this->uniqueName('MCP4j Feed LowPriv');
		$writeResult = $this->callToolAsLowPriv('newsfeeds_create', [
			'name'  => $name,
			'link'  => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId' => $category['id'],
		]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('newsfeeds_list', ['filterSearch' => $name]);
		$this->assertSame([], $search['items']);
	}

	public function testOutputWhitelistExcludesAssetIdAndAssociations(): void
	{
		$category = $this->fixtureCategory();

		$created = $this->callToolOk('newsfeeds_create', [
			'name'  => $this->uniqueName('MCP4j Feed Whitelist'),
			'link'  => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId' => $category['id'],
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_delete', ['id' => $id]));

		$this->assertKeysAbsent($created, ['asset_id', 'associations']);

		$read = $this->callToolOk('newsfeeds_read', ['id' => $id]);
		$this->assertKeysAbsent($read, ['asset_id', 'associations']);
		$this->assertArrayNotHasKey('tags', $read['metadata'] ?? [], 'NewsfeedModel::getItem() must never leak a TagsHelper into metadata.tags.');

		$list = $this->callToolOk('newsfeeds_list', ['filterCategory' => $category['id']]);
		foreach ($list['items'] as $item)
		{
			$this->assertKeysAbsent($item, ['asset_id', 'associations']);
		}
	}
}
