<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\TypeConverter;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\UnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TypeConverter::class)]
final class TypeConverterTest extends TestCase
{
    private TypeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TypeConverter();
    }

    public function testConvertSimpleTypes(): void
    {
        // Scalar types
        $this->assertEquals('string', $this->converter->typeToString(new Identifier('string')));
        $this->assertEquals('int', $this->converter->typeToString(new Identifier('int')));
        $this->assertEquals('float', $this->converter->typeToString(new Identifier('float')));
        $this->assertEquals('bool', $this->converter->typeToString(new Identifier('bool')));
        $this->assertEquals('array', $this->converter->typeToString(new Identifier('array')));
        $this->assertEquals('object', $this->converter->typeToString(new Identifier('object')));
        $this->assertEquals('void', $this->converter->typeToString(new Identifier('void')));
        $this->assertEquals('mixed', $this->converter->typeToString(new Identifier('mixed')));
        $this->assertEquals('never', $this->converter->typeToString(new Identifier('never')));
    }

    public function testConvertClassTypes(): void
    {
        // Simple class name
        $this->assertEquals('ClassName', $this->converter->typeToString(new Name('ClassName')));

        // Fully qualified class name
        $this->assertEquals('App\Service\UserService', $this->converter->typeToString(new Name\FullyQualified('App\Service\UserService')));

        // Relative class name
        $this->assertEquals('namespace\SubClass', $this->converter->typeToString(new Name\Relative('SubClass')));
    }

    public function testConvertNullableTypes(): void
    {
        $nullableString = new NullableType(new Identifier('string'));
        $this->assertEquals('?string', $this->converter->typeToString($nullableString));

        $nullableClass = new NullableType(new Name('ClassName'));
        $this->assertEquals('?ClassName', $this->converter->typeToString($nullableClass));

        $nullableArray = new NullableType(new Identifier('array'));
        $this->assertEquals('?array', $this->converter->typeToString($nullableArray));
    }

    public function testConvertUnionTypes(): void
    {
        $unionType = new UnionType([
            new Identifier('string'),
            new Identifier('int'),
        ]);
        $this->assertEquals('string|int', $this->converter->typeToString($unionType));

        $unionWithNull = new UnionType([
            new Identifier('string'),
            new Identifier('null'),
        ]);
        $this->assertEquals('string|null', $this->converter->typeToString($unionWithNull));

        $complexUnion = new UnionType([
            new Name('ClassName'),
            new Identifier('array'),
            new Identifier('null'),
        ]);
        $this->assertEquals('ClassName|array|null', $this->converter->typeToString($complexUnion));
    }

    public function testConvertIntersectionTypes(): void
    {
        $intersectionType = new IntersectionType([
            new Name('Countable'),
            new Name('Traversable'),
        ]);
        $this->assertEquals('Countable&Traversable', $this->converter->typeToString($intersectionType));

        $complexIntersection = new IntersectionType([
            new Name\FullyQualified('Iterator'),
            new Name('ArrayAccess'),
            new Name('Countable'),
        ]);
        $this->assertEquals('Iterator&ArrayAccess&Countable', $this->converter->typeToString($complexIntersection));
    }

    public function testConvertMixedComplexTypes(): void
    {
        // Simple nullable type
        $nullableString = new NullableType(new Identifier('string'));
        $this->assertEquals('?string', $this->converter->typeToString($nullableString));
    }

    public function testStringToType(): void
    {
        // Simple types
        $stringType = $this->converter->stringToType('string');
        $this->assertInstanceOf(Identifier::class, $stringType);
        $this->assertEquals('string', $stringType->name);

        // Class types
        $classType = $this->converter->stringToType('App\Service\UserService');
        $this->assertInstanceOf(Name::class, $classType);
        $this->assertEquals('App\Service\UserService', $classType->toString());

        // Nullable types
        $nullableType = $this->converter->stringToType('?string');
        $this->assertInstanceOf(NullableType::class, $nullableType);

        // Union types
        $unionType = $this->converter->stringToType('string|int');
        $this->assertInstanceOf(UnionType::class, $unionType);

        // Intersection types
        $intersectionType = $this->converter->stringToType('Countable&Traversable');
        $this->assertInstanceOf(IntersectionType::class, $intersectionType);
    }

    public function testNormalizeTypeName(): void
    {
        // Leading backslash removal
        $this->assertEquals('App\Service\UserService', $this->converter->normalizeTypeName('\App\Service\UserService'));

        // No change for normal names
        $this->assertEquals('ClassName', $this->converter->normalizeTypeName('ClassName'));

        // Special types remain unchanged
        $this->assertEquals('string', $this->converter->normalizeTypeName('string'));
        $this->assertEquals('int', $this->converter->normalizeTypeName('int'));
    }

    public function testIsScalarType(): void
    {
        $this->assertTrue($this->converter->isScalarType('string'));
        $this->assertTrue($this->converter->isScalarType('int'));
        $this->assertTrue($this->converter->isScalarType('float'));
        $this->assertTrue($this->converter->isScalarType('bool'));
        $this->assertTrue($this->converter->isScalarType('array'));
        $this->assertTrue($this->converter->isScalarType('object'));
        $this->assertTrue($this->converter->isScalarType('callable'));
        $this->assertTrue($this->converter->isScalarType('iterable'));
        $this->assertTrue($this->converter->isScalarType('void'));
        $this->assertTrue($this->converter->isScalarType('mixed'));
        $this->assertTrue($this->converter->isScalarType('never'));
        $this->assertTrue($this->converter->isScalarType('null'));
        $this->assertTrue($this->converter->isScalarType('false'));
        $this->assertTrue($this->converter->isScalarType('true'));

        $this->assertFalse($this->converter->isScalarType('ClassName'));
        $this->assertFalse($this->converter->isScalarType('App\Service'));
    }

    public function testConvertValueToString(): void
    {
        // Scalars
        $this->assertEquals('123', $this->converter->valueToString(123));
        $this->assertEquals('123.45', $this->converter->valueToString(123.45));
        $this->assertEquals("'hello'", $this->converter->valueToString('hello'));
        $this->assertEquals('true', $this->converter->valueToString(true));
        $this->assertEquals('false', $this->converter->valueToString(false));
        $this->assertEquals('null', $this->converter->valueToString(null));

        // Arrays
        $this->assertEquals('[1, 2, 3]', $this->converter->valueToString([1, 2, 3]));
        $this->assertEquals("['a' => 1, 'b' => 2]", $this->converter->valueToString(['a' => 1, 'b' => 2]));

        // Objects (simple representation)
        $obj = new \stdClass();
        $this->assertStringContainsString('stdClass', $this->converter->valueToString($obj));
    }

    public function testConvertSpecialTypes(): void
    {
        // Self type
        $this->assertEquals('self', $this->converter->typeToString(new Name('self')));

        // Parent type
        $this->assertEquals('parent', $this->converter->typeToString(new Name('parent')));

        // Static type
        $this->assertEquals('static', $this->converter->typeToString(new Name('static')));
    }

    public function testHandleNullType(): void
    {
        $nullType = $this->converter->stringToType(null);
        $this->assertNull($nullType);

        $emptyType = $this->converter->typeToString(null);
        $this->assertEquals('', $emptyType);
    }

    public function testExtractFqcn(): void
    {
        // Test with namespacedName property (simulates NameResolver behavior)
        $node = new Name('TestClass');
        $node->setAttribute('namespacedName', new Name('App\TestClass'));
        $fqcn = $this->converter->extractFqcn($node);
        $this->assertEquals('App\TestClass', $fqcn);

        // Test with name property (class node)
        $classNode = new Class_('TestClass');
        $fqcn = $this->converter->extractFqcn($classNode);
        $this->assertEquals('TestClass', $fqcn);

        // Test with method node
        $methodNode = new ClassMethod('testMethod');
        $fqcn = $this->converter->extractFqcn($methodNode);
        $this->assertEquals('testMethod', $fqcn);

        // Test with function node
        $functionNode = new Function_('globalFunction');
        $fqcn = $this->converter->extractFqcn($functionNode);
        $this->assertEquals('globalFunction', $fqcn);

        // Test with property node (has no name property)
        $propertyProperty = new PropertyProperty('prop');
        $propertyNode = new Property(0, [$propertyProperty]);
        $fqcn = $this->converter->extractFqcn($propertyNode);
        $this->assertNull($fqcn);
    }

    public function testStringToTypeWithEmptyString(): void
    {
        $result = $this->converter->stringToType('');
        $this->assertNull($result);

        $result = $this->converter->stringToType('   ');
        $this->assertNull($result);
    }

    public function testStringToTypeComplexCases(): void
    {
        // Test fully qualified name
        $result = $this->converter->stringToType('\App\Service\UserService');
        $this->assertInstanceOf(Name\FullyQualified::class, $result);
        $this->assertEquals('App\Service\UserService', $result->toString());

        // Test relative name
        $result = $this->converter->stringToType('namespace\SubClass');
        $this->assertInstanceOf(Name\Relative::class, $result);
        $this->assertEquals('SubClass', $result->toString());

        // Test nullable union type
        $result = $this->converter->stringToType('?string|int');
        $this->assertInstanceOf(UnionType::class, $result);

        // Test nullable intersection type
        $result = $this->converter->stringToType('?Countable&Traversable');
        $this->assertInstanceOf(UnionType::class, $result);
    }

    public function testValueToStringEdgeCases(): void
    {
        // Test string with special characters
        $this->assertEquals("'test\\'s string'", $this->converter->valueToString("test's string"));

        // Test nested arrays
        $nested = [
            'level1' => [
                'level2' => 'value',
            ],
        ];
        $result = $this->converter->valueToString($nested);
        $this->assertStringContainsString('level1', $result);
        $this->assertStringContainsString('level2', $result);
        $this->assertStringContainsString('value', $result);

        // Test empty array
        $this->assertEquals('[]', $this->converter->valueToString([]));

        // Test mixed array keys
        $mixed = [0 => 'first', 'key' => 'second'];
        $result = $this->converter->valueToString($mixed);
        $this->assertStringContainsString('0 =>', $result);
        $this->assertStringContainsString("'key' =>", $result);
    }

    public function testNullableTypeWithSimpleTypes(): void
    {
        // Test nullable identifier type
        $nullableString = new NullableType(new Identifier('string'));
        $result = $this->converter->typeToString($nullableString);
        $this->assertEquals('?string', $result);

        // Test nullable class name
        $nullableClass = new NullableType(new Name('MyClass'));
        $result = $this->converter->typeToString($nullableClass);
        $this->assertEquals('?MyClass', $result);

        // Test nullable int
        $nullableInt = new NullableType(new Identifier('int'));
        $result = $this->converter->typeToString($nullableInt);
        $this->assertEquals('?int', $result);
    }

    public function testIsScalarTypeCaseInsensitive(): void
    {
        $this->assertTrue($this->converter->isScalarType('STRING'));
        $this->assertTrue($this->converter->isScalarType('Int'));
        $this->assertTrue($this->converter->isScalarType('BOOL'));
        $this->assertTrue($this->converter->isScalarType('Array'));
        $this->assertTrue($this->converter->isScalarType('Mixed'));
    }

    public function testTypeToStringWithUnsupportedType(): void
    {
        // Test with an object that doesn't match any known type
        $unsupportedType = new \stdClass();
        $result = $this->converter->typeToString($unsupportedType);
        $this->assertEquals('mixed', $result);
    }
}
