<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Recovery;

use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Interact\TestSuite\IslandTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises application-owned draft restoration through fresh signed island interactions.
 *
 * Only the recovery example's writable message can be restored. Restoration remains
 * distinct from accepting a draft and is subject to current session authorization.
 */
class RecoveryWorkflowTest extends TestCase
{
    use IslandTestTrait;

    public function testRestoringACopiedDraftRequiresAnExplicitSeparateAcceptance(): void
    {
        $this->testIsland('Recovery')
            ->call('restoreDraft', '{"message":"Recovered <draft>"}')
            ->assertHasNoErrors()
            ->assertSet('message', 'Recovered <draft>')
            ->assertSee('Recovered <draft>')
            ->assertSet('saves', 0)
            ->assertDispatched('draft-restored', [])
            ->sync()
            ->assertSet('message', 'Recovered <draft>')
            ->assertSet('saves', 0)
            ->call('save')
            ->assertSet('saves', 1);
    }

    public function testEmptyStringsRemainValidDraftValues(): void
    {
        $this->testIsland('Recovery')
            ->set('message', 'Previous draft')
            ->call('restoreDraft', '{"message":""}')
            ->assertHasNoErrors()
            ->assertSet('message', '')
            ->assertSet('saves', 0);
    }

    /**
     * Supply malformed or foreign exports that cannot become application state.
     *
     * @return iterable<string, array{string}> Invalid copied drafts.
     */
    public static function invalidDrafts(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'null root' => ['null'];
        yield 'scalar root' => ['"draft"'];
        yield 'empty object' => ['{}'];
        yield 'list' => ['["draft"]'];
        yield 'null message' => ['{"message":null}'];
        yield 'number message' => ['{"message":0}'];
        yield 'boolean message' => ['{"message":false}'];
        yield 'array message' => ['{"message":[]}'];
        yield 'object message' => ['{"message":{"value":"draft"}}'];
        yield 'locked state' => ['{"message":"draft","saves":100}'];
        yield 'signed state' => ['{"message":"draft","snapshot":"old"}'];
        yield 'foreign draft' => ['{"title":"draft"}'];
        yield 'unsafe property' => ['{"message":"draft","__proto__":{}}'];
        yield 'excessive depth' => ['{"message":{"a":{"b":{"c":{}}}}}'];
    }

    #[DataProvider('invalidDrafts')]
    public function testInvalidCopiesPreserveTheDraftWithoutAcceptingIt(string $draft): void
    {
        $this->testIsland('Recovery')
            ->set('message', 'Keep my current draft')
            ->call('restoreDraft', $draft)
            ->assertHasErrors(['_form'])
            ->assertSet('message', 'Keep my current draft')
            ->assertSet('saves', 0)
            ->assertNotDispatched('draft-restored')
            ->call('restoreDraft', '{"message":"Corrected copy"}')
            ->assertHasNoErrors()
            ->assertSet('message', 'Corrected copy');
    }

    public function testRestoringADraftNeverBypassesCurrentSignIn(): void
    {
        $session = new Session();
        $request = new ServerRequest(['session' => $session]);
        $test = $this->testIsland('Recovery', request: $request);
        $session->write('demo.recoveryAuthenticated', false);
        try {
            $this->expectException(UnauthorizedException::class);
            $test->call('restoreDraft', '{"message":"Protected draft"}');
        } finally {
            $test->assertSet('message', '')->assertSet('saves', 0);
            $session->delete('demo.recoveryAuthenticated');
        }
    }
}
