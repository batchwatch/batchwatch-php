<?php

declare(strict_types=1);

// Spoolen: maalinger gaar ikke tabt fordi netvaerket var nede.
//
// Det er praecis naar det halter at maalingen er interessant, saa det er ogsaa
// praecis der en klient uden spool taber det man ville have vidst.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;
use Batchwatch\Spool;

$h = new Harness('spool');

/** @return list<array<string,mixed>> */
$linjer = function (string $sti): array {
    if (!is_file($sti)) {
        return [];
    }
    $ud = [];
    foreach (file($sti, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }
        $ud[] = json_decode($l, true);
    }
    return $ud;
};

$h->test('maalingen lander paa disken naar serveren er nede', function (Harness $t) use ($linjer): void {
    medTmp(function (string $dir) use ($t, $linjer): void {
        $sti = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5, spool: $sti);
        $bw->track('gpt-5.6-sol', inputTokens: 9720, blok: function ($tr): void {
            $tr->done(outputTokens: 4519);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));

        $poster = $linjer($sti);
        $t->assertCount(1, $poster);
        $p = $poster[0];
        // Hele maalingen skal vaere der - ellers kan /v1/calls/complete ikke
        // tage imod den senere.
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

$h->test('det spoolede bliver sendt senere', function (Harness $t) use ($linjer): void {
    medTmp(function (string $dir) use ($t, $linjer): void {
        $sti = $dir . '/spool.jsonl';

        $nede = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5, spool: $sti);
        for ($i = 0; $i < 3; $i++) {
            $nede->track('gpt-5.6-sol', inputTokens: 100, blok: function ($tr): void {
                $tr->done(outputTokens: 40);
            });
        }
        $t->assertTrue($nede->flush(timeout: 5.0));
        $t->assertCount(3, $linjer($sti));

        $server = new FalskServer();
        try {
            $oppe = new Client(baseUrl: $server->url, token: 'tk_test', timeout: 2.0, spool: $sti);
            $t->assertEquals(3, $oppe->flushSpool());

            $kald = $server->kaldTil('/v1/calls/complete', 'POST');
            $t->assertCount(1, $kald);
            $t->assertTrue(is_array($kald[0]['body']) && array_is_list($kald[0]['body']));
            $t->assertCount(3, $kald[0]['body']);
            $t->assertEquals('Bearer tk_test', $kald[0]['headers']['authorization']);
            // Filen - og pending-filen - skal vaere vaek bagefter.
            $t->assertFalse(is_file($sti));
            $t->assertFalse(is_file($sti . '.pending'));
        } finally {
            $server->luk();
        }
    });
});

$h->test('fejlet afsendelse taber ikke noget', function (Harness $t) use ($linjer): void {
    // Kan vi stadig ikke naa frem, skal posterne blive liggende.
    medTmp(function (string $dir) use ($t, $linjer): void {
        $sti = $dir . '/spool.jsonl';
        $s = new Spool($sti);
        $s->append(['model' => 'm', 'input_tokens' => 1]);
        $s->append(['model' => 'm', 'input_tokens' => 2]);

        $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5, spool: $sti);
        $t->assertEquals(0, $bw->flushSpool());
        $t->assertCount(2, $linjer($sti . '.pending'));

        // ... og naeste forsoeg tager dem med igen.
        $t->assertEquals(2, (new Spool($sti))->size());
    });
});

$h->test('uden token spooles der ikke', function (Harness $t): void {
    // /v1/calls/complete kraever en noegle. En fil vi aldrig kan sende er ikke
    // datasikring, det er en disklaek - saa lader vi den vaere.
    medTmp(function (string $dir) use ($t): void {
        $sti = $dir . '/spool.jsonl';
        $bw = new Client(baseUrl: lukketPort(), timeout: 0.5, spool: $sti);
        $t->assertNull($bw->spool());
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $t->assertFalse(is_file($sti));
    });
});

$h->test('spool kan slaas fra', function (Harness $t): void {
    $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5, spool: null);
    $t->assertNull($bw->spool());
    $t->assertEquals(0, $bw->flushSpool());
});

$h->test('udeladt spool giver default-fil naar der er token', function (Harness $t): void {
    // Regression: at UDELADE spool skal give default-stien (temp-fil eller
    // $BATCHWATCH_SPOOL), IKKE slaa spooling fra. Kun eksplicit null slaar fra.
    medTmp(function (string $dir) use ($t): void {
        $sti = $dir . '/via-env.jsonl';
        putenv('BATCHWATCH_SPOOL=' . $sti);
        try {
            $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5);
            $t->assertNotNull($bw->spool(), 'udeladt spool slog spooling fra - forkert');
            $t->assertEquals($sti, $bw->spool()->path());
        } finally {
            putenv('BATCHWATCH_SPOOL');
        }
    });
});

