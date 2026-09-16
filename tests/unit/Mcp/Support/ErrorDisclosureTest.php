<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ErrorDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * security.md M5: an uncaught \Throwable's raw message (a filesystem path, a line number, SQL/schema
 * detail, ...) must never reach an MCP client unless the site owner has explicitly turned on Joomla's
 * Debug System. These tests pin the exact message shape on both sides of that switch.
 */
class ErrorDisclosureTest extends TestCase
{
	public function testToolErrorMessageWithDebugDisabledOmitsTheRawMessage(): void
	{
		$message = ErrorDisclosure::toolErrorMessage(
			false,
			'tickets_tickets_list',
			'SQLSTATE[42S02]: Table \'joomla.jos_ats_tickets\' doesn\'t exist in /var/www/site/libraries/src/Database/DatabaseDriver.php on line 123'
		);

		$this->assertSame('Internal error while running tickets_tickets_list. See the server log for details.', $message);
		$this->assertStringNotContainsString('SQLSTATE', $message);
		$this->assertStringNotContainsString('/var/www', $message);
	}

	public function testToolErrorMessageWithDebugEnabledIncludesTheRawMessage(): void
	{
		$message = ErrorDisclosure::toolErrorMessage(true, 'tickets_tickets_list', 'Something specific broke');

		$this->assertSame('Internal error while running tickets_tickets_list: Something specific broke', $message);
	}

	public function testServerErrorMessageWithDebugDisabledOmitsTheRawMessage(): void
	{
		$message = ErrorDisclosure::serverErrorMessage(
			false,
			'TypeError: strlen(): Argument #1 ($string) must be of type string, null given in /var/www/site/administrator/components/com_mcp4j/src/Foo.php:42'
		);

		$this->assertSame('Internal server error. See the server log for details.', $message);
		$this->assertStringNotContainsString('TypeError', $message);
		$this->assertStringNotContainsString('/var/www', $message);
	}

	public function testServerErrorMessageWithDebugEnabledIncludesTheRawMessage(): void
	{
		$message = ErrorDisclosure::serverErrorMessage(true, 'Something specific broke');

		$this->assertSame('Something specific broke', $message);
	}
}
