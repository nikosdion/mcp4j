<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Redirects;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `redirects_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Redirects\Redirects}). See
 * `.plans/11-overrides-redirects-messages.md` Part B for the coverage this class is meant to satisfy.
 */
class RedirectsTest extends AbstractToolTestCase
{
	private function oldUrl(): string
	{
		return '/mcp-old-' . bin2hex(random_bytes(4));
	}

	private function newUrl(): string
	{
		return '/mcp-new-' . bin2hex(random_bytes(4));
	}

	public function testListShapeAndFilters(): void
	{
		$old = $this->oldUrl();
		$new = $this->newUrl();

		$created = $this->callToolOk('redirects_create', ['oldUrl' => $old, 'newUrl' => $new, 'published' => 0]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('redirects_delete', ['id' => $id]));

		$all = $this->callToolOk('redirects_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('redirects_list', ['filterSearch' => $old]);
		$this->assertListShape($filtered);
		$this->assertCount(1, $filtered['items']);
		$this->assertSame($old, $filtered['items'][0]['old_url']);

		$unpublishedOnly = $this->callToolOk('redirects_list', ['filterSearch' => $old, 'filterState' => 0]);
		$this->assertCount(1, $unpublishedOnly['items']);
		$this->assertSame(0, $unpublishedOnly['items'][0]['published']);
	}

	public function testFullLifecycle(): void
	{
		$old = $this->oldUrl();
		$new = $this->newUrl();

		$created = $this->callToolOk('redirects_create', [
			'oldUrl'     => $old,
			'newUrl'     => $new,
			'statusCode' => 302,
			'comment'    => 'Original comment',
		]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('redirects_delete', ['id' => $id]));

		$this->assertSame(302, $created['header']);
		$this->assertSame($old, $created['old_url']);
		$this->assertSame($new, $created['new_url']);

		$read = $this->callToolOk('redirects_read', ['id' => $id]);
		$this->assertSame(302, $read['header']);
		$this->assertSame('', $read['referer'], 'The original tool wrote statusCode into referer; this port must never do that.');
		$this->assertSame($old, $read['old_url']);

		// Partial update: only "comment" changes — URLs and header must be unaffected.
		$updated = $this->callToolOk('redirects_update', ['id' => $id, 'comment' => 'Updated comment']);
		$this->assertSame('Updated comment', $updated['comment']);
		$this->assertSame($old, $updated['old_url']);
		$this->assertSame($new, $updated['new_url']);
		$this->assertSame(302, $updated['header']);

		$trashed = $this->callToolOk('redirects_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['published']);

		$deleted = $this->callToolOk('redirects_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('redirects_read', ['id' => $id]));
	}

	public function testDuplicateOldUrlAndSameUrlAreRejected(): void
	{
		$old = $this->oldUrl();
		$new = $this->newUrl();

		$created = $this->callToolOk('redirects_create', ['oldUrl' => $old, 'newUrl' => $new]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('redirects_delete', ['id' => $id]));

		$duplicate = $this->callTool('redirects_create', ['oldUrl' => $old, 'newUrl' => $this->newUrl()]);
		$this->assertTrue($duplicate['isError'], 'Expected a tool error: ' . $duplicate['text']);

		$same    = $this->oldUrl();
		$sameUrl = $this->callTool('redirects_create', ['oldUrl' => $same, 'newUrl' => $same]);
		$this->assertTrue($sameUrl['isError'], 'Expected a tool error: ' . $sameUrl['text']);
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('redirects_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$readResult = $this->callToolAsLowPriv('redirects_list');
		$this->assertPermissionDenied($readResult);

		$old         = $this->oldUrl();
		$writeResult = $this->callToolAsLowPriv('redirects_create', ['oldUrl' => $old, 'newUrl' => $this->newUrl()]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was actually created, as the super user.
		$search = $this->callToolOk('redirects_list', ['filterSearch' => $old]);
		$this->assertSame([], $search['items']);
	}
}
