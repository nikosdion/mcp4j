<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Config;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\ConfigDataMapper;
use PHPUnit\Framework\TestCase;

class ConfigDataMapperTest extends TestCase
{
	public function testArrayInputPassesThroughUnchanged(): void
	{
		$this->assertSame(['sitename' => 'My Site'], ConfigDataMapper::normaliseConfigData(['sitename' => 'My Site']));
	}

	public function testJsonObjectStringIsDecoded(): void
	{
		$this->assertSame(['sitename' => 'My Site'], ConfigDataMapper::normaliseConfigData('{"sitename":"My Site"}'));
	}

	public function testEmptyJsonObjectStringIsAccepted(): void
	{
		$this->assertSame([], ConfigDataMapper::normaliseConfigData('{}'));
	}

	public function testEmptyArrayIsAccepted(): void
	{
		$this->assertSame([], ConfigDataMapper::normaliseConfigData([]));
	}

	public function testJsonListStringIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The configData value must be a valid JSON object.');

		ConfigDataMapper::normaliseConfigData('[1,2]');
	}

	public function testJsonListArrayIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ConfigDataMapper::normaliseConfigData([1, 2, 3]);
	}

	public function testInvalidJsonIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The configData value must be a valid JSON object.');

		ConfigDataMapper::normaliseConfigData('{not valid json');
	}

	public function testJsonScalarIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ConfigDataMapper::normaliseConfigData('"just a string"');
	}
}
