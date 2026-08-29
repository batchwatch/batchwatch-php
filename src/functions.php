<?php

declare(strict_types=1);

// Namespace-level constants and free functions for the batchwatch client.
//
// They live in their own file for two reasons: PSR-4 only autoloads CLASSES, so
// free functions and constants must be loaded explicitly (composer "files", or
// bootstrap.php without composer); and both Client and Spool share some of them
// (MAX_BATCH/MAX_BYTES), so keeping them in one file avoids a circular
// dependency.
//
// The file is idempotent: it can be require_once'd from both Client.php and
// Spool.php without redeclaring anything.

namespace Batchwatch;

// Idempotent guard: if the file is already loaded (by composer "files", by
// bootstrap.php, or by a require_once in Client/Spool) then everything is
// declared and we return immediately. const/function cannot sit inside an
// if-block at compile time, so an early file-return is the clean solution.
if (\defined('Batchwatch\\VERSION')) {
    return;
}

const VERSION = '0.2.1';

// How often, at most, we try to flush the spool on our own.
const SPOOL_INTERVAL_S = 60.0;

// The server's cap on one POST /v1/calls/complete.
const MAX_BATCH = 500;

// Cap on the spool file. If batchwatch is down for a week, a busy pipeline must
// not fill up the user's disk. Once the cap is reached the measurement is
// dropped - and that is the right call: his machine is not our storage.
const MAX_BYTES = 5 * 1024 * 1024;

// Field names that may leave the machine. Everything else is absent from the
// body. The test test_no_content.php holds this list fixed. Same fields as the
// Python, Ruby, Go, TypeScript and .NET clients.
//
// The last four (acted_verdict, deadline_s, quoted_p50_s, quoted_p90_s) are the
// outcome measurement (#101): the advice we gave ourselves, attached to the
// later completion so the SERVER can compare its own measured duration against
// what we promised. Everything is numbers and a decision we made ourselves - no
// new PII.
const ALLOWED_FIELDS = [
    'provider', 'model', 'mode', 'endpoint', 'requests',
    'input_tokens', 'output_tokens', 'started_at', 'ended_at',
    'status', 'ttfb_ms', 'source',
    'acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s',
];

/**
 * Drop everything not on the allowlist.
 *
 * The last stop before the network. Even though no public method accepts free
 * text, THIS function must be the place you can point at when someone asks "how
 * do you know a prompt cannot slip out".
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function sanitize(array $body): array
{
    $out = [];
    foreach ($body as $k => $v) {
        if (\in_array((string) $k, ALLOWED_FIELDS, true)) {
            $out[(string) $k] = $v;
        }
    }
    return $out;
}

/**
 * Translate a max_wait into seconds, or null if we cannot parse it.
 *
 * The caller typically writes "15m", "1h", "30s" or "2d" - the same language
 * /v1/should-i-batch itself accepts. A bare number is read as seconds. If we
 * cannot parse it, we send null rather than a guess (rule #30).
 */
function seconds(?string $maxWait): ?float
{
    if ($maxWait === null) {
        return null;
    }
    $s = \strtolower(\trim($maxWait));
    if ($s === '') {
        return null;
    }
    $factor = ['s' => 1.0, 'm' => 60.0, 'h' => 3600.0, 'd' => 86400.0];
    $unit = $s[\strlen($s) - 1];
    if (isset($factor[$unit])) {
        $number = \substr($s, 0, -1);
        $mult = $factor[$unit];
    } else {
        $number = $s;
        $mult = 1.0;
    }
    $number = \trim($number);
    if ($number === '' || !\is_numeric($number)) {
        return null;
    }
    return (float) $number * $mult;
}

/**
 * The fields from a stored advice that may be attached to a completion (#101).
 *
 * Only non-null values are included: if a percentile or a deadline is missing,
 * the field is OMITTED entirely - we do not invent a zero (rule #30). With no
 * advice at all the result is an empty array, so nothing is attached.
 *
 * @param array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}|null $advice
 * @return array<string,mixed>
 */
