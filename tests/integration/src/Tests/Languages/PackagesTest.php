<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Languages;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `languages_packages_list`/`languages_packages_install`
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Languages\Packages}). See
 * `.plans/10-config-plugins-installer-languages.md` for the coverage this class is meant to satisfy.
 *
 * Deliberately never installs a real language pack: it is network-dependent (the Docker test containers
 * may have no outbound network access at all), slow, and would permanently change the site for every test
 * that runs after it.
 */
class PackagesTest extends AbstractToolTestCase
{
	public function testListReturnsListShapeOrMessagesWhenEmpty(): void
	{
		$result = $this->callToolOk('languages_packages_list');

		$this->assertListShape($result);

		if ($result['items'] === [])
		{
			$this->assertArrayHasKey('messages', $result, 'An empty package list must explain why (e.g. no network).');
		}
	}

	public function testInstallOfUnknownPackageIsAToolError(): void
	{
		$result = $this->callTool('languages_packages_install', ['package' => 'xx-XX']);

		$this->assertTrue($result['isError']);
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('languages_packages_install', ['package' => 'en-GB']));
	}
}
