<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Content;

defined('_JEXEC') || die;

use League\CommonMark\CommonMarkConverter;

/**
 * Converts the Markdown an MCP client sends for an article's intro/full text into the HTML Joomla's
 * `#__content` table actually stores, exactly as the original `joomla-mcp-php` project's
 * `ArticleTextTrait::toHtml()` did (`html_input: allow`, `allow_unsafe_links: true` — the converter is
 * deliberately permissive about raw HTML passthrough and link schemes).
 *
 * This is NOT the final safety net: whatever HTML comes out of this conversion still passes through
 * `ArticleModel::save()`'s form validation, in particular `introtext`/`fulltext`'s
 * `filter="\Joomla\CMS\Component\ComponentHelper::filterText"` (see
 * `administrator/components/com_content/forms/article.xml`), which applies the site's own Text Filter
 * settings for the current user group. That is the actual XSS boundary; this class only produces the
 * Markdown-to-HTML conversion the original tool performed before handing text to Joomla's API.
 *
 * Pure: no Joomla (the `league/commonmark` dependency this plan adds is a plain Composer library, not
 * part of Joomla core).
 *
 * @since  0.0.1
 */
final class MarkdownConverter
{
	public static function toHtml(?string $markdown): string
	{
		if ($markdown === null || $markdown === '')
		{
			return '';
		}

		$converter = new CommonMarkConverter([
			'html_input'         => 'allow',
			'allow_unsafe_links' => true,
		]);

		return $converter->convert($markdown)->getContent();
	}
}
