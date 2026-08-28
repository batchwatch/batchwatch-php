<?php

declare(strict_types=1);

// The spool: measurements are not lost because the network was down.
//
// It is exactly when things are limping that the measurement is interesting, so
// it is also exactly there that a client without a spool loses what one would
// have known.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;
use Batchwatch\Spool;

$h = new Harness('spool');

/** @return list<array<string,mixed>> */
$lines = function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }
        $out[] = json_decode($l, true);
    }
    return $out;
};

$h->test('the measurement lands on disk when the server is down', function (Harness $t) use ($lines): void {
    withTmp(function (string $dir) use ($t, $lines): void {
        $path = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: $path);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $records = $lines($path);
        $t->assertCount(1, $records);
        $p = $records[0];
        // The whole measurement must be there - otherwise /v1/calls/complete
        // cannot accept it later.
        $t->assertEquals('gpt-5.6-sol', $p['model']);
        $t->assertEquals('openai', $p['provider']);
        $t->assertEquals('batch', $p['mode']);
        $t->assertEquals(9720, $p['input_tokens']);
        $t->assertEquals(4519, $p['output_tokens']);
        $t->assertEquals('completed', $p['status']);
        $t->assertTrue(str_ends_with($p['started_at'], 'Z'));
        $t->assertTrue(str_ends_with($p['ended_at'], 'Z'));
    });
});

$h->test('the spooled records are sent later', function (Harness $t) use ($lines): void {
    withTmp(function (string $dir) use ($t, $lines): void {
        $path = $dir . '/spool.jsonl';

        $down = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: $path);
        for ($i = 0; $i < 3; $i++) {
            $down->track('gpt-5.6-sol', inputTokens: 100, block: function ($tr): void {
                $tr->done(outputTokens: 40);
            });
        }
        $t->assertTrue($down->flush(timeout: 5.0));
        $t->assertCount(3, $lines($path));

        $server = new FakeServer();
        try {
            $up = new Client(baseUrl: $server->url, token: 'tk_test', timeout: 2.0, spool: $path);
            $t->assertEquals(3, $up->flushSpool());

            $calls = $server->callsTo('/v1/calls/complete', 'POST');
            $t->assertCount(1, $calls);
            $t->assertTrue(is_array($calls[0]['body']) && array_is_list($calls[0]['body']));
            $t->assertCount(3, $calls[0]['body']);
            $t->assertEquals('Bearer tk_test', $calls[0]['headers']['authorization']);
            // The file - and the pending file - must be gone afterwards.
            $t->assertFalse(is_file($path));
            $t->assertFalse(is_file($path . '.pending'));
        } finally {
            $server->close();
        }
    });
});

$h->test('a failed send does not lose anything', function (Harness $t) use ($lines): void {
    // If we still cannot reach the server, the records must stay put.
    withTmp(function (string $dir) use ($t, $lines): void {
        $path = $dir . '/spool.jsonl';
        $s = new Spool($path);
        $s->append(['model' => 'm', 'input_tokens' => 1]);
        $s->append(['model' => 'm', 'input_tokens' => 2]);

        $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: $path);
        $t->assertEquals(0, $bw->flushSpool());
        $t->assertCount(2, $lines($path . '.pending'));

        // ... and the next attempt picks them up again.
        $t->assertEquals(2, (new Spool($path))->size());
    });
});

$h->test('without a token nothing is spooled', function (Harness $t): void {
    // /v1/calls/complete requires a key. A file we can never send is not data
    // safety, it is a disk leak - so we leave it be.
    withTmp(function (string $dir) use ($t): void {
        $path = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: closedPort(), timeout: 0.5, spool: $path);
        $t->assertNull($bw->spool());
        $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $t->assertFalse(is_file($path));
    });
});

$h->test('the spool can be turned off', function (Harness $t): void {
    $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: null);
    $t->assertNull($bw->spool());
    $t->assertEquals(0, $bw->flushSpool());
});

$h->test('an omitted spool gives the default file when there is a token', function (Harness $t): void {
    // Regression: OMITTING spool must give the default path (temp file or
    // $BATCHWATCH_SPOOL), NOT turn spooling off. Only an explicit null turns it off.
    withTmp(function (string $dir) use ($t): void {
        $path = $dir . '/via-env.jsonl';
        putenv('BATCHWATCH_SPOOL=' . $path);
        try {
            $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5);
            $t->assertNotNull($bw->spool(), 'an omitted spool turned spooling off - wrong');
            $t->assertEquals($path, $bw->spool()->path());
        } finally {
            putenv('BATCHWATCH_SPOOL');
        }
    });
});

