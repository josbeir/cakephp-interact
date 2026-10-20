<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Test\TestSuite\HttpTestCase;

/**
 * Verifies that widget examples use real signed updates, application validation, and PHP rendering.
 *
 * Morph protection is browser behavior: PHP still renders current values inside protected regions.
 */
class WidgetHttpTest extends HttpTestCase
{
    public function testBothPanelsReceiveCurrentPhpValuesAndRecreationChangesIdentity(): void
    {
        $mount = $this->mounted(7, '/playground');
        $this->assertResponseContains('x-interact:ignore');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'advance']);
        $this->assertResponseOk();
        $this->assertResponseContains('"count":1');
        $this->assertResponseContains('swatch-violet');
        $this->assertResponseContains('protected-panel-0');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'recreate']);
        $this->assertResponseOk();
        $this->assertResponseContains('protected-panel-1');
        $this->assertResponseContains('"count":1');
    }

    public function testWidgetValidationRejectsForgedChoicesAndAllowsResetAndRecreation(): void
    {
        $mount = $this->mounted(8, '/playground');
        $this->assertResponseContains('/js/widgets.js');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['country' => 'forged'], 'action' => 'confirm']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Choose a country from the list.');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['country' => 'BE'], 'action' => 'confirm']);
        $this->assertResponseOk();
        $this->assertResponseContains('Confirmed: Belgium.');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'refreshExample']);
        $this->assertResponseOk();
        $this->assertResponseContains('"refreshes":1');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'resetSelection']);
        $this->assertResponseOk();
        $this->assertResponseContains('"country":"","confirmed":false');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggleWidget']);
        $this->assertResponseOk();
        $this->assertResponseNotContains('x-modelable');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggleWidget']);
        $this->assertResponseOk();
        $this->assertResponseContains('x-modelable');
    }
}
