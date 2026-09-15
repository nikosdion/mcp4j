<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Menus;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Shared E2E scenarios for the `menus_sitemenus_*` and `menus_adminmenus_*` tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\Menus} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\AdminMenus}) — identical behaviour except for the
 * client id, parameterised here on the tool name prefix. See `MenusTest` and `AdminMenusTest` for the
 * concrete subclasses (PHPUnit skips this abstract class during discovery) and `.plans/06-menus.md` for the
 * scenario list this class implements.
 */
abstract class AbstractMenusToolCase extends AbstractToolTestCase
{
	/**
	 * e.g. "menus_sitemenus" or "menus_adminmenus".
	 */
	abstract protected function prefix(): string;

	/**
	 * The sibling class's prefix, used for the cross-client "Not found" scenario.
	 */
	abstract protected function otherPrefix(): string;

	abstract protected function expectedClientId(): int;

	public function testListShapeAndSearchFilter(): void
	{
		$needle = $this->uniqueName('MCP4j Menu Needle');
		$hay    = $this->uniqueName('MCP4j Menu Hay');

		$needleMenu = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $needle,
			'menutype' => $this->uniqueAlias('mcp'),
		]);
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $needleMenu['id']]));

		$hayMenu = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $hay,
			'menutype' => $this->uniqueAlias('mcp'),
		]);
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $hayMenu['id']]));

		$all = $this->callToolOk($this->prefix() . '_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $needle]);
		$this->assertListShape($filtered);

		$titles = array_column($filtered['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);
	}

	public function testFullLifecycle(): void
	{
		$title    = $this->uniqueName('MCP4j Menu Lifecycle');
		$menutype = $this->uniqueAlias('mcp');

		$created = $this->callToolOk($this->prefix() . '_create', [
			'title'       => $title,
			'menutype'    => $menutype,
			'description' => 'Original description',
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertKeysPresent($created, ['id', 'menutype', 'title', 'description', 'client_id']);
		$this->assertSame($title, $created['title']);
		$this->assertSame($this->expectedClientId(), $created['client_id']);
		$this->assertSame('Original description', $created['description']);

		$read = $this->callToolOk($this->prefix() . '_read', ['id' => $id]);
		$this->assertSame($title, $read['title']);
		$this->assertSame($menutype, $read['menutype'], 'menutype is stringURLSafe()d server-side — read it back rather than echoing the input.');
		$this->assertSame('Original description', $read['description']);
		$this->assertSame($this->expectedClientId(), $read['client_id']);

		// Partial update: only "description" changes — "title" must be unaffected.
		$updated = $this->callToolOk($this->prefix() . '_update', ['id' => $id, 'description' => 'Updated description']);
		$this->assertSame('Updated description', $updated['description']);
		$this->assertSame($title, $updated['title'], 'A partial update must not touch fields it was not given.');

		$deleted = $this->callToolOk($this->prefix() . '_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => $id]));
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => 999999999]));
	}

	public function testCrossClientReadIsNotFound(): void
	{
		$title    = $this->uniqueName('MCP4j Menu CrossClient');
		$menutype = $this->uniqueAlias('mcp');

		$created = $this->callToolOk($this->prefix() . '_create', ['title' => $title, 'menutype' => $menutype]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertNotFound($this->callTool($this->otherPrefix() . '_read', ['id' => $id]));
	}

	public function testCreateWithReservedMenutypeMainFails(): void
	{
		$result = $this->callTool($this->prefix() . '_create', [
			'title'    => $this->uniqueName('MCP4j Menu Reserved'),
			'menutype' => 'main',
		]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('reserved', strtolower($result['text']));
	}

	public function testLowPrivilegeUserIsRefusedListAndCreate(): void
	{
		$listResult = $this->callToolAsLowPriv($this->prefix() . '_list');
		$this->assertPermissionDenied($listResult);

		$title       = $this->uniqueName('MCP4j Menu LowPriv');
		$writeResult = $this->callToolAsLowPriv($this->prefix() . '_create', [
			'title'    => $title,
			'menutype' => $this->uniqueAlias('mcp'),
		]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was actually created, as the super user.
		$search = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
