<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Modules;

/**
 * End-to-end coverage for the seven `modules_site_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\SiteModules}).
 */
class SiteModulesTest extends AbstractModulesToolCase
{
	protected function prefix(): string
	{
		return 'modules_site';
	}

	protected function otherPrefix(): string
	{
		return 'modules_admin';
	}

	protected function expectedClientId(): int
	{
		return 0;
	}

	protected function sampleParamsJson(): string
	{
		// "layout" must always be given for a site mod_custom — see baselineParams()'s docblock.
		return '{"prepare_content":"1","layout":"_:default"}';
	}

	protected function sampleParamsKey(): string
	{
		return 'prepare_content';
	}

	protected function sampleParamsValue(): mixed
	{
		// mod_custom's site config.xml declares prepare_content with filter="integer" — the submitted
		// string "1" is coerced to the int 1 by Form::filter() before it is ever stored.
		return 1;
	}

	protected function baselineParams(): ?string
	{
		return '{"layout":"_:default"}';
	}
}
