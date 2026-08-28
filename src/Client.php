<?php

declare(strict_types=1);

// batchwatch client.
//
// Two rules shape this file.
//
// **It never fails upward.** If batchwatch is down, slow or broken, the
// caller's batch job must not notice. Every network call has a short timeout
// (both connect and read), and every error is swallowed and passed to the
// optional debug logger. The only exception that ever leaves this module is the
// caller's own.
//
// **It never sends content.** Prompts, completions, system prompts, tool calls,
// file names - none of it. The body is built from a fixed allowlist of fields:
// provider, model, mode, endpoint, request count, token counts and timestamps.
// There is no field to put text in, by construction.
//
// **A note on threads.** The Python and Ruby clients put every network call on
// a background thread, so the caller's thread is not touched at all. PHP CLI
// has no real background threads. We therefore mirror the SEMANTICS, not the
// thread: the submission happens synchronously, but is (a) bounded by a short
// timeout on both connect and read, and (b) wrapped so NO batchwatch error can
// reach the caller. The caller thus pays at most the timeout (default 2 s) per
// submission in the worst case - never a hang, never an exception. If you would
// rather not pay even that, set a low BATCHWATCH_TIMEOUT or enabled=false.
// flush() exists for API parity, but has nothing to wait on.
//
//     use Batchwatch\Client;
//
//     $bw = new Client(token: 'tk_...');            // token is optional
//
//     // 1) before you submit: does this belong in the queue?
//     if ($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m')) {
//         // $job = $client->batches->create(...);
//     } else {
//         // $answer = $client->chat->completions->create(...);
//     }
//
//     // 2) measure it
//     $t = $bw->track('gpt-5.6-sol', inputTokens: 9720);
//     // ...
//     $t->done(outputTokens: 4519);

namespace Batchwatch;

// Constants (VERSION, ALLOWED_FIELDS, ...) and free functions (sanitize, iso,
// defaultUrl, ...) live in functions.php, so Client and Spool share one copy
// and PSR-4 (which only autoloads classes) does not leave them undefined.
// require_once is idempotent - composer may have loaded it already.
require_once __DIR__ . '/functions.php';
// HttpError and Tracking live in their own files (clean PSR-4), but we load
// them here too, so bootstrap.php without composer still works. require_once is
// idempotent.
require_once __DIR__ . '/HttpError.php';
require_once __DIR__ . '/Tracking.php';

/**
 * The client. Every submission is non-blocking for the caller's LOGIC (no
 * exception escapes) and bounded by a short timeout, and it fails open.
 */
final class Client
{
    // Sentinel for the spool argument, so we can tell "spool not provided" (use
    // default) apart from "spool: null" (turn it off). Ruby has a hidden UNSET;
    // PHP cannot use an object as a default argument, but an impossible path as
    // a default string does the same job. A real path is never equal to this.
    public const SPOOL_DEFAULT = "\0__BATCHWATCH_SPOOL_DEFAULT__";

    private ?string $token;
    private string $base;
    private float $timeout;
    private bool $enabled;
    /** @var callable|null fn(string $message): void */
    private $logger;
    private ?Spool $spool;
    private float $spoolLast = 0.0;
    /**
     * Last advice per model (#101). When shouldBatch answers, we store here what
     * we recommended + the quoted percentiles, so the NEXT completion for the
     * same model can attach them. The correlation is a documented approximation:
     * latest-advice-per-model, not per-job.
     *
     * @var array<string, array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}>
     */
    private array $advice = [];

