<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Routing\Attribute\Get;

/**
 * Hosts action outcomes while serving saved records and downloads through ordinary CakePHP routes.
 * File access is checked on every download request, independently of any island action.
 */
class OutcomesController extends AppController
{
    /**
     * Render the form and invoice controls inside an ordinary application page.
     */
    #[Get('/outcomes', name: 'demo:outcomes')]
    public function index(): void {}

    /**
     * Read the saved article using its normal primary-key lookup.
     */
    #[Get('/outcomes/saved', name: 'demo:outcomes:saved')]
    public function saved(): void
    {
        $id = (int)$this->getRequest()->getQuery('id');
        $this->set('article', $this->fetchTable('OutcomeArticles')->get($id));
    }

    /**
     * Return a small deterministic attachment after checking current application permissions.
     */
    #[Get('/outcomes/invoice', name: 'demo:outcomes:invoice')]
    public function invoice(): Response
    {
        /** @var mixed $identity */
        $identity = $this->getRequest()->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor') {
            throw new ForbiddenException('Invoice access denied.');
        }

        return $this->getResponse()
            ->withType('text/csv')
            ->withDownload('invoice.csv')
            ->withStringBody("Item,Amount\nWorkshop,125.00\n");
    }
}
