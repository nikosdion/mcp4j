<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Media;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;

/**
 * Pure path/content helpers for the Media tool category: `"<adapter>:<path>"` composition, strict base64
 * decoding, folder-vs-file path detection, the upload size-limit check, and client-side pagination of the
 * media model's "return everything" result.
 *
 * com_media's Web Services API models (`Joomla\Component\Media\Api\Model\*`, see `.plans/13-media-joomlaupdate.md`)
 * have no concept of `list.limit`/`list.start` the way `ListModel` does — `MediaModel::getItems()` always
 * returns the full folder listing — so `media_files_list`'s pagination is applied here, after the fact,
 * exactly like the original `joomla-mcp-php` project's own `page[limit]`/`page[offset]` query parameters
 * did against the JSON:API response.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class MediaPathMapper
{
	/**
	 * Composes the `"<adapter>:<path>"` string `media_files_list` sets as the model's `path` state.
	 *
	 * - `$path` already contains a `:` — an adapter is already encoded in it, so it is returned unchanged
	 *   and `$adapter` is ignored.
	 * - `$adapter` given (and `$path` has no `:`) — `"<adapter>:/" . ltrim($path, '/')`.
	 * - Neither given — `''`, so the model falls back to the default adapter's root.
	 * - Only `$path` given — returned unchanged.
	 */
	public static function combine(?string $path, ?string $adapter): string
	{
		$path = (string) $path;

		if ($path !== '' && str_contains($path, ':'))
		{
			return $path;
		}

		if ($adapter !== null && $adapter !== '')
		{
			return $adapter . ':/' . ltrim($path, '/');
		}

		return $path;
	}

	/**
	 * Strictly decodes base64-encoded tool input, mirroring `MediaController::add()`/`edit()`'s
	 * `base64_decode($json->get('content', '', 'raw'))` except with `$strict = true` — a real corrupt
	 * upload must fail loudly here rather than silently truncate.
	 *
	 * @throws  \InvalidArgumentException  `$content` is not valid base64.
	 */
	public static function decodeContent(string $content): string
	{
		if ($content === '')
		{
			return '';
		}

		$decoded = base64_decode($content, true);

		if ($decoded === false)
		{
			throw new \InvalidArgumentException('The content must be valid base64.');
		}

		return $decoded;
	}

	/**
	 * True when `$path`'s basename (after the last `:` and `/`) has no `.` — com_media's own convention
	 * for "this creates a folder, not a file" (see `MediumModel::save()`'s `$content ? createFile() :
	 * createFolder()` branch).
	 */
	public static function isFolderPath(string $path): bool
	{
		$afterAdapter = $path;
		$colonPos     = strrpos($path, ':');

		if ($colonPos !== false)
		{
			$afterAdapter = substr($path, $colonPos + 1);
		}

		$slashPos = strrpos($afterAdapter, '/');
		$basename = $slashPos !== false ? substr($afterAdapter, $slashPos + 1) : $afterAdapter;

		return !str_contains($basename, '.');
	}

	/**
	 * Pure mirror of `MediaController::checkContent()`'s size checks, evaluated on the already-decoded
	 * byte count rather than the raw `CONTENT_LENGTH` header (we never see the HTTP request `com_media`'s
	 * own controller does) — see `.plans/13-media-joomlaupdate.md`. Any limit that is `0` (or negative) is
	 * treated as "unlimited", matching `com_media`'s own `upload_maxsize` semantics.
	 */
	public static function exceedsSizeLimit(int $bytes, int $uploadMaxsizeMb, int $iniUploadMax, int $iniPostMax): bool
	{
		if ($uploadMaxsizeMb > 0 && $bytes > $uploadMaxsizeMb * 1048576)
		{
			return true;
		}

		if ($iniUploadMax > 0 && $bytes > $iniUploadMax)
		{
			return true;
		}

		if ($iniPostMax > 0 && $bytes > $iniPostMax)
		{
			return true;
		}

		return false;
	}

	/**
	 * Slices an already-shaped item list into a page, using the same limit/offset defaults and bounds as
	 * every other `*_list` tool ({@see Pagination::normalise()}).
	 *
	 * @param   list<array<string, mixed>>  $items  Every item the media model returned (already shaped).
	 *
	 * @return  array{items: list<array<string, mixed>>, pagination: array{total: int, limit: int, offset: int}}
	 */
	public static function paginate(array $items, ?int $limit, ?int $offset): array
	{
		[$limit, $offset] = Pagination::normalise($limit, $offset);

		return [
			'items'      => array_values(array_slice($items, $offset, $limit)),
			'pagination' => [
				'total'  => count($items),
				'limit'  => $limit,
				'offset' => $offset,
			],
		];
	}
}
