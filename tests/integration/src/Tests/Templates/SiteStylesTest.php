<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Templates;

/**
 * End-to-end coverage for the five `templates_sitestyles_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\SiteStyles}).
 */
class SiteStylesTest extends AbstractStylesToolCase
{
	protected function prefix(): string
	{
		return 'templates_sitestyles';
	}

	protected function otherPrefix(): string
	{
		return 'templates_adminstyles';
	}

	protected function expectedClientId(): int
	{
		return 0;
	}

	protected function template(): string
	{
		return 'cassiopeia';
	}

	protected function sampleParamsJson(): string
	{
		// "siteTitle" (plain type="text" filter="string") round-trips identically on every supported Joomla
		// version. "brand" (type="radio" filter="options") was tried first but Joomla 4.4 (only) hands it
		// back as a genuine PHP bool rather than the submitted string "1" — a real, narrow Joomla-version
		// quirk in that specific field's filter handling, not a bug in this tool; see the final report.
		return '{"siteTitle":"MCP4j Site Title"}';
	}

	protected function sampleParamsKey(): string
	{
		return 'siteTitle';
	}

	protected function sampleParamsValue(): mixed
	{
		return 'MCP4j Site Title';
	}
}
