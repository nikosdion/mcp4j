<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Content;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `content_categories_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Content\Categories}), and indirectly for the shared
 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base. See
 * `.plans/04-content.md` Tests for the coverage this class is meant to satisfy.
 */
class CategoriesTest extends AbstractToolTestCase
{
	public function testListShapeAndFilters(): void
	{
		$needle = $this->uniqueName('MCP4j Cat Needle');
		$hay    = $this->uniqueName('MCP4j Cat Hay');

		$needleCat = $this->callToolOk('content_categories_create', ['title' => $needle]);
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $needleCat['id']]));

		$hayCat = $this->callToolOk('content_categories_create', ['title' => $hay, 'published' => 0]);
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $hayCat['id']]));

		$all = $this->callToolOk('content_categories_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('content_categories_list', ['filterSearch' => $needle]);
		$this->assertListShape($filtered);
		$titles = array_column($filtered['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);

		$unpublished = $this->callToolOk('content_categories_list', ['filterSearch' => $hay, 'filterState' => 0]);
		$titles       = array_column($unpublished['items'], 'title');
		$this->assertContains($hay, $titles);
	}

	public function testFullLifecycle(): void
	{
		$parentTitle = $this->uniqueName('MCP4j Cat Parent');

		$parent = $this->callToolOk('content_categories_create', ['title' => $parentTitle]);
		$parentId = $parent['id'];
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $parentId]));

		$this->assertSame(1, $parent['parent_id'], 'Omitting parentId must default to the ROOT category (1).');
		$this->assertSame(1, $parent['level']);
		$this->assertSame('com_content', $parent['extension']);
		$this->assertSame([], $parent['tags']);

		$childTitle = $this->uniqueName('MCP4j Cat Child');
		$child      = $this->callToolOk('content_categories_create', ['title' => $childTitle, 'parentId' => $parentId]);
		$childId    = $child['id'];
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $childId]));

		$this->assertSame($parentId, $child['parent_id']);
		$this->assertSame(2, $child['level']);
		$this->assertStringContainsString($parent['alias'], $child['path']);

		// Partial update: only "note" changes — "title" must be unaffected.
		$updatedChild = $this->callToolOk('content_categories_update', ['id' => $childId, 'note' => 'Child note']);
		$this->assertSame('Child note', $updatedChild['note']);
		$this->assertSame($childTitle, $updatedChild['title']);

		// Tag round-trip on the parent.
		$tagTitle = $this->uniqueName('MCP4j Cat Tag');
		$tag      = $this->callToolOk('tags_create', ['title' => $tagTitle]);
		$this->registerCleanup(fn () => $this->callTool('tags_delete', ['id' => $tag['id']]));

		$taggedParent = $this->callToolOk('content_categories_update', ['id' => $parentId, 'tags' => [$tag['id']]]);
		$this->assertSame([$tag['id']], $taggedParent['tags']);

		$readParent = $this->callToolOk('content_categories_read', ['id' => $parentId]);
		$this->assertSame([$tag['id']], $readParent['tags']);

		$clearedParent = $this->callToolOk('content_categories_update', ['id' => $parentId, 'tags' => []]);
		$this->assertSame([], $clearedParent['tags'], 'Sending an explicit empty tags list must clear the category\'s tags.');

		// Trash + delete child, then delete parent.
		$trashedChild = $this->callToolOk('content_categories_trash', ['id' => $childId]);
		$this->assertSame(-2, $trashedChild['published']);

		$deletedChild = $this->callToolOk('content_categories_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('content_categories_delete', ['id' => $parentId]);
		$this->assertSame(['deleted' => true, 'id' => $parentId], $deletedParent);

		$this->assertNotFound($this->callTool('content_categories_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('content_categories_read', ['id' => $parentId]));
	}

	public function testListOnlyReturnsContentExtensionCategories(): void
	{
		$title = $this->uniqueName('MCP4j Cat Isolation');
		$cat   = $this->callToolOk('content_categories_create', ['title' => $title]);
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $cat['id']]));

		$list = $this->callToolOk('content_categories_list', ['pageLimit' => 100]);

		foreach ($list['items'] as $item)
		{
			$this->assertSame('com_content', $item['extension'], 'Every item content_categories_list returns must belong to com_content.');
		}
	}

	public function testReadAndUpdateOfNonexistentIdAreNotFound(): void
	{
		$this->assertNotFound($this->callTool('content_categories_read', ['id' => 999999]));
		$this->assertNotFound($this->callTool('content_categories_update', ['id' => 999999, 'title' => 'x']));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('content_categories_list');
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Cat LowPriv');
		$writeResult = $this->callToolAsLowPriv('content_categories_create', ['title' => $title]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('content_categories_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	public function testDeleteRefusedWhileCategoryStillHasAnArticle(): void
	{
		$catTitle = $this->uniqueName('MCP4j Cat WithArticle');
		$cat      = $this->callToolOk('content_categories_create', ['title' => $catTitle]);
		$catId    = $cat['id'];
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $catId]));

		$articleTitle = $this->uniqueName('MCP4j Article InCat');
		$article      = $this->callToolOk('content_articles_create', [
			'title'     => $articleTitle,
			'catId'     => $catId,
			'introText' => 'Intro',
			'fullText'  => '',
		]);
		$this->registerCleanup(fn () => $this->callTool('content_articles_delete', ['id' => $article['id']]));

		$result = $this->callTool('content_categories_delete', ['id' => $catId]);
		$this->assertTrue($result['isError'], 'Deleting a category that still contains an article must fail: ' . $result['text']);

		// The category must still be readable afterwards.
		$stillThere = $this->callToolOk('content_categories_read', ['id' => $catId]);
		$this->assertSame($catTitle, $stillThere['title']);
	}
}
