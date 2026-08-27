<?php

declare(strict_types=1);

namespace Batchwatch;

/**
 * En ikke-2xx-svar. Naar aldrig kalderen af en offentlig metode - kun den
 * valgfri debug-logger. Egen fil, saa PSR-4 kan autoloade den hvis en kalder
 * vil catch'e den ved navn.
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
