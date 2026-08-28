<?php

declare(strict_types=1);

// The idempotency key on submission (#30).
//
// WHAT IS TESTED: the dataset does not count the same job twice because a client
// dies and replays its spool.
//
// The server deduplicates on an Idempotency-Key. The guard works ONLY if the
// replay carries the SAME key as the first attempt. A fresh key per submission
// would give two different keys -> no dedup -> the bug is not fixed. So: (a) the
// key is SET on the write, and (b) it is the SAME when the record is sent again
// from the spool.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;

use function Batchwatch\idemComplete;
use function Batchwatch\idemStart;

$h = new Harness('idempotency (#30)');

/** @return list<array<string,mixed>> */
$lines = function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = json_decode($l, true);
        }
    }
    return $out;
};

/** @param array<string,mixed> $call */
$key = fn (array $call): ?string => $call['headers']['idempotency-key'] ?? null;

$h->test('the start call carries an idempotency key', function (Harness $t) use ($key): void {
    $s = new FakeServer();
    try {
        $bw = new Client(baseUrl: $s->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: fn ($tr) => $tr->done(outputTokens: 4519));
        $start = $s->callsTo('/v1/calls', 'POST');
        $t->assertNotEmpty($start, 'no POST /v1/calls');
        $k = $key($start[count($start) - 1]);
        $t->assertNotNull($k, 'the start call sent no idempotency key');
        $t->assertTrue(str_starts_with((string) $k, 'bw-start-'), "unexpected start key: {$k}");
    } finally {
        $s->close();
    }
});

$h->test('the complete fallback carries a key', function (Harness $t) use ($key): void {
    // No "id" in the /v1/calls response -> the client falls back to complete.
    $s = new FakeServer(responses: ['/v1/calls' => []]);
    try {
        $bw = new Client(baseUrl: $s->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: fn ($tr) => $tr->done(outputTokens: 4519));
        $comp = $s->callsTo('/v1/calls/complete', 'POST');
        $t->assertNotEmpty($comp, 'no complete fallback');
        $k = $key($comp[count($comp) - 1]);
        $t->assertNotNull($k, 'complete sent no idempotency key');
        $t->assertTrue(str_starts_with((string) $k, 'bw-complete-'), "unexpected complete key: {$k}");
    } finally {
        $s->close();
    }
});

$h->test('a replay from the spool carries the SAME key', function (Harness $t) use ($lines, $key): void {
    withTmp(function (string $dir) use ($t, $lines, $key): void {
        $path = $dir . '/spool.jsonl';
        // Server down: the measurement lands in the spool.
        $down = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: $path);
        $down->track('gpt-5.6-sol', inputTokens: 100, block: fn ($tr) => $tr->done(outputTokens: 40));
        $records = $lines($path);
        $t->assertCount(1, $records, 'the measurement did not reach the spool');

        $expected = idemComplete($records);
        $t->assertNotNull($expected, 'could not derive the key');
        $t->assertTrue(str_starts_with((string) $expected, 'bw-complete-'), 'unexpected key');

        $s = new FakeServer();
        try {
            $up = new Client(baseUrl: $s->url, token: 'tk_test', timeout: 2.0, spool: $path);
            $t->assertEquals(1, $up->flushSpool(), 'expected 1 accepted');
            $calls1 = $s->callsTo('/v1/calls/complete', 'POST');
            $key1 = $key($calls1[count($calls1) - 1]);
            $t->assertEquals($expected, $key1, 'the first replay used a different key than derived');

            // ANOTHER client replays the SAME record (the first response was
            // lost, so the record is still there). The key must be identical.
            $path2 = $dir . '/spool2.jsonl';
            file_put_contents($path2, json_encode($records[0], JSON_UNESCAPED_SLASHES) . "\n");
            $again = new Client(baseUrl: $s->url, token: 'tk_test', timeout: 2.0, spool: $path2);
            $t->assertEquals(1, $again->flushSpool(), 'expected 1 accepted on replay');
            $calls2 = $s->callsTo('/v1/calls/complete', 'POST');
            $key2 = $key($calls2[count($calls2) - 1]);
            $t->assertEquals($key1, $key2, '#30 not fixed: the replay used a different key');
        } finally {
            $s->close();
        }
    });
});

$h->test("the key is deterministic and honours the server's contract", function (Harness $t): void {
    $record = [
        'provider' => 'openai', 'model' => 'gpt-5.6-sol', 'mode' => 'batch',
        'started_at' => '2026-08-25T10:00:00Z', 'ended_at' => '2026-08-25T10:04:00Z',
        'status' => 'completed',
    ];
    $t->assertEquals(idemStart($record), idemStart($record));
    $t->assertEquals(idemComplete([$record]), idemComplete([$record]));
    foreach ([idemStart($record), idemComplete([$record])] as $k) {
        $t->assertTrue($k !== '' && strlen((string) $k) <= 255, 'key length out of bounds');
        $t->assertTrue(preg_match('/[\x00-\x1f\x7f]/', (string) $k) === 0, 'the key has a control character');
    }
});

exit($h->run());
