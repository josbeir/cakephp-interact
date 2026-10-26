<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Forms;

use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Verifies dependent choices preserve unrelated drafts and accept valid selections.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class DependentChoicesHttpTest extends HttpTestCase
{
    public function testDependentChoicesConfirmAValidCityWithoutLosingNotes(): void
    {
        $mount = $this->mounted(4);
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['cityId' => 1, 'notes' => 'Keep my draft'],
            'action' => 'confirm',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('City confirmed.');
        $this->assertResponseContains('Keep my draft');
    }

    public function testDependentChoicesAndUnrelatedDrafts(): void
    {
        $mount = $this->mounted(4);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['country' => 'NL', 'notes' => 'keep draft']]);
        $this->assertResponseOk();
        $this->assertResponseContains('Amsterdam');
        $this->assertResponseContains('keep draft');
        $this->assertResponseNotContains('Brussels');
    }
}
