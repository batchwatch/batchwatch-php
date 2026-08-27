<?php

declare(strict_types=1);

// On-disk spool for maalinger der ikke kunne afleveres.
//
// En maaling er mest vaerd praecis naar netvaerket driller, saa det er det
// vaerst taenkelige oejeblik at tabe en. Uafleverbare *faerdige* maalinger
// bliver derfor lagt i en JSONL-fil og spillet af senere gennem
// POST /v1/calls/complete.
//
// Filformatet er eet JSON-objekt pr. linje, i praecis den form
// /v1/calls/complete tager imod. Det er det samme format i Python-, Ruby-,
// Go-, TypeScript- og .NET-klienterne, saa en spoolfil skrevet af den ene kan
// toemmes af den anden.
//
// Genafspilning kraever en API-noegle: /v1/calls/complete tager kalderens
// egne tidsstempler og er lukket for anonyme kaldere af den grund. En klient
// uden token spooler derfor slet ikke - en fil ingen kan sende er bare en
// disklaek.
//
// Samtidige SKRIVERE i samme proces beskyttes med en fillaas (flock). PHP CLI
// har ingen rigtige baggrundstraade, saa i praksis skriver een proces ad
// gangen; men flere PHP-processer der deler samme spoolfil er ikke fuldt
// koordineret. To processer der toemmer den samme fil samtidig kan sende den
// samme maaling to gange. Giv hver proces sin egen BATCHWATCH_SPOOL hvis det
// betyder noget.

namespace Batchwatch;

// Konstanterne MAX_BATCH/MAX_BYTES bor i functions.php, saa Client og Spool
// deler een kopi. require_once er idempotent - functions.php redeklarerer
// intet hvis composer allerede har loadet den.
require_once __DIR__ . '/functions.php';

/**
 * Append-only JSONL-fil af faerdige maalinger der venter paa at blive sendt.
 */
final class Spool
{
    private string $path;
    private string $pending;
    private int $maxBytes;
    /** @var callable|null Valgfri debug-logger: fn(string $besked): void */
    private $logger;

    /**
     * @param callable|null $logger fn(string $besked): void
     */
    public function __construct(string $path, int $maxBytes = MAX_BYTES, ?callable $logger = null)
    {
        $this->path = $path;
        $this->pending = $path . '.pending';
        $this->maxBytes = $maxBytes;
        $this->logger = $logger;
    }

    // ------------------------------------------------------------------ skriv

