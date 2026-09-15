<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Templates;

/**
 * End-to-end coverage for the five `templates_adminstyles_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\AdminStyles}).
 */
class AdminStylesTest extends AbstractStylesToolCase
{
	protected function prefix(): string
	{
		return 'templates_adminstyles';
	}

	protected function otherPrefix(): string
	{
		return 'templates_sitestyles';
	}

	protected function expectedClientId(): int
	{
		return 1;
	}

	protected function template(): string
	{
		return 'atum';
	}

	protected function sampleParamsJson(): string
	{
		// "colorScheme" was tried first but does not exist on Joomla 4.4's atum template at all (added only
		// in 5.4/6.x — dark/light mode support post-dates that release); "hue" (type="color", no filter
		// attribute) exists, unchanged, on every supported version. See the final report's Joomla-version
		// differences.
		return '{"hue":"hsl(200,50%,30%)"}';
	}

	protected function sampleParamsKey(): string
	{
		return 'hue';
	}

	protected function sampleParamsValue(): mixed
	{
		return 'hsl(200,50%,30%)';
	}
}
