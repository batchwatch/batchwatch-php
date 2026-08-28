<?php

declare(strict_types=1);

// A tiny test runner. No PHPUnit, no Composer - the client must be testable on
// the bare standard installation, exactly as it runs.
//
// Use:
//   $h = new Harness('SuiteName');
//   $h->test('description', function (Harness $t) { $t->assertTrue(...); });
//   exit($h->run());   // 0 = all green

final class Harness
{
    private string $name;
    /** @var list<array{name:string, fn:callable}> */
    private array $tests = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $asserts = 0;
    /** @var list<string> */
    private array $errors = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function test(string $name, callable $fn): void
    {
        $this->tests[] = ['name' => $name, 'fn' => $fn];
    }

    public function run(): int
    {
        fwrite(STDOUT, "\n== {$this->name} ==\n");
        foreach ($this->tests as $t) {
            try {
                ($t['fn'])($this);
                $this->passed++;
                fwrite(STDOUT, "  ok    {$t['name']}\n");
            } catch (\Throwable $e) {
                $this->failed++;
                $location = $e->getFile() . ':' . $e->getLine();
                $this->errors[] = "{$this->name} > {$t['name']}: {$e->getMessage()} ({$location})";
                fwrite(STDOUT, "  FAIL  {$t['name']}: {$e->getMessage()}\n");
            }
        }
        fwrite(STDOUT, sprintf(
            "-- %s: %d passed, %d failed, %d asserts --\n",
            $this->name,
            $this->passed,
            $this->failed,
            $this->asserts,
        ));
        return $this->failed === 0 ? 0 : 1;
    }

    public function passedCount(): int
    {
        return $this->passed;
    }

    public function failedCount(): int
    {
        return $this->failed;
    }

    /** @return list<string> */
    public function errorList(): array
    {
        return $this->errors;
    }

    // ----------------------------------------------------------- assertions

    public function assertTrue(bool $condition, string $message = ''): void
    {
        $this->asserts++;
        if ($condition !== true) {
            throw new \AssertionError($message !== '' ? $message : 'expected true');
        }
    }

    public function assertFalse(bool $condition, string $message = ''): void
    {
        $this->asserts++;
        if ($condition !== false) {
            throw new \AssertionError($message !== '' ? $message : 'expected false');
        }
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    public function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->asserts++;
        if ($expected !== $actual) {
            $e = var_export($expected, true);
            $a = var_export($actual, true);
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected {$e}, got {$a}");
        }
    }

    /**
     * @param mixed $value
     */
    public function assertNull($value, string $message = ''): void
    {
        $this->asserts++;
        if ($value !== null) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . 'expected null, got ' . var_export($value, true));
        }
    }

    /**
     * @param mixed $value
     */
    public function assertNotNull($value, string $message = ''): void
    {
        $this->asserts++;
        if ($value === null) {
            throw new \AssertionError($message !== '' ? $message : 'expected non-null');
        }
    }

    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->asserts++;
        if (!str_contains($haystack, $needle)) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected to find '{$needle}'");
        }
    }

    public function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->asserts++;
        if (str_contains($haystack, $needle)) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected NOT to find '{$needle}'");
        }
    }

    /**
     * @param mixed $value
     * @param list<mixed> $list
     */
    public function assertInList($value, array $list, string $message = ''): void
    {
        $this->asserts++;
        if (!in_array($value, $list, true)) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . 'not in the list: ' . var_export($value, true));
        }
    }

    /**
     * @param array<mixed>|\Countable $list
     */
    public function assertNotEmpty($list, string $message = ''): void
    {
        $this->asserts++;
        if (count($list) === 0) {
            throw new \AssertionError($message !== '' ? $message : 'expected non-empty');
        }
    }

    /**
     * @param array<mixed>|\Countable $list
     */
    public function assertCount(int $expected, $list, string $message = ''): void
    {
        $this->asserts++;
        $n = count($list);
        if ($n !== $expected) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected {$expected}, got {$n}");
        }
    }

    public function assertLessThan(float $limit, float $actual, string $message = ''): void
    {
        $this->asserts++;
        if (!($actual < $limit)) {
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected < {$limit}, got {$actual}");
        }
    }

    /**
     * Call and expect a specific exception class.
     */
    public function assertThrows(string $class, callable $fn, string $message = ''): void
    {
        $this->asserts++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected {$class}, got " . get_class($e));
        }
        throw new \AssertionError(($message !== '' ? $message . ' - ' : '') . "expected {$class}, nothing thrown");
    }
}
