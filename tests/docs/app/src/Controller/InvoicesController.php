<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Routing\Attribute\Get;

/**
 * Serves a public sample CSV through CakePHP's normal attachment response.
 * Real invoice endpoints must authorize the requested record independently.
 *
 * Save as src/Controller/InvoicesController.php.
 */
class InvoicesController extends Controller
{
    /**
     * Let the browser handle the attachment name and transfer outside the island update.
     */
    #[Get('/invoices/sample', name: 'invoices:download')]
    public function download(): Response
    {
        return $this->getResponse()
            ->withType('text/csv')
            ->withDownload('invoice.csv')
            ->withStringBody("Item,Amount\nWorkshop,125.00\n");
    }
}
