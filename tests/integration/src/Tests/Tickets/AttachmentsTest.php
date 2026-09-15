<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the `tickets_attachments_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\Attachments}).
 *
 * Attachments are an Akeeba Ticket System **Pro** feature — every tool in this class answers the tool
 * error "This tool requires Akeeba Ticket System Pro." on an ATS Core install
 * ({@see self::testAtsCoreRefusesEveryTool()}); the rest of this class self-skips cleanly unless a Pro
 * build is present. It also requires the ATS zip in `tests/integration/inbox/extensions/` in the first
 * place — see {@see \Akeeba\Mcp4j\Tests\Integration\Tests\Tickets\CategoriesTest}'s docblock for why this
 * whole class self-skips on the Joomla 4.4 matrix entry and on any run without it.
 */
class AttachmentsTest extends AbstractToolTestCase
{
	private array $ats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(
			[
				'tickets_attachments_list',
				'tickets_attachments_list_for_post',
				'tickets_attachments_read',
				'tickets_attachments_download',
				'tickets_attachments_delete',
				'tickets_tickets_delete',
			]
		);

		$this->ats = AtsFixtures::ensure();

		if (!($this->ats['installed'] ?? false))
		{
			$this->markTestSkipped('Akeeba Ticket System is not installed on this site.');
		}
	}

	/**
	 * `tickets_attachments_save_to_file` must never be registered — see the Attachments tool class's own
	 * docblock and `.plans/16b-tickets-pro.md` "Deviations from the original" #1.
	 */
	public function testSaveToFileToolIsNotRegistered(): void
	{
		$this->assertNotContains('tickets_attachments_save_to_file', $this->listToolNames());
	}

	public function testAtsCoreRefusesEveryTool(): void
	{
		if ($this->ats['pro'] ?? false)
		{
			$this->markTestSkipped('This test only applies when ATS Pro is not present.');
		}

		$expected = 'This tool requires Akeeba Ticket System Pro.';

		$this->assertSame($expected, $this->callToolError('tickets_attachments_list'));
		$this->assertSame($expected, $this->callToolError('tickets_attachments_list_for_post', ['postId' => 1]));
		$this->assertSame($expected, $this->callToolError('tickets_attachments_read', ['id' => 1]));
		$this->assertSame($expected, $this->callToolError('tickets_attachments_download', ['id' => 1]));
		$this->assertSame($expected, $this->callToolError('tickets_attachments_delete', ['id' => 1]));
	}

	/**
	 * One flowing scenario across a single, freshly provisioned fixture set (a ticket + post with four
	 * attachments: text, PNG, an oversized text file, and a ZIP archive) rather than several independent
	 * test methods — steps 8 onward depend on the state steps 1-7 observed (in particular, step 8 deletes
	 * the "text" attachment other steps already read), so splitting this into order-independent test
	 * methods would either duplicate the (relatively expensive) fixture provisioning many times over or
	 * silently depend on PHPUnit's declaration-order execution. Numbered comments below correspond to the
	 * plan's own numbered assertion list.
	 */
	public function testAttachmentsLifecycle(): void
	{
		if (!($this->ats['pro'] ?? false))
		{
			$this->markTestSkipped('Requires Akeeba Ticket System Pro.');
		}

		$fixture  = AtsAttachmentsFixtures::create($this->ats['categoryId']);
		$ticketId = $fixture['ticketId'];
		$postId   = $fixture['postId'];
		$ids      = $fixture['attachments'];

		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $ticketId]));

		// 1. list_for_post -> 4 items, original_filenames match, no mangled_filename.
		$listForPost = $this->callToolOk('tickets_attachments_list_for_post', ['postId' => $postId]);
		$this->assertCount(4, $listForPost['items']);

		$names = array_column($listForPost['items'], 'original_filename');
		sort($names);
		$this->assertSame(['big.log', 'bundle.zip', 'hello.txt', 'pixel.png'], $names);

		foreach ($listForPost['items'] as $item)
		{
			$this->assertArrayNotHasKey('mangled_filename', $item);
		}

		// 2. list(pageLimit: 100) -> list shape; contains the fixture ids (skip containment if total > 100).
		$listAll = $this->callToolOk('tickets_attachments_list', ['pageLimit' => 100]);
		$this->assertListShape($listAll);

		if ($listAll['pagination']['total'] <= 100)
		{
			$allIds = array_column($listAll['items'], 'id');

			foreach ($ids as $id)
			{
				$this->assertContains($id, $allIds);
			}
		}

		// 3. read(text) -> file_size 17, created_by_info.username admin, no mangled_filename; no
		// filesystem path anywhere in the JSON-encoded result.
		$read = $this->callToolOk('tickets_attachments_read', ['id' => $ids['text']]);
		$this->assertSame(17, $read['file_size']);
		$this->assertSame('admin', $read['created_by_info']['username'] ?? null);
		$this->assertArrayNotHasKey('mangled_filename', $read);

		$encoded = (string) json_encode($read);
		$this->assertStringNotContainsString('/var/www', $encoded);
		$this->assertStringNotContainsString('attachments/', $encoded);

		// 4. download(text) -> files[0] encoding text, content "Hello attachment\n".
		$textDownload = $this->callToolOk('tickets_attachments_download', ['id' => $ids['text']]);
		$this->assertSame('text', $textDownload['files'][0]['encoding']);
		$this->assertSame("Hello attachment\n", $textDownload['files'][0]['content']);

		// 5. download(png) -> encoding base64, decoded bytes equal the fixture, mimeType image/png.
		$pngDownload = $this->callToolOk('tickets_attachments_download', ['id' => $ids['png']]);
		$this->assertSame('base64', $pngDownload['files'][0]['encoding']);
		$this->assertSame('image/png', $pngDownload['mimeType']);

		$expectedPng = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
			true
		);
		$this->assertSame($expectedPng, base64_decode($pngDownload['files'][0]['content'], true));

		// 6. download(big) -> tool error containing "too large to return inline".
		$bigError = $this->callToolError('tickets_attachments_download', ['id' => $ids['big']]);
		$this->assertStringContainsString('too large to return inline', $bigError);

		// 7. download(zip) -> small.txt text with 1024 chars; big.log has "skipped".
		$zipDownload = $this->callToolOk('tickets_attachments_download', ['id' => $ids['zip']]);
		$zipByName   = [];

		foreach ($zipDownload['files'] as $entry)
		{
			$zipByName[$entry['name']] = $entry;
		}

		$this->assertSame('text', $zipByName['small.txt']['encoding']);
		$this->assertSame(1024, strlen($zipByName['small.txt']['content']));
		$this->assertArrayHasKey('skipped', $zipByName['big.log']);

		// 8. delete(text) -> read(text) Not found; list_for_post -> 3 items.
		$deleted = $this->callToolOk('tickets_attachments_delete', ['id' => $ids['text']]);
		$this->assertSame(['deleted' => true, 'id' => $ids['text']], $deleted);

		$this->assertNotFound($this->callTool('tickets_attachments_read', ['id' => $ids['text']]));

		$listAfterDelete = $this->callToolOk('tickets_attachments_list_for_post', ['postId' => $postId]);
		$this->assertCount(3, $listAfterDelete['items']);

		// 9. read(999999), download(999999), list_for_post(999999) -> Not found.
		$this->assertNotFound($this->callTool('tickets_attachments_read', ['id' => 999999]));
		$this->assertNotFound($this->callTool('tickets_attachments_download', ['id' => 999999]));
		$this->assertNotFound($this->callTool('tickets_attachments_list_for_post', ['postId' => 999999]));
	}

	/**
	 * 10. Low-priv: tickets_attachments_list, tickets_attachments_download(zip) -> Permission denied.
	 *
	 * requireAtsManager() is checked before requireAtsPro() in every tool method, so a low-privilege user
	 * is refused identically whether or not ATS Pro is present — no fixture, and no Pro gate, is needed
	 * here.
	 */
	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_attachments_list'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_attachments_download', ['id' => 1]));
	}
}
