<?php

declare(strict_types=1);

namespace Batchwatch;

/**
 * Thrown when an action REQUIRES a key and none is set.
 *
 * The measurement path fails open - with no key it degrades silently, because a
 * missing telemetry call must never stand in the way of the caller's job. But
 * reading your own contributions or your key status is an EXPLICIT action
 * against a per-key route: here a silent empty answer would be a lie - the
 * caller would read "no contributions" where the truth is "no key to read
 * with". So we say it out loud instead. Its own file, so PSR-4 can autoload it
 * if a caller wants to catch it by name.
 */
final class BatchwatchAuthError extends \RuntimeException
{
}
