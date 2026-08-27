<?php

declare(strict_types=1);

namespace Batchwatch;

require_once __DIR__ . '/functions.php';

/**
 * Haandtag for een maaling i luften. Returneres af Client::track().
 *
 * Egen fil, saa PSR-4 kan autoloade den. Bemaerk traad-idiomet i Client.php:
 * "i baggrunden" er synkront i PHP, men fejlfrit mod kalderen.
 */
final class Tracking
{
    private Client $bw;
    /** @var array<string,mixed> */
    private array $krop;
    private ?int $inputTokens;
    private ?string $id = null;
    private float $t0;
    private bool $afsluttet = false;

    public function __construct(
        Client $bw,
        string $provider,
        string $model,
        string $mode,
        int $requests,
        ?int $inputTokens,
        ?string $endpoint,
    ) {
        $this->bw = $bw;
        $this->krop = [
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'requests' => $requests,
            'endpoint' => $endpoint,
        ];
        $this->inputTokens = $inputTokens;
        $this->t0 = (float) time();
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function afsluttet(): bool
    {
        return $this->afsluttet;
    }

    public function start(): void
    {
        $krop = $this->krop;
        $krop['input_tokens'] = $this->inputTokens;
        $krop['started_at'] = iso($this->t0);

        $this->bw->iBaggrunden(function () use ($krop): void {
            $r = $this->bw->kald('/v1/calls', 'POST', rens($krop));
            $this->id = ($r !== null && isset($r['id'])) ? (string) $r['id'] : null;
            if ($r !== null && !empty($r['warning'])) {
                // Serverens advarsel er interessant men maa aldrig kaste.
                error_log('batchwatch: ' . $r['warning']);
            }
            if ($r !== null) {
                $this->bw->maaskeToemSpool();
            }
        });
    }

    /**
     * Opdater tokentallet naar det foerst kendes efter afsendelsen.
     */
    public function started(?int $inputTokens = null): void
    {
        if ($inputTokens !== null) {
            $this->inputTokens = $inputTokens;
        }
    }

    /**
     * Luk maalingen.
     *
     * outputTokens forbliver null naar du ikke kender dem. Den defaultes aldrig
     * til nul: nul er en maaling, fravaer er ikke, og serveren prissaetter dem
     * forskelligt med vilje.
     */
    public function done(?int $outputTokens = null, string $status = 'completed', ?int $ttfbMs = null): void
    {
        if ($this->afsluttet) {
            return;
        }
        $this->afsluttet = true;
        $slut = (float) time();

        $this->bw->iBaggrunden(function () use ($outputTokens, $status, $ttfbMs, $slut): void {
            $fuld = $this->krop;
            $fuld['input_tokens'] = $this->inputTokens;
            $fuld['output_tokens'] = $outputTokens;
            $fuld['status'] = $status;
            $fuld['started_at'] = iso($this->t0);
            $fuld['ended_at'] = iso($slut);
            if ($ttfbMs !== null) {
                $fuld['ttfb_ms'] = $ttfbMs;
            }

            if ($this->id !== null) {
                try {
                    $this->bw->kald('/v1/calls/' . $this->id, 'PATCH', rens([
                        'status' => $status,
                        'output_tokens' => $outputTokens,
                        'ttfb_ms' => $ttfbMs,
                        'ended_at' => iso($slut),
                    ]));
                    return;
                } catch (\Throwable $e) {
                    // Serveren har allerede starten. En spoolet genindsendelse
                    // kan derfor give en dublet hvis PATCH'en naaede frem
                    // alligevel - valgt med vilje: en dublet kan ses i
                    // datasaettet, en tabt maaling kan ikke.
                    $this->bw->spoolMaaling(rens($fuld));
                    return;
                }
            }
            // Start-kaldet naaede aldrig frem. Send hele maalingen paa een gang
            // i stedet for at tabe den.
            try {
                $this->bw->kald('/v1/calls/complete', 'POST', rens($fuld));
            } catch (\Throwable $e) {
                $this->bw->spoolMaaling(rens($fuld));
            }
        });
    }

    /**
     * Optag jobbet som ikke-faerdigt. En uafsluttet ventetid er ikke en ventetid.
     */
    public function failed(string $status = 'failed'): void
    {
        $this->done(status: $status);
    }
}
