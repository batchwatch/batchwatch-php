<?php

declare(strict_types=1);

// Klienten sender tider og tokental. Aldrig prompt, aldrig svar.
//
// Og: output_tokens defaulter til null, ALDRIG 0. Nul er en maaling, fravaer
// er ikke, og serveren prissaetter dem forskelligt med vilje - output koster
// fem-seks gange saa meget som input, saa et default paa nul giver en
// systematisk for lav besparelse uden at nogen kan se det.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;

use function Batchwatch\rens;

const HEMMELIG_PROMPT = 'SECRET-PROMPT-kundens-journal-cpr-0101781234';
const HEMMELIGT_SVAR = 'SECRET-COMPLETION-diagnosen-er-fortrolig';

$h = new Harness('no_content');

$h->test('ingen prompt eller svar forlader maskinen', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $prompt = HEMMELIG_PROMPT; // brugerens data, i hans egen kode
        $svar = HEMMELIGT_SVAR;
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr) use ($svar): void {
            $tr->done(outputTokens: count(explode(' ', $svar)));
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $alt = $server->altViModtog();
        $t->assertNotContains(HEMMELIG_PROMPT, $alt);
        $t->assertNotContains(HEMMELIGT_SVAR, $alt);
        $t->assertNotContains($prompt, $alt);

        // Positiv kontrol: optageren VIRKER. Uden den her ville testen ovenfor
        // ogsaa bestaa hvis klienten slet ikke havde sendt noget.
        $t->assertContains('gpt-5.6-sol', $alt);
        $t->assertContains('9720', $alt);
    } finally {
        $server->luk();
    }
});

$h->test('kroppen indeholder kun tilladte felter', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, endpoint: '/v1/chat/completions', blok: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $kald = $server->kaldTil('/v1/calls');
        $t->assertNotEmpty($kald, 'klienten sendte ingenting - testen beviser intet');
        foreach ($kald as $k) {
            foreach (array_keys((array) ($k['body'] ?? [])) as $navn) {
                $t->assertInList((string) $navn, \Batchwatch\TILLADTE_FELTER, "ukendt felt sendt: {$navn}");
            }
        }
    } finally {
        $server->luk();
    }
});

$h->test('rens smider alt udenfor listen vaek', function (Harness $t): void {
    // Enhedstesten paa selve spaerren. rens er det sted man peger paa naar
    // nogen spoerger hvordan vi ved at en prompt ikke kan slippe ud.
    $ud = rens([
        'model' => 'm',
        'prompt' => HEMMELIG_PROMPT,
        'messages' => [['content' => HEMMELIGT_SVAR]],
        'input_tokens' => 5,
    ]);
    $t->assertEquals(['model' => 'm', 'input_tokens' => 5], $ud);
});

$h->test('rens beholder rekkefoelge og kun tilladte noegler', function (Harness $t): void {
    // Robusthed: ogsaa hvis kroppen er fyldt med ukendte felter maa kun de
    // tolv slippe ud, uanset raekkefoelge.
    $ud = rens([
        'endpoint' => '/v1/chat',
        'system_prompt' => HEMMELIG_PROMPT,
        'provider' => 'openai',
        'tool_calls' => ['x'],
        'output_tokens' => 0,
    ]);
    $t->assertEquals(['endpoint' => '/v1/chat', 'provider' => 'openai', 'output_tokens' => 0], $ud);
});

$h->test('output_tokens er null og ikke nul naar de er ukendte', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(); // vi kender dem ikke
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $patch = $server->kaldTil('/v1/calls/c_', 'PATCH');
        $t->assertNotEmpty($patch, 'ingen PATCH - saa er der ikke noget at bevise');
        $sidste = $patch[count($patch) - 1];
        $krop = $sidste['body'];
        // Noeglen SKAL vaere der, men med vaerdien null - ikke udeladt.
        $t->assertInList('output_tokens', array_keys((array) $krop));
        $t->assertTrue(array_key_exists('output_tokens', (array) $krop), 'output_tokens-noeglen mangler i PATCH');
        $t->assertNull($krop['output_tokens'], 'output_tokens blev til noget andet end null');
    } finally {
        $server->luk();
    }
});

$h->test('output_tokens nul bliver sendt som nul', function (Harness $t): void {
    // Nul er et gyldigt maalt tal. Vi maa ikke lave det om til fravaer.
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(outputTokens: 0);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $patch = $server->kaldTil('/v1/calls/c_', 'PATCH');
        $krop = $patch[count($patch) - 1]['body'];
        $t->assertEquals(0, $krop['output_tokens']);
    } finally {
        $server->luk();
    }
});

$h->test('advice udelader output_tokens naar de ikke er oplyst', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer(['/v1/should-i-batch' => ['verdict' => 'run_batch']]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
        $kald1 = $server->kaldTil('/v1/should-i-batch');
        $sti = $kald1[count($kald1) - 1]['path'];
        $t->assertNotContains('output_tokens', $sti);
        $t->assertContains('max_wait=15m', $sti);
        // Positiv kontrol paa selve query-bygningen.
        $bw->advice('gpt-5.6-sol', outputTokens: 4519);
        $kald2 = $server->kaldTil('/v1/should-i-batch');
        $t->assertContains('output_tokens=4519', $kald2[count($kald2) - 1]['path']);
    } finally {
        $server->luk();
    }
});

$h->test('spoolet maaling har ogsaa null', function (Harness $t): void {
    // Spoolen skriver den fulde maaling. Nullet skal ogsaa overleve disken.
    medTmp(function (string $dir) use ($t): void {
        $sti = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5, spool: $sti);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done();
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $linjer = array_values(array_filter(array_map('trim', file($sti) ?: []), fn ($l) => $l !== ''));
        $poster = array_map(fn ($l) => json_decode($l, true), $linjer);
        $t->assertCount(1, $poster);
        $t->assertNull($poster[0]['output_tokens']);
        $t->assertNotContains(HEMMELIG_PROMPT, file_get_contents($sti));
    });
});

exit($h->run());
