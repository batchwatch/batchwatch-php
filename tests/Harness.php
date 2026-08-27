<?php

declare(strict_types=1);

// En lillebitte testrunner. Ingen PHPUnit, ingen Composer - klienten skal
// kunne proeves paa den noegne standardinstallation, praecis som den koerer.
//
// Brug:
//   $h = new Harness('SuiteNavn');
//   $h->test('beskrivelse', function (Harness $t) { $t->assertTrue(...); });
//   exit($h->run());   // 0 = alt groent

final class Harness
{
    private string $navn;
    /** @var list<array{navn:string, fn:callable}> */
    private array $tests = [];
    private int $bestaaet = 0;
    private int $fejlet = 0;
    private int $asserts = 0;
    /** @var list<string> */
    private array $fejl = [];

    public function __construct(string $navn)
    {
        $this->navn = $navn;
    }

    public function test(string $navn, callable $fn): void
    {
        $this->tests[] = ['navn' => $navn, 'fn' => $fn];
    }

    public function run(): int
    {
        fwrite(STDOUT, "\n== {$this->navn} ==\n");
        foreach ($this->tests as $t) {
            try {
                ($t['fn'])($this);
                $this->bestaaet++;
                fwrite(STDOUT, "  ok    {$t['navn']}\n");
            } catch (\Throwable $e) {
                $this->fejlet++;
                $sted = $e->getFile() . ':' . $e->getLine();
                $this->fejl[] = "{$this->navn} > {$t['navn']}: {$e->getMessage()} ({$sted})";
                fwrite(STDOUT, "  FAIL  {$t['navn']}: {$e->getMessage()}\n");
            }
        }
        fwrite(STDOUT, sprintf(
            "-- %s: %d bestaaet, %d fejlet, %d asserts --\n",
            $this->navn,
            $this->bestaaet,
            $this->fejlet,
            $this->asserts,
        ));
        return $this->fejlet === 0 ? 0 : 1;
    }

    public function bestaaetAntal(): int
    {
        return $this->bestaaet;
    }

    public function fejletAntal(): int
    {
        return $this->fejlet;
    }

    /** @return list<string> */
    public function fejlListe(): array
    {
        return $this->fejl;
    }

    // ----------------------------------------------------------- assertions

    public function assertTrue(bool $vilkaar, string $besked = ''): void
    {
        $this->asserts++;
        if ($vilkaar !== true) {
            throw new \AssertionError($besked !== '' ? $besked : 'forventede true');
        }
    }

    public function assertFalse(bool $vilkaar, string $besked = ''): void
    {
        $this->asserts++;
        if ($vilkaar !== false) {
            throw new \AssertionError($besked !== '' ? $besked : 'forventede false');
        }
    }

    /**
     * @param mixed $forventet
     * @param mixed $faktisk
     */
    public function assertEquals($forventet, $faktisk, string $besked = ''): void
    {
        $this->asserts++;
        if ($forventet !== $faktisk) {
            $f = var_export($forventet, true);
            $a = var_export($faktisk, true);
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede {$f}, fik {$a}");
        }
    }

    /**
     * @param mixed $vaerdi
     */
    public function assertNull($vaerdi, string $besked = ''): void
    {
        $this->asserts++;
        if ($vaerdi !== null) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . 'forventede null, fik ' . var_export($vaerdi, true));
        }
    }

    /**
     * @param mixed $vaerdi
     */
    public function assertNotNull($vaerdi, string $besked = ''): void
    {
        $this->asserts++;
        if ($vaerdi === null) {
            throw new \AssertionError($besked !== '' ? $besked : 'forventede ikke-null');
        }
    }

    public function assertContains(string $noegleord, string $haystack, string $besked = ''): void
    {
        $this->asserts++;
        if (!str_contains($haystack, $noegleord)) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede at finde '{$noegleord}'");
        }
    }

    public function assertNotContains(string $noegleord, string $haystack, string $besked = ''): void
    {
        $this->asserts++;
        if (str_contains($haystack, $noegleord)) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede IKKE at finde '{$noegleord}'");
        }
    }

    /**
     * @param mixed $behold
     * @param list<mixed> $liste
     */
    public function assertInList($behold, array $liste, string $besked = ''): void
    {
        $this->asserts++;
        if (!in_array($behold, $liste, true)) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . 'ikke paa listen: ' . var_export($behold, true));
        }
    }

    /**
     * @param array<mixed>|\Countable $liste
     */
    public function assertNotEmpty($liste, string $besked = ''): void
    {
        $this->asserts++;
        if (count($liste) === 0) {
            throw new \AssertionError($besked !== '' ? $besked : 'forventede ikke-tom');
        }
    }

    /**
     * @param array<mixed>|\Countable $liste
     */
    public function assertCount(int $forventet, $liste, string $besked = ''): void
    {
        $this->asserts++;
        $n = count($liste);
        if ($n !== $forventet) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede {$forventet}, fik {$n}");
        }
    }

    public function assertLessThan(float $graense, float $faktisk, string $besked = ''): void
    {
        $this->asserts++;
        if (!($faktisk < $graense)) {
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede < {$graense}, fik {$faktisk}");
        }
    }

    /**
     * Kald og forvent en bestemt exception-klasse.
     */
    public function assertThrows(string $klasse, callable $fn, string $besked = ''): void
    {
        $this->asserts++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $klasse) {
                return;
            }
            throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede {$klasse}, fik " . get_class($e));
        }
        throw new \AssertionError(($besked !== '' ? $besked . ' - ' : '') . "forventede {$klasse}, intet kastet");
    }
}