    /**
     * Gem een faerdig maaling. Returnerer true hvis den blev gemt.
     *
     * Kaster aldrig: at fejle en spool maa ikke vaere vaerre end den
     * netvaerksfejl der udloeste spoolen.
     *
     * @param array<string,mixed> $record
     */
    public function append(array $record): bool
    {
        try {
            return $this->skrivEn($record);
        } catch (\Throwable $e) {
            $this->debug('batchwatch: kunne ikke spoole: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------- laes

    /**
     * Flyt alt spoolet ind i pending-filen og returner det.
     *
     * Returnerer en liste af poster. En tom liste betyder at der ikke er
     * noget at sende - ogsaa naar spoolen slet ikke kunne laeses.
     *
     * @return list<array<string,mixed>>
     */
    public function take(): array
    {
        try {
            return $this->tag();
        } catch (\Throwable $e) {
            $this->debug('batchwatch: kunne ikke laese spool: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Laeg poster tilbage efter en delvis eller fejlet toemning.
     *
     * @param list<array<string,mixed>> $remaining
     */
    public function keep(array $remaining): void
    {
        try {
            if (!empty($remaining)) {
                $this->skriv($this->pending, $remaining);
            } elseif (is_file($this->pending)) {
                @unlink($this->pending);
            }
        } catch (\Throwable $e) {
            $this->debug('batchwatch: kunne ikke skrive spool tilbage: ' . $e->getMessage());
        }
    }

    /**
     * Antal poster der venter paa disken. Bedste forsoeg, kaster aldrig.
     */
    public function size(): int
    {
        try {
            return count($this->laes($this->pending)) + count($this->laes($this->path));
        } catch (\Throwable $e) {
            $this->debug('batchwatch: kunne ikke taelle spool: ' . $e->getMessage());
            return 0;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function pending(): string
    {
        return $this->pending;
    }

    // ----------------------------------------------------------------- privat

    /**
     * @param array<string,mixed> $record
     */
    private function skrivEn(array $record): bool
    {
        if (is_file($this->path) && filesize($this->path) >= $this->maxBytes) {
            $this->debug("batchwatch: spool er fuld ({$this->path}) - maalingen tabes");
            return false;
        }
        $mappe = dirname($this->path);
        if ($mappe !== '' && $mappe !== '.' && !is_dir($mappe)) {
            @mkdir($mappe, 0o755, true);
        }

        $linje = self::json($record);

        // "c+b" og ikke "a+b": vi skal kunne LAESE den sidste byte, og en ren
        // append er skrivebeskyttet. Slutter filen midt i en linje efter et
        // nedbrud, skal den naeste maaling ikke limes fast paa den - saa taber
        // vi ikke bare den halve linje, men ogsaa den hele.
        //
        // flock() beskytter mod at to skrivere i samme (eller en anden) proces
        // splitter deres skrivninger ind i hinanden. PHP CLI kan ikke traade
        // egentligt, men shutdown-hooks og genindlejrede kald kan alligevel
        // krydse hinanden, saa laasen bliver staaende.
        $f = @fopen($this->path, 'c+b');
        if ($f === false) {
            $this->debug("batchwatch: kunne ikke aabne spoolfilen ({$this->path})");
            return false;
        }
        try {
            @flock($f, LOCK_EX);
            fseek($f, 0, SEEK_END);
            $stoerrelse = ftell($f);
            $foran = '';
            if ($stoerrelse > 0) {
                fseek($f, $stoerrelse - 1);
                $sidste = fread($f, 1);
                if ($sidste !== "\n") {
                    $foran = "\n";
                }
                fseek($f, 0, SEEK_END);
            }
            // EEN skrivning. Deles den op, kan en anden skriver naa at skyde
            // sin linje ind imellem de to halvdele.
            fwrite($f, $foran . $linje . "\n");
            @flock($f, LOCK_UN);
        } finally {
            fclose($f);
        }
        $this->debug("batchwatch: maaling spoolet til {$this->path}");
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tag(): array
    {
        $poster = array_merge($this->laes($this->pending), $this->laes($this->path));
        if (empty($poster)) {
            return [];
        }
        // Raekkefoelgen er med vilje: skriv pending FOER kilden fjernes. Et
        // nedbrud midt imellem skal give dubletter, ikke tab - en dublet kan
        // ses og filtreres fra, en tabt maaling findes ikke.
        $this->skriv($this->pending, $poster);
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        return $poster;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function laes(string $sti): array
    {
        if (!is_file($sti)) {
            return [];
        }
        $ud = [];
        $f = @fopen($sti, 'rb');
        if ($f === false) {
            return [];
        }
        try {
            while (($linje = fgets($f)) !== false) {
                $linje = trim($linje);
                if ($linje === '') {
                    continue;
                }
                $rec = json_decode($linje, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($rec)) {
                    // En halvskreven linje efter et nedbrud. Spring den over i
                    // stedet for at tabe resten af filen.
                    $this->debug('batchwatch: ubrugelig linje i spool sprunget over');
                    continue;
                }
                $ud[] = $rec;
            }
        } finally {
            fclose($f);
        }
        return $ud;
    }

    /**
     * @param list<array<string,mixed>> $poster
     */
    private function skriv(string $sti, array $poster): void
    {
        $mappe = dirname($sti);
        if ($mappe !== '' && $mappe !== '.' && !is_dir($mappe)) {
            @mkdir($mappe, 0o755, true);
        }
        $buffer = '';
        foreach ($poster as $p) {
            $buffer .= self::json($p) . "\n";
        }
        // Een write under laas, saa en samtidig laeser aldrig ser en halv fil.
        $f = @fopen($sti, 'wb');
        if ($f === false) {
            $this->debug("batchwatch: kunne ikke skrive spool ({$sti})");
            return;
        }
        try {
            @flock($f, LOCK_EX);
            fwrite($f, $buffer);
            @flock($f, LOCK_UN);
        } finally {
            fclose($f);
        }
    }

    /**
     * JSON uden escaping af skraastreger, saa endpoint-stier forbliver
     * laesbare og formatet matcher de andre klienter byte for byte.
     *
     * @param array<string,mixed> $record
     */
    private static function json(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function debug(string $besked): void
    {
        if ($this->logger !== null) {
            ($this->logger)($besked);
        }
    }
}
