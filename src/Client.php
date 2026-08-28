<?php

declare(strict_types=1);

// batchwatch client.
//
// To regler former denne fil.
//
// **Den fejler aldrig opad.** Er batchwatch nede, langsom eller i stykker,
// maa kalderens batch-job ikke maerke det. Hvert netvaerkskald har en kort
// timeout (baade opkobling og laesning), og hver fejl bliver slugt og sendt
// til den valgfri debug-logger. Den eneste undtagelse der nogensinde forlader
// dette modul er kalderens egen.
//
// **Den sender aldrig indhold.** Prompts, svar, systemprompter, tool-kald,
// filnavne - intet af det. Kroppen bygges fra en fast tilladelsesliste af
// felter: provider, model, mode, endpoint, request-antal, tokental og
// tidsstempler. Der er intet felt at putte tekst i, per konstruktion.
//
// **En note om traade.** Python- og Ruby-klienterne laegger hvert netvaerks-
// kald paa en baggrundstraad, saa kalderens traad slet ikke roeres. PHP CLI
// har ingen aegte baggrundstraade. Vi efterligner derfor SEMANTIKKEN, ikke
// traaden: afsendelsen sker synkront, men er (a) bundet af en kort timeout paa
// baade opkobling og laesning, og (b) pakket saa INGEN batchwatch-fejl kan
// naa kalderen. Kalderen betaler dermed hoejst timeouten (default 2 s) pr.
// afsendelse i vaerste fald - aldrig en hang, aldrig en exception. Vil man
// heller ikke betale den tid, saettes en lav BATCHWATCH_TIMEOUT eller
// enabled=false. flush() findes for API-paritet, men har intet at vente paa.
//
//     use Batchwatch\Client;
//
//     $bw = new Client(token: 'tk_...');            // token er valgfri
//
//     // 1) foer du sender: hoerer det her til i koeen?
//     if ($bw->shouldBatch('gpt-5.6-sol', maxWait: '15m')) {
//         // $job = $client->batches->create(...);
//     } else {
//         // $answer = $client->chat->completions->create(...);
//     }
//
//     // 2) maal det
//     $t = $bw->track('gpt-5.6-sol', inputTokens: 9720);
//     // ...
//     $t->done(outputTokens: 4519);

namespace Batchwatch;

// Konstanter (VERSION, TILLADTE_FELTER, ...) og fri-funktioner (rens, iso,
// standardUrl, ...) bor i functions.php, saa Client og Spool deler een kopi
// og PSR-4 (som kun autoloader klasser) ikke efterlader dem udefinerede.
// require_once er idempotent - composer kan have loadet den allerede.
require_once __DIR__ . '/functions.php';
// HttpError og Tracking bor i egne filer (ren PSR-4), men vi loader dem her
// ogsaa, saa bootstrap.php uden composer stadig virker. require_once er
// idempotent.
require_once __DIR__ . '/HttpError.php';
require_once __DIR__ . '/Tracking.php';

/**
 * Klienten. Hver afsendelse er ikke-blokerende for kalderens LOGIK (ingen
 * exception slipper ud) og bundet af en kort timeout, og fejler aabent.
 */
final class Client
{
    // Sentinel til spool-argumentet, saa vi kan skelne "spool ikke oplyst"
    // (brug default) fra "spool: null" (slaa fra). Ruby har en skjult UNSET;
    // PHP kan ikke bruge et objekt som default-argument, men en umulig sti
    // som default-streng goer samme nytte. En rigtig sti er aldrig lig denne.
    public const SPOOL_DEFAULT = "\0__BATCHWATCH_SPOOL_DEFAULT__";

    private ?string $token;
    private string $base;
    private float $timeout;
    private bool $enabled;
    /** @var callable|null fn(string $besked): void */
    private $logger;
    private ?Spool $spool;
    private float $spoolSidst = 0.0;
    /**
     * Sidste raad pr. model (#101). Naar shouldBatch svarer, gemmer vi her hvad
     * vi anbefalede + de citerede percentiler, saa den NAESTE afslutning for
     * samme model kan haefte dem paa. Korrelationen er en dokumenteret
     * tilnaermelse: seneste-raad-pr-model, ikke pr-job.
     *
     * @var array<string, array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}>
     */
    private array $raad = [];

