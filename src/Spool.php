<?php

declare(strict_types=1);

// On-disk spool for measurements that could not be delivered.
//
// A measurement is worth the most exactly when the network is playing up, so
// that is the worst conceivable moment to lose one. Undeliverable *completed*
// measurements are therefore written to a JSONL file and replayed later through
// POST /v1/calls/complete.
//
// The file format is one JSON object per line, in exactly the shape
// /v1/calls/complete accepts. It is the same format in the Python, Ruby, Go,
// TypeScript and .NET clients, so a spool file written by one can be flushed by
// another.
//
// Replay requires an API key: /v1/calls/complete takes the caller's own
// timestamps and is closed to anonymous callers for that reason. A client
// without a token therefore does not spool at all - a file no one can send is
// just a disk leak.
//
// Concurrent WRITERS in the same process are protected with a file lock
// (flock). PHP CLI has no real background threads, so in practice one process
// writes at a time; but several PHP processes sharing the same spool file are
// not fully coordinated. Two processes flushing the same file at once can send
// the same measurement twice. Give each process its own BATCHWATCH_SPOOL if
// that matters.

namespace Batchwatch;

// The constants MAX_BATCH/MAX_BYTES live in functions.php, so Client and Spool
// share one copy. require_once is idempotent - functions.php redeclares nothing
// if composer already loaded it.
require_once __DIR__ . '/functions.php';

/**
 * Append-only JSONL file of completed measurements waiting to be sent.
 */
final class Spool
{
    private string $path;
    private string $pending;
    private int $maxBytes;
    /** @var callable|null Optional debug logger: fn(string $message): void */
    private $logger;

    /**
     * @param callable|null $logger fn(string $message): void
     */
    public function __construct(string $path, int $maxBytes = MAX_BYTES, ?callable $logger = null)
    {
        $this->path = $path;
        $this->pending = $path . '.pending';
        $this->maxBytes = $maxBytes;
        $this->logger = $logger;
    }

    // ------------------------------------------------------------------ write

    /**
     * Store one completed measurement. Returns true if it was stored.
     *
     * Never throws: failing a spool must not be worse than the network error
     * that triggered the spool.
     *
     * @param array<string,mixed> $record
     */
    public function append(array $record): bool
    {
        try {
            return $this->writeOne($record);
        } catch (\Throwable $e) {
            $this->debug('batchwatch: could not spool: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------- read

    /**
     * Move everything spooled into the pending file and return it.
     *
     * Returns a list of records. An empty list means there is nothing to send -
     * also when the spool could not be read at all.
     *
     * @return list<array<string,mixed>>
     */
    public function take(): array
    {
        try {
            return $this->takeInternal();
        } catch (\Throwable $e) {
            $this->debug('batchwatch: could not read spool: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Put records back after a partial or failed flush.
     *
     * @param list<array<string,mixed>> $remaining
     */
    public function keep(array $remaining): void
    {
        try {
            if (!empty($remaining)) {
                $this->writeFile($this->pending, $remaining);
            } elseif (is_file($this->pending)) {
                @unlink($this->pending);
            }
        } catch (\Throwable $e) {
            $this->debug('batchwatch: could not write spool back: ' . $e->getMessage());
        }
    }

    /**
     * Number of records waiting on disk. Best effort, never throws.
     */
    public function size(): int
    {
        try {
            return count($this->readFile($this->pending)) + count($this->readFile($this->path));
        } catch (\Throwable $e) {
            $this->debug('batchwatch: could not count spool: ' . $e->getMessage());
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

    // --------------------------------------------------------------- private

    /**
     * @param array<string,mixed> $record
     */
    private function writeOne(array $record): bool
    {
        if (is_file($this->path) && filesize($this->path) >= $this->maxBytes) {
            $this->debug("batchwatch: spool is full ({$this->path}) - the measurement is dropped");
            return false;
        }
        $dir = dirname($this->path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $line = self::json($record);

        // "c+b" and not "a+b": we must be able to READ the last byte, and a pure
        // append is read-locked. If the file ends mid-line after a crash, the
        // next measurement must not be glued onto it - otherwise we lose not
        // just the half line, but the whole one too.
        //
        // flock() guards against two writers in the same (or another) process
        // splitting their writes into each other. PHP CLI cannot really thread,
        // but shutdown hooks and re-entrant calls can still cross each other, so
        // the lock stays.
        $f = @fopen($this->path, 'c+b');
        if ($f === false) {
            $this->debug("batchwatch: could not open the spool file ({$this->path})");
            return false;
        }
        try {
            @flock($f, LOCK_EX);
            fseek($f, 0, SEEK_END);
            $size = ftell($f);
            $prefix = '';
            if ($size > 0) {
                fseek($f, $size - 1);
                $last = fread($f, 1);
                if ($last !== "\n") {
                    $prefix = "\n";
                }
                fseek($f, 0, SEEK_END);
            }
            // ONE write. If split up, another writer can slip its line in
            // between the two halves.
            fwrite($f, $prefix . $line . "\n");
            @flock($f, LOCK_UN);
        } finally {
            fclose($f);
        }
        $this->debug("batchwatch: measurement spooled to {$this->path}");
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function takeInternal(): array
    {
        $records = array_merge($this->readFile($this->pending), $this->readFile($this->path));
        if (empty($records)) {
            return [];
        }
        // The order is deliberate: write pending BEFORE the source is removed. A
        // crash in between must produce duplicates, not loss - a duplicate can
        // be seen and filtered out, a lost measurement does not exist.
        $this->writeFile($this->pending, $records);
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        return $records;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return [];
        }
        try {
            while (($line = fgets($f)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $rec = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($rec)) {
                    // A half-written line after a crash. Skip it instead of
                    // losing the rest of the file.
                    $this->debug('batchwatch: unusable line in spool skipped');
                    continue;
                }
                $out[] = $rec;
            }
        } finally {
            fclose($f);
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function writeFile(string $path, array $records): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $buffer = '';
        foreach ($records as $p) {
            $buffer .= self::json($p) . "\n";
        }
        // One write under lock, so a concurrent reader never sees a half file.
        $f = @fopen($path, 'wb');
        if ($f === false) {
            $this->debug("batchwatch: could not write spool ({$path})");
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
     * JSON without escaping of slashes, so endpoint paths stay readable and the
     * format matches the other clients byte for byte.
     *
     * @param array<string,mixed> $record
     */
    private static function json(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function debug(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
