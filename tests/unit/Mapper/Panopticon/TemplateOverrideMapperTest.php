<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\TemplateOverrideMapper;
use PHPUnit\Framework\TestCase;

class TemplateOverrideMapperTest extends TestCase
{
	public function testItemFieldsHaveNoAbsolutePathKeys(): void
	{
		$this->assertNotContains('overridePath', TemplateOverrideMapper::ITEM_FIELDS);
		$this->assertNotContains('corePath', TemplateOverrideMapper::ITEM_FIELDS);
		$this->assertNotContains('diff', TemplateOverrideMapper::ITEM_FIELDS);
	}

	public function testItemFieldsContainRelativePathsOnly(): void
	{
		$this->assertContains('overridePathRelative', TemplateOverrideMapper::ITEM_FIELDS);
		$this->assertContains('corePathRelative', TemplateOverrideMapper::ITEM_FIELDS);
	}

	public function testListFieldsMatchConnectorView(): void
	{
		$this->assertSame(
			['id', 'template', 'hash_id', 'extension_id', 'state', 'action', 'client_id', 'created_date', 'modified_date'],
			TemplateOverrideMapper::LIST_FIELDS
		);
	}
}
