<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Fields;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `fields_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Fields\Fields}).
 */
class FieldsTest extends AbstractToolTestCase
{
	public function testFullLifecycleInArticleContext(): void
	{
		$title = $this->uniqueName('MCP4j Field Lifecycle');

		$created = $this->callToolOk('fields_create', [
			'context' => 'com_content.article',
			'title'   => $title,
			'type'    => 'text',
			'params'  => '{"hint":"x"}',
		]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('fields_delete', ['context' => 'com_content.article', 'id' => $id]));

		$this->assertSame('com_content.article', $created['context']);
		$this->assertSame('text', $created['type']);
		$this->assertSame('x', $created['params']['hint'] ?? null);

		$read = $this->callToolOk('fields_read', ['context' => 'com_content.article', 'id' => $id]);
		$this->assertSame('com_content.article', $read['context']);
		$this->assertSame('text', $read['type']);
		$this->assertSame('x', $read['params']['hint'] ?? null);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation']);

		// Partial update: only "label" changes — "title" must be unaffected.
		$updated = $this->callToolOk('fields_update', [
			'context' => 'com_content.article',
			'id'      => $id,
			'label'   => 'Updated Label',
		]);
		$this->assertSame('Updated Label', $updated['label']);
		$this->assertSame($title, $updated['title'], 'A partial update must not touch fields it was not given.');
		$this->assertSame('com_content.article', $updated['context'], 'Update must never change the stored context.');

		$found = $this->callToolOk('fields_list', ['context' => 'com_content.article', 'filterSearch' => $title]);
		$this->assertListShape($found);
		$this->assertContains($id, array_column($found['items'], 'id'));

		// The field is published (state 1) by default — filterState 0 (unpublished) must not find it.
		$notFound = $this->callToolOk('fields_list', ['context' => 'com_content.article', 'filterSearch' => $title, 'filterState' => 0]);
		$this->assertNotContains($id, array_column($notFound['items'], 'id'));

		$deleted = $this->callToolOk('fields_delete', ['context' => 'com_content.article', 'id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('fields_read', ['context' => 'com_content.article', 'id' => $id]));
	}

	public function testReadingAFieldWithTheWrongContextIsNotFound(): void
	{
		$title = $this->uniqueName('MCP4j Field WrongContext');

		$created = $this->callToolOk('fields_create', [
			'context' => 'com_users.user',
			'title'   => $title,
			'type'    => 'text',
		]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('fields_delete', ['context' => 'com_users.user', 'id' => $id]));

		$this->assertNotFound($this->callTool('fields_read', ['context' => 'com_content.article', 'id' => $id]));
	}

	public function testInvalidAndUnknownContextsAreRejected(): void
	{
		$invalid = $this->callTool('fields_list', ['context' => 'nonsense']);
		$this->assertTrue($invalid['isError']);
		$this->assertStringContainsString('Invalid context', $invalid['text']);

		$unknown = $this->callTool('fields_list', ['context' => 'com_doesnotexist.item']);
		$this->assertNotFound($unknown);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('fields_list', ['context' => 'com_content.article']);
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Field LowPriv');
		$writeResult = $this->callToolAsLowPriv('fields_create', ['context' => 'com_content.article', 'title' => $title, 'type' => 'text']);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was actually created, as the super user.
		$search = $this->callToolOk('fields_list', ['context' => 'com_content.article', 'filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
