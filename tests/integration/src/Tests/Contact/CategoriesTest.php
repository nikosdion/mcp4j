<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Contact;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `contact_categories_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Contact\Categories}), and (together with
 * `Tests\Newsfeeds\CategoriesTest`) cross-extension isolation of the shared
 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base. See
 * `.plans/09-contact-newsfeeds.md` Tests for the coverage this class is meant to satisfy.
 */
class CategoriesTest extends AbstractToolTestCase
{
	public function testListShapeAndFilters(): void
	{
		$needle = $this->uniqueName('MCP4j Contact Cat Needle');
		$hay    = $this->uniqueName('MCP4j Contact Cat Hay');

		$needleCat = $this->callToolOk('contact_categories_create', ['title' => $needle]);
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $needleCat['id']]));

		$hayCat = $this->callToolOk('contact_categories_create', ['title' => $hay, 'published' => 0]);
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $hayCat['id']]));

		$all = $this->callToolOk('contact_categories_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('contact_categories_list', ['filterSearch' => $needle]);
		$titles   = array_column($filtered['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);

		$unpublished = $this->callToolOk('contact_categories_list', ['filterSearch' => $hay, 'filterState' => 0]);
		$titles      = array_column($unpublished['items'], 'title');
		$this->assertContains($hay, $titles);
	}

	public function testFullLifecycleWithChild(): void
	{
		$parentTitle = $this->uniqueName('MCP4j Contact Cat Parent');

		$parent   = $this->callToolOk('contact_categories_create', ['title' => $parentTitle]);
		$parentId = $parent['id'];
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $parentId]));

		$this->assertSame(1, $parent['parent_id']);
		$this->assertSame('com_contact', $parent['extension']);
		$this->assertSame([], $parent['tags']);

		$childTitle = $this->uniqueName('MCP4j Contact Cat Child');
		$child      = $this->callToolOk('contact_categories_create', ['title' => $childTitle, 'parentId' => $parentId]);
		$childId    = $child['id'];
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $childId]));

		$this->assertSame($parentId, $child['parent_id']);

		$updatedChild = $this->callToolOk('contact_categories_update', ['id' => $childId, 'note' => 'Child note']);
		$this->assertSame('Child note', $updatedChild['note']);
		$this->assertSame($childTitle, $updatedChild['title']);

		$trashedChild = $this->callToolOk('contact_categories_trash', ['id' => $childId]);
		$this->assertSame(-2, $trashedChild['published']);

		$deletedChild = $this->callToolOk('contact_categories_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('contact_categories_delete', ['id' => $parentId]);
		$this->assertSame(['deleted' => true, 'id' => $parentId], $deletedParent);

		$this->assertNotFound($this->callTool('contact_categories_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('contact_categories_read', ['id' => $parentId]));
	}

	public function testReadOfNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('contact_categories_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('contact_categories_list');
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Contact Cat LowPriv');
		$writeResult = $this->callToolAsLowPriv('contact_categories_create', ['title' => $title]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('contact_categories_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	/**
	 * A `com_newsfeeds` category id must be invisible through `contact_categories_read` — the shared
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool} base scopes every lookup to
	 * the calling tool's own extension.
	 */
	public function testNewsfeedsCategoryIdIsNotFoundThroughContactCategories(): void
	{
		$feedCat = $this->callToolOk('newsfeeds_categories_create', ['title' => $this->uniqueName('MCP4j Cross Feed Cat')]);
		$this->registerCleanup(fn () => $this->callTool('newsfeeds_categories_delete', ['id' => $feedCat['id']]));

		$this->assertNotFound($this->callTool('contact_categories_read', ['id' => $feedCat['id']]));
	}
}
