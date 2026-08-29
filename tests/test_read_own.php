<?php

declare(strict_types=1);

// Reading your own contributions and key status from the client.
//
// Both routes are per-key: they carry the caller's Bearer token, and without a
// key there is nothing to read. So - like the write paths, and UNLIKE the
// measurement/decision path - they do NOT fail open: they throw a clear
// BatchwatchAuthError rather than return an empty answer that a caller would
// misread as "no contributions".
//
// The response shapes are the documented server contract (GET /v1/calls/mine,
// GET /v1/keys/current). The client returns the server's row untouched - nothing
// is renamed.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\BatchwatchAuthError;
use Batchwatch\Client;

$h = new Harness('read own (calls/mine + keys/current)');

$h->test('myCalls hits /v1/calls/mine with Bearer auth and returns the row verbatim', function (Harness $t): void {
    cleanEnv();
    $s = new FakeServer(responses: [
        '/v1/calls/mine' => [
            'label' => 'prod-pipeline',
            'count' => 1,
            'next' => null,
            'calls' => [
                [
                    'id' => 'c_684f85eb12f34bf3b365',
                    'mode' => 'batch',
                    'provider' => 'anthropic',
                    'model' => 'claude-haiku-4-5',
                    'status' => 'completed',
                    'duration_s' => 2460,
                    'excluded' => false,
                ],
            ],
            'note' => 'Everything we hold that came from this key.',
        ],
    ]);
    try {
        $bw = new Client(baseUrl: $s->url, token: 'tk_test', timeout: 2.0);
        $r = $bw->myCalls();

        // The row comes back untouched - keys are not renamed.
        $t->assertNotNull($r, 'myCalls returned null');
        $t->assertEquals(1, $r['count']);
        $t->assertNull($r['next']);
        $t->assertEquals('claude-haiku-4-5', $r['calls'][0]['model']);
        $t->assertEquals('prod-pipeline', $r['label']);

        $calls = $s->callsTo('/v1/calls/mine', 'GET');
        $t->assertCount(1, $calls, 'exactly one GET /v1/calls/mine was not sent');
        $t->assertEquals('/v1/calls/mine', $calls[0]['path'], 'no query when unset');
        $t->assertEquals('Bearer tk_test', $calls[0]['headers']['authorization'] ?? null);
    } finally {
        $s->close();
    }
});

$h->test('myCalls passes after and limit as query params', function (Harness $t): void {
    cleanEnv();
    $s = new FakeServer(responses: ['/v1/calls/mine' => ['count' => 0, 'next' => null, 'calls' => []]]);
    try {
        $bw = new Client(baseUrl: $s->url, token: 'tk_test', timeout: 2.0);
        $bw->myCalls(after: 1787666964, limit: 100);

        $calls = $s->callsTo('/v1/calls/mine', 'GET');
        $t->assertNotEmpty($calls, 'no GET /v1/calls/mine');
        $path = $calls[count($calls) - 1]['path'];
        $t->assertContains('after=1787666964', $path);
        $t->assertContains('limit=100', $path);
    } finally {
        $s->close();
    }
});

$h->test('keyStatus hits /v1/keys/current with Bearer auth and returns the row verbatim', function (Harness $t): void {
    cleanEnv();
    $s = new FakeServer(responses: [
        '/v1/keys/current' => [
            'label' => 'prod-pipeline',
            'tier' => 'contributor',
            'contributing' => true,
            'recent_measurements' => 7,
            'required' => 5,
            'window_days' => 7,
            'delayed_by_s' => 300,
            'live' => false,
            'quota' => [
                'calls_used' => 0,
                'calls_limit' => 10000,
                'calls_left' => 10000,
                'window' => '7 days',
            ],
        ],
    ]);
    try {
        $bw = new Client(baseUrl: $s->url, token: 'tk_test', timeout: 2.0);
        $r = $bw->keyStatus();

        $t->assertNotNull($r, 'keyStatus returned null');
        $t->assertEquals('contributor', $r['tier']);
        $t->assertTrue($r['contributing'] === true);
        $t->assertEquals(10000, $r['quota']['calls_left']);

        $calls = $s->callsTo('/v1/keys/current', 'GET');
        $t->assertCount(1, $calls, 'exactly one GET /v1/keys/current was not sent');
        $t->assertEquals('Bearer tk_test', $calls[0]['headers']['authorization'] ?? null);
    } finally {
        $s->close();
    }
});

$h->test('without a token both throw BatchwatchAuthError and make NO network call', function (Harness $t): void {
    cleanEnv();
    // No silent empty answer: without a key there is nothing to read.
    $s = new FakeServer();
    try {
        $bw = new Client(baseUrl: $s->url, timeout: 2.0); // no token
        $t->assertNull($bw->token());

        $t->assertThrows(BatchwatchAuthError::class, fn () => $bw->myCalls());
        $t->assertThrows(BatchwatchAuthError::class, fn () => $bw->keyStatus());

        // The error fell before any network call reached the server.
        $t->assertCount(0, $s->calls(), 'a network call was made despite no token');
    } finally {
        $s->close();
    }
});

exit($h->run());
