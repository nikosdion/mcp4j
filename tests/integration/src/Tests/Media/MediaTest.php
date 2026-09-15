<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Media;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the seven `media_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Media\Media}). See `.plans/13-media-joomlaupdate.md`
 * for the coverage this class is meant to satisfy.
 *
 * A single unique folder (under the first `local` adapter) is created in {@see setUpTestFolder()} and torn
 * down in {@see tearDown()}, so every test method shares one fixture rather than creating its own —
 * `MediaModel::getFiles()` recurses, so leftover clutter from an earlier failed run would otherwise leak
 * into every later assertion.
 */
class MediaTest extends AbstractToolTestCase
{
	/**
	 * A known-valid 1x1 PNG (from the plan).
	 */
	private const PIXEL_PNG_1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

	/**
	 * A different, larger (4x4) valid PNG — used to prove media_files_update actually changed the bytes on
	 * disk (its size differs from {@see self::PIXEL_PNG_1}'s 68 bytes).
	 */
	private const PIXEL_PNG_2 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQI12PkOiHHAANMDEgANwcAL9AA+Pb82q8AAAAASUVORK5CYII=';

	private string $adapter = '';

	private string $folder = '';

	protected function setUp(): void
	{
		parent::setUp();

		$this->setUpTestFolder();
	}

	private function setUpTestFolder(): void
	{
		$adapters = $this->callToolOk('media_adapters_list');

		$local = null;

		foreach ($adapters['items'] as $item)
		{
			if (($item['provider_id'] ?? null) === 'local')
			{
				$local = $item;
				break;
			}
		}

		$this->assertNotNull($local, 'No local media adapter is available on this test site: ' . json_encode($adapters));

		$this->adapter = (string) $local['id'];
		$this->folder  = $this->adapter . ':/mcp4j-e2e-' . bin2hex(random_bytes(4));

		$created = $this->callToolOk('media_files_create', ['path' => $this->folder, 'content' => '']);
		$this->assertSame('dir', $created['type'] ?? null, 'Expected media_files_create with empty content to create a folder: ' . json_encode($created));

		$this->registerCleanup(function (): void {
			// Delete any files left in the folder first (LocalAdapter::delete() removes a non-empty
			// folder outright via Folder::delete(), so this is defensive rather than strictly required).
			$listing = $this->callTool('media_files_list', ['path' => $this->folder]);

			if ($listing['isError'] === false)
			{
				foreach ($listing['data']['items'] ?? [] as $item)
				{
					if (($item['type'] ?? null) === 'file')
					{
						$this->callTool('media_files_delete', ['path' => $item['path']]);
					}
				}
			}

			$this->callTool('media_files_delete', ['path' => $this->folder]);
		});
	}

	public function testAdaptersListAndRead(): void
	{
		$adapters = $this->callToolOk('media_adapters_list');
		$this->assertListShape($adapters);
		$this->assertNotSame([], $adapters['items']);

		foreach ($adapters['items'] as $item)
		{
			$this->assertKeysPresent($item, ['id', 'provider_id', 'name', 'path']);
			$this->assertIsString($item['id']);
		}

		$read = $this->callToolOk('media_adapters_read', ['id' => $this->adapter]);
		$this->assertSame($this->adapter, $read['id']);
		$this->assertSame('local', $read['provider_id']);

		$this->assertNotFound($this->callTool('media_adapters_read', ['id' => 'nope-nope']));
	}

	public function testFileLifecycle(): void
	{
		$path = $this->folder . '/pixel.png';

		$created = $this->callToolOk('media_files_create', ['path' => $path, 'content' => self::PIXEL_PNG_1]);
		$this->assertSame('file', $created['type']);
		$this->assertSame('png', $created['extension']);
		$this->assertGreaterThan(0, $created['size']);
		$this->assertSame(1, $created['width']);
		$this->assertNotEmpty($created['url']);
		$this->assertFalse(str_starts_with($created['url'], '/var/www'), 'url must not be a server filesystem path: ' . $created['url']);
		$this->assertFalse(str_starts_with($created['path'], '/var/www'), 'path must not be a server filesystem path: ' . $created['path']);

		$read = $this->callToolOk('media_files_read', ['path' => $path]);
		$this->assertSame('png', $read['extension']);
		$this->assertGreaterThan(0, $read['size']);
		$this->assertSame(1, $read['width']);
		$this->assertNotEmpty($read['url']);
		$this->assertKeysAbsent($read, ['content', 'tempUrl']);

		$listing = $this->callToolOk('media_files_list', ['path' => $this->folder]);
		$this->assertListShape($listing);
		$names = array_column($listing['items'], 'name');
		$this->assertContains('pixel.png', $names);

		$paged = $this->callToolOk('media_files_list', ['path' => $this->folder, 'pageLimit' => 1]);
		$this->assertCount(1, $paged['items']);
		$this->assertGreaterThanOrEqual(1, $paged['pagination']['total']);

		// Creating the same file again, without override, must fail.
		$duplicate = $this->callToolError('media_files_create', ['path' => $path, 'content' => self::PIXEL_PNG_1]);
		$this->assertStringContainsString('already exists', $duplicate);

		// Update changes the bytes; size must differ from the original.
		$updated = $this->callToolOk('media_files_update', ['path' => $path, 'content' => self::PIXEL_PNG_2]);
		$this->assertNotSame($created['size'], $updated['size']);

		$deleted = $this->callToolOk('media_files_delete', ['path' => $path]);
		$this->assertSame(['deleted' => true, 'path' => $path], $deleted);

		$this->assertNotFound($this->callTool('media_files_read', ['path' => $path]));
	}

	/**
	 * Proves `mediatypes` is widened to every media type (images/audio/video/documents), not just images
	 * — `.txt` is in `doc_extensions`' default value.
	 */
	public function testDocumentExtensionIsAllowedByDefault(): void
	{
		$path = $this->folder . '/notes.txt';

		$created = $this->callToolOk('media_files_create', ['path' => $path, 'content' => 'dGVzdA==']);
		$this->assertSame('txt', $created['extension']);

		$this->callTool('media_files_delete', ['path' => $path]);
	}

	public function testRefusalsWriteNothing(): void
	{
		$listBefore = $this->callToolOk('media_files_list', ['path' => $this->folder]);

		// A disallowed extension.
		$phpResult = $this->callToolError('media_files_create', [
			'path'    => $this->folder . '/evil.php',
			'content' => base64_encode('<?php echo "no"; ?>'),
		]);
		$this->assertStringContainsString('Invalid media path', $phpResult);

		// Path traversal out of the adapter root.
		$traversalResult = $this->callToolError('media_files_create', [
			'path'    => $this->adapter . ':/../mcp4j-escape.png',
			'content' => self::PIXEL_PNG_1,
		]);
		$this->assertStringContainsString('Invalid media path', $traversalResult);

		// Malformed base64.
		$badBase64Result = $this->callToolError('media_files_create', [
			'path'    => $this->folder . '/bad.png',
			'content' => '%%%notbase64',
		]);
		$this->assertStringContainsString('base64', $badBase64Result);

		$listAfter = $this->callToolOk('media_files_list', ['path' => $this->folder]);
		$this->assertSame(
			array_column($listBefore['items'], 'name'),
			array_column($listAfter['items'], 'name'),
			'None of the refused create calls should have written anything to the folder.'
		);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$readResult = $this->callToolAsLowPriv('media_files_list', ['path' => $this->folder]);
		$this->assertPermissionDenied($readResult);

		$path        = $this->folder . '/lowpriv.png';
		$writeResult = $this->callToolAsLowPriv('media_files_create', ['path' => $path, 'content' => self::PIXEL_PNG_1]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was created, as the super user.
		$this->assertNotFound($this->callTool('media_files_read', ['path' => $path]));
	}
}
