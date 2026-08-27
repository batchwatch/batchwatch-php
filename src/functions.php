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
// Testen test_no_content.php holder listen fast. Samme tolv felter som
// Python-, Ruby-, Go-, TypeScript- og .NET-klienterne.
const TILLADTE_FELTER = [
    'provider', 'model', 'mode', 'endpoint', 'requests',
    'input_tokens', 'output_tokens', 'started_at', 'ended_at',
    'status', 'ttfb_ms', 'source',
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
