<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite;

use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadManager;

/**
 * Owns one test's staging and saved-document directories and optional configuration scope.
 *
 * Staging is created by storage only, so authorization tests can assert that
 * no staging occurred. Cleanup never traverses symlinks or removes shared uploads.
 */
class UploadFixture
{
    public readonly string $directory;

    public readonly string $documentsDirectory;

    protected readonly ?Chronos $clock;

    protected mixed $previousConfiguration = null;

    protected mixed $previousDocuments = null;

    protected bool $configured = false;

    /**
     * Reserve a unique path without performing filesystem work.
     */
    public function __construct()
    {
        $this->directory = Path::join(TMP, 'upload-test-' . bin2hex(random_bytes(8)));
        $this->documentsDirectory = $this->directory . '-documents';
        $this->clock = DateTime::getTestNow();
    }

    /**
     * Isolate staging and application-saved documents while preserving other settings.
     */
    public function configure(): void
    {
        if (!$this->configured) {
            $this->previousConfiguration = Configure::read('Interact.uploads');
            $this->previousDocuments = Configure::read('Documents');
            $this->configured = true;
        }

        Configure::write('Interact.uploads.directory', $this->directory);
        Configure::write('Documents.directory', $this->documentsDirectory);
    }

    /**
     * Construct matching storage and configuration for manually assembled runtimes.
     *
     * @param array<string, mixed> $options Optional transport limits; the directory remains fixture-owned.
     */
    public function manager(array $options = []): UploadManager
    {
        $config = new UploadConfig(['directory' => $this->directory] + $options);

        return new UploadManager(new LocalUploadStorage($config), $config);
    }

    /**
     * Restore configuration and clock state even if file cleanup fails; repeated cleanup is safe.
     */
    public function close(): void
    {
        try {
            try {
                $this->remove($this->directory);
            } finally {
                $this->remove($this->documentsDirectory);
            }
        } finally {
            DateTime::setTestNow($this->clock);
            if ($this->configured) {
                Configure::delete('Interact.uploads');
                if ($this->previousConfiguration !== null) {
                    Configure::write('Interact.uploads', $this->previousConfiguration);
                }

                Configure::delete('Documents');
                if ($this->previousDocuments !== null) {
                    Configure::write('Documents', $this->previousDocuments);
                }

                $this->configured = false;
            }
        }
    }

    /**
     * Remove owned files, including dotfiles, without following links outside the fixture.
     */
    protected function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = iterator_to_array(new Finder()->in($path)->ignoreHiddenFiles(false)->all(), false);
        foreach (array_reverse($entries) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
