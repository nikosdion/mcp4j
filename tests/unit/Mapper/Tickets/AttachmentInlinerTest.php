<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\AttachmentInliner;
use PHPUnit\Framework\TestCase;

class AttachmentInlinerTest extends TestCase
{
	/**
	 * A minimal valid 1x1 transparent PNG, embedded as a base64 literal so the test doesn't depend on any
	 * fixture file on disk.
	 */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/mcp4j-attachment-inliner-' . bin2hex(random_bytes(6));

		mkdir($this->dir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->dir);

		parent::tearDown();
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		foreach (scandir($dir) ?: [] as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$path = $dir . '/' . $entry;

			is_dir($path) ? $this->removeDirectory($path) : unlink($path);
		}

		rmdir($dir);
	}

	private function writeFile(string $name, string $content): string
	{
		$path = $this->dir . '/' . $name;

		file_put_contents($path, $content);

		return $path;
	}

	// -----------------------------------------------------------------------------------------------------
	// Single (non-ZIP) files
	// -----------------------------------------------------------------------------------------------------

	public function testTextFileReturnsOneTextEntryWithExactContent(): void
	{
		$path = $this->writeFile('hello.txt', "Hello attachment\n");

		$result = AttachmentInliner::inline($path, 'text/plain', 'hello.txt');

		$this->assertSame('hello.txt', $result['filename']);
		$this->assertSame('text/plain', $result['mimeType']);
		$this->assertSame(17, $result['size']);
		$this->assertCount(1, $result['files']);

		$entry = $result['files'][0];
		$this->assertSame('hello.txt', $entry['name']);
		$this->assertSame('text', $entry['encoding']);
		$this->assertSame("Hello attachment\n", $entry['content']);
	}

	public function testPngFileReturnsBase64EntryThatDecodesBack(): void
	{
		$bytes = base64_decode(self::PNG_BASE64, true);
		$path  = $this->writeFile('pixel.png', $bytes);

		$result = AttachmentInliner::inline($path, 'image/png', 'pixel.png');

		$entry = $result['files'][0];
		$this->assertSame('base64', $entry['encoding']);
		$this->assertSame($bytes, base64_decode($entry['content'], true));
		$this->assertSame('image/png', $result['mimeType']);
	}

	public function testInvalidUtf8TextPlainFileIsBase64Encoded(): void
	{
		// 0xFF 0xFE is not valid UTF-8.
		$path = $this->writeFile('binary.txt', "\xFF\xFE\x00\x01");

		$result = AttachmentInliner::inline($path, 'text/plain', 'binary.txt');

		$this->assertSame('base64', $result['files'][0]['encoding']);
	}

	public function testFileOfExactlyMaxInlineBytesIsReturned(): void
	{
		$content = str_repeat('a', AttachmentInliner::MAX_INLINE_BYTES);
		$path    = $this->writeFile('exact.txt', $content);

		$result = AttachmentInliner::inline($path, 'text/plain', 'exact.txt');

		$this->assertSame(AttachmentInliner::MAX_INLINE_BYTES, $result['size']);
		$this->assertSame($content, $result['files'][0]['content']);
	}

	public function testFileOverMaxInlineBytesThrows(): void
	{
		$content = str_repeat('a', AttachmentInliner::MAX_INLINE_BYTES + 1);
		$path    = $this->writeFile('toobig.txt', $content);

		try
		{
			AttachmentInliner::inline($path, 'text/plain', 'toobig.txt');
			$this->fail('Expected a LengthException.');
		}
		catch (\LengthException $e)
		{
			$this->assertStringContainsString('204,800', $e->getMessage());
			$this->assertStringNotContainsString('save_to_file', $e->getMessage());
		}
	}

	// -----------------------------------------------------------------------------------------------------
	// ZIP archives
	// -----------------------------------------------------------------------------------------------------

	private function requireZip(): void
	{
		if (!class_exists(\ZipArchive::class))
		{
			$this->markTestSkipped('The PHP zip extension is not available.');
		}
	}

	private function buildZip(string $name, array $entries): string
	{
		$path = $this->dir . '/' . $name;
		$zip  = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE);

		foreach ($entries as $entryName => $content)
		{
			if ($content === null)
			{
				// A directory entry.
				$zip->addEmptyDir(rtrim($entryName, '/'));

				continue;
			}

			$zip->addFromString($entryName, $content);
		}

		$zip->close();

		return $path;
	}

	public function testZipWithSmallAndTooLargeEntryAndDirectory(): void
	{
		$this->requireZip();

		$path = $this->buildZip(
			'bundle.zip',
			[
				'dir/'    => null,
				'small.txt' => str_repeat('a', 1024),
				'big.log'   => str_repeat('x', 300 * 1024),
			]
		);

		$result = AttachmentInliner::inline($path, 'application/zip', 'bundle.zip');

		$this->assertCount(2, $result['files']);

		$byName = [];

		foreach ($result['files'] as $entry)
		{
			$byName[$entry['name']] = $entry;
		}

		$this->assertArrayHasKey('small.txt', $byName);
		$this->assertSame('text', $byName['small.txt']['encoding']);
		$this->assertSame(str_repeat('a', 1024), $byName['small.txt']['content']);

		$this->assertArrayHasKey('big.log', $byName);
		$this->assertArrayHasKey('skipped', $byName['big.log']);
		$this->assertStringContainsString('too large', $byName['big.log']['skipped']);
	}

	public function testZipWithSixEntriesSkipsTheSixthOnTotalBudget(): void
	{
		$this->requireZip();

		$entries = [];

		for ($i = 1; $i <= 6; $i++)
		{
			$entries["entry{$i}.txt"] = str_repeat('a', AttachmentInliner::MAX_INLINE_BYTES);
		}

		$path = $this->buildZip('six.zip', $entries);

		$result = AttachmentInliner::inline($path, 'application/zip', 'six.zip');

		$this->assertCount(6, $result['files']);

		for ($i = 1; $i <= 5; $i++)
		{
			$this->assertArrayNotHasKey('skipped', $result['files'][$i - 1], "entry{$i}.txt should not be skipped");
		}

		$this->assertArrayHasKey('skipped', $result['files'][5]);
		$this->assertStringContainsString('budget', $result['files'][5]['skipped']);
	}

	public function testEmptyZipReturnsEmptyFilesAndNote(): void
	{
		$this->requireZip();

		// ZipArchive refuses to write a file at all for an archive with zero entries (its close() call
		// silently no-ops), so a genuinely empty ZIP has to be written as raw bytes: the minimal valid
		// ZIP is nothing but a 22-byte "End Of Central Directory" record.
		$path = $this->writeFile('empty.zip', "PK\x05\x06" . str_repeat("\x00", 18));

		$result = AttachmentInliner::inline($path, 'application/zip', 'empty.zip');

		$this->assertSame([], $result['files']);
		$this->assertSame('The ZIP archive is empty.', $result['note']);
	}

	public function testCorruptZipThrows(): void
	{
		$this->requireZip();

		$path = $this->writeFile('corrupt.zip', 'this is not a zip file');

		$this->expectException(\RuntimeException::class);

		AttachmentInliner::inline($path, 'application/zip', 'corrupt.zip');
	}

	// -----------------------------------------------------------------------------------------------------
	// isZip()
	// -----------------------------------------------------------------------------------------------------

	public function testIsZipByMimeType(): void
	{
		$this->assertTrue(AttachmentInliner::isZip('application/zip', 'file.dat'));
		$this->assertTrue(AttachmentInliner::isZip('application/x-zip-compressed', 'file.dat'));
		$this->assertTrue(AttachmentInliner::isZip('application/x-zip', 'file.dat'));
		$this->assertTrue(AttachmentInliner::isZip('APPLICATION/ZIP', 'file.dat'));
	}

	public function testIsZipByExtension(): void
	{
		$this->assertTrue(AttachmentInliner::isZip('application/octet-stream', 'archive.zip'));
		$this->assertTrue(AttachmentInliner::isZip('application/octet-stream', 'archive.ZIP'));
		$this->assertFalse(AttachmentInliner::isZip('application/octet-stream', 'archive.rar'));
	}

	// -----------------------------------------------------------------------------------------------------
	// mimeFromExtension()
	// -----------------------------------------------------------------------------------------------------

	public function testMimeFromExtensionTable(): void
	{
		$this->assertSame('image/jpeg', AttachmentInliner::mimeFromExtension('jpg'));
		$this->assertSame('image/jpeg', AttachmentInliner::mimeFromExtension('jpeg'));
		$this->assertSame('image/png', AttachmentInliner::mimeFromExtension('png'));
		$this->assertSame('image/gif', AttachmentInliner::mimeFromExtension('gif'));
		$this->assertSame('image/webp', AttachmentInliner::mimeFromExtension('webp'));
		$this->assertSame('image/svg+xml', AttachmentInliner::mimeFromExtension('svg'));

		foreach (['txt', 'log', 'csv', 'md', 'php', 'js', 'css', 'html', 'htm', 'xml', 'json', 'ini', 'conf', 'cfg', 'yaml', 'yml', 'sql', 'sh', 'bat'] as $ext)
		{
			$this->assertSame('text/plain', AttachmentInliner::mimeFromExtension($ext), "extension: $ext");
		}

		$this->assertSame('application/octet-stream', AttachmentInliner::mimeFromExtension('exe'));
		$this->assertSame('application/octet-stream', AttachmentInliner::mimeFromExtension(''));
	}

	// -----------------------------------------------------------------------------------------------------
	// isTextMime()
	// -----------------------------------------------------------------------------------------------------

	public function testIsTextMime(): void
	{
		$this->assertTrue(AttachmentInliner::isTextMime('text/plain'));
		$this->assertTrue(AttachmentInliner::isTextMime('text/html'));
		$this->assertTrue(AttachmentInliner::isTextMime('application/json'));
		$this->assertTrue(AttachmentInliner::isTextMime('application/xml'));
		$this->assertTrue(AttachmentInliner::isTextMime('application/xhtml+xml'));
		$this->assertTrue(AttachmentInliner::isTextMime('application/javascript'));
		$this->assertFalse(AttachmentInliner::isTextMime('image/png'));
		$this->assertFalse(AttachmentInliner::isTextMime('application/zip'));
	}
}
