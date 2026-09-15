<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\SchemaGenerator;
use PHPUnit\Framework\TestCase;

class SchemaGeneratorTest extends TestCase
{
	private function propertySchema(string $method, string $parameter = 'p'): array
	{
		$schema = SchemaGenerator::forMethod(new \ReflectionMethod(SchemaGeneratorFixture::class, $method));

		return $schema['properties'][$parameter];
	}

	private function fullSchema(string $method): array
	{
		return SchemaGenerator::forMethod(new \ReflectionMethod(SchemaGeneratorFixture::class, $method));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 1: base type mapping
	// -----------------------------------------------------------------------------------------------------------

	public function testMapsInt(): void
	{
		$this->assertSame(['type' => 'integer'], $this->propertySchema('typeInt'));
	}

	public function testMapsFloat(): void
	{
		$this->assertSame(['type' => 'number'], $this->propertySchema('typeFloat'));
	}

	public function testMapsString(): void
	{
		$this->assertSame(['type' => 'string'], $this->propertySchema('typeString'));
	}

	public function testMapsBool(): void
	{
		$this->assertSame(['type' => 'boolean'], $this->propertySchema('typeBool'));
	}

	public function testMapsArray(): void
	{
		$this->assertSame(['type' => 'array'], $this->propertySchema('typeArray'));
	}

	public function testUntypedParameterHasNoTypeKey(): void
	{
		$this->assertSame([], $this->propertySchema('typeUntyped'));
	}

	public function testMixedParameterHasNoTypeKey(): void
	{
		$this->assertSame([], $this->propertySchema('typeMixed'));
	}

	public function testNullableSingleTypeStaysAString(): void
	{
		$this->assertSame(['type' => 'integer'], $this->propertySchema('typeNullableSingle'));
	}

	public function testUnionTypeBecomesAListInDeclarationOrder(): void
	{
		$this->assertSame(['type' => ['array', 'string']], $this->propertySchema('typeUnion'));
	}

	public function testUnionTypeIgnoresNullMember(): void
	{
		$this->assertSame(['type' => ['array', 'string']], $this->propertySchema('typeUnionWithNull'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 2: object promotion
	// -----------------------------------------------------------------------------------------------------------

	public function testPropertiesAttributePromotesArrayToObject(): void
	{
		$schema = $this->propertySchema('objectPromotionViaProperties');

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['a' => ['type' => 'string']], $schema['properties']);
	}

	public function testAdditionalPropertiesAttributePromotesArrayToObject(): void
	{
		$schema = $this->propertySchema('objectPromotionViaAdditionalProperties');

		$this->assertSame('object', $schema['type']);
		$this->assertFalse($schema['additionalProperties']);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 3: explicit type override
	// -----------------------------------------------------------------------------------------------------------

	public function testExplicitTypeOverridesInferredType(): void
	{
		$this->assertSame(['type' => 'string'], $this->propertySchema('typeOverride'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 5: exclusiveMinimum/Maximum conversion
	// -----------------------------------------------------------------------------------------------------------

	public function testExclusiveMinimumTrueConvertsToNumericForm(): void
	{
		$this->assertSame(['type' => 'integer', 'exclusiveMinimum' => 5], $this->propertySchema('exclusiveMinTrue'));
	}

	public function testExclusiveMinimumFalseIsDropped(): void
	{
		$this->assertSame(['type' => 'integer', 'minimum' => 5], $this->propertySchema('exclusiveMinFalse'));
	}

	public function testExclusiveMinimumTrueWithoutMinimumEmitsNothing(): void
	{
		$this->assertSame(['type' => 'integer'], $this->propertySchema('exclusiveMinNoMinimum'));
	}

	public function testExclusiveMaximumTrueConvertsToNumericForm(): void
	{
		$this->assertSame(['type' => 'integer', 'exclusiveMaximum' => 99], $this->propertySchema('exclusiveMaxTrue'));
	}

	public function testExclusiveMaximumFalseIsDropped(): void
	{
		$this->assertSame(['type' => 'integer', 'maximum' => 99], $this->propertySchema('exclusiveMaxFalse'));
	}

	public function testExclusiveMaximumTrueWithoutMaximumEmitsNothing(): void
	{
		$this->assertSame(['type' => 'integer'], $this->propertySchema('exclusiveMaxNoMaximum'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 6: $definition replaces the whole property schema
	// -----------------------------------------------------------------------------------------------------------

	public function testDefinitionReplacesWholeSchema(): void
	{
		$this->assertSame(['type' => 'boolean', 'const' => true], $this->propertySchema('definitionReplacement'));
	}

	public function testDefaultIsStillAppliedOnTopOfADefinition(): void
	{
		$schema = $this->propertySchema('definitionReplacementWithDefault');

		$this->assertSame('boolean', $schema['type']);
		$this->assertTrue($schema['default']);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 7: default value handling
	// -----------------------------------------------------------------------------------------------------------

	public function testNullDefaultIsOmitted(): void
	{
		$schema = $this->propertySchema('defaultOmittedWhenNull');

		$this->assertArrayNotHasKey('default', $schema);
	}

	public function testNonNullDefaultIsRecorded(): void
	{
		$schema = $this->propertySchema('defaultPresent');

		$this->assertSame(5, $schema['default']);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 8: required vs optional
	// -----------------------------------------------------------------------------------------------------------

	public function testRequiredListsOnlyNonOptionalParameters(): void
	{
		$schema = $this->fullSchema('requiredVsOptional');

		$this->assertSame(['required'], $schema['required']);
		$this->assertArrayHasKey('optional', $schema['properties']);
	}

	public function testMethodWithNoParametersHasEmptyPropertiesAndRequired(): void
	{
		$schema = $this->fullSchema('noParameters');

		$this->assertSame([], $schema['properties']);
		$this->assertSame([], $schema['required']);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rule 9: variadic parameters are unsupported
	// -----------------------------------------------------------------------------------------------------------

	public function testVariadicParameterThrows(): void
	{
		$this->expectException(\LogicException::class);

		SchemaGenerator::forMethod(new \ReflectionMethod(SchemaGeneratorFixture::class, 'variadicMethod'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Top-level schema shape
	// -----------------------------------------------------------------------------------------------------------

	public function testTopLevelSchemaIsAnObject(): void
	{
		$schema = $this->fullSchema('typeInt');

		$this->assertSame('object', $schema['type']);
	}
}

/**
 * Fixture used only by {@see SchemaGeneratorTest}. Every method isolates one rule from
 * `.plans/01-tool-framework.md` §7.
 */
class SchemaGeneratorFixture
{
	public function typeInt(int $p): void
	{
	}

	public function typeFloat(float $p): void
	{
	}

	public function typeString(string $p): void
	{
	}

	public function typeBool(bool $p): void
	{
	}

	public function typeArray(array $p): void
	{
	}

	public function typeUntyped($p): void
	{
	}

	public function typeMixed(mixed $p): void
	{
	}

	public function typeNullableSingle(?int $p): void
	{
	}

	public function typeUnion(array|string $p): void
	{
	}

	public function typeUnionWithNull(array|string|null $p): void
	{
	}

	public function objectPromotionViaProperties(
		#[Schema(properties: ['a' => ['type' => 'string']])]
		array $p
	): void {
	}

	public function objectPromotionViaAdditionalProperties(
		#[Schema(additionalProperties: false)]
		array $p
	): void {
	}

	public function typeOverride(
		#[Schema(type: 'string')]
		int $p
	): void {
	}

	public function exclusiveMinTrue(
		#[Schema(minimum: 5, exclusiveMinimum: true)]
		int $p
	): void {
	}

	public function exclusiveMinFalse(
		#[Schema(minimum: 5, exclusiveMinimum: false)]
		int $p
	): void {
	}

	public function exclusiveMinNoMinimum(
		#[Schema(exclusiveMinimum: true)]
		int $p
	): void {
	}

	public function exclusiveMaxTrue(
		#[Schema(maximum: 99, exclusiveMaximum: true)]
		int $p
	): void {
	}

	public function exclusiveMaxFalse(
		#[Schema(maximum: 99, exclusiveMaximum: false)]
		int $p
	): void {
	}

	public function exclusiveMaxNoMaximum(
		#[Schema(exclusiveMaximum: true)]
		int $p
	): void {
	}

	public function definitionReplacement(
		#[Schema(type: 'string', definition: ['type' => 'boolean', 'const' => true])]
		string $p
	): void {
	}

	public function definitionReplacementWithDefault(
		#[Schema(definition: ['type' => 'boolean'])]
		bool $p = true
	): void {
	}

	public function defaultOmittedWhenNull(?string $p = null): void
	{
	}

	public function defaultPresent(int $p = 5): void
	{
	}

	public function requiredVsOptional(string $required, ?string $optional = null): void
	{
	}

	public function noParameters(): void
	{
	}

	public function variadicMethod(string ...$p): void
	{
	}
}
