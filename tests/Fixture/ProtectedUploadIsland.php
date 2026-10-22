<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Render;
use Interact\IslandContext;
use TestApp\Island\DocumentUploadIsland as DocumentUpload;

/**
 * Requires current request permission so tests can verify authorization before staging or saving.
 */
#[Render(template: 'island/document_upload')]
class ProtectedUploadIsland extends DocumentUpload
{
    /**
     * Reject a revoked request before new files or application actions are processed.
     */
    public function authorize(IslandContext $context): void
    {
        if ($context->request->getAttribute('allowed', true) !== true) {
            throw new ForbiddenException('Upload access revoked.');
        }
    }
}
