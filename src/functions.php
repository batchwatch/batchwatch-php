<?php

declare(strict_types=1);

// Namespace-niveau konstanter og fri-funktioner for batchwatch-klienten.
//
// De ligger i deres egen fil af to grunde: PSR-4 autoloader kun KLASSER, saa
// fri-funktioner og konstanter skal loades eksplicit (composer "files", eller
// bootstrap.php uden composer); og baade Client og Spool deler nogle af dem
// (MAX_BATCH/MAX_BYTES), saa een fil undgaar en cirkulaer afhaengighed.
//
// Filen er idempotent: den kan require_once'es fra baade Client.php og
// Spool.php uden at redeklarere noget.

namespace Batchwatch;

// Idempotent guard: er filen allerede loadet (af composer "files", af
// bootstrap.php, eller af et require_once i Client/Spool), saa er alt
// deklareret, og vi vender om med det samme. const/function kan ikke staa i en
// if-blok paa compile-tid, saa et tidligt file-return er den rene loesning.
if (\defined('Batchwatch\\VERSION')) {
    return;
}

const VERSION = '0.1.0';

// Hvor ofte vi hoejst proever at toemme spoolen af os selv.
const SPOOL_INTERVAL_S = 60.0;

// Serverens loft paa een POST /v1/calls/complete.
const MAX_BATCH = 500;

// Loft paa spoolfilen. Er batchwatch nede i en uge, skal en travl pipeline
// ikke fylde brugerens disk op. Naar loftet er naaet, tabes maalingen - og
// det er det rigtige valg: hans maskine er ikke vores lager.
const MAX_BYTES = 5 * 1024 * 1024;

// Feltnavne der maa forlade maskinen. Alt andet findes ikke i kroppen.
// Testen test_no_content.php holder listen fast. Samme felter som
// Python-, Ruby-, Go-, TypeScript- og .NET-klienterne.
//
// De fire sidste (acted_verdict, deadline_s, quoted_p50_s, quoted_p90_s) er
// udfaldsmaalingen (#101): raadet vi selv gav, haeftet paa den senere
// afslutning saa SERVEREN kan sammenholde sin egen maalte varighed med hvad vi
// lovede. Alt er tal og en beslutning vi selv traf - ingen ny PII.
const TILLADTE_FELTER = [
    'provider', 'model', 'mode', 'endpoint', 'requests',
    'input_tokens', 'output_tokens', 'started_at', 'ended_at',
    'status', 'ttfb_ms', 'source',
    'acted_verdict', 'deadline_s', 'quoted_p50_s', 'quoted_p90_s',
];

/**
 * Fjern alt der ikke staar paa tilladelseslisten.
 *
 * Sidste stop foer netvaerket. Selv om ingen offentlig metode tager imod
 * fritekst, skal DENNE funktion vaere det sted man kan pege paa naar nogen
 * spoerger "hvordan ved I at en prompt ikke kan slippe ud".
 *
 * @param array<string,mixed> $krop
 * @return array<string,mixed>
 */
function rens(array $krop): array
{
    $ud = [];
    foreach ($krop as $k => $v) {
        if (\in_array((string) $k, TILLADTE_FELTER, true)) {
            $ud[(string) $k] = $v;
        }
    }
    return $ud;
}

/**
 * Oversaet et max_wait til sekunder, eller null hvis vi ikke kan tyde det.
 *
 * Kalderen skriver typisk "15m", "1h", "30s" eller "2d" - samme sprog som
 * /v1/should-i-batch selv tager imod. Et blankt tal laeses som sekunder. Kan
 * vi ikke tyde det, sender vi hellere null end et gaet (regel #30).
 */
function sekunder(?string $maxWait): ?float
{
    if ($maxWait === null) {
        return null;
    }
    $s = \strtolower(\trim($maxWait));
    if ($s === '') {
        return null;
    }
    $faktor = ['s' => 1.0, 'm' => 60.0, 'h' => 3600.0, 'd' => 86400.0];
    $enhed = $s[\strlen($s) - 1];
    if (isset($faktor[$enhed])) {
        $tal = \substr($s, 0, -1);
        $mult = $faktor[$enhed];
    } else {
        $tal = $s;
        $mult = 1.0;
    }
    $tal = \trim($tal);
    if ($tal === '' || !\is_numeric($tal)) {
        return null;
    }
    return (float) $tal * $mult;
}

/**
 * De felter fra et gemt raad der maa haeftes paa en afslutning (#101).
 *
 * Kun ikke-null vaerdier kommer med: mangler en percentil eller en frist,
 * UDELADES feltet helt - vi opfinder ikke et nul (regel #30). Uden raad
 * overhovedet er resultatet en tom array, saa ingenting haeftes paa.
 *
 * @param array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}|null $raad
 * @return array<string,mixed>
 */
function udfaldsfelter(?array $raad): array
{
    if ($raad === null) {
        return [];
    }
    $ud = ['acted_verdict' => $raad['acted_verdict']];
    foreach (['deadline_s', 'quoted_p50_s', 'quoted_p90_s'] as $navn) {
        if (($raad[$navn] ?? null) !== null) {
            $ud[$navn] = $raad[$navn];
        }
    }
    return $ud;
}

/**
 * ISO-8601 UTC, sekundoploesning med et efterstillet Z - som de andre
 * klienter.
 */
function iso(float $ts): string
{
    return \gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
}

function standardUrl(): string
{
    $v = \getenv('BATCHWATCH_URL');
    return ($v === false || $v === '') ? 'https://batchwatch.dev' : $v;
}

function standardTimeout(): float
{
    $v = \getenv('BATCHWATCH_TIMEOUT');
    return ($v === false || $v === '') ? 2.0 : (float) $v;
}

/**
 * Default spool-sti. Tom streng slaar spoolen fra; ellers en fil i
 * temp-mappen.
 */
function standardSpool(): ?string
{
    $v = \getenv('BATCHWATCH_SPOOL');
    if ($v !== false) {
        // Tom streng slaar spoolen fra.
        return $v === '' ? null : $v;
    }
    return \rtrim(\sys_get_temp_dir(), '/\\') . \DIRECTORY_SEPARATOR . 'batchwatch-spool.jsonl';
}
