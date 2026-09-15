<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Reads an ATS attachment file off disk and turns it into the JSON-encodable shape
 * `tickets_attachments_download` returns — a single-file entry, or one entry per member of a ZIP archive.
 *
 * This framework's `ToolResult` (plan 01) only ever emits a single JSON text block, unlike the original
 * `joomla-mcp-php` project, which could return MCP `ImageContent`/multiple `TextContent` blocks. There is
 * therefore no vision hand-off here — every byte, image or otherwise, is base64-encoded inside the JSON
 * result rather than becoming a native `image` content block a vision-capable client would render
 * automatically. See `.plans/16b-tickets-pro.md` "Deviations from the original" #2.
 *
 * Two independent inline-size guards protect against a small ZIP expanding to something huge: a
 * per-entry cap ({@see self::MAX_INLINE_BYTES}, same limit the original used, and the same limit applied
 * to a non-ZIP attachment) and a running total across every entry actually inlined
 * ({@see self::MAX_TOTAL_BYTES} — the original had no equivalent). A ZIP entry's *stated* uncompressed
 * size (`ZipArchive::statIndex()`) is attacker-controlled, so every read from the archive additionally
 * caps the number of bytes actually requested from `getFromIndex()` and re-checks the real length
 * afterwards, rather than trusting the header.
 *
 * Pure: no Joomla (the PHP `zip` extension is a PHP core/PECL dependency, not a Joomla one).
 *
 * @since  0.0.1
 */
final class AttachmentInliner
{
	/**
	 * Maximum size, in bytes, of a single file (a whole non-ZIP attachment, or one ZIP entry) this class
	 * will inline. Same limit the original `joomla-mcp-php` project used.
	 */
	public const MAX_INLINE_BYTES = 204800; // 200 KiB

	/**
	 * Maximum combined size, in bytes, of every entry actually inlined out of one ZIP archive — a guard
	 * the original did not have, added because a ZIP that individually satisfies the per-entry limit can
	 * still contain enough small-enough entries to add up to an unreasonable total.
	 */
	public const MAX_TOTAL_BYTES = 1048576; // 1 MiB

	/**
	 * @return  array{filename: string, mimeType: string, size: int, files: list<array<string, mixed>>, note?: string}
	 * @throws  \LengthException  A non-ZIP attachment exceeds {@see self::MAX_INLINE_BYTES}.
	 * @throws  \RuntimeException  The `zip` extension is unavailable, or the archive can't be opened.
	 */
	public static function inline(string $path, string $mimeType, string $filename): array
	{
		if (self::isZip($mimeType, $filename))
		{
			return self::inlineZip($path, $mimeType, $filename);
		}

		return self::inlineSingle($path, $mimeType, $filename);
	}

	/**
	 * @throws  \LengthException
	 */
	private static function inlineSingle(string $path, string $mimeType, string $filename): array
	{
		$size = (int) (@filesize($path) ?: 0);

		if ($size > self::MAX_INLINE_BYTES)
		{
			throw new \LengthException(sprintf(
				'The attachment is %s bytes, too large to return inline (limit: %s bytes). '
				. "Download it from the site's administrator backend instead.",
				number_format($size),
				number_format(self::MAX_INLINE_BYTES)
			));
		}

		$content = (string) (@file_get_contents($path) ?: '');

		return [
			'filename' => $filename,
			'mimeType' => $mimeType,
			'size'     => $size,
			'files'    => [self::buildEntry($filename, $mimeType, $content)],
		];
	}