$h->test('an empty BATCHWATCH_SPOOL turns spooling off', function (Harness $t): void {
    // An empty string is the documented way to turn the spool off via the environment.
    putenv('BATCHWATCH_SPOOL=');
    try {
        $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5);
        $t->assertNull($bw->spool());
    } finally {
        putenv('BATCHWATCH_SPOOL');
    }
});

$h->test('the spool has a cap', function (Harness $t) use ($lines): void {
    // A week's outage must not fill the user's disk.
    withTmp(function (string $dir) use ($t, $lines): void {
        $path = $dir . '/spool.jsonl';
        $s = new Spool($path, maxBytes: 100);
        $t->assertTrue($s->append(['model' => str_repeat('m', 150)]));
        $t->assertFalse($s->append(['model' => 'one more']));
        $t->assertCount(1, $lines($path));
    });
});

$h->test('a half line after a crash does not cost the rest', function (Harness $t): void {
    withTmp(function (string $dir) use ($t): void {
        $path = $dir . '/spool.jsonl';
        $s = new Spool($path);
        $s->append(['model' => 'a']);
        // Crash mid-write: a half, invalid JSON line.
        file_put_contents($path, '{"model": "b", "inp', FILE_APPEND);
        $s->append(['model' => 'c']);
        $records = $s->take();
        $t->assertEquals(['a', 'c'], array_map(fn ($p) => $p['model'], $records));
    });
});

$h->test('flushed on its own when the network comes back', function (Harness $t): void {
    // The user who never calls flush_spool must also get his data out. We try
    // right after a successful call - because then we KNOW the network is up.
    withTmp(function (string $dir) use ($t): void {
        $path = $dir . '/spool.jsonl';
        $s = new Spool($path);
        $s->append([
            'provider' => 'openai', 'model' => 'gpt-5.6-sol', 'mode' => 'batch',
            'input_tokens' => 100, 'output_tokens' => null, 'status' => 'completed',
            'started_at' => '2026-08-25T10:00:00Z',
            'ended_at' => '2026-08-25T10:04:00Z',
        ]);

        $server = new FakeServer();
        try {
            $bw = new Client(baseUrl: $server->url, token: 'tk_test', timeout: 2.0, spool: $path);
            $bw->track('gpt-5.6-sol', inputTokens: 10, block: function ($tr): void {
                $tr->done(outputTokens: 5);
            });
            $t->assertTrue($bw->flush(timeout: 5.0));

            $t->assertNotEmpty(
                $server->callsTo('/v1/calls/complete', 'POST'),
                'the spool was not flushed on its own',
            );
            $t->assertFalse(is_file($path));
        } finally {
            $server->close();
        }
    });
});

$h->test('concurrent writers do not lose measurements', function (Harness $t) use ($lines): void {
    // Regression test on a bug that was in the thread clients: two concurrent
    // completions each found the end of the file and wrote, and that is not
    // atomic.
    //
    // PHP CLI has no threads, so we test it STRONGER: 40 FORKED processes write
    // to the same spool file at once. Without flock in Spool, lines would
    // overwrite/split each other. A barrier file makes them write as close to
    // simultaneously as possible.
    withTmp(function (string $dir) use ($t, $lines): void {
        $path = $dir . '/spool.jsonl';
        $barrier = $dir . '/go';
        $n = 40;

        $children = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $t->assertTrue(false, 'pcntl_fork failed');
                return;
            }
            if ($pid === 0) {
                // Child: wait for the barrier, then write one record.
                $attempts = 0;
                while (!is_file($barrier) && $attempts < 20000) {
                    usleep(200);
                    $attempts++;
                }
                $s = new Spool($path);
                $s->append(['model' => 'gpt-5.6-sol', 'input_tokens' => $i]);
                exit(0);
            }
            $children[] = $pid;
        }

        // Release all children at once.
        usleep(50_000);
        file_put_contents($barrier, '1');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $s);
        }

        $records = $lines($path);
        $t->assertCount($n, $records);
        $toks = array_map(fn ($p) => $p['input_tokens'], $records);
        sort($toks);
        $t->assertEquals(range(0, $n - 1), $toks);
    });
});

exit($h->run());
