<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Http\Exception\HttpException;
use Cake\Http\ServerRequest;
use Cake\Utility\Text;

/**
 * Relates staging limits to PHP's earlier multipart parsing limits.
 *
 * Oversized bodies may lose both their snapshot and file before the handler runs.
 * Only transport feedback is possible then; application validation still requires
 * a verified island request and remains in the application's normal workflow.
 */
class UploadLimits
{
    /**
     * Advertise a file ceiling that does not exceed PHP's configured request limits.
     */
    public static function fileSize(int $configured): int
    {
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $limit = static::phpSize($setting);
            if ($limit > 0) {
                $configured = min($configured, $limit);
            }
        }

        return $configured;
    }

    /**
     * Distinguish a discarded multipart body from a malformed island envelope.
     */
    public static function assertRequest(ServerRequest $request): void
    {
        $length = $request->getHeaderLine('Content-Length');
        $limit = static::phpSize('post_max_size');
        if ($limit > 0 && ctype_digit($length) && (int)$length > $limit) {
            throw new HttpException('The multipart request exceeds PHP post_max_size.', 413);
        }
    }

    /**
     * Treat disabled PHP limits as unlimited while parsing CakePHP-compatible size strings.
     */
    protected static function phpSize(string $setting): int
    {
        $value = trim((string)ini_get($setting));

        return $value === '-1' ? 0 : Text::parseFileSize($value);
    }
}
