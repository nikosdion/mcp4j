<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Media;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Media\MediaMapper;
use PHPUnit\Framework\TestCase;

class MediaMapperTest extends TestCase
{
	public function testFileFieldsNeverIncludesContentOrTempUrl(): void
	{
		$this->assertNotContains('content', MediaMapper::FILE_FIELDS);
		$this->assertNotContains('tempUrl', MediaMapper::FILE_FIELDS);
	}

	public function testFileFieldsMatchesCoreLocalAdapterOutput(): void
	{
		$this->assertSame(
			[
				'type',
				'name',
				'path',
				'extension',
				'size',
				'mime_type',
				'width',
				'height',
				'create_date',
				'create_date_formatted',
				'modified_date',
				'modified_date_formatted',
				'thumb_path',
				'adapter',
				'url',
			],
			MediaMapper::FILE_FIELDS
		);
	}

	public function testAdapterFieldsMatchesCoreApiModel(): void
	{
		$this->assertSame(['id', 'provider_id', 'name', 'path'], MediaMapper::ADAPTER_FIELDS);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['size', 'width', 'height'], MediaMapper::INT_FIELDS);
	}

	public function testShapeAdapterKeepsOnlyWhitelistedFields(): void
	{
		$adapter = [
			'id'          => 'local-images',
			'provider_id' => 'local',
			'name'        => 'images',
			'path'        => 'local-images:/',
			'unexpected'  => 'should not survive',
		];

		$this->assertSame(
			[
				'id'          => 'local-images',
				'provider_id' => 'local',
				'name'        => 'images',
				'path'        => 'local-images:/',
			],
			MediaMapper::shapeAdapter($adapter)
		);
	}

	public function testShapeAdapterKeepsStringIdUncoerced(): void
	{
		// Unlike OutputShaper::shape(), this must NEVER int-cast "id" — adapter ids are strings such as
		// "local-images", not numeric.
		$shaped = MediaMapper::shapeAdapter(['id' => 'local-images', 'provider_id' => 'local', 'name' => 'images', 'path' => 'local-images:/']);

		$this->assertIsString($shaped['id']);
		$this->assertSame('local-images', $shaped['id']);
	}

	public function testShapeAdapterOmitsMissingFieldsRatherThanFillingNull(): void
	{
		$shaped = MediaMapper::shapeAdapter(['id' => 'local-images']);

		$this->assertSame(['id' => 'local-images'], $shaped);
		$this->assertArrayNotHasKey('provider_id', $shaped);
	}
}
