<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Modules;

/**
 * End-to-end coverage for the seven `modules_admin_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\AdminModules}).
 */
class AdminModulesTest extends AbstractModulesToolCase
{
	protected function prefix(): string
	{
		return 'modules_admin';
	}

	protected function otherPrefix(): string
	{
		return 'modules_site';
	}

	protected function expectedClientId(): int
	{
		return 1;
	}

	protected function sampleParamsJson(): string
	{
		return '{"layout":"_:default"}';
	}

	protected function sampleParamsKey(): string
	{
		return 'layout';
	}

	protected function sampleParamsValue(): mixed
	{
		return '_:default';
	}

	protected function baselineParams(): ?string
	{
		// Administrator mod_custom's "layout" field has no validate rule, and every other field has a
		// usable XML default — nothing extra is needed here.
		return null;
	}
}
