<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Contact;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `contact_*` CRUD tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Contact\Contacts}). See
 * `.plans/09-contact-newsfeeds.md` Tests for the coverage this class is meant to satisfy.
 * `contact_form_submit` is covered separately in `ContactFormSubmitTest`.
 */
class ContactsTest extends AbstractToolTestCase
{
	private function fixtureCategory(): array
	{
		$category = $this->callToolOk('contact_categories_create', ['title' => $this->uniqueName('MCP4j Contact Fixture Cat')]);
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $category['id']]));

		return $category;
	}

	private function fixtureTag(): array
	{
		$tag = $this->callToolOk('tags_create', ['title' => $this->uniqueName('MCP4j Contact Fixture Tag')]);
		$this->registerCleanup(fn () => $this->callTool('tags_delete', ['id' => $tag['id']]));

		return $tag;
	}

	public function testListShapeAndFiltersIncludePublishedAndCatid(): void
	{
		$category = $this->fixtureCategory();

		$needle  = $this->uniqueName('MCP4j Contact Needle');
		$contact = $this->callToolOk('contact_create', ['name' => $needle, 'catId' => $category['id']]);
		$this->registerCleanup(fn () => $this->callTool('contact_delete', ['id' => $contact['id']]));

		$hay      = $this->uniqueName('MCP4j Contact Hay');
		$otherCat = $this->fixtureCategory();
		$contact2 = $this->callToolOk('contact_create', ['name' => $hay, 'catId' => $otherCat['id']]);
		$this->registerCleanup(fn () => $this->callTool('contact_delete', ['id' => $contact2['id']]));

		$all = $this->callToolOk('contact_list');
		$this->assertListShape($all);

		$byCategory = $this->callToolOk('contact_list', ['filterCategory' => $category['id']]);
		$names      = array_column($byCategory['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		$bySearch = $this->callToolOk('contact_list', ['filterSearch' => $needle]);
		$names    = array_column($bySearch['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		foreach ($byCategory['items'] as $item)
		{
			$this->assertArrayHasKey('published', $item);
			$this->assertArrayHasKey('catid', $item);
		}
	}

	public function testFullLifecycleAndStateVsPublishedRegressionGuard(): void
	{
		$category = $this->fixtureCategory();
		$tag      = $this->fixtureTag();

		$created = $this->callToolOk('contact_create', [
			'name'      => $this->uniqueName('MCP4j Contact Lifecycle'),
			'catId'     => $category['id'],
			'email'     => 'info@example.com',
			'state'     => 'Attica',
			'telephone' => '555-1234',
			'tags'      => [$tag['id']],
		]);
		$id = $created['id'];

		$this->assertSame('info@example.com', $created['email_to']);
		$this->assertSame('Attica', $created['state']);
		$this->assertSame('555-1234', $created['telephone']);
		$this->assertSame([$tag['id']], $created['tags']);

		// Regression guard: "state" (address province) must never be confused with "published" (publishing
		// state) — a fresh contact_create (published defaults to 1) must show state = "Attica", published = 1.
		$read = $this->callToolOk('contact_read', ['id' => $id]);
		$this->assertSame('Attica', $read['state']);
		$this->assertSame(1, $read['published']);

		$updated = $this->callToolOk('contact_update', ['id' => $id, 'telephone' => '555-9999']);
		$this->assertSame('555-9999', $updated['telephone']);
		$this->assertSame($created['name'], $updated['name'], 'Updating telephone only must leave name unchanged.');
		$this->assertSame($created['email_to'], $updated['email_to'], 'Updating telephone only must leave email_to unchanged.');
		$this->assertSame($created['state'], $updated['state'], 'Updating telephone only must leave the address state unchanged.');
		$this->assertSame($created['tags'], $updated['tags'], 'Updating telephone only must leave tags unchanged.');

		$trashed = $this->callToolOk('contact_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['published']);

		$deleted = $this->callToolOk('contact_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('contact_read', ['id' => $id]));
		$this->assertNotFound($this->callTool('contact_delete', ['id' => $id]));
	}

	public function testCreateWithNonexistentCategoryIsNotFound(): void
	{
		$result = $this->callTool('contact_create', [
			'name'  => $this->uniqueName('MCP4j Contact BadCat'),
			'catId' => 999999,
		]);
		$this->assertNotFound($result, 'contact_create with a nonexistent catId must fail: ' . $result['text']);

		$search = $this->callToolOk('contact_categories_list', ['filterSearch' => '999999']);
		$this->assertSame([], $search['items'], 'ContactModel::save() must never be allowed to auto-create a category named after the number.');
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('contact_list');
		$this->assertPermissionDenied($readResult);

		$category    = $this->fixtureCategory();
		$name        = $this->uniqueName('MCP4j Contact LowPriv');
		$writeResult = $this->callToolAsLowPriv('contact_create', ['name' => $name, 'catId' => $category['id']]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('contact_list', ['filterSearch' => $name]);
		$this->assertSame([], $search['items']);
	}

	public function testOutputWhitelistExcludesParamsAndAssetId(): void
	{
		$category = $this->fixtureCategory();

		$created = $this->callToolOk('contact_create', [
			'name'  => $this->uniqueName('MCP4j Contact Whitelist'),
			'catId' => $category['id'],
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('contact_delete', ['id' => $id]));

		$this->assertKeysAbsent($created, ['params', 'asset_id', 'associations']);

		$read = $this->callToolOk('contact_read', ['id' => $id]);
		$this->assertKeysAbsent($read, ['params', 'asset_id', 'associations']);

		$list = $this->callToolOk('contact_list', ['filterCategory' => $category['id']]);
		foreach ($list['items'] as $item)
		{
			$this->assertKeysAbsent($item, ['params', 'asset_id', 'associations']);
		}
	}
}