function outcomeFields(?array $advice): array
{
    if ($advice === null) {
        return [];
    }
    $out = ['acted_verdict' => $advice['acted_verdict']];
    foreach (['deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $name) {
        if (($advice[$name] ?? null) !== null) {
            $out[$name] = $advice[$name];
        }
    }
    return $out;
}

/**
 * ISO-8601 UTC, second resolution with a trailing Z - like the other clients.
 */
function iso(float $ts): string
{
    return \gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
}

// ---------------------------------------------------------------- idempotency
//
// The die-and-reflush problem: the process dies with a completed measurement on
// disk, starts again and replays it. If the first attempt already arrived, the
// replay must NOT count the same job twice - a duplicate row drags the
// percentiles (the whole product) towards the slow tail, because the slow calls
// are exactly the ones that time out and get sent again.
//
// The fix is an Idempotency-Key that the server deduplicates on. The rule that
// makes it work: the key is DERIVED FROM THE MEASUREMENT, deterministically, so
// the replay reconstructs exactly the same key as the first attempt. A fresh
// key at send time (a UUID per call) would give the replay a DIFFERENT key, and
// nothing would be deduplicated. The key is a pure function of the
// measurement's content - the record IS its own stored key material.
//
// The scheme follows backfill.py's send(): a readable, named string built from
// the identifying fields; printable ASCII with no control characters.

/**
 * @param array<string,mixed> $body
 */
function idemField(array $body, string $key): string
{
    $v = $body[$key] ?? null;
    return ($v === null) ? '' : (string) $v;
}

/**
 * A short, stable content hash of a record. Hex SHA-256 (first 16 bytes) of the
 * record's canonical JSON, serialised with the same flags the client sends on
 * the wire - so the same record hashes to the same value every time, on every
 * run, and a replay reconstructs it exactly. Printable ASCII, no control chars,
 * well within the server's 255-char key limit.
 *
 * @param array<string,mixed> $body
 */
function contentHash(array $body): string
{
    $canonical = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return substr(hash('sha256', $canonical === false ? '' : $canonical), 0, 32);
}

/**
 * Per-record key for POST /v1/calls (the start call).
 *
 * Keeps the readable provider/model prefix for logs, then a content hash of the
 * full (sanitized) start body. started_at is second-resolution, so two DISTINCT
 * measurements of the same provider+model within one second would otherwise
 * share a key - and the server refuses a reused key that carries a different
 * body (409 body_differs), silently dropping the second measurement. Hashing
 * the whole body keeps distinct measurements apart; an identical body (a retry
 * or a die-and-reflush replay) still hashes the same and dedupes, so #30 holds.
 * This key is only ever computed live at the start POST, never reconstructed
 * from a spool line, so idemComplete's cross-client spool-replay key format is
 * untouched.
 *
 * @param array<string,mixed> $body
 */
function idemStart(array $body): string
{
    return 'bw-start-' . idemField($body, 'provider') . '-' . idemField($body, 'model')
        . '-' . contentHash($body);
}

/**
 * Per-request key for POST /v1/calls/complete. Derived from the group being
 * sent, exactly like backfill.py: a single record is just a group of one. A
 * replay of the SAME records chunks them identically (the spool preserves
 * order), so the same request carries the same key.
 *
 * @param list<array<string,mixed>> $group
 */
function idemComplete(array $group): ?string
{
    if (empty($group)) {
        return null;
    }
    $first = $group[0];
    $last = $group[\count($group) - 1];
    return 'bw-complete-' . idemField($first, 'provider') . '-' . idemField($first, 'started_at')
        . '-' . \count($group) . '-' . idemField($last, 'ended_at');
}

function defaultUrl(): string
{
    $v = \getenv('BATCHWATCH_URL');
    return ($v === false || $v === '') ? 'https://batchwatch.dev' : $v;
}

function defaultTimeout(): float
{
    $v = \getenv('BATCHWATCH_TIMEOUT');
    return ($v === false || $v === '') ? 2.0 : (float) $v;
}

/**
 * Default spool path. An empty string turns the spool off; otherwise a file in
 * the temp directory.
 */
function defaultSpool(): ?string
{
    $v = \getenv('BATCHWATCH_SPOOL');
    if ($v !== false) {
        // An empty string turns the spool off.
        return $v === '' ? null : $v;
    }
    return \rtrim(\sys_get_temp_dir(), '/\\') . \DIRECTORY_SEPARATOR . 'batchwatch-spool.jsonl';
}