    /**
     * @param string|null $token   API-noegle. Falder tilbage til $BATCHWATCH_TOKEN.
     *                             Valgfri til maaling, paakraevet for at genafspille en spool.
     * @param string|null $baseUrl Default $BATCHWATCH_URL eller https://batchwatch.dev
     * @param float|null  $timeout Sekunder pr. HTTP-kald. Default $BATCHWATCH_TIMEOUT eller 2.0.
     * @param bool        $enabled false goer hvert netvaerkskald til en no-op.
     * @param string|null $spool   Sti til spoolfilen. UDELAD for default
     *                             ($BATCHWATCH_SPOOL eller en temp-fil); send null for at
     *                             slaa spooling fra; send en streng for en bestemt sti.
     *                             Spooling er altid inaktiv uden token.
     * @param callable|null $logger Valgfri logger fn(string): void. Alle slugte fejl gaar
     *                             hertil paa debug.
     */
    public function __construct(
        ?string $token = null,
        ?string $baseUrl = null,
        ?float $timeout = null,
        bool $enabled = true,
        ?string $spool = self::SPOOL_DEFAULT,
        ?callable $logger = null,
    ) {
        $tokenIn = $token ?: (getenv('BATCHWATCH_TOKEN') ?: null);
        $this->token = ($tokenIn === '') ? null : $tokenIn;
        $this->base = rtrim($baseUrl ?? standardUrl(), '/');
        $this->timeout = $timeout ?? standardTimeout();
        $this->enabled = $enabled;
        $this->logger = $logger;

        // Sentinel (udeladt) => default-sti; eksplicit null => spooling fra;
        // ellers den oplyste sti.
        $sti = ($spool === self::SPOOL_DEFAULT) ? standardSpool() : $spool;
        // Uden noegle kan /v1/calls/complete ikke tage imod, saa en spoolfil
        // ville aldrig kunne sendes. Saa lader vi vaere med at skrive den.
        $this->spool = ($sti !== null && $this->token !== null)
            ? new Spool($sti, MAX_BYTES, $logger)
            : null;
        if ($sti !== null && $this->token === null) {
            $this->debug('batchwatch: ingen token - spool slaaet fra '
                . '(/v1/calls/complete kraever en noegle)');
        }
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function spool(): ?Spool
    {
        return $this->spool;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    // ---------------------------------------------------------------- kerne

    /**
     * Eet HTTP-kald. Kaster ved fejl - kun offentlige metoder sluger.
     *
     * Bruger PHP-streams (ikke curl - klienten skal koere paa den noegne
     * standardinstallation). Baade opkoblings- og laese-timeout saettes, saa
     * en server der accepterer men aldrig svarer ikke kan holde os fanget
     * laengere end fristen.
     *
     * @param array<string,mixed>|list<mixed>|null $krop
     * @return array<string,mixed>|null
     */
    public function kald(string $sti, string $metode = 'GET', array|null $krop = null, ?float $timeout = null): ?array
    {
        $frist = $timeout ?? $this->timeout;
        $url = $this->base . $sti;

        $headers = [
            'user-agent: batchwatch-php/' . VERSION,
        ];
        $body = null;
        if ($krop !== null) {
            $headers[] = 'content-type: application/json';
            $body = json_encode($krop, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($this->token !== null) {
            $headers[] = 'authorization: Bearer ' . $this->token;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $metode,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $frist,           // laese-timeout
                'ignore_errors' => true,        // laes kroppen ogsaa paa 4xx/5xx
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Opkoblings-timeout haandteres separat, for stream-context "timeout"
        // daekker kun laesning/skrivning, ikke selve opkoblingen. Vi aabner
        // derfor foerst socket'en med samme frist, saa en black-hole-server der
        // aldrig ACK'er heller ikke kan holde os laengere end fristen.
        $this->sikrOpkobling($url, $frist);

        $raa = @file_get_contents($url, false, $ctx);
        if ($raa === false) {
            $fejl = error_get_last();
            throw new \RuntimeException('batchwatch netvaerksfejl: ' . ($fejl['message'] ?? 'ukendt'));
        }

        $kode = $this->statusFraHeaders($http_response_header ?? []);
        if ($kode < 200 || $kode >= 300) {
            throw new HttpError($kode, $raa);
        }

        $raa = trim($raa);
        if ($raa === '') {
            return null;
        }
        $ud = json_decode($raa, true);
        return is_array($ud) ? $ud : null;
    }

    /**
     * Aaben en TCP/TLS-opkobling med en haard frist, saa en server der aldrig
     * accepterer/ACK'er ikke kan holde os fanget - stream-context "timeout"
     * daekker ikke opkoblingen selv. Vi lukker den straks igen; file_get_contents
     * laver sin egen. Kaster paa timeout/refuse, saa kald() sluger det.
     */
    private function sikrOpkobling(string $url, float $frist): void
    {
        $dele = parse_url($url);
        if ($dele === false || !isset($dele['host'])) {
            return; // lad file_get_contents haandtere en misdannet URL
        }
        $skema = $dele['scheme'] ?? 'http';
        $vaert = $dele['host'];
        $port = $dele['port'] ?? ($skema === 'https' ? 443 : 80);
        $transport = $skema === 'https' ? 'ssl' : 'tcp';

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "{$transport}://{$vaert}:{$port}",
            $errno,
            $errstr,
            $frist,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            throw new \RuntimeException("batchwatch: kunne ikke koble op ({$errstr})");
        }
        fclose($sock);
    }

    /**
     * @param list<string> $headers
     */
    private function statusFraHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                // Ved redirects kan der vaere flere status-linjer; den sidste
                // vinder. ignore_errors=true + follow_location=0 giver os dog
                // normalt kun een.
                $sidste = (int) $m[1];
            }
        }
        return $sidste ?? 0;
    }

    /**
     * Koer en afsendelse "i baggrunden". PHP CLI har ingen aegte traade, saa
     * det er synkront - men fejl bliver slugt, saa dette maa aldrig tage
     * kalderens eget kald ned med sig. Se fil-headeren om traad-idiomet.
     *
     * @param callable(): void $blok
     */
    public function iBaggrunden(callable $blok): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $blok();
        } catch (HttpError $e) {
            $this->debug("batchwatch {$e->status}: {$e->bodyText}");
        } catch (\Throwable $e) {
            $this->debug('batchwatch utilgaengelig: ' . $e->getMessage());
        }
    }

    /**
     * Findes for API-paritet med Python/Ruby. Afsendelser er synkrone i PHP,
     * saa der er aldrig noget udestaaende at vente paa; returnerer altid true.
     */
    public function flush(float $timeout = 5.0): bool
    {
        return true;
    }

    // ---------------------------------------------------------- beslutning

    /**
     * Hvad koeen laver lige nu, eller null hvis vi ikke kan sige det.
     *
     * @return array<string,mixed>|null
     */
    public function waitNow(string $model, string $provider = 'openai', string $mode = 'batch'): ?array
    {
        try {
            $q = http_build_query(['provider' => $provider, 'model' => $model, 'mode' => $mode]);
            $r = $this->kald('/v1/wait?' . $q);
            if (!$r || ($r['verdict'] ?? null) === 'insufficient_data') {
                return null;
            }
            return $r;
        } catch (\Throwable $e) {
            $this->debug('batchwatch wait fejlede: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Hele dommen. Returnerer null hvis vi ikke kan svare.
     *
     * output_tokens er som regel UKENDT paa dette tidspunkt - modellen
     * bestemmer dem. Defaulten er derfor null, ikke nul. At sende nul ville faa
     * serveren til at regne besparelsen paa nul output, og output koster fem
     * til seks gange saa meget som input: svaret ville vaere systematisk for
     * lavt, uden at nogen kan se det. Kender du et loft, saa send maxTokens.
     *
     * @return array<string,mixed>|null
     */
    public function advice(
        string $model,
        ?string $maxWait = null,
        string $provider = 'openai',
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $maxTokens = null,
        string $risk = 'p90',
    ): ?array {
        $q = ['provider' => $provider, 'model' => $model, 'risk' => $risk];
        foreach ([
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'max_tokens' => $maxTokens,
        ] as $navn => $vaerdi) {
            if ($vaerdi !== null) {
                $q[$navn] = (int) $vaerdi;
            }
        }
        if ($maxWait !== null) {
            $q['max_wait'] = $maxWait;
        }
        try {
            return $this->kald('/v1/should-i-batch?' . http_build_query($q));
        } catch (\Throwable $e) {
            $this->debug('batchwatch advice fejlede: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * true/false. Ved enhver tvivl faar du din default tilbage.
     *
     * Vi gaetter aldrig paa kalderens vegne: kan vi ikke svare, faar kalderen
     * sin egen forudbestemte vaerdi. Defaulten er false - "koer det synkront" -
     * som er den sikre maade at tage fejl paa, for et synkront kald koster bare
     * mere, mens en uventet otte-timers koe kan tage et produkt ned.
     *
     * @param array<string,mixed> $kw ekstra advice()-argumenter (provider, inputTokens, ...)
     */
    public function shouldBatch(string $model, ?string $maxWait = null, bool $default = false, array $kw = []): bool
    {
        $r = $this->advice(
            $model,
            maxWait: $maxWait,
            provider: $kw['provider'] ?? 'openai',
            inputTokens: $kw['inputTokens'] ?? $kw['input_tokens'] ?? null,
            outputTokens: $kw['outputTokens'] ?? $kw['output_tokens'] ?? null,
            maxTokens: $kw['maxTokens'] ?? $kw['max_tokens'] ?? null,
            risk: $kw['risk'] ?? 'p90',
        );
        if (!$r) {
            return $default;
        }
        $svar = match ($r['verdict'] ?? null) {
            'run_batch' => true,
            'run_sync', 'batch_at' => false,
            default => $default,
        };
        $this->huskRaad($model, $svar, $maxWait, $r);
        return $svar;
    }

    /**
     * Gem det raad vi lige gav for DENNE model (#101). Kun tal og vores egen
     * beslutning ender her - intet brugerdata. De citerede percentiler tages
     * fra serverens svar; mangler et af dem, gemmer vi null og haefter det
     * simpelthen ikke paa (regel #30: intet opfundet 0).
     *
     * @param array<string,mixed> $svar
     */
    private function huskRaad(string $model, bool $actedVerdict, ?string $maxWait, array $svar): void
    {
        $tal = static fn ($v): ?float => \is_int($v) || \is_float($v) ? (float) $v : null;
        $this->raad[$model] = [
            'acted_verdict' => $actedVerdict,
            'deadline_s' => sekunder($maxWait),
            'quoted_p50_s' => $tal($svar['p50_s'] ?? null),
            'quoted_p90_s' => $tal($svar['p90_s'] ?? null),
        ];
    }

    /**
     * Det seneste raad for en model, eller null. Tilnaermelsen er
     * seneste-raad-pr-model: det nyeste shouldBatch haefter paa den naeste
     * afslutning af samme model.
     *
     * @return array{acted_verdict:bool,deadline_s:?float,quoted_p50_s:?float,quoted_p90_s:?float}|null
     */
    public function hentRaad(string $model): ?array
    {
        return $this->raad[$model] ?? null;
    }

    // ------------------------------------------------------------- maaling

    /**
     * Maal eet kald. Afsendelsen sker "i baggrunden" (synkront, men fejlfrit
     * mod kalderen - se fil-headeren).
     *
     * Ingen undtagelse fra batchwatch naar nogensinde kalderen. Uden en
     * callback returneres sporingen, og du kalder selv done(). Med en callback
     * fungerer den som Python-kontekstmanageren: en undtagelse fra din egen
     * blok optages som "failed" og kastes videre uroert.
     *
     * @param callable(Tracking): void|null $blok
     */
    public function track(
        string $model,
        string $provider = 'openai',
        string $mode = 'batch',
        int $requests = 1,
        ?int $inputTokens = null,
        ?string $endpoint = null,
        ?callable $blok = null,
    ): Tracking {
        $t = new Tracking($this, $provider, $model, $mode, $requests, $inputTokens, $endpoint);
        $t->start();
        if ($blok === null) {
            return $t;
        }
        try {
            $blok($t);
        } catch (\Throwable $e) {
            // Vi sluger vores egne fejl, aldrig kalderens.
            $t->done(status: 'failed');
            throw $e;
        }
        if (!$t->afsluttet()) {
            $t->done();
        }
        return $t;
    }

    // --------------------------------------------------------------- spool

    /**
     * Send alt der venter paa disken. Returnerer antallet accepteret.
     *
     * Synkron og sikker at kalde fra en shutdown-hook. Kaster aldrig. Poster
     * serveren afviser som ugyldige droppes - de bliver aldrig gyldige - og
     * antallet logges.
     */
    public function flushSpool(?float $timeout = null): int
    {
        if ($this->spool === null || !$this->enabled) {
            return 0;
        }
        $poster = $this->spool->take();
        if (empty($poster)) {
            return 0;
        }
        $sendt = 0;
        $rest = $poster;
        while (!empty($rest)) {
            $gruppe = array_slice($rest, 0, MAX_BATCH);
            $rest = array_slice($rest, MAX_BATCH);
            try {
                $r = $this->kald(
                    '/v1/calls/complete',
                    'POST',
                    array_values($gruppe),
                    $timeout ?? max($this->timeout, 10.0),
                );
            } catch (\Throwable $e) {
                $this->debug('batchwatch: spool kunne ikke sendes: ' . $e->getMessage());
                // Gruppen der fejlede bliver liggende sammen med resten.
                $this->spool->keep(array_merge($gruppe, $rest));
                return $sendt;
            }
            $sendt += (int) (($r ?? [])['accepted'] ?? 0);
            $afvist = (int) (($r ?? [])['rejected'] ?? 0);
            if ($afvist > 0) {
                $this->debug("batchwatch: {$afvist} spoolede maalinger blev afvist og er droppet");
            }
        }
        $this->spool->keep([]);
        $this->debug("batchwatch: {$sendt} spoolede maalinger sendt");
        return $sendt;
    }

    /**
     * Kaldes EFTER et vellykket kald - saa ved vi at netvaerket er oppe lige
     * nu, og vi undgaar at hamre paa en server der alligevel ikke svarer.
     */
    public function maaskeToemSpool(): void
    {
        if ($this->spool === null) {
            return;
        }
        $nu = $this->monotonic();
        if ($nu - $this->spoolSidst < SPOOL_INTERVAL_S) {
            return;
        }
        $this->spoolSidst = $nu;
        try {
            $this->flushSpool();
        } catch (\Throwable $e) {
            $this->debug('batchwatch: spooltoemning fejlede: ' . $e->getMessage());
        }
    }

    /**
     * Gem en FAERDIG maaling der ikke kunne afleveres.
     *
     * @param array<string,mixed> $krop
     */
    public function spoolMaaling(array $krop): void
    {
        if ($this->spool !== null) {
            $this->spool->append($krop);
        } else {
            $this->debug('batchwatch: maalingen gik tabt (ingen spool)');
        }
    }

    private function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000.0;
    }

    private function debug(string $besked): void
    {
        if ($this->logger !== null) {
            ($this->logger)($besked);
        }
    }
}