$h->test('tom BATCHWATCH_SPOOL slaar spooling fra', function (Harness $t): void {
    // Tom streng er den dokumenterede maade at slaa spoolen fra via miljoeet.
    putenv('BATCHWATCH_SPOOL=');
    try {
        $bw = new Client(baseUrl: lukketPort(), token: 'tk_test', timeout: 0.5);
        $t->assertNull($bw->spool());
    } finally {
        putenv('BATCHWATCH_SPOOL');
    }
});

$h->test('spool har et loft', function (Harness $t) use ($linjer): void {
    // En uges nedbrud maa ikke fylde brugerens disk.
    medTmp(function (string $dir) use ($t, $linjer): void {
        $sti = $dir . '/spool.jsonl';
        $s = new Spool($sti, maxBytes: 100);
        $t->assertTrue($s->append(['model' => str_repeat('m', 150)]));
        $t->assertFalse($s->append(['model' => 'endnu en']));
        $t->assertCount(1, $linjer($sti));
    });
});

$h->test('halv linje efter nedbrud koster ikke resten', function (Harness $t): void {
    medTmp(function (string $dir) use ($t): void {
        $sti = $dir . '/spool.jsonl';
        $s = new Spool($sti);
        $s->append(['model' => 'a']);
        // Nedbrud midt i en skrivning: en halv, ugyldig JSON-linje.
        file_put_contents($sti, '{"model": "b", "inp', FILE_APPEND);
        $s->append(['model' => 'c']);
        $poster = $s->take();
        $t->assertEquals(['a', 'c'], array_map(fn ($p) => $p['model'], $poster));
    });
});

$h->test('toemmes af sig selv naar netvaerket kommer tilbage', function (Harness $t): void {
    // Den bruger der aldrig kalder flush_spool skal ogsaa faa sine data afsted.
    // Vi proever naar vi lige har haft et vellykket kald - saa VED vi at
    // netvaerket er oppe.
    medTmp(function (string $dir) use ($t): void {
        $sti = $dir . '/spool.jsonl';
        $s = new Spool($sti);
        $s->append([
            'provider' => 'openai', 'model' => 'gpt-5.6-sol', 'mode' => 'batch',
            'input_tokens' => 100, 'output_tokens' => null, 'status' => 'completed',
            'started_at' => '2026-08-25T10:00:00Z',
            'ended_at' => '2026-08-25T10:04:00Z',
        ]);

        $server = new FalskServer();
        try {
            $bw = new Client(baseUrl: $server->url, token: 'tk_test', timeout: 2.0, spool: $sti);
            $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
                $tr->done(outputTokens: 5);
            });
            $t->assertTrue($bw->flush(timeout: 5.0));

            $t->assertNotEmpty(
                $server->kaldTil('/v1/calls/complete', 'POST'),
                'spoolen blev ikke toemt af sig selv',
            );
            $t->assertFalse(is_file($sti));
        } finally {
            $server->luk();
        }
    });
});

$h->test('samtidige skrivere taber ikke maalinger', function (Harness $t) use ($linjer): void {
    // Regressionstest paa en fejl der var der i traad-klienterne: to samtidige
    // afslutninger fandt hver enden af filen og skrev, og det er ikke atomart.
    //
    // PHP CLI har ingen traade, saa vi tester det STAERKERE: 40  FORKEDE
    // processer skriver samtidig til samme spoolfil. Uden flock i Spool ville
    // linjer overskrive/splitte hinanden. En barriere-fil faar dem til at
    // skrive saa taet paa samtidig som muligt.
    medTmp(function (string $dir) use ($t, $linjer): void {
        $sti = $dir . '/spool.jsonl';
        $barriere = $dir . '/go';
        $n = 40;

        $boern = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $t->assertTrue(false, 'pcntl_fork fejlede');
                return;
            }
            if ($pid === 0) {
                // Child: vent paa barrieren, skriv saa een post.
                $forsoeg = 0;
                while (!is_file($barriere) && $forsoeg < 20000) {
                    usleep(200);
                    $forsoeg++;
                }
                $s = new Spool($sti);
                $s->append(['model' => 'gpt-5.6-sol', 'input_tokens' => $i]);
                exit(0);
            }
            $boern[] = $pid;
        }

        // Slip alle boern loes paa een gang.
        usleep(50_000);
        file_put_contents($barriere, '1');
        foreach ($boern as $pid) {
            pcntl_waitpid($pid, $s);
        }

        $poster = $linjer($sti);
        $t->assertCount($n, $poster);
        $toks = array_map(fn ($p) => $p['input_tokens'], $poster);
        sort($toks);
        $t->assertEquals(range(0, $n - 1), $toks);
    });
});

exit($h->run());
