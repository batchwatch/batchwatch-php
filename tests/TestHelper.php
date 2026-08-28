<?php

declare(strict_types=1);

// Shared test equipment.
//
// The tests run against a REAL HTTP server on loopback, not against a stubbed
// stream wrapper. We test that the client behaves properly on the network - so
// the network should be part of it.
//
// All servers bind to port 0. Port numbers are shared with everything else on
// the machine, and a fixed port would make the test flaky for reasons that have
// nothing to do with batchwatch.
//
// PHP CLI has no threads, so the server runs in a FORKED child process
// (pcntl_fork). It records every raw request into a JSONL file on disk; the
// test process reads that file afterwards. Because the client's submissions are
// synchronous, the recording is on disk by the time track()/flush() returns.
//
// We roll a tiny raw HTTP/1.1 server on stream_socket_server instead of a
// framework, so the suite does not depend on anything outside the standard
// installation - exactly like the client itself.

require_once __DIR__ . '/../src/Spool.php';
require_once __DIR__ . '/../src/Client.php';

/**
 * A batchwatch server that records everything it receives.
 *
 * The recording happens in a forked child process and is written to a capture
 * file. callsTo()/everythingReceived() read that file.
 */
final class FakeServer
{
    public int $port;
    public string $url;
    private int $pid;
    private string $captureFile;

    /**
     * @param array<string,array<string,mixed>> $responses path prefix -> body
     * @param array<string,int>                 $status    path prefix -> status code
     */
    public function __construct(array $responses = [], array $status = [])
    {
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($srv === false) {
            throw new \RuntimeException("could not bind server: {$errstr}");
        }
        $name = stream_socket_get_name($srv, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->url = "http://127.0.0.1:{$this->port}";
        $this->captureFile = tempnam(sys_get_temp_dir(), 'bw-cap-');

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: server loop. Ends when the parent drops the socket / dies.
            $this->serveForever($srv, $responses, $status);
            exit(0);
        }
        // Parent: close our copy of the listen socket; children inherit it.
        fclose($srv);
        $this->pid = $pid;
    }

