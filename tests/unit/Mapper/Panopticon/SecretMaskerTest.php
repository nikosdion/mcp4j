<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\SecretMasker;
use PHPUnit\Framework\TestCase;

class SecretMaskerTest extends TestCase
{
	public function testNullIsUnchanged(): void
	{
		$this->assertNull(SecretMasker::mask(null));
	}

	public function testEmptyStringIsUnchanged(): void
	{
		$this->assertSame('', SecretMasker::mask(''));
	}

	public function testShortValueIsFullyMasked(): void
	{
		$this->assertSame('********', SecretMasker::mask('a'));
		$this->assertSame('********', SecretMasker::mask('1234567'));
		$this->assertSame('********', SecretMasker::mask('12345678'));
	}

	public function testLongValueKeepsLastFourCharacters(): void
	{
		$this->assertSame('****6789', SecretMasker::mask('123456789'));
		$this->assertSame('****cdef', SecretMasker::mask('abcdef0123456789abcdef'));
	}
}
