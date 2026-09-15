<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Newsfeeds;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `newsfeeds_categories_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Newsfeeds\Categories}), and (together with
 * `Tests\Contact\CategoriesTest`) cross-extension isolation of the shared
 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base. See
 * `.plans/09-contact-newsfeeds.md` Tests for the coverage this class is meant to satisfy.
 */
class CategoriesTest extends AbstractToolTestCase
{
	public function testListShapeAndFilters(): void
	{
		$needle = $this->uniqueName('MCP4j Feed Cat Needle');
		$hay    = $this->uniqueName('MCP4j Feed Cat Hay');

		$needleCat = $this->callToolOk('newsfeeds_categories_create', ['title' => $needle]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $needleCat['id']]));

		$hayCat = $this->callToolOk('newsfeeds_categories_create', ['title' => $hay, 'published' => 0]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $hayCat['id']]));

		$all = $this->callToolOk('newsfeeds_categories_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('newsfeeds_categories_list', ['filterSearch' => $needle]);
		$titles   = array_column($filtered['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);

		$unpublished = $this->callToolOk('newsfeeds_categories_list', ['filterSearch' => $hay, 'filterState' => 0]);
		$titles      = array_column($unpublished['items'], 'title');
		$this->assertContains($hay, $titles);
	}

	public function testFullLifecycleWithChild(): void
	{
		$parentTitle = $this->uniqueName('MCP4j Feed Cat Parent');

		$parent   = $this->callToolOk('newsfeeds_categories_create', ['title' => $parentTitle]);
		$parentId = $parent['id'];
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $parentId]));

		$this->assertSame(1, $parent['parent_id']);
		$this->assertSame('com_newsfeeds', $parent['extension']);
		$this->assertSame([], $parent['tags']);

		$childTitle = $this->uniqueName('MCP4j Feed Cat Child');
		$child      = $this->callToolOk('newsfeeds_categories_create', ['title' => $childTitle, 'parentId' => $parentId]);
		$childId    = $child['id'];
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $childId]));

		$this->assertSame($parentId, $child['parent_id']);

		$updatedChild = $this->callToolOk('newsfeeds_categories_update', ['id' => $childId, 'note' => 'Child note']);
		$this->assertSame('Child note', $updatedChild['note']);
		$this->assertSame($childTitle, $updatedChild['title']);

		$trashedChild = $this->callToolOk('newsfeeds_categories_trash', ['id' => $childId]);
		$this->assertSame(-2, $trashedChild['published']);

		$deletedChild = $this->callToolOk('newsfeeds_categories_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('newsfeeds_categories_delete', ['id' => $parentId]);
		$this->assertSame(['deleted' => true, 'id' => $parentId], $deletedParent);

		$this->assertNotFound($this->callTool('newsfeeds_categories_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('newsfeeds_categories_read', ['id' => $parentId]));
	}

	public function testReadOfNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('newsfeeds_categories_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('newsfeeds_categories_list');
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Feed Cat LowPriv');
		$writeResult = $this->callToolAsLowPriv('newsfeeds_categories_create', ['title' => $title]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('newsfeeds_categories_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	/**
	 * A `com_contact` category id must be invisible through `newsfeeds_categories_read` — the shared
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base scopes every lookup to
	 * the calling tool's own extension.
	 */
	public function testContactCategoryIdIsNotFoundThroughNewsfeedsCategories(): void
	{
		$contactCat = $this->callToolOk('contact_categories_create', ['title' => $this->uniqueName('MCP4j Cross Contact Cat')]);
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $contactCat['id']]));

		$this->assertNotFound($this->callTool('newsfeeds_categories_read', ['id' => $contactCat['id']]));
	}
}
