<?php

declare(strict_types=1);

namespace Batchwatch;

require_once __DIR__ . '/functions.php';

/**
 * Handle for one measurement in flight. Returned by Client::track().
 *
 * Its own file, so PSR-4 can autoload it. Note the thread idiom in Client.php:
 * "in the background" is synchronous in PHP, but error-free towards the caller.
 */
final class Tracking
{
    private Client $bw;
    /** @var array<string,mixed> */
    private array $body;
    private ?int $inputTokens;
    private ?string $id = null;
    private float $t0;
    private bool $finished = false;

    public function __construct(
        Client $bw,
        string $provider,
        string $model,
        string $mode,
        int $requests,
        ?int $inputTokens,
        ?string $endpoint,
    ) {
        $this->bw = $bw;
        $this->body = [
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'requests' => $requests,
            'endpoint' => $endpoint,
        ];
        $this->inputTokens = $inputTokens;
        $this->t0 = (float) time();
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function start(): void
    {
        $body = $this->body;
        $body['input_tokens'] = $this->inputTokens;
        $body['started_at'] = iso($this->t0);

        $this->bw->inBackground(function () use ($body): void {
            $startBody = sanitize($body);
            $r = $this->bw->request('/v1/calls', 'POST', $startBody, null, idemStart($startBody));
            $this->id = ($r !== null && isset($r['id'])) ? (string) $r['id'] : null;
            if ($r !== null && !empty($r['warning'])) {
                // The server's warning is interesting but must never throw.
                error_log('batchwatch: ' . $r['warning']);
            }
            if ($r !== null) {
                $this->bw->maybeFlushSpool();
            }
        });
    }

    /**
     * Update the token count when it is only known after submission.
     */
    public function started(?int $inputTokens = null): void
    {
        if ($inputTokens !== null) {
            $this->inputTokens = $inputTokens;
        }
    }

    /**
     * Close the measurement.
     *
     * outputTokens stays null when you do not know it. It never defaults to
     * zero: zero is a measurement, absence is not, and the server prices them
     * differently on purpose.
     */
    public function done(?int $outputTokens = null, string $status = 'completed', ?int $ttfbMs = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $end = (float) time();

        // The outcome measurement (#101): the latest advice for THIS model,
        // attached to the completion. No advice -> empty array -> the fields are
        // omitted entirely.
        $advice = outcomeFields($this->bw->getAdvice((string) $this->body['model']));

        $this->bw->inBackground(function () use ($outputTokens, $status, $ttfbMs, $end, $advice): void {
            $full = $this->body;
            $full['input_tokens'] = $this->inputTokens;
            $full['output_tokens'] = $outputTokens;
            $full['status'] = $status;
            $full['started_at'] = iso($this->t0);
            $full['ended_at'] = iso($end);
            if ($ttfbMs !== null) {
                $full['ttfb_ms'] = $ttfbMs;
            }
            $full = array_merge($full, $advice);

            if ($this->id !== null) {
                try {
                    $this->bw->request('/v1/calls/' . $this->id, 'PATCH', sanitize(array_merge([
                        'status' => $status,
                        'output_tokens' => $outputTokens,
                        'ttfb_ms' => $ttfbMs,
                        'ended_at' => iso($end),
                    ], $advice)));
                    return;
                } catch (\Throwable $e) {
                    // The server already has the start. A spooled resubmission
                    // can therefore produce a duplicate if the PATCH did arrive
                    // after all - chosen deliberately: a duplicate is visible in
                    // the dataset, a lost measurement is not.
                    $this->bw->spoolMeasurement(sanitize($full));
                    return;
                }
            }
            // The start call never arrived. Send the whole measurement in one go
            // instead of losing it. The key is derived from the measurement, so
            // a later spool replay of exactly this record carries the SAME key
            // and is deduplicated (#30).
            $sanitized = sanitize($full);
            try {
                $this->bw->request('/v1/calls/complete', 'POST', $sanitized, null, idemComplete([$sanitized]));
            } catch (\Throwable $e) {
                $this->bw->spoolMeasurement($sanitized);
            }
        });
    }

    /**
     * Record the job as not completed. An unfinished wait is not a wait.
     */
    public function failed(string $status = 'failed'): void
    {
        $this->done(status: $status);
    }
}