    /**
     * @param string|null $token   API key. Falls back to $BATCHWATCH_TOKEN.
     *                             Optional for measuring, required to replay a spool.
     * @param string|null $baseUrl Default $BATCHWATCH_URL or https://batchwatch.dev
     * @param float|null  $timeout Seconds per HTTP call. Default $BATCHWATCH_TIMEOUT or 2.0.
     * @param bool        $enabled false makes every network call a no-op.
     * @param string|null $spool   Path to the spool file. OMIT for the default
     *                             ($BATCHWATCH_SPOOL or a temp file); pass null to
     *                             turn spooling off; pass a string for a specific path.
     *                             Spooling is always inactive without a token.
     * @param callable|null $logger Optional logger fn(string): void. Every swallowed error goes
     *                             here at debug level.
     */
    public function __construct(
        ?string $token = null,
        ?string $baseUrl = null,
        ?float $timeout = null,
        bool $enabled = true,
        ?string $spool = self::SPOOL_DEFAULT,
        ?callable $logger = null,
    ) {
        $tokenIn = $token ?: (getenv('BATCHWATCH_TOKEN') ?: null);
        $this->token = ($tokenIn === '') ? null : $tokenIn;
        $this->base = rtrim($baseUrl ?? defaultUrl(), '/');
        $this->timeout = $timeout ?? defaultTimeout();
        $this->enabled = $enabled;
        $this->logger = $logger;

        // Sentinel (omitted) => default path; explicit null => spooling off;
        // otherwise the provided path.
        $path = ($spool === self::SPOOL_DEFAULT) ? defaultSpool() : $spool;
        // Without a key /v1/calls/complete cannot accept, so a spool file could
        // never be sent. So we simply do not write it.
        $this->spool = ($path !== null && $this->token !== null)
            ? new Spool($path, MAX_BYTES, $logger)
            : null;
        if ($path !== null && $this->token === null) {
            $this->debug('batchwatch: no token - spool turned off '
                . '(/v1/calls/complete requires a key)');
        }
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function spool(): ?Spool
    {
        return $this->spool;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    // ----------------------------------------------------------------- core

    /**
     * One HTTP call. Throws on error - only public methods swallow.
     *
     * Uses PHP streams (not curl - the client must run on the bare standard
     * installation). Both the connect and the read timeout are set, so a server
     * that accepts but never answers cannot hold us captive longer than the
     * deadline.
     *
     * @param array<string,mixed>|list<mixed>|null $body
     * @return array<string,mixed>|null
     */
    public function request(string $path, string $method = 'GET', array|null $body = null, ?float $timeout = null, ?string $idem = null): ?array
    {
        $deadline = $timeout ?? $this->timeout;
        $url = $this->base . $path;

        $headers = [
            'user-agent: batchwatch-php/' . VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'content-type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($this->token !== null) {
            $headers[] = 'authorization: Bearer ' . $this->token;
        }
        // Idempotency-Key on the write paths, so the server can deduplicate a
        // resubmission (#30). The key is derived deterministically from the
        // measurement.
        if ($idem !== null && $idem !== '') {
            $headers[] = 'idempotency-key: ' . $idem;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload ?? '',
                'timeout' => $deadline,          // read timeout
                'ignore_errors' => true,         // read the body on 4xx/5xx too
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // The connect timeout is handled separately, because the stream-context
        // "timeout" only covers reading/writing, not the connection itself. We
        // therefore open the socket first with the same deadline, so a
        // black-hole server that never ACKs cannot hold us longer than the
        // deadline either.
        $this->ensureConnection($url, $deadline);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $error = error_get_last();
            throw new \RuntimeException('batchwatch network error: ' . ($error['message'] ?? 'unknown'));
        }

        $code = $this->statusFromHeaders($http_response_header ?? []);
        if ($code < 200 || $code >= 300) {
            throw new HttpError($code, $raw);
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $out = json_decode($raw, true);
        return is_array($out) ? $out : null;
    }

    /**
     * Open a TCP/TLS connection with a hard deadline, so a server that never
     * accepts/ACKs cannot hold us captive - the stream-context "timeout" does
     * not cover the connection itself. We close it again straight away;
     * file_get_contents makes its own. Throws on timeout/refuse, so request()
     * swallows it.
     */
    private function ensureConnection(string $url, float $deadline): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return; // let file_get_contents handle a malformed URL
        }
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            $deadline,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            throw new \RuntimeException("batchwatch: could not connect ({$errstr})");
        }
        fclose($sock);
    }

