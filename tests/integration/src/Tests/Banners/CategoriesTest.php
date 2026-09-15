<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Banners;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `banners_categories_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Banners\Categories}), and indirectly for the shared
 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base (already covered for
 * `com_content` by `Tests\Content\CategoriesTest` — this class repeats the same scenarios for `com_banners`
 * plus the cross-extension isolation case). See `.plans/08-banners.md` Tests.
 */
class CategoriesTest extends AbstractToolTestCase
{
	public function testListShapeAndFilters(): void
	{
		$needle = $this->uniqueName('MCP4j BannerCat Needle');
		$hay    = $this->uniqueName('MCP4j BannerCat Hay');

		$needleCat = $this->callToolOk('banners_categories_create', ['title' => $needle]);
		$this->registerCleanup(fn () => $this->callTool('banners_categories_delete', ['id' => $needleCat['id']]));

		$hayCat = $this->callToolOk('banners_categories_create', ['title' => $hay, 'published' => 0]);
		$this->registerCleanup(fn () => $this->callTool('banners_categories_delete', ['id' => $hayCat['id']]));

		$all = $this->callToolOk('banners_categories_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('banners_categories_list', ['filterSearch' => $needle]);
		$this->assertListShape($filtered);
		$titles = array_column($filtered['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);

		$unpublished = $this->callToolOk('banners_categories_list', ['filterSearch' => $hay, 'filterState' => 0]);
		$titles = array_column($unpublished['items'], 'title');
		$this->assertContains($hay, $titles);
	}

	public function testFullLifecycle(): void
	{
		$parentTitle = $this->uniqueName('MCP4j BannerCat Parent');

		$parent   = $this->callToolOk('banners_categories_create', ['title' => $parentTitle]);
		$parentId = $parent['id'];
		$this->registerCleanup(fn () => $this->callTool('banners_categories_delete', ['id' => $parentId]));

		$this->assertSame(1, $parent['parent_id'], 'Omitting parentId must default to the ROOT category (1).');
		$this->assertSame(1, $parent['level']);
		$this->assertSame('com_banners', $parent['extension']);
		$this->assertSame([], $parent['tags']);

		$childTitle = $this->uniqueName('MCP4j BannerCat Child');
		$child      = $this->callToolOk('banners_categories_create', ['title' => $childTitle, 'parentId' => $parentId]);
		$childId    = $child['id'];
		$this->registerCleanup(fn () => $this->callTool('banners_categories_delete', ['id' => $childId]));

		$this->assertSame($parentId, $child['parent_id']);
		$this->assertSame(2, $child['level']);
		$this->assertStringContainsString($parent['alias'], $child['path']);

		// Partial update: only "note" changes — "title" must be unaffected.
		$updatedChild = $this->callToolOk('banners_categories_update', ['id' => $childId, 'note' => 'Child note']);
		$this->assertSame('Child note', $updatedChild['note']);
		$this->assertSame($childTitle, $updatedChild['title']);

		// Trash + delete child, then delete parent.
		$trashedChild = $this->callToolOk('banners_categories_trash', ['id' => $childId]);
		$this->assertSame(-2, $trashedChild['published']);

		$deletedChild = $this->callToolOk('banners_categories_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('banners_categories_delete', ['id' => $parentId]);
		$this->assertSame(['deleted' => true, 'id' => $parentId], $deletedParent);

		$this->assertNotFound($this->callTool('banners_categories_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('banners_categories_read', ['id' => $parentId]));
	}

	public function testReadAndUpdateOfNonexistentIdAreNotFound(): void
	{
		$this->assertNotFound($this->callTool('banners_categories_read', ['id' => 999999]));
		$this->assertNotFound($this->callTool('banners_categories_update', ['id' => 999999, 'title' => 'x']));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('banners_categories_list');
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j BannerCat LowPriv');
		$writeResult = $this->callToolAsLowPriv('banners_categories_create', ['title' => $title]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('banners_categories_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	/**
	 * A `com_content` category's id must be invisible to `banners_categories_*` and vice versa —
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool::loadOwn()} enforces this
	 * for every extension sharing the base, see `.plans/04-content.md` Part B and `.plans/08-banners.md`
	 * Tests.
	 */
	public function testCrossExtensionCategoryIsInvisibleToBannersTools(): void
	{
		$title = $this->uniqueName('MCP4j ContentCat CrossExt');

		$contentCat = $this->callToolOk('content_categories_create', ['title' => $title]);
		$contentId  = $contentCat['id'];
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $contentId]));

		$this->assertNotFound($this->callTool('banners_categories_read', ['id' => $contentId]));
		$this->assertNotFound($this->callTool('banners_categories_update', ['id' => $contentId, 'title' => 'Hijacked']));

		$stillThere = $this->callToolOk('content_categories_read', ['id' => $contentId]);
		$this->assertSame($title, $stillThere['title'], 'The content category must be unaffected by the refused banners_categories_update call.');
	}
}
