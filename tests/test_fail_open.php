<?php

declare(strict_types=1);

// The most important requirement: a batchwatch outage must never stop the
// user's job.
//
// The tests here run against a port no one listens on, and against a socket
// that listens but never answers. Neither may be noticeable to the caller -
// neither as an exception nor as a wait that matters.
//
// Note on the PHP idiom: Python/Ruby put the submission on a background thread,
// so the caller's thread is not touched at all. PHP CLI has no threads, so the
// submission is synchronous - but bounded by the timeout and wrapped so no
// error escapes. The caller thus pays at most the timeout per call in the worst
// case, never a hang and never an exception. The timeout cap in the tests
// therefore accounts for BOTH the start AND the done call running synchronously
// against a dead server (2x the timeout), unlike the thread clients where the
// block is essentially free.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;
use Batchwatch\HttpError;

$h = new Harness('fail_open');

$h->test('track does not throw when the server is gone', function (Harness $t): void {
    cleanEnv();
    $bw = new Client(baseUrl: closedPort(), timeout: 0.5);
    $result = null;
    $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr) use (&$result): void {
        $result = 2 + 2;
        $tr->done(outputTokens: 5);
    });
    $t->assertEquals(4, $result);
    $t->assertTrue($bw->flush(timeout: 5.0));
});

$h->test('track is fast when the server hangs', function (Harness $t): void {
    // If the client blocks on the network, the user pays for our outage. In PHP
    // start+done run synchronously, so the cap is ~2x the timeout plus a little
    // slack - still far from a real hang, and still bounded by the deadline.
    cleanEnv();
    $blackhole = new BlackHole();
    try {
        $bw = new Client(baseUrl: $blackhole->url, timeout: 0.5);
        $t0 = microtime(true);
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $elapsed = microtime(true) - $t0;
        // 2 calls x 0.5 s timeout = ~1.0 s worst case; the cap is generous.
        $t->assertLessThan(2.0, $elapsed, sprintf('track() blocked %.2f s', $elapsed));
    } finally {
        $blackhole->close();
    }
});

$h->test("the caller's own exception passes through", function (Harness $t): void {
    // We swallow our own errors - never the caller's.
    cleanEnv();
    $bw = new Client(baseUrl: closedPort(), timeout: 0.5);
    $t->assertThrows(DivisionByZeroError::class, function () use ($bw): void {
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            intdiv(1, 0);
        });
    });
    $bw->flush(timeout: 5.0);
});

$h->test("should_batch gives the caller's default back", function (Harness $t): void {
    cleanEnv();
    $bw = new Client(baseUrl: closedPort(), timeout: 0.5);
    $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
    $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m', default: true));
});

$h->test('should_batch holds the timeout when the server hangs', function (Harness $t): void {
    // This one IS synchronous - the caller waits for the answer - so the timeout
    // is the only thing protecting him.
    cleanEnv();
    $blackhole = new BlackHole();
    try {
        $bw = new Client(baseUrl: $blackhole->url, timeout: 0.5);
        $t0 = microtime(true);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
        $elapsed = microtime(true) - $t0;
        $t->assertLessThan(3.0, $elapsed, sprintf('should_batch waited %.2f s on a dead server', $elapsed));
    } finally {
        $blackhole->close();
    }
});

$h->test('advice and wait_now return null', function (Harness $t): void {
    cleanEnv();
    $bw = new Client(baseUrl: closedPort(), timeout: 0.5);
    $t->assertNull($bw->advice('gpt-5.6-sol'));
    $t->assertNull($bw->waitNow('gpt-5.6-sol'));
});

$h->test('server errors are also errors that get swallowed', function (Harness $t): void {
    // 500 from a server that IS there. A different branch than "no connection".
    cleanEnv();
    $server = new FakeServer(['/v1' => ['error' => 'boom']], ['/v1' => 500]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 1.0);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol'));
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
    } finally {
        $server->close();
    }
});

$h->test('enabled=false sends nothing', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, enabled: false);
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $bw->flush(timeout: 2.0);
        // Give any submission time to land (none should come).
        usleep(100_000);
        $t->assertCount(0, $server->calls(), 'enabled=false sent something anyway');
    } finally {
        $server->close();
    }
});

$h->test('positive control - the server does otherwise get called', function (Harness $t): void {
    // Without this one the tests above prove nothing: a client that never sends
    // anything would also pass all of them.
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $t->assertNotEmpty($server->callsTo('/v1/calls', 'POST'), 'no POST /v1/calls');
    } finally {
        $server->close();
    }
});

exit($h->run());