    /**
     * @param list<string> $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                // On redirects there can be several status lines; the last one
                // wins. ignore_errors=true + follow_location=0 normally leaves
                // us with only one, though.
                $last = (int) $m[1];
            }
        }
        return $last ?? 0;
    }

    /**
     * Run a submission "in the background". PHP CLI has no real threads, so it
     * is synchronous - but errors are swallowed, so this must never take the
     * caller's own call down with it. See the file header on the thread idiom.
     *
     * @param callable(): void $block
     */
    public function inBackground(callable $block): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $block();
        } catch (HttpError $e) {
            $this->debug("batchwatch {$e->status}: {$e->bodyText}");
        } catch (\Throwable $e) {
            $this->debug('batchwatch unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Exists for API parity with Python/Ruby. Submissions are synchronous in
     * PHP, so there is never anything outstanding to wait on; always returns
     * true.
     */
    public function flush(float $timeout = 5.0): bool
    {
        return true;
    }

    // ------------------------------------------------------------- decision

    /**
     * What the queue is doing right now, or null if we cannot say.
     *
     * @return array<string,mixed>|null
     */
    public function waitNow(string $model, string $provider = 'openai', string $mode = 'batch'): ?array
    {
        try {
            $q = http_build_query(['provider' => $provider, 'model' => $model, 'mode' => $mode]);
            $r = $this->request('/v1/wait?' . $q);
            if (!$r || ($r['verdict'] ?? null) === 'insufficient_data') {
                return null;
            }
            return $r;
        } catch (\Throwable $e) {
            $this->debug('batchwatch wait failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The full verdict. Returns null if we cannot answer.
     *
     * output_tokens is usually UNKNOWN at this point - the model decides them.
     * The default is therefore null, not zero. Sending zero would make the
     * server compute the saving on zero output, and output costs five to six
     * times as much as input: the answer would be systematically too low,
     * without anyone being able to see it. If you know a ceiling, pass
     * maxTokens.
     *
     * @return array<string,mixed>|null
     */
    public function advice(
        string $model,
        ?string $maxWait = null,
        string $provider = 'openai',
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $maxTokens = null,
        string $risk = 'p90',
    ): ?array {
        $q = ['provider' => $provider, 'model' => $model, 'risk' => $risk];
        foreach ([
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'max_tokens' => $maxTokens,
        ] as $name => $value) {
            if ($value !== null) {
                $q[$name] = (int) $value;
            }
        }
        if ($maxWait !== null) {
            $q['max_wait'] = $maxWait;
        }
        try {
            return $this->request('/v1/should-i-batch?' . http_build_query($q));
        } catch (\Throwable $e) {
            $this->debug('batchwatch advice failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * true/false. On any doubt you get your own default back.
     *
     * We never guess on the caller's behalf: if we cannot answer, the caller
     * gets their own predetermined value. The default is false - "run it
     * synchronously" - which is the safe way to be wrong, because a synchronous
     * call just costs more, while an unexpected eight-hour queue can take a
     * product down.
     *
     * @param array<string,mixed> $kw extra advice() arguments (provider, inputTokens, ...)
     */
    public function shouldBatch(string $model, ?string $maxWait = null, bool $default = false, array $kw = []): bool
    {
        $r = $this->advice(
            $model,
            maxWait: $maxWait,
            provider: $kw['provider'] ?? 'openai',
            inputTokens: $kw['inputTokens'] ?? $kw['input_tokens'] ?? null,
            outputTokens: $kw['outputTokens'] ?? $kw['output_tokens'] ?? null,
            maxTokens: $kw['maxTokens'] ?? $kw['max_tokens'] ?? null,
            risk: $kw['risk'] ?? 'p90',
        );
        if (!$r) {
            return $default;
        }
        $answer = match ($r['verdict'] ?? null) {
            'run_batch' => true,
            'run_sync', 'batch_at' => false,
            default => $default,
        };
        $this->rememberAdvice($model, $answer, $maxWait, $r);
        return $answer;
    }

    /**
     * Store the advice we just gave for THIS model (#101). Only numbers and our
     * own decision end up here - no user data. The quoted percentiles are taken
     * from the server's answer; if one of them is missing, we store null and
     * simply do not attach it (rule #30: no invented 0).
     *
     * @param array<string,mixed> $answer
     */
    private function rememberAdvice(string $model, bool $actedVerdict, ?string $maxWait, array $answer): void
    {
        $number = static fn ($v): ?float => \is_int($v) || \is_float($v) ? (float) $v : null;
        $this->advice[$model] = [
            'acted_verdict' => $actedVerdict,
            'deadline_s' => seconds($maxWait),
            'quoted_p50_s' => $number($answer['p50_s'] ?? null),
            'quoted_p90_s' => $number($answer['p90_s'] ?? null),
        ];
    }

    /**
     * The latest advice for a model, or null. The approximation is
     * latest-advice-per-model: the newest shouldBatch attaches to the next
     * completion of the same model.
     *
     * @return array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}|null
     */
    public function getAdvice(string $model): ?array
    {
        return $this->advice[$model] ?? null;
    }

    // ---------------------------------------------------------- measurement

    /**
     * Measure one call. The submission happens "in the background"
     * (synchronously, but error-free towards the caller - see the file header).
     *
     * No exception from batchwatch ever reaches the caller. Without a callback
     * the tracking is returned and you call done() yourself. With a callback it
     * works like the Python context manager: an exception from your own block is
     * recorded as "failed" and re-thrown untouched.
     *
     * @param callable(Tracking): void|null $block
     */
    public function track(
        string $model,
        string $provider = 'openai',
        string $mode = 'batch',
        int $requests = 1,
        ?int $inputTokens = null,
        ?string $endpoint = null,
        ?callable $block = null,
    ): Tracking {
        $t = new Tracking($this, $provider, $model, $mode, $requests, $inputTokens, $endpoint);
        $t->start();
        if ($block === null) {
            return $t;
        }
        try {
            $block($t);
        } catch (\Throwable $e) {
            // We swallow our own errors, never the caller's.
            $t->done(status: 'failed');
            throw $e;
        }
        if (!$t->finished()) {
            $t->done();
        }
        return $t;
    }

    // --------------------------------------------------------------- spool

    /**
     * Send everything waiting on disk. Returns the number accepted.
     *
     * Synchronous and safe to call from a shutdown hook. Never throws. Records
     * the server rejects as invalid are dropped - they never become valid - and
     * the count is logged.
     */
    public function flushSpool(?float $timeout = null): int
    {
        if ($this->spool === null || !$this->enabled) {
            return 0;
        }
        $records = $this->spool->take();
        if (empty($records)) {
            return 0;
        }
        $sent = 0;
        $rest = $records;
        while (!empty($rest)) {
            $group = array_slice($rest, 0, MAX_BATCH);
            $rest = array_slice($rest, MAX_BATCH);
            try {
                // The key is derived from the group itself: a replay after a
                // crash chunks the records identically (the spool preserves
                // order), so the key is the same and the server deduplicates the
                // double write.
                $r = $this->request(
                    '/v1/calls/complete',
                    'POST',
                    array_values($group),
                    $timeout ?? max($this->timeout, 10.0),
                    idemComplete(array_values($group)),
                );
            } catch (\Throwable $e) {
                $this->debug('batchwatch: spool could not be sent: ' . $e->getMessage());
                // The group that failed stays put together with the rest.
                $this->spool->keep(array_merge($group, $rest));
                return $sent;
            }
            $sent += (int) (($r ?? [])['accepted'] ?? 0);
            $rejected = (int) (($r ?? [])['rejected'] ?? 0);
            if ($rejected > 0) {
                $this->debug("batchwatch: {$rejected} spooled measurements were rejected and dropped");
            }
        }
        $this->spool->keep([]);
        $this->debug("batchwatch: {$sent} spooled measurements sent");
        return $sent;
    }

    /**
     * Called AFTER a successful call - so we know the network is up right now,
     * and we avoid hammering a server that is not answering anyway.
     */
    public function maybeFlushSpool(): void
    {
        if ($this->spool === null) {
            return;
        }
        $now = $this->monotonic();
        if ($now - $this->spoolLast < SPOOL_INTERVAL_S) {
            return;
        }
        $this->spoolLast = $now;
        try {
            $this->flushSpool();
        } catch (\Throwable $e) {
            $this->debug('batchwatch: spool flush failed: ' . $e->getMessage());
        }
    }

    /**
     * Store a COMPLETED measurement that could not be delivered.
     *
     * @param array<string,mixed> $body
     */
    public function spoolMeasurement(array $body): void
    {
        if ($this->spool !== null) {
            $this->spool->append($body);
        } else {
            $this->debug('batchwatch: the measurement was lost (no spool)');
        }
    }

    private function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000.0;
    }

    private function debug(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
