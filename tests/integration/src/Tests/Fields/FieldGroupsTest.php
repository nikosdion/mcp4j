<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Fields;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `fields_groups_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Fields\FieldGroups}).
 */
class FieldGroupsTest extends AbstractToolTestCase
{
	public function testFullLifecycleInArticleContext(): void
	{
		$title = $this->uniqueName('MCP4j Field Group Lifecycle');

		$created = $this->callToolOk('fields_groups_create', [
			'context' => 'com_content.article',
			'title'   => $title,
			'note'    => 'Original note',
		]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('fields_groups_delete', ['context' => 'com_content.article', 'id' => $id]));

		$this->assertSame('com_content.article', $created['context']);
		$this->assertSame('Original note', $created['note']);

		$read = $this->callToolOk('fields_groups_read', ['context' => 'com_content.article', 'id' => $id]);
		$this->assertSame('com_content.article', $read['context']);
		$this->assertSame('Original note', $read['note']);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation']);

		// Partial update: only "note" changes — "title" must be unaffected.
		$updated = $this->callToolOk('fields_groups_update', [
			'context' => 'com_content.article',
			'id'      => $id,
			'note'    => 'Updated note',
		]);
		$this->assertSame('Updated note', $updated['note']);
		$this->assertSame($title, $updated['title'], 'A partial update must not touch fields it was not given.');
		$this->assertSame('com_content.article', $updated['context'], 'Update must never change the stored context.');

		$found = $this->callToolOk('fields_groups_list', ['context' => 'com_content.article', 'filterSearch' => $title]);
		$this->assertListShape($found);
		$this->assertContains($id, array_column($found['items'], 'id'));

		// Assign a field to this group and verify it round-trips.
		$fieldTitle = $this->uniqueName('MCP4j Field InGroup');
		$field      = $this->callToolOk('fields_create', [
			'context'  => 'com_content.article',
			'title'    => $fieldTitle,
			'type'     => 'text',
			'group_id' => $id,
		]);
		$fieldId = $field['id'];

		// Clean up the field before the group.
		$this->registerCleanup(fn () => $this->callTool('fields_delete', ['context' => 'com_content.article', 'id' => $fieldId]));

		$readField = $this->callToolOk('fields_read', ['context' => 'com_content.article', 'id' => $fieldId]);
		$this->assertSame($id, $readField['group_id']);

		$this->callToolOk('fields_delete', ['context' => 'com_content.article', 'id' => $fieldId]);

		$deleted = $this->callToolOk('fields_groups_delete', ['context' => 'com_content.article', 'id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('fields_groups_read', ['context' => 'com_content.article', 'id' => $id]));
	}

	public function testReadingAFieldGroupWithTheWrongContextIsNotFound(): void
	{
		$title = $this->uniqueName('MCP4j Field Group WrongContext');

		$created = $this->callToolOk('fields_groups_create', ['context' => 'com_users.user', 'title' => $title]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('fields_groups_delete', ['context' => 'com_users.user', 'id' => $id]));

		$this->assertNotFound($this->callTool('fields_groups_read', ['context' => 'com_content.article', 'id' => $id]));
	}

	public function testInvalidAndUnknownContextsAreRejected(): void
	{
		$invalid = $this->callTool('fields_groups_list', ['context' => 'nonsense']);
		$this->assertTrue($invalid['isError']);
		$this->assertStringContainsString('Invalid context', $invalid['text']);

		$unknown = $this->callTool('fields_groups_list', ['context' => 'com_doesnotexist.item']);
		$this->assertNotFound($unknown);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('fields_groups_list', ['context' => 'com_content.article']);
		$this->assertPermissionDenied($readResult);

		$title       = $this->uniqueName('MCP4j Field Group LowPriv');
		$writeResult = $this->callToolAsLowPriv('fields_groups_create', ['context' => 'com_content.article', 'title' => $title]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was actually created, as the super user.
		$search = $this->callToolOk('fields_groups_list', ['context' => 'com_content.article', 'filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
