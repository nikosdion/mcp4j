<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Banners;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `banners_clients_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Banners\Clients}). See `.plans/08-banners.md` Tests.
 */
class ClientsTest extends AbstractToolTestCase
{
	public function testListShapeAndFilters(): void
	{
		$token = bin2hex(random_bytes(4));

		$needle = 'MCP4j Client Needle ' . $token;
		$hay    = 'MCP4j Client Hay ' . $token;

		$needleClient = $this->callToolOk('banners_clients_create', ['name' => 'Contact', 'contactName' => $needle]);
		$this->registerCleanup(fn () => $this->callTool('banners_clients_delete', ['id' => $needleClient['id']]));

		$hayClient = $this->callToolOk('banners_clients_create', ['name' => 'Contact', 'contactName' => $hay, 'state' => 0]);
		$this->registerCleanup(fn () => $this->callTool('banners_clients_delete', ['id' => $hayClient['id']]));

		$all = $this->callToolOk('banners_clients_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('banners_clients_list', ['filterSearch' => $needle]);
		$this->assertListShape($filtered);
		$names = array_column($filtered['items'], 'name');
		$this->assertContains($needle, $names);
		$this->assertNotContains($hay, $names);

		// filterState: 0 must find the unpublished client and exclude the published one.
		$unpublished = $this->callToolOk('banners_clients_list', ['filterSearch' => $token, 'filterState' => 0]);
		$names       = array_column($unpublished['items'], 'name');
		$this->assertContains($hay, $names);
		$this->assertNotContains($needle, $names);
	}

	public function testFullLifecycle(): void
	{
		$companyName = $this->uniqueName('MCP4j Client Co');

		$created = $this->callToolOk('banners_clients_create', [
			'name'        => 'Jane Doe',
			'contactName' => $companyName,
			'email'       => 'jane.doe@example.com',
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('banners_clients_delete', ['id' => $id]));

		$this->assertSame('Jane Doe', $created['contact'], '"name" (contact name) must round-trip through the "contact" field.');
		$this->assertSame($companyName, $created['name'], '"contactName" (company name) must round-trip through the "name" field.');
		$this->assertSame('jane.doe@example.com', $created['email']);

		$read = $this->callToolOk('banners_clients_read', ['id' => $id]);
		$this->assertSame('Jane Doe', $read['contact']);
		$this->assertSame($companyName, $read['name']);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation']);

		// Partial update: only extraInfo changes — email must be unaffected.
		$updated = $this->callToolOk('banners_clients_update', ['id' => $id, 'extraInfo' => 'Some extra info']);
		$this->assertSame('Some extra info', $updated['extrainfo']);
		$this->assertSame('jane.doe@example.com', $updated['email'], 'A partial update must not touch fields it was not given.');

		$trashed = $this->callToolOk('banners_clients_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['state']);

		$deleted = $this->callToolOk('banners_clients_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('banners_clients_read', ['id' => $id]));
	}

	public function testCreateWithInvalidEmailFails(): void
	{
		$result = $this->callTool('banners_clients_create', [
			'name'        => 'Jane Doe',
			'contactName' => $this->uniqueName('MCP4j Client BadEmail'),
			'email'       => 'not-an-email',
		]);

		$this->assertTrue($result['isError'], 'An invalid email address must be refused: ' . $result['text']);
	}

	public function testCreateWithMetadescFails(): void
	{
		$title  = $this->uniqueName('MCP4j Client Metadesc');
		$result = $this->callTool('banners_clients_create', [
			'name'        => 'Jane Doe',
			'contactName' => $title,
			'metadesc'    => 'x',
		]);

		$this->assertTrue($result['isError'], 'metadesc must be refused for banner clients: ' . $result['text']);
		$this->assertStringContainsString('metadesc', $result['text']);

		// Prove nothing was created.
		$search = $this->callToolOk('banners_clients_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('banners_clients_list');
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Client LowPriv');
		$writeResult = $this->callToolAsLowPriv('banners_clients_create', ['name' => 'Contact', 'contactName' => $title]);
		$this->assertPermissionDenied($writeResult);

		$search = $this->callToolOk('banners_clients_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
