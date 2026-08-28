<?php

declare(strict_types=1);

namespace Batchwatch;

/**
 * A non-2xx response. Never reaches the caller of a public method - only the
 * optional debug logger. Its own file, so PSR-4 can autoload it if a caller
 * wants to catch it by name.
 */
final class HttpError extends \RuntimeException
{
    public int $status;
    public string $bodyText;

    public function __construct(int $status, string $bodyText)
    {
        $this->status = $status;
        $this->bodyText = $bodyText;
        parent::__construct("batchwatch {$status}");
    }
}
