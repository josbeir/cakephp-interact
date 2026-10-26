<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Demo;

use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Exercises the playground counter, conditional quote, and task-board workflows.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class PlaygroundHttpTest extends HttpTestCase
{
    public function testPlaygroundMountsDistinctExamplesWithinTheSharedLayout(): void
    {
        $this->mounted(url: '/playground');
        $this->assertResponseContains('Playground');
        $this->assertResponseContains('href="/demo.css"');
        $xpath = new XPath(HTMLDocument::createFromString((string)$this->response?->getBody()));
        $xpath->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        static::assertSame(
            1,
            $xpath->query(
                '//html:nav[@aria-label="Demo pages"]/html:a[@aria-current="page" and normalize-space(.)="Playground"]',
            )->length,
        );
        $this->assertResponseContains('First counter');
        $this->assertResponseContains('Second counter');
        $this->assertResponseContains('Conditional form');
        $this->assertResponseContains('Task list');
    }

    public function testCountersUseTypedActionsAndRetainTheirIndependentMountProps(): void
    {
        $first = $this->mounted(url: '/playground');
        $second = $this->mounted(1, '/playground');
        static::assertNotSame($first['id'], $second['id']);
        $this->update(['snapshot' => $first['snapshot'], 'action' => 'adjust', 'args' => [5]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"count":5');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'reset']);
        $this->assertResponseContains('"count":0');
        $this->update(['snapshot' => $second['snapshot'], 'action' => 'adjust', 'args' => [-1]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"count":4');
    }

    public function testCounterRejectsUnsupportedIncrementsAndDirectStateAssignments(): void
    {
        $mount = $this->mounted(url: '/playground');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'adjust', 'args' => [99]]);
        $this->assertResponseCode(400);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['count' => 99]]);
        $this->assertResponseCode(400);
    }

    public function testWorkshopQuoteCalculatesExtrasAndValidatesConditionalFields(): void
    {
        $mount = $this->mounted(2, '/playground');
        $this->assertResponseContains('€90.00');
        $this->assertResponseNotContains('placeholder="Your company"');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => [
                'format' => 'studio',
                'attendees' => 3,
                'extras' => ['materials', 'recording'],
                'business' => true,
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('\u20ac300.00');
        $this->assertResponseContains('Company name');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'confirm']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Enter a company name');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['company' => 'Cake Studio'],
            'action' => 'confirm',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Quote confirmed.');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['business' => false]]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('Quote confirmed.');
        $this->assertResponseNotContains('placeholder=');
        $this->assertResponseContains('"company":"Cake Studio"');
    }

    public function testWorkshopRejectsInvalidChoicesAndClientSuppliedTotals(): void
    {
        $mount = $this->mounted(2, '/playground');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['format' => 'unknown', 'attendees' => 0, 'extras' => [['unexpected' => true]]],
            'action' => 'confirm',
        ]);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Choose an online or studio workshop.');
        $this->assertResponseContains('Choose between 1 and 12 attendees.');
        $this->assertResponseContains('Choose an available workshop extra.');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['total' => 1]]);
        $this->assertResponseCode(400);
    }

    public function testTaskBoardAddsTogglesAndRemovesRowsByStableIdentity(): void
    {
        $mount = $this->mounted(3, '/playground');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['draft' => 'Try typed actions'],
            'action' => 'add',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"id":4,"text":"Try typed actions","done":false');
        $this->assertResponseContains('"draft":""');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggle', 'args' => [4]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"id":4,"text":"Try typed actions","done":true');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'remove', 'args' => [4]]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('Try typed actions');
        $this->assertResponseContains('"id":3,"text":"Build something with CakePHP"');
    }

    public function testTaskBoardValidatesDraftsEscapesTextAndRejectsUnknownRows(): void
    {
        $mount = $this->mounted(3, '/playground');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'add']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Use 1 to 80 characters');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['draft' => '<img src=x onerror=alert(1)>'],
            'action' => 'add',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('&lt;img src=x onerror=alert(1)&gt;');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggle', 'args' => [999]]);
        $this->assertResponseCode(400);
    }
}
