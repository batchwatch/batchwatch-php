<?php

declare(strict_types=1);

// The client sends times and token counts. Never a prompt, never a completion.
//
// And: output_tokens defaults to null, NEVER 0. Zero is a measurement, absence
// is not, and the server prices them differently on purpose - output costs five
// to six times as much as input, so a default of zero gives a systematically
// too-low saving without anyone being able to see it.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;

use function Batchwatch\sanitize;

const SECRET_PROMPT = 'SECRET-PROMPT-customer-medical-record-ssn-0101781234';
const SECRET_COMPLETION = 'SECRET-COMPLETION-the-diagnosis-is-confidential';

$h = new Harness('no_content');

$h->test('no prompt or completion leaves the machine', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $prompt = SECRET_PROMPT; // the user's data, in his own code
        $completion = SECRET_COMPLETION;
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr) use ($completion): void {
            $tr->done(outputTokens: count(explode(' ', $completion)));
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $all = $server->everythingReceived();
        $t->assertNotContains(SECRET_PROMPT, $all);
        $t->assertNotContains(SECRET_COMPLETION, $all);
        $t->assertNotContains($prompt, $all);

        // Positive control: the recorder WORKS. Without this the test above
        // would also pass if the client had not sent anything at all.
        $t->assertContains('gpt-5.6-sol', $all);
        $t->assertContains('9720', $all);
    } finally {
        $server->close();
    }
});

$h->test('the body contains only allowed fields', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, endpoint: '/v1/chat/completions', block: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $calls = $server->callsTo('/v1/calls');
        $t->assertNotEmpty($calls, 'the client sent nothing - the test proves nothing');
        foreach ($calls as $k) {
            foreach (array_keys((array) ($k['body'] ?? [])) as $name) {
                $t->assertInList((string) $name, \Batchwatch\ALLOWED_FIELDS, "unknown field sent: {$name}");
            }
        }
    } finally {
        $server->close();
    }
});

$h->test('sanitize drops everything outside the list', function (Harness $t): void {
    // The unit test on the barrier itself. sanitize is the place you point at
    // when someone asks how we know a prompt cannot slip out.
    $out = sanitize([
        'model' => 'm',
        'prompt' => SECRET_PROMPT,
        'messages' => [['content' => SECRET_COMPLETION]],
        'input_tokens' => 5,
    ]);
    $t->assertEquals(['model' => 'm', 'input_tokens' => 5], $out);
});

$h->test('sanitize keeps order and only allowed keys', function (Harness $t): void {
    // Robustness: even if the body is full of unknown fields, only the sixteen
    // may slip out, regardless of order.
    $out = sanitize([
        'endpoint' => '/v1/chat',
        'system_prompt' => SECRET_PROMPT,
        'provider' => 'openai',
        'tool_calls' => ['x'],
        'output_tokens' => 0,
    ]);
    $t->assertEquals(['endpoint' => '/v1/chat', 'provider' => 'openai', 'output_tokens' => 0], $out);
});

$h->test('output_tokens is null and not zero when unknown', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(); // we do not know them
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $patch = $server->callsTo('/v1/calls/c_', 'PATCH');
        $t->assertNotEmpty($patch, 'no PATCH - so there is nothing to prove');
        $last = $patch[count($patch) - 1];
        $body = $last['body'];
        // The key MUST be there, but with the value null - not omitted.
        $t->assertInList('output_tokens', array_keys((array) $body));
        $t->assertTrue(array_key_exists('output_tokens', (array) $body), 'the output_tokens key is missing in PATCH');
        $t->assertNull($body['output_tokens'], 'output_tokens turned into something other than null');
    } finally {
        $server->close();
    }
});

$h->test('output_tokens zero is sent as zero', function (Harness $t): void {
    // Zero is a valid measured number. We must not turn it into absence.
    cleanEnv();
    $server = new FakeServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done(outputTokens: 0);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $patch = $server->callsTo('/v1/calls/c_', 'PATCH');
        $body = $patch[count($patch) - 1]['body'];
        $t->assertEquals(0, $body['output_tokens']);
    } finally {
        $server->close();
    }
});

$h->test('advice omits output_tokens when not provided', function (Harness $t): void {
    cleanEnv();
    $server = new FakeServer(['/v1/should-i-batch' => ['verdict' => 'run_batch']]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
        $call1 = $server->callsTo('/v1/should-i-batch');
        $path = $call1[count($call1) - 1]['path'];
        $t->assertNotContains('output_tokens', $path);
        $t->assertContains('max_wait=15m', $path);
        // Positive control on the query building itself.
        $bw->advice('gpt-5.6-sol', outputTokens: 4519);
        $call2 = $server->callsTo('/v1/should-i-batch');
        $t->assertContains('output_tokens=4519', $call2[count($call2) - 1]['path']);
    } finally {
        $server->close();
    }
});

$h->test('a spooled measurement also has null', function (Harness $t): void {
    // The spool writes the full measurement. The null must survive the disk too.
    withTmp(function (string $dir) use ($t): void {
        $path = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: closedPort(), token: 'tk_test', timeout: 0.5, spool: $path);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, block: function ($tr): void {
            $tr->done();
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $lines = array_values(array_filter(array_map('trim', file($path) ?: []), fn ($l) => $l !== ''));
        $records = array_map(fn ($l) => json_decode($l, true), $lines);
        $t->assertCount(1, $records);
        $t->assertNull($records[0]['output_tokens']);
        $t->assertNotContains(SECRET_PROMPT, file_get_contents($path));
    });
});

exit($h->run());
