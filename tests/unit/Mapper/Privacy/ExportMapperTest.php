<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Privacy;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\ExportMapper;
use PHPUnit\Framework\TestCase;

/**
 * Duck-typed stand-ins for `\Joomla\Component\Privacy\Administrator\Export\{Domain,Item,Field}` — same
 * public/protected shape (protected `items`/`fields`, reachable only via `getItems()`/`getFields()`), built
 * without depending on Joomla at all, so {@see ExportMapperTest} can prove {@see ExportMapper} really reads
 * through the getters rather than `get_object_vars()`/reflection.
 */
final class StubExportField
{
	public function __construct(public string $name, public mixed $value)
	{
	}
}

final class StubExportItem
{
	/** @var StubExportField[] */
	protected array $fields = [];

	public function __construct(public mixed $id)
	{
	}

	public function addField(StubExportField $field): void
	{
		$this->fields[] = $field;
	}

	/** @return StubExportField[] */
	public function getFields(): array
	{
		return $this->fields;
	}
}

final class StubExportDomain
{
	/** @var StubExportItem[] */
	protected array $items = [];

	public function __construct(public string $name, public string $description)
	{
	}

	public function addItem(StubExportItem $item): void
	{
		$this->items[] = $item;
	}

	/** @return StubExportItem[] */
	public function getItems(): array
	{
		return $this->items;
	}
}

class ExportMapperTest extends TestCase
{
	public function testDomainsToArrayShapesNameDescriptionAndItems(): void
	{
		$domain = new StubExportDomain('com_users', 'User account data');
		$item   = new StubExportItem(42);
		$item->addField(new StubExportField('username', 'lowpriv'));
		$item->addField(new StubExportField('email', 'lowpriv@example.com'));
		$domain->addItem($item);

		$result = ExportMapper::domainsToArray([$domain]);

		$this->assertSame(
			[
				[
					'name'        => 'com_users',
					'description' => 'User account data',
					'items'       => [
						[
							'id'     => 42,
							'fields' => [
								'username' => 'lowpriv',
								'email'    => 'lowpriv@example.com',
							],
						],
					],
				],
			],
			$result
		);
	}

	public function testDomainsToArrayHandlesMultipleDomainsAndEmptyItems(): void
	{
		$empty = new StubExportDomain('com_empty', 'Nothing here');
		$other = new StubExportDomain('com_other', 'Other data');
		$other->addItem(new StubExportItem(1));

		$result = ExportMapper::domainsToArray([$empty, $other]);

		$this->assertCount(2, $result);
		$this->assertSame([], $result[0]['items']);
		$this->assertSame(1, $result[1]['items'][0]['id']);
		$this->assertSame([], $result[1]['items'][0]['fields']);
	}

	public function testDomainsToArrayJsonEncodesNonScalarFieldValues(): void
	{
		$domain = new StubExportDomain('com_test', 'Test');
		$item   = new StubExportItem(1);
		$item->addField(new StubExportField('tags', ['a', 'b']));
		$domain->addItem($item);

		$result = ExportMapper::domainsToArray([$domain]);

		$this->assertSame('["a","b"]', $result[0]['items'][0]['fields']['tags']);
	}

	public function testDomainsToArrayKeepsScalarAndNullFieldValuesAsIs(): void
	{
		$domain = new StubExportDomain('com_test', 'Test');
		$item   = new StubExportItem(1);
		$item->addField(new StubExportField('count', 5));
		$item->addField(new StubExportField('active', true));
		$item->addField(new StubExportField('note', null));
		$domain->addItem($item);

		$result = ExportMapper::domainsToArray([$domain]);

		$this->assertSame(5, $result[0]['items'][0]['fields']['count']);
		$this->assertTrue($result[0]['items'][0]['fields']['active']);
		$this->assertNull($result[0]['items'][0]['fields']['note']);
	}

	public function testDomainsToArrayWithNoDomains(): void
	{
		$this->assertSame([], ExportMapper::domainsToArray([]));
	}
}
