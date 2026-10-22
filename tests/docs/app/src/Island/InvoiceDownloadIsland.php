<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Updates an export count while asking the browser to download a public sample invoice.
 *
 * Save as src/Island/InvoiceDownloadIsland.php.
 */
class InvoiceDownloadIsland extends Island
{
    #[State]
    public int $exports = 0;

    /**
     * Render the new count and request a native download from a separate application route.
     */
    #[Action]
    public function download(): void
    {
        $this->exports++;
        $this->downloadUrl(['_name' => 'invoices:download']);
    }
}
