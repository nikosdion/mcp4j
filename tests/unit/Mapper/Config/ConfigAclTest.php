<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Config;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\ConfigAcl;
use PHPUnit\Framework\TestCase;

class ConfigAclTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// componentConfigRequirement()
	// -----------------------------------------------------------------------------------------------------

	public function testJoomlaUpdateRequiresOnlyRootCoreAdmin(): void
	{
		$this->assertSame([['core.admin', 'root.1']], ConfigAcl::componentConfigRequirement('com_joomlaupdate'));
	}

	public function testPrivacyRequiresOnlyRootCoreAdmin(): void
	{
		$this->assertSame([['core.admin', 'root.1']], ConfigAcl::componentConfigRequirement('com_privacy'));
	}

	public function testPrivacyMatchIsCaseInsensitive(): void
	{
		$this->assertSame([['core.admin', 'root.1']], ConfigAcl::componentConfigRequirement('COM_PRIVACY'));
	}

	public function testOtherComponentsAcceptCoreAdminOrCoreOptionsOnTheComponent(): void
	{
		$this->assertSame(
			[['core.admin', 'com_content'], ['core.options', 'com_content']],
			ConfigAcl::componentConfigRequirement('com_content')
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// isValidComponentName()
	// -----------------------------------------------------------------------------------------------------

	public function testValidComponentNames(): void
	{
		$this->assertTrue(ConfigAcl::isValidComponentName('com_content'));
		$this->assertTrue(ConfigAcl::isValidComponentName('com_x'));
		$this->assertTrue(ConfigAcl::isValidComponentName('com_x_y'));
		$this->assertTrue(ConfigAcl::isValidComponentName('com_mcp4j'));
	}

	public function testInvalidComponentNames(): void
	{
		$this->assertFalse(ConfigAcl::isValidComponentName('COM_content'));
		$this->assertFalse(ConfigAcl::isValidComponentName('com_'));
		$this->assertFalse(ConfigAcl::isValidComponentName('../etc'));
		$this->assertFalse(ConfigAcl::isValidComponentName('com_x.y'));
		$this->assertFalse(ConfigAcl::isValidComponentName('content'));
		$this->assertFalse(ConfigAcl::isValidComponentName(''));
	}
}
