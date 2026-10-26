<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Interact\Attribute\Upload;
use Interact\Runtime\SnapshotData;
use Interact\Test\Fixture\Uploads\UploadTypesIsland;
use Interact\Upload\UploadOperation;
use Interact\Upload\UploadProperty;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * Rejects unsafe upload declarations, arbitrary PHP values, and malformed signed references.
 */
class UploadDeclarationTest extends TestCase
{
    /**
     * Provide invalid PHP declarations while keeping each failure independently observable.
     *
     * @return iterable<string, array{string, bool}> Property name and collection mode.
     */
    public static function invalidDeclarations(): iterable
    {
        foreach (['wrong', 'missing', 'nonempty', 'hidden', 'shared', 'immutable', 'constructor'] as $name) {
            yield $name => [$name, $name === 'nonempty'];
        }

        yield 'array as single' => ['multiple', false];
        yield 'single as array' => ['single', true];
    }

    #[DataProvider('invalidDeclarations')]
    public function testRejectsInvalidDeclarations(string $name, bool $multiple): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UploadProperty(new ReflectionProperty(UploadTypesIsland::class, $name), new Upload($multiple));
    }

    public function testRejectsArbitraryObjectsInACollection(): void
    {
        $island = new UploadTypesIsland();
        $property = new UploadProperty(new ReflectionProperty($island, 'multiple'), new Upload(true));
        $island->multiple = [new stdClass()];
        $this->expectException(InvalidArgumentException::class);
        $property->files($island);
    }

    public function testRejectsAssociativeUploadCollections(): void
    {
        $island = new UploadTypesIsland();
        $property = new UploadProperty(new ReflectionProperty($island, 'multiple'), new Upload(true));
        $island->multiple = ['invalid' => null];
        $this->expectException(InvalidArgumentException::class);
        $property->files($island);
    }

    /**
     * Provide malformed operations before they can access staging or invoke actions.
     *
     * @return iterable<string, array{mixed}> Invalid operation envelopes.
     */
    public static function operations(): iterable
    {
        yield 'not a record' => [null];
        yield 'missing field' => [['kind' => 'stage']];
        yield 'unknown kind' => [['field' => 'document', 'kind' => 'save']];
        yield 'non-string id' => [['field' => 'document', 'kind' => 'remove', 'id' => 1]];
    }

    #[DataProvider('operations')]
    public function testRejectsMalformedUploadOperations(mixed $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UploadOperation($operation);
    }

    /**
     * @return iterable<string, array{mixed}> Invalid signed reference schemas.
     */
    public static function references(): iterable
    {
        yield 'duplicate reference' => [['document' => array_fill(0, 2, str_repeat('a', 32))]];
        yield 'not a list' => [['document' => ['bad' => 'a']]];
        yield 'not an identifier' => [['document' => ['../outside']]];
        yield 'not a string' => [['document' => [false]]];
        yield 'not an array' => [['document' => null]];
        yield 'too many' => [['document' => array_fill(0, 257, str_repeat('a', 32))]];
    }

    #[DataProvider('references')]
    public function testRejectsInvalidSignedReferences(mixed $uploads): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'test',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => [],
            'uploads' => $uploads,
        ]);
    }
}
