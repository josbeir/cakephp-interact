<?php
declare(strict_types=1);

namespace Interact\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\ConsoleOptionParser;
use Interact\Bake\IslandName;
use InvalidArgumentException;
use Override;

/**
 * Scaffolds an application-owned island and its conventional PHP template through Bake.
 *
 * This command is discovered only by the optional Bake plugin. It declares no
 * application behavior and never inspects a database or modifies existing classes.
 */
class IslandCommand extends BakeCommand
{
    protected string $pathFragment = 'Island/';

    /**
     * Collect a conventional name and generate both files using native Bake facilities.
     */
    #[Override]
    public function execute(): ?int
    {
        try {
            $this->extractCommonProperties($this->args);
            $name = $this->islandName();
            if (!$name instanceof IslandName) {
                return static::CODE_ERROR;
            }

            $this->plugin = $name->plugin;

            $pagination = (bool)$this->args->getOption('pagination');
            if (!$this->args->hasArgument('name') && !$pagination) {
                $pagination = strtolower($this->io->askChoice('Enable pagination?', ['y', 'n'], 'n')) === 'y';
            }

            return $this->generate($name, $pagination);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->io->error($invalidArgumentException->getMessage());

            return static::CODE_ERROR;
        }
    }

    /**
     * Offer the same small scaffold interactively or through explicit command arguments.
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $this->setCommonOptions($parser)
            ->removeOption('connection')
            ->setDescription('Create an empty island class and its matching PHP template.')
            ->addArgument('name', [
                'help' => 'Island name, such as Counter, Admin/Article/Listing, or Commerce.Article/Editor.',
            ])
            ->addOption('pagination', [
                'boolean' => true,
                'help' => 'Add a PaginationState declaration without generating queries or controls.',
            ])
            ->setEpilog([
                'bin/cake bake island',
                'bin/cake bake island Counter',
                'bin/cake bake island Admin/Article/Listing --pagination',
                'bin/cake bake island Commerce.Article/Editor',
                'bin/cake bake island Article/Editor --plugin Commerce',
            ]);
    }

    /**
     * Re-prompt invalid interactive names while treating empty or closed input as cancellation.
     */
    protected function islandName(): ?IslandName
    {
        do {
            $input = trim(
                $this->args->getArgument('name') ?? $this->io->ask(
                    'Island name (for example Counter or Admin/Article/Listing):',
                ),
            );
            if ($input === '') {
                $this->io->error('An island name is required. No files were generated.');

                return null;
            }

            try {
                return new IslandName($input, $this->plugin ?: null);
            } catch (InvalidArgumentException $exception) {
                $this->io->error($exception->getMessage());
            }
        } while (!$this->args->hasArgument('name'));

        return null;
    }

    /**
     * Render both scaffold templates before writing and report incomplete file generation.
     */
    protected function generate(IslandName $name, bool $pagination): int
    {
        $renderer = $this->createTemplateRenderer()->set([
            'namespace' => $name->namespace(),
            'className' => basename($name->relative) . 'Island',
            'pagination' => $pagination,
        ]);
        $files = [
            $this->getPath() . $name->relative . 'Island.php' => $renderer->generate('Interact.Island/island'),
            $this->getTemplatePath('island') . $name->template() => $renderer->generate(
                'Interact.Island/template',
            ),
        ];
        $complete = true;
        foreach ($files as $path => $contents) {
            $complete = $this->writeFile($this->io, $path, $contents, $this->force) && $complete;
        }

        if (!$complete) {
            $this->io->error('Generation was incomplete. Review the file results above.');

            return static::CODE_ERROR;
        }

        $this->io->success('Island scaffold ready.');
        $this->io->out(sprintf("Mount with: <?= \$this->Interact->render('%s') ?>", $name->name));

        return static::CODE_SUCCESS;
    }
}
