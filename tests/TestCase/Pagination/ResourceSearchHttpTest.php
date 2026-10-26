<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Pagination;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Verifies resource filtering, sorting, and paging through signed HTTP updates.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class ResourceSearchHttpTest extends HttpTestCase
{
    public function testResourceLibraryRendersOnlyOnePageAndKeepsRowsOutsideState(): void
    {
        $this->mounted(url: '/search');
        $this->assertResponseContains('1,200 sample resources');
        $this->assertResponseContains('Showing 1–20 of');
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $xpath = new XPath($document);
        $xpath->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        static::assertSame(
            1,
            $xpath->query(
                '//html:nav[@aria-label="Demo pages"]/html:a[@aria-current="page" and normalize-space(.)="Search"]',
            )->length,
        );
        static::assertSame(20, $xpath->query('//html:table/html:tbody/html:tr')->length);
        $root = $xpath->query('//*[@data-interact-state]')->item(0);
        static::assertInstanceOf(Element::class, $root);
        static::assertJsonStringEqualsJsonString(
            '{"controlPrefix":"ResourceSearch","notes":"","search":"","category":"","level":"","paging":{"page":1,"sort":"title","direction":"asc","limit":20}}',
            (string)$root->getAttribute('data-interact-state'),
        );
        $options = $xpath->query('//html:select[@*[name()="x-interact:model.change" and .="paging.sort"]]/html:option');
        $labels = [];
        foreach ($options as $option) {
            $labels[] = $option->textContent;
        }

        static::assertSame(['Title: A–Z', 'Title: Z–A', 'Shortest read first', 'Longest read first'], $labels);
        $selected = $xpath->query(
            '//html:select[@*[name()="x-interact:model.change" and .="paging.sort"]]/html:option[@selected]',
        );
        static::assertSame(1, $selected->length);
        $selectedOption = $selected->item(0);
        static::assertInstanceOf(Element::class, $selectedOption);
        static::assertSame('title', $selectedOption->getAttribute('value'));
    }

    public function testResourceSearchCombinesFiltersAndResetsPaginationAfterEdits(): void
    {
        $mount = $this->mounted(url: '/search');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['paging.page' => 999]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":60');
        $this->assertResponseContains('Showing 1,181\u20131,200');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => [
                'search' => 'validation',
                'category' => 'cakephp',
                'level' => 'beginner',
                'paging.sort' => 'shortest',
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
        $this->assertResponseContains('Showing 1\u20135 of');
        $this->assertResponseContains('Entity validation');
        $this->assertResponseNotContains('Input validation');
        $this->assertResponseNotContains('Undefined variable');
        $this->assertResponseContains('value=\"shortest\" selected');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['paging.page' => -10]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
    }

    public function testResourceSearchChangesPageSizeAndRestoresTheFullLibrary(): void
    {
        $mount = $this->mounted(url: '/search');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['paging.limit' => 50]]);
        $this->assertResponseOk();
        $this->assertResponseContains('Showing 1\u201350 of');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['paging.page' => 999]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":24');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['search' => '%']]);
        $this->assertResponseOk();
        $this->assertResponseContains('No resources found');
        $this->assertResponseContains('"page":1');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'clear']);
        $this->assertResponseOk();
        $this->assertResponseContains('Showing 1\u201320 of');
        $this->assertResponseContains('"search":""');
        $this->assertResponseContains('"limit":20');
        $this->assertResponseNotContains('No resources found');
    }

    public function testResourceSearchRejectsUnsupportedFiltersAndNormalizesWritablePages(): void
    {
        $mount = $this->mounted(url: '/search');
        foreach ([
            ['category' => 'unknown'],
            ['level' => 'unknown'],
            ['paging.sort' => 'Resources.id'],
            ['paging.limit' => 0],
            ['search' => str_repeat('x', 201)],
        ] as $updates) {
            $this->update(['snapshot' => $mount['snapshot'], 'updates' => $updates]);
            $this->assertResponseCode(400);
        }

        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['paging.page' => 999]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":60');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['paging.page' => -1]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
    }
}
