<?php

declare(strict_types=1);

// Udfaldsmaaling (#101): raadet vi gav haeftes paa den senere afslutning.
//
// Saa serveren senere kan vise "vores p90 holdt X% af tiden", skal klienten
// huske hvad den anbefalede - og haefte det paa afslutningen af det SAMME job.
// Korrelationen er en dokumenteret tilnaermelse: seneste-raad-pr-model.
//
// Ingen ny PII: kun modellen, tiderne vi allerede sender, og de tal vi selv
// fik tilbage fra serveren. Har vi intet raad, haefter vi ingenting - og slet
// ikke et opfundet nul.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;

use function Batchwatch\sekunder;

// should-i-batch-svar med rigtige percentiler at citere.
const RAADSVAR = ['verdict' => 'run_batch', 'p50_s' => 1234.0, 'p90_s' => 5678.0, 'p95_s' => 9000.0];

/**
 * Sidste afslutnings-krop (PATCH mod /v1/calls/c_...).
 *
 * @return array<string,mixed>
 */
function sidsteAfslutning(FalskServer $server): array
{
    $kald = $server->kaldTil('/v1/calls/c_', 'PATCH');
    if (empty($kald)) {
        throw new \AssertionError('ingen afslutning fanget - testen beviser intet');
    }
    return $kald[count($kald) - 1]['body'];
}

$h = new Harness('verdict_accuracy');

$h->test('afslutning baerer raadet efter shouldBatch', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer(['/v1/should-i-batch' => RAADSVAR]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));

        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $krop = sidsteAfslutning($server);
        $t->assertTrue($krop['acted_verdict'] === true, 'acted_verdict skulle vaere true');
        $t->assertEquals(900.0, (float) $krop['deadline_s']);        // 15m -> 900 s
        $t->assertEquals(5678.0, (float) $krop['quoted_p90_s']);
        $t->assertEquals(1234.0, (float) $krop['quoted_p50_s']);     // valgfri, men sendt
    } finally {
        $server->luk();
    }
});

$h->test('afslutning uden forudgaaende raad baerer ingenting', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        // Intet shouldBatch for denne model foerst.
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $krop = sidsteAfslutning($server);
        foreach (['acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $felt) {
            $t->assertFalse(array_key_exists($felt, $krop), "haeftede {$felt} uden noget raad");
        }
    } finally {
        $server->luk();
    }
});

$h->test('raadet er pr model', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer(['/v1/should-i-batch' => RAADSVAR]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->shouldBatch('gpt-5.6-sol', maxWait: '15m');

        // Afslut en HELT anden model - den har intet raad.
        $bw->track('claude-4-haiku', inputTokens: 100, blok: function ($tr): void {
            $tr->done(outputTokens: 50);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $krop = sidsteAfslutning($server);
        $t->assertFalse(array_key_exists('acted_verdict', $krop), 'raadet laekkede paa tvaers af modeller');
    } finally {
        $server->luk();
    }
});

$h->test('run_sync giver acted_verdict false', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer(['/v1/should-i-batch' => ['verdict' => 'run_sync', 'p50_s' => 12.0, 'p90_s' => 34.0]]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '5m'));

        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $krop = sidsteAfslutning($server);
        $t->assertTrue($krop['acted_verdict'] === false, 'acted_verdict skulle vaere false');
        $t->assertEquals(300.0, (float) $krop['deadline_s']);
        $t->assertEquals(34.0, (float) $krop['quoted_p90_s']);
    } finally {
        $server->luk();
    }
});

$h->test('manglende percentil udelades, opfindes ikke', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer(['/v1/should-i-batch' => ['verdict' => 'run_batch', 'p50_s' => 7.0]]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->shouldBatch('gpt-5.6-sol', maxWait: '15m');
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $krop = sidsteAfslutning($server);
        $t->assertTrue($krop['acted_verdict'] === true);
        $t->assertEquals(7.0, (float) $krop['quoted_p50_s']);
        $t->assertFalse(array_key_exists('quoted_p90_s', $krop), 'opfandt en manglende p90');
    } finally {
        $server->luk();
    }
});

$h->test('sekunder tyder enhederne, ellers null', function (Harness $t): void {
    $t->assertEquals(900.0, sekunder('15m'));
    $t->assertEquals(3600.0, sekunder('1h'));
    $t->assertEquals(30.0, sekunder('30s'));
    $t->assertEquals(172800.0, sekunder('2d'));
    $t->assertEquals(45.0, sekunder('45'));        // blankt tal = sekunder
    $t->assertNull(sekunder(null));
    $t->assertNull(sekunder(''));
    $t->assertNull(sekunder('vroevl'));            // uforstaaeligt -> null, ikke gaet
});

$h->test('raadfelterne er paa tilladelseslisten', function (Harness $t): void {
    foreach (['acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $felt) {
        $t->assertInList($felt, \Batchwatch\TILLADTE_FELTER);
    }
});

exit($h->run());