	/**
	 * @throws  \RuntimeException
	 */
	private static function inlineZip(string $path, string $mimeType, string $filename): array
	{
		if (!class_exists(\ZipArchive::class))
		{
			throw new \RuntimeException('ZIP support (the PHP zip extension) is not available on this server.');
		}

		$zip = new \ZipArchive();

		if ($zip->open($path, \ZipArchive::RDONLY) !== true)
		{
			throw new \RuntimeException('Failed to open the ZIP archive.');
		}

		$files      = [];
		$totalBytes = 0;

		try
		{
			for ($i = 0; $i < $zip->numFiles; $i++)
			{
				$name = $zip->getNameIndex($i);

				if ($name === false || str_ends_with($name, '/'))
				{
					continue;
				}

				$stat           = $zip->statIndex($i);
				$statedSize     = (int) ($stat['size'] ?? 0);
				$tooLargeMsg    = sprintf(
					'too large to return inline (limit: %s bytes)',
					number_format(self::MAX_INLINE_BYTES)
				);

				if ($statedSize > self::MAX_INLINE_BYTES)
				{
					$files[] = ['name' => $name, 'size' => $statedSize, 'skipped' => $tooLargeMsg];

					continue;
				}

				if ($totalBytes + $statedSize > self::MAX_TOTAL_BYTES)
				{
					$files[] = [
						'name'    => $name,
						'size'    => $statedSize,
						'skipped' => sprintf(
							'inline size budget for this archive exhausted (limit: %s bytes)',
							number_format(self::MAX_TOTAL_BYTES)
						),
					];

					continue;
				}

				// Cap what we actually pull from the archive at one byte past the limit — a lying header
				// (statedSize understated) can't make us read an unbounded amount into memory.
				$bytes = $zip->getFromIndex($i, self::MAX_INLINE_BYTES + 1);

				if ($bytes === false)
				{
					$files[] = ['name' => $name, 'size' => $statedSize, 'skipped' => 'unreadable'];

					continue;
				}

				if (strlen($bytes) > self::MAX_INLINE_BYTES)
				{
					$files[] = ['name' => $name, 'size' => $statedSize, 'skipped' => $tooLargeMsg];

					continue;
				}

				$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
				$entryMime = self::mimeFromExtension($extension);

				$files[]    = self::buildEntry($name, $entryMime, $bytes);
				$totalBytes += strlen($bytes);
			}
		}
		finally
		{
			$zip->close();
		}

		$result = [
			'filename' => $filename,
			'mimeType' => $mimeType,
			'size'     => (int) (@filesize($path) ?: 0),
			'files'    => $files,
		];

		if ($files === [])
		{
			$result['note'] = 'The ZIP archive is empty.';
		}

		return $result;
	}

	/**
	 * @return  array{name: string, mimeType: string, size: int, encoding: 'text'|'base64', content: string}
	 */
	private static function buildEntry(string $name, string $mimeType, string $content): array
	{
		$isText = self::isTextMime($mimeType) && preg_match('//u', $content) === 1;

		return [
			'name'     => $name,
			'mimeType' => $mimeType,
			'size'     => strlen($content),
			'encoding' => $isText ? 'text' : 'base64',
			'content'  => $isText ? $content : base64_encode($content),
		];
	}

	/**
	 * .php and other source/config/log extensions are treated as `text/plain` so a caller can inspect a
	 * diagnostic file's content directly rather than receiving raw base64. Ported verbatim from the
	 * original `joomla-mcp-php` project's `Attachments::mimeFromExtension()`.
	 */
	public static function mimeFromExtension(string $extension): string
	{
		return match (strtolower($extension))
		{
			'jpg', 'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'txt', 'log', 'csv', 'md',
			'php', 'js', 'css', 'html', 'htm',
			'xml', 'json', 'ini', 'conf', 'cfg',
			'yaml', 'yml', 'sql', 'sh', 'bat' => 'text/plain',
			default => 'application/octet-stream',
		};
	}

	public static function isZip(string $mimeType, string $filename): bool
	{
		$mime = strtolower(trim($mimeType));

		if (in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/x-zip'], true))
		{
			return true;
		}

		return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip';
	}

	public static function isTextMime(string $mimeType): bool
	{
		$mime = strtolower(trim($mimeType));

		if (str_starts_with($mime, 'text/'))
		{
			return true;
		}

		return in_array(
			$mime,
			['application/json', 'application/xml', 'application/xhtml+xml', 'application/javascript'],
			true
		);
	}
}