    /**
     * @param resource $srv
     * @param array<string,array<string,mixed>> $responses
     * @param array<string,int> $status
     */
    private function serveForever($srv, array $responses, array $status): void
    {
        // Ignore SIGPIPE so a client that closes early does not kill us.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            exit(0);
        });

        while (true) {
            $conn = @stream_socket_accept($srv, 30);
            if ($conn === false) {
                continue;
            }
            $this->handle($conn, $responses, $status);
            @fclose($conn);
        }
    }

    /**
     * @param resource $conn
     * @param array<string,array<string,mixed>> $responses
     * @param array<string,int> $status
     */
    private function handle($conn, array $responses, array $status): void
    {
        stream_set_timeout($conn, 5);
        $firstLine = fgets($conn);
        if ($firstLine === false) {
            return;
        }
        $parts = explode(' ', trim($firstLine));
        $method = $parts[0] ?? '';
        $path = $parts[1] ?? '';

        $headers = [];
        while (($h = fgets($conn)) !== false) {
            $h = rtrim($h, "\r\n");
            if ($h === '') {
                break;
            }
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($h, 0, $pos)));
                $value = trim(substr($h, $pos + 1));
                $headers[$name] = $value;
            }
        }
        $n = (int) ($headers['content-length'] ?? '0');
        $raw = '';
        while ($n > 0 && strlen($raw) < $n) {
            $chunk = fread($conn, $n - strlen($raw));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }

        $body = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $body = ($decoded !== null && (is_array($decoded))) ? $decoded : null;
        }

        // Record to disk, atomically per request (one line).
        $record = json_encode([
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
            'raw' => $raw,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $f = @fopen($this->captureFile, 'a');
        if ($f !== false) {
            @flock($f, LOCK_EX);
            fwrite($f, $record . "\n");
            fflush($f);
            @flock($f, LOCK_UN);
            fclose($f);
        }

        [$bodyOut, $code] = $this->responseFor($path, $method, $responses, $status, $body);
        $data = json_encode($bodyOut, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statusText = $code === 201 ? 'Created' : 'OK';
        $out = "HTTP/1.1 {$code} {$statusText}\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($data) . "\r\n"
            . "connection: close\r\n\r\n"
            . $data;
        @fwrite($conn, $out);
        @fflush($conn);
    }

    /**
     * @param array<string,array<string,mixed>> $responses
     * @param array<string,int> $status
     * @param array<string,mixed>|list<mixed>|null $body
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function responseFor(string $path, string $method, array $responses, array $status, $body): array
    {
        $cleanPath = explode('?', $path)[0];
        foreach ($responses as $prefix => $responseBody) {
            if (str_starts_with($cleanPath, $prefix)) {
                return [$responseBody, $status[$prefix] ?? 200];
            }
        }
        if (str_starts_with($cleanPath, '/v1/calls/complete')) {
            $n = is_array($body) && array_is_list($body) ? count($body) : 0;
            return [['accepted' => $n, 'rejected' => 0, 'ids' => [], 'errors' => []], 201];
        }
        if ($cleanPath === '/v1/calls' && $method === 'POST') {
            return [['id' => 'c_test0000000000000001', 'recorded_at' => 0], 201];
        }
        if (str_starts_with($cleanPath, '/v1/calls/')) {
            $id = substr($cleanPath, (int) strrpos($cleanPath, '/') + 1);
            return [['id' => $id, 'duration_s' => 1, 'status' => 'completed'], 200];
        }
        return [['verdict' => 'run_batch'], 200];
    }

    /**
     * All recorded calls (in the order received).
     *
     * @return list<array{method:string,path:string,headers:array<string,string>,body:mixed,raw:string}>
     */
    public function calls(): array
    {
        if (!is_file($this->captureFile)) {
            return [];
        }
        $out = [];
        foreach (file($this->captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rec = json_decode($line, true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        return $out;
    }

    /**
     * All calls whose path starts with prefix (and matches method, if given).
     *
     * @return list<array{method:string,path:string,headers:array<string,string>,body:mixed,raw:string}>
     */
    public function callsTo(string $prefix, ?string $method = null): array
    {
        return array_values(array_filter($this->calls(), function ($k) use ($prefix, $method) {
            return str_starts_with($k['path'], $prefix)
                && ($method === null || $k['method'] === $method);
        }));
    }

    /**
     * Everything: paths, headers and bodies, as one string. For a crude
     * "leak the secret" scan.
     */
    public function everythingReceived(): string
    {
        $parts = [];
        foreach ($this->calls() as $k) {
            $parts[] = $k['path'];
            $parts[] = json_encode($k['headers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $parts[] = $k['raw'];
        }
        return implode("\n", $parts);
    }

    public function close(): void
    {
        @posix_kill($this->pid, SIGTERM);
        pcntl_waitpid($this->pid, $s, WNOHANG);
        // Give it a moment and reap hard if it is not gone.
        usleep(20_000);
        pcntl_waitpid($this->pid, $s, WNOHANG);
        @posix_kill($this->pid, SIGKILL);
        pcntl_waitpid($this->pid, $s, WNOHANG);
        if (is_file($this->captureFile)) {
            @unlink($this->captureFile);
        }
    }
}

/**
 * Listens, but never answers. To prove that the timeout holds.
 *
 * A closed port gives "connection refused" immediately and therefore does not
 * test the timeout - only the error handling. Here we never accept the
 * connection, so the client sits and waits until it gives up on its own.
 */
final class BlackHole
{
    public int $port;
    public string $url;
    /** @var resource */
    private $srv;

    public function __construct()
    {
        // backlog=1: we never accept, so subsequent connections hang in the OS
        // queue exactly like an overloaded server.
        $ctx = stream_context_create(['socket' => ['backlog' => 1]]);
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        if ($srv === false) {
            throw new \RuntimeException("black hole could not bind: {$errstr}");
        }
        $this->srv = $srv;
        $name = stream_socket_get_name($srv, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->url = "http://127.0.0.1:{$this->port}";
        // We never accept; connections stay in the OS queue.
    }

    public function close(): void
    {
        @fclose($this->srv);
    }
}

/**
 * A port no one listens on. Gives an immediate connection refused.
 */
function closedPort(): string
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($srv, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($srv);
    return "http://127.0.0.1:{$port}";
}

/**
 * A temporary directory per test run.
 */
function withTmp(callable $block): void
{
    $dir = sys_get_temp_dir() . '/bw-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);
    try {
        $block($dir);
    } finally {
        // Clean up recursively.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

/**
 * Clear the four environment variables, so no test depends on the developer's
 * own.
 */
function cleanEnv(): void
{
    foreach (['BATCHWATCH_TOKEN', 'BATCHWATCH_URL', 'BATCHWATCH_TIMEOUT', 'BATCHWATCH_SPOOL'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
