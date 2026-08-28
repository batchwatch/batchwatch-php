# batchwatch — PHP client

Client for [batchwatch.dev](https://batchwatch.dev): crowdsourced measurement
of queue time on LLM batch APIs.

Batch endpoints cost 50% of the synchronous ones, but "completes within 24
hours" is impossible to plan around. batchwatch measures what the queue
actually does and answers one question: *should I use batch for this job?*

No dependencies. The standard installation — streams, `json`, and nothing
else. It does **not** need the `curl` extension: all HTTP goes through PHP
stream contexts, so it runs on a bare PHP with no extra modules.

## Install

Not published to Packagist yet. Until it is, either point Composer at the
subdirectory, or `require` the bootstrap directly.

**With Composer** (from a `composer.json` that references this repo):

```json
{
  "autoload": { "psr-4": { "Batchwatch\\": "clients/php/src/" }, "files": ["clients/php/src/functions.php"] }
}
```

**Without Composer** — vendor the four files under `src/` and require the
bootstrap; they have no third-party imports:

```php
require __DIR__ . '/clients/php/bootstrap.php';
```

A client you have to run `composer install` to try is a client nobody tries,
so it runs on the bare standard library either way.

## Two lines

```php
use Batchwatch\Client;

$bw = new Client(token: 'tk_...');  // token optional; falls back to $BATCHWATCH_TOKEN

// 1. before you submit — does this belong in the queue?
if ($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m')) {
    $job = $client->batches->create(...);
} else {
    $answer = $client->chat->completions->create(...);
}

// 2. measure it, so the next person gets a better answer
$t = $bw->track('gpt-5.6-sol', inputTokens: 9720);
$result = wait_for($job);
$t->done(outputTokens: $result->usage->completion_tokens);
```

Or pass a callback and `track` closes the measurement for you, including on the
error path — an exception thrown inside your callback is recorded as `failed`
and re-thrown untouched:

```php
$bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($t) use ($job) {
    $result = wait_for($job);
    $t->done(outputTokens: $result->usage->completion_tokens);
});
```

Get a key with no email and no card:

    curl -X POST https://batchwatch.dev/v1/keys -d '{"label":"my pipeline"}'

## It fails open, always

If batchwatch is down, slow, or broken, your job must not notice. That is the
first requirement, ahead of collecting any data at all.

- **No batchwatch error ever reaches you.** Every error is swallowed and passed
  to the optional `logger` at debug level. Nothing is printed unless you wire
  one up. The only exception that ever leaves this library is your own.
- **Two-second timeout by default** (`BATCHWATCH_TIMEOUT`), applied to both the
  connect and the read, so a server that accepts but never answers cannot hold
  you.
- `shouldBatch()` is the one synchronous call, because you are waiting for the
  answer. If it cannot answer, you get your own `default` back — never a guess.
  The default is `false`, "run it synchronously": being wrong that way costs
  money, being wrong the other way blows a deadline.

`tests/test_fail_open.php` proves it against a port nothing listens on and
against a socket that accepts but never answers.

### A note on threads — the PHP idiom difference (read this)

The Python, Ruby, TypeScript and Go clients put every network call on a
**background thread**, so the caller's thread is not touched at all. **PHP CLI
has no real background threads.** This client therefore mirrors the *semantics*
of fail-open, not the literal thread:

- Submissions run **synchronously** on your thread, but are (a) bounded by the
  short connect-and-read timeout, and (b) wrapped so **no batchwatch error can
  reach you**.
- The worst case cost to you is therefore the timeout (default 2 s) per
  submission — never a hang, never an exception. Because both the `start`
  (POST) and the `done` (PATCH) run synchronously, a fully dead server costs up
  to *two* timeouts across a `track(...); done(...)` pair.
- If you cannot afford even that, set a low `BATCHWATCH_TIMEOUT`, or
  `enabled: false` in CI, which turns every network call into a no-op.
- `flush()` exists for API parity with the threaded clients. In PHP there is
  never anything outstanding to wait for, so it always returns `true`.

This is the one deliberate difference from the other clients. The fail-open
*guarantee* — your job never breaks and never hangs because of batchwatch — is
identical; only the mechanism (bounded-synchronous instead of a thread) differs.

## It never sends your content

No prompts, no completions, no system prompts, no tool calls, no file names.
The request body is built from a fixed allowlist — provider, model, mode,
endpoint, request count, token counts, timestamps, status, ttfb, source — and
everything else is dropped by `Batchwatch\sanitize()` on the way out. There is no
field to put text in.

`tests/test_no_content.php` asserts it on the bytes a real HTTP server
received, and includes a positive control so the test cannot pass by the client
simply sending nothing. The `sanitize()` unit tests are the guard you can point at
when someone asks how you know a prompt cannot escape.

## `outputTokens` defaults to `null`, never `0`

You know your input tokens. You cannot know your output tokens before the model
has answered. So the default is absence (`null`), not zero.

Zero is not a harmless placeholder here: output costs five to six times as much
as input, so a saving computed on zero output is systematically too low —
measured at 3.4x too low on a real model — and nothing in the response would
tell you. If you know a ceiling, pass `maxTokens` instead and the answer comes
back labelled as a ceiling.

Passing `outputTokens: 0` really does send `0`: zero is a measurement, absence
is not.

## Spooling

When a measurement cannot be delivered, the completed record is appended to a
JSONL file and replayed later through `POST /v1/calls/complete`. Losing
measurements exactly when the network is bad means losing them exactly when
they are most interesting.

- Default path: `$BATCHWATCH_SPOOL`, or `batchwatch-spool.jsonl` in the system
  temp directory. Set `BATCHWATCH_SPOOL=""` or pass `spool: null` to turn it
  off. **Omitting** the `spool:` argument keeps the default — only an explicit
  `null` disables it.
- The spool is replayed automatically, at most once a minute, right after a
  successful call — that is the moment we know the network is up. Call
  `$bw->flushSpool()` yourself from a shutdown hook if you want it drained on
  exit.
- **Spooling requires a token.** `/v1/calls/complete` takes your own
  timestamps, so it is closed to anonymous callers; without a key a spool file
  could never be sent, and writing one would just leak disk. `$bw->spool()` is
  `null` when no token is set.
- The file is capped at 5 MB. Beyond that, measurements are dropped rather than
  filling your disk.
- A replayed measurement can arrive twice if the original `PATCH` reached the
  server but the response did not. That is deliberate: a duplicate is visible
  in the dataset, a lost measurement is not.
- The file format is identical across the Python, Ruby, TypeScript, Go and .NET
  clients, so a spool written by one can be flushed by another.
- **Concurrency:** within one process (and across processes on the same host)
  writers are serialised with an advisory file lock (`flock`). Because PHP CLI
  is single-threaded, in practice one writer runs at a time; but shutdown hooks
  and re-entrant calls can still overlap, so the lock stays. Two *separate*
  processes flushing the same file can still send a record twice — give each
  process its own `BATCHWATCH_SPOOL` if that matters.

## Configuration

| Argument   | Environment          | Default                                   |
|------------|----------------------|-------------------------------------------|
| `token`    | `BATCHWATCH_TOKEN`   | none (anonymous)                          |
| `baseUrl`  | `BATCHWATCH_URL`     | `https://batchwatch.dev`                  |
| `timeout`  | `BATCHWATCH_TIMEOUT` | `2.0` seconds                             |
| `spool`    | `BATCHWATCH_SPOOL`   | `<tempdir>/batchwatch-spool.jsonl`        |
| `enabled`  | —                    | `true`                                    |
| `logger`   | —                    | `null` (nothing logged)                   |

`enabled: false` turns every network call into a no-op, which is what you want
in CI.

## API

- `shouldBatch(string $model, ?string $maxWait = null, bool $default = false, array $kw = []): bool`
  — extra advice options (`provider`, `inputTokens`, `outputTokens`, `maxTokens`,
  `risk`) go in `$kw`.
- `advice(string $model, ?string $maxWait = null, string $provider = 'openai', ?int $inputTokens = null, ?int $outputTokens = null, ?int $maxTokens = null, string $risk = 'p90'): ?array`
- `waitNow(string $model, string $provider = 'openai', string $mode = 'batch'): ?array`
- `track(string $model, string $provider = 'openai', string $mode = 'batch', int $requests = 1, ?int $inputTokens = null, ?string $endpoint = null, ?callable $blok = null): Tracking`
  — with or without a callback.
  - `$t->done(?int $outputTokens = null, string $status = 'completed', ?int $ttfbMs = null): void`
  - `$t->failed(string $status = 'failed'): void`
  - `$t->started(?int $inputTokens = null): void` — when the count is only known after submission
- `flush(float $timeout = 5.0): bool` — parity no-op in PHP (always `true`; there is no background work to await)
- `flushSpool(?float $timeout = null): int` — send what is on disk, returns accepted

## Tests

    php tests/run.php
    # or a single suite:
    php tests/test_fail_open.php

28 tests, no network beyond loopback. They start real HTTP servers on
ephemeral ports (raw `stream_socket_server`, port 0, in a forked child via
`pcntl_fork`) rather than stubbing the stream layer: the thing under test is
network behaviour, so the network should be in the test. The allowlist and
fail-open tests carry positive controls, so a client that sent nothing at all
would fail them rather than pass, and the concurrency test spawns 40 forked
writers so the `flock` guard is exercised for real.

The runner and the fake-server harness use `pcntl`/`posix` — that is a **test**
requirement only. The client itself uses neither.

## What is not verified

- **No live round-trip.** The suite runs against loopback servers and a fake
  batchwatch; the request shapes were mirrored from the verified Python and
  Ruby clients, not confirmed against a running batchwatch worker.
- **PHP 8.2.32 only.** `composer.json` claims `>=8.2` on the strength of the
  syntax used, not a test matrix. Only 8.2.32 has actually been run.
- **Not on Packagist.** No `composer validate`/publish has been performed; the
  `composer.json` is written but publishing is unverified. The tests run
  without `composer install` by design.
- **The no-threads idiom is a real behavioural difference** from the other
  clients (see above): fail-open is preserved, but submissions are
  bounded-synchronous rather than off-thread.

## Licence

MIT
