<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Validation;

use Cake\ORM\Entity;
use Cake\Validation\Validator;
use Interact\Test\Fixture\Runtime\PlainIsland;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies the application error boundary accepts native CakePHP errors and custom messages.
 *
 * Normalization preserves entity-owned feedback while producing predictable browser paths
 * and rejecting values that cannot represent validation messages.
 */
class ValidationErrorsTest extends TestCase
{
    public function testNativeEntityAndAssociationErrorsRemainUnchanged(): void
    {
        $validator = new Validator()->notEmptyString('title', 'A title is required.');
        $author = new Entity();
        $author->setError('name', ['required' => 'An author is required.']);

        $comment = new Entity();
        $comment->setError('body', ['required' => 'A comment is required.']);

        $article = new Entity([
            'author' => $author,
            'comments' => [2 => $comment],
        ]);
        $article->setErrors($validator->validate(['title' => '']));

        $original = $article->getErrors();
        $island = new PlainIsland();
        $island->setErrors([...$original, '_form' => ['Please review these fields.']]);

        static::assertSame(
            [
                'title' => ['A title is required.'],
                'author.name' => ['An author is required.'],
                'comments.2.body' => ['A comment is required.'],
                '_form' => ['Please review these fields.'],
            ],
            $island->errors(),
        );
        static::assertSame($original, $article->getErrors());
        static::assertSame(['_empty' => 'A title is required.'], $article->getError('title'));
    }

    /**
     * Provide supported message shapes, including sparse associations and already dotted fields.
     *
     * @return iterable<string, array{array<array-key, mixed>, array<string, list<string>>}>
     */
    public static function validErrors(): iterable
    {
        yield 'custom lists' => [
            ['title' => ['Choose another title.', 'Use at least three characters.']],
            ['title' => ['Choose another title.', 'Use at least three characters.']],
        ];
        yield 'rule names are discarded' => [
            ['title' => ['unique' => 'Choose another title.', 'minLength' => 'Use at least three characters.']],
            ['title' => ['Choose another title.', 'Use at least three characters.']],
        ];
        yield 'empty arrays are not failures' => [
            ['title' => [], 'comments' => [0 => ['body' => []]]],
            [],
        ];
        yield 'string contents are preserved' => [
            ['title' => ['', '0', '<script>alert(1)</script>']],
            ['title' => ['', '0', '<script>alert(1)</script>']],
        ];
        yield 'dotted paths merge without dropping messages' => [
            ['comments' => [2 => ['body' => ['required' => 'Required.']]], 'comments.2.body' => ['Also invalid.']],
            ['comments.2.body' => ['Required.', 'Also invalid.']],
        ];
        yield 'parent messages and nested messages coexist' => [
            ['comments' => ['limit' => 'Too many comments.', 2 => ['body' => ['Too long.']]]],
            ['comments' => ['Too many comments.'], 'comments.2.body' => ['Too long.']],
        ];
    }

    /**
     * Keep custom and CakePHP messages in one consistent response format.
     *
     * @param array<array-key, mixed> $input Application-supplied errors.
     * @param array<string, list<string>> $expected Browser-visible feedback.
     */
    #[DataProvider('validErrors')]
    public function testSupportedErrors(array $input, array $expected): void
    {
        $island = new PlainIsland();
        $island->setErrors($input);
        static::assertSame($expected, $island->errors());
    }

    public function testSettingErrorsReplacesFeedbackAndAnEmptyArrayClearsIt(): void
    {
        $island = new PlainIsland();
        $island->setErrors(['title' => ['Invalid.']]);
        $island->setErrors(['_form' => ['Unavailable.']]);
        static::assertSame(['_form' => ['Unavailable.']], $island->errors());
        $island->setErrors([]);
        static::assertSame([], $island->errors());
    }

    /**
     * Provide values that should not be guessed at or silently discarded.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidErrors(): iterable
    {
        yield 'numeric root field' => [[0 => ['Invalid.']]];
        yield 'empty field' => [['' => ['Invalid.']]];
        yield 'bare message' => [['title' => 'Invalid.']];
        yield 'null field errors' => [['title' => null]];
        yield 'null message' => [['title' => ['required' => null]]];
        yield 'boolean message' => [['title' => ['required' => false]]];
        yield 'integer message' => [['title' => [42]]];
        yield 'float message' => [['title' => [1.5]]];
        yield 'object message' => [['title' => [new stdClass()]]];
        yield 'empty nested path' => [['comments' => ['' => ['Invalid.']]]];
        yield 'deeply nested invalid message' => [['comments' => [2 => ['body' => ['required' => true]]]]];
    }

    /**
     * Reject unsupported feedback without replacing the previous response errors.
     *
     * @param array<array-key, mixed> $input Malformed application errors.
     */
    #[DataProvider('invalidErrors')]
    public function testInvalidErrorsLeaveExistingFeedbackIntact(array $input): void
    {
        $island = new PlainIsland();
        $island->setErrors(['title' => ['Existing error.']]);
        try {
            $island->setErrors(['valid' => ['Valid message.'], ...$input]);
            static::fail('Malformed validation feedback must fail.');
        } catch (InvalidArgumentException) {
            static::assertSame(['title' => ['Existing error.']], $island->errors());
        }
    }

    public function testNestingLimitAndCyclicErrors(): void
    {
        $messages = ['Required.'];
        for ($index = 0; $index < 32; $index++) {
            $messages = ['child' => $messages];
        }

        $island = new PlainIsland();
        $island->setErrors(['field' => $messages]);
        static::assertSame(['field' . str_repeat('.child', 32) => ['Required.']], $island->errors());

        $cyclic = [];
        $cyclic['child'] = &$cyclic;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 32 levels deep');
        $island->setErrors(['field' => $cyclic]);
    }
}
