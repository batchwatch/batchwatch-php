<?php

declare(strict_types=1);

// Outcome measurement (#101): the advice we gave is attached to the later
// completion.
//
// So the server can later show "our p90 held X% of the time", the client must
// remember what it recommended - and attach it to the completion of the SAME
// job. The correlation is a documented approximation: latest-advice-per-model.
//
// No new PII: only the model, the times we already send, and the numbers we
// ourselves got back from the server. If we have no advice, we attach nothing -
// and certainly not an invented zero.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;

use function Batchwatch\seconds;

// should-i-batch answer with real percentiles to quote.
const ADVICE_RESPONSE = ['verdict' => 'run_batch', 'p50_s' => 1234.0, 'p90_s' => 5678.0, 'p95_s' => 9000.0];

/**
 * The last completion body (PATCH to /v1/calls/c_...).
 *
 * @return array<string,mixed>
 */
function lastCompletion(FakeServer $server): array
{
    $calls = $server->callsTo('/v1/calls/c_', 'PATCH');
    if (empty($calls)) {
        throw new \AssertionError('no completion captured - the test proves nothing');
    }
    return $calls[count($calls) - 1]['body'];
}

$h = new Harness('verdict_accuracy');

$h->test('the completion carries the advice after shouldBatch', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer(['/v1/should-i-batch' => ADVICE_RESPONSE]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));

        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $body = lastCompletion($server);
        $t->assertTrue($body['acted_verdict'] === true, 'acted_verdict should be true');
        $t->assertEquals(900.0, (float) $body['deadline_s']);        // 15m -> 900 s
        $t->assertEquals(5678.0, (float) $body['quoted_p90_s']);
        $t->assertEquals(1234.0, (float) $body['quoted_p50_s']);     // optional, but sent
    } finally {
        $server->close();
    }
});

$h->test('a completion without prior advice carries nothing', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        // No shouldBatch for this model first.
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $body = lastCompletion($server);
        foreach (['acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $field) {
            $t->assertFalse(array_key_exists($field, $body), "attached {$field} without any advice");
        }
    } finally {
        $server->close();
    }
});

$h->test('the advice is per model', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer(['/v1/should-i-batch' => ADVICE_RESPONSE]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->shouldBatch('gpt-5.6-sol', maxWait: '15m');

        // Complete a COMPLETELY different model - it has no advice.
        $bw->track('claude-4-haiku', inputTokens: 100, block: function ($tr): void {
            $tr->done(outputTokens: 50);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $body = lastCompletion($server);
        $t->assertFalse(array_key_exists('acted_verdict', $body), 'the advice leaked across models');
    } finally {
        $server->close();
    }
});

$h->test('run_sync gives acted_verdict false', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer(['/v1/should-i-batch' => ['verdict' => 'run_sync', 'p50_s' => 12.0, 'p90_s' => 34.0]]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '5m'));

        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $body = lastCompletion($server);
        $t->assertTrue($body['acted_verdict'] === false, 'acted_verdict should be false');
        $t->assertEquals(300.0, (float) $body['deadline_s']);
        $t->assertEquals(34.0, (float) $body['quoted_p90_s']);
    } finally {
        $server->close();
    }
});

$h->test('a missing percentile is omitted, not invented', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer(['/v1/should-i-batch' => ['verdict' => 'run_batch', 'p50_s' => 7.0]]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->shouldBatch('gpt-5.6-sol', maxWait: '15m');
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $body = lastCompletion($server);
        $t->assertTrue($body['acted_verdict'] === true);
        $t->assertEquals(7.0, (float) $body['quoted_p50_s']);
        $t->assertFalse(array_key_exists('quoted_p90_s', $body), 'invented a missing p90');
    } finally {
        $server->close();
    }
});

$h->test('seconds parses the units, otherwise null', function (Harness $t): void {
    $t->assertEquals(900.0, seconds('15m'));
    $t->assertEquals(3600.0, seconds('1h'));
    $t->assertEquals(30.0, seconds('30s'));
    $t->assertEquals(172800.0, seconds('2d'));
    $t->assertEquals(45.0, seconds('45'));        // bare number = seconds
    $t->assertNull(seconds(null));
    $t->assertNull(seconds(''));
    $t->assertNull(seconds('nonsense'));          // unparseable -> null, not a guess
});

$h->test('the advice fields are on the allowlist', function (Harness $t): void {
    foreach (['acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $field) {
        $t->assertInList($field, \Batchwatch\ALLOWED_FIELDS);
    }
});

exit($h->run());
