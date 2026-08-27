<?php

declare(strict_types=1);

// Det vigtigste krav: et batchwatch-udfald maa aldrig stoppe brugerens job.
//
// Testene her koerer mod en port ingen lytter paa, og mod en socket der lytter
// men aldrig svarer. Ingen af delene maa kunne maerkes af kalderen - hverken
// som en undtagelse eller som en ventetid der betyder noget.
//
// Note om PHP-idiomet: Python/Ruby laegger afsendelsen paa en baggrundstraad,
// saa kalderens traad slet ikke roeres. PHP CLI har ingen traade, saa
// afsendelsen er synkron - men bundet af timeouten og pakket saa ingen fejl
// slipper ud. Kalderen betaler dermed hoejst timeouten pr. kald i vaerste
// fald, aldrig en hang og aldrig en exception. Timeout-loftet i testene tager
// derfor hoejde for at BAADE start- OG done-kaldet loeber synkront mod en
// doed server (2x timeouten), i modsaetning til traad-klienterne hvor blokken
// stort set er gratis.

require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/TestHelper.php';

use Batchwatch\Client;
use Batchwatch\HttpError;

$h = new Harness('fail_open');

$h->test('track kaster ikke naar serveren er vaek', function (Harness $t): void {
    reneOmgivelser();
    $bw = new Client(baseUrl: lukketPort(), timeout: 0.5);
    $resultat = null;
    $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr) use (&$resultat): void {
        $resultat = 2 + 2;
        $tr->done(outputTokens: 5);
    });
    $t->assertEquals(4, $resultat);
    $t->assertTrue($bw->flush(timeout: 5.0));
});

$h->test('track er hurtig naar serveren haenger', function (Harness $t): void {
    // Blokerer klienten paa netvaerket, betaler brugeren for vores nedbrud.
    // I PHP loeber start+done synkront, saa loftet er ~2x timeouten plus lidt
    // slack - stadig langt fra en aegte hang, og stadig bundet af fristen.
    reneOmgivelser();
    $sorthul = new SortHul();
    try {
        $bw = new Client(baseUrl: $sorthul->url, timeout: 0.5);
        $t0 = microtime(true);
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $brugt = microtime(true) - $t0;
        // 2 kald x 0.5 s timeout = ~1.0 s vaerste fald; loftet er rundhaandet.
        $t->assertLessThan(2.0, $brugt, sprintf('track() blokerede %.2f s', $brugt));
    } finally {
        $sorthul->luk();
    }
});

$h->test('brugerens egen undtagelse slipper igennem', function (Harness $t): void {
    // Vi sluger vores egne fejl - aldrig kalderens.
    reneOmgivelser();
    $bw = new Client(baseUrl: lukketPort(), timeout: 0.5);
    $t->assertThrows(DivisionByZeroError::class, function () use ($bw): void {
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            intdiv(1, 0);
        });
    });
    $bw->flush(timeout: 5.0);
});

$h->test('should_batch giver kalderens default tilbage', function (Harness $t): void {
    reneOmgivelser();
    $bw = new Client(baseUrl: lukketPort(), timeout: 0.5);
    $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
    $t->assertTrue($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m', default: true));
});

$h->test('should_batch holder timeouten naar serveren haenger', function (Harness $t): void {
    // Denne ER synkron - kalderen venter paa svaret - saa timeouten er det
    // eneste der beskytter ham.
    reneOmgivelser();
    $sorthul = new SortHul();
    try {
        $bw = new Client(baseUrl: $sorthul->url, timeout: 0.5);
        $t0 = microtime(true);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m'));
        $brugt = microtime(true) - $t0;
        $t->assertLessThan(3.0, $brugt, sprintf('should_batch ventede %.2f s paa en doed server', $brugt));
    } finally {
        $sorthul->luk();
    }
});

$h->test('advice og wait_now returnerer null', function (Harness $t): void {
    reneOmgivelser();
    $bw = new Client(baseUrl: lukketPort(), timeout: 0.5);
    $t->assertNull($bw->advice('gpt-5.6-sol'));
    $t->assertNull($bw->waitNow('gpt-5.6-sol'));
});

$h->test('serverfejl er ogsaa fejl der sluges', function (Harness $t): void {
    // 500 fra en server der ER der. En anden gren end "ingen forbindelse".
    reneOmgivelser();
    $server = new FalskServer(['/v1' => ['error' => 'boom']], ['/v1' => 500]);
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 1.0);
        $t->assertFalse($bw->shouldBatch('gpt-5.6-sol'));
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
    } finally {
        $server->luk();
    }
});

$h->test('enabled=false sender intet', function (Harness $t): void {
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, enabled: false);
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $bw->flush(timeout: 2.0);
        // Giv en evt. afsendelse tid til at lande (der skal ingen komme).
        usleep(100_000);
        $t->assertCount(0, $server->kald(), 'enabled=false sendte alligevel noget');
    } finally {
        $server->luk();
    }
});

$h->test('positiv kontrol - serveren bliver ellers kaldt', function (Harness $t): void {
    // Uden den her beviser testene ovenfor ingenting: en klient der aldrig
    // sender noget ville ogsaa bestaa dem alle sammen.
    reneOmgivelser();
    $server = new FalskServer();
    try {
        $bw = new Client(baseUrl: $server->url, timeout: 2.0);
        $bw->track('gpt-5.6-sol', inputTokens: 10, blok: function ($tr): void {
            $tr->done(outputTokens: 5);
        });
        $t->assertTrue($bw->flush(timeout: 5.0));
        $t->assertNotEmpty($server->kaldTil('/v1/calls', 'POST'), 'ingen POST /v1/calls');
    } finally {
        $server->luk();
    }
});

exit($h->run());
