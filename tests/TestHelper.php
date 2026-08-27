<?php

declare(strict_types=1);

// Faelles testudstyr.
//
// Testene koerer mod en RIGTIG HTTP-server paa loopback, ikke mod en stubbet
// stream-wrapper. Vi tester at klienten opfoerer sig ordentligt paa netvaerket
// - saa skal netvaerket ogsaa vaere med.
//
// Alle servere binder til port 0. Portnumre er delt med alt andet paa
// maskinen, og en fast port ville goere testen flaky af grunde der intet har
// med batchwatch at goere.
//
// PHP CLI har ingen traade, saa serveren koerer i en FORKET child-proces
// (pcntl_fork). Den optager hvert raa request i en JSONL-fil paa disken;
// test-processen laeser den fil bagefter. Fordi klientens afsendelser er
// synkrone, staar optagelsen paa disken naar track()/flush() vender tilbage.
//
// Vi ruller en lille raa HTTP/1.1-server paa stream_socket_server i stedet for
// et framework, saa suiten ikke afhaenger af noget uden for
// standardinstallationen - praecis som klienten selv.

require_once __DIR__ . '/../src/Spool.php';
require_once __DIR__ . '/../src/Client.php';

/**
 * En batchwatch-server der optager alt hvad den faar.
 *
 * Optagelsen sker i en forket child-proces og skrives til en capture-fil.
 * kaldTil()/altViModtog() laeser den fil.
 */
final class FalskServer
{
    public int $port;
    public string $url;
    private int $pid;
    private string $captureFile;

    /**
     * @param array<string,array<string,mixed>> $svar   sti-praefiks -> krop
     * @param array<string,int>                 $status sti-praefiks -> statuskode
     */
    public function __construct(array $svar = [], array $status = [])
    {
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($srv === false) {
            throw new \RuntimeException("kunne ikke binde server: {$errstr}");
        }
        $navn = stream_socket_get_name($srv, false);
        $this->port = (int) substr($navn, (int) strrpos($navn, ':') + 1);
        $this->url = "http://127.0.0.1:{$this->port}";
        $this->captureFile = tempnam(sys_get_temp_dir(), 'bw-cap-');

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('pcntl_fork fejlede');
        }
        if ($pid === 0) {
            // Child: server-loop. Slutter naar forael dropper socket'en / doer.
            $this->serveForever($srv, $svar, $status);
            exit(0);
        }
        // Parent: luk vores kopi af lytte-socket'en; children arver den.
        fclose($srv);
        $this->pid = $pid;
    }

    /**
     * @param resource $srv
     * @param array<string,array<string,mixed>> $svar
     * @param array<string,int> $status
     */
    private function serveForever($srv, array $svar, array $status): void
    {
        // Ignorer SIGPIPE saa en klient der lukker tidligt ikke draeber os.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            exit(0);
        });

        while (true) {
            $conn = @stream_socket_accept($srv, 30);
            if ($conn === false) {
                continue;
            }
            $this->haandter($conn, $svar, $status);
            @fclose($conn);
        }
    }

    /**
     * @param resource $conn
     * @param array<string,array<string,mixed>> $svar
     * @param array<string,int> $status
     */
    private function haandter($conn, array $svar, array $status): void
    {
        stream_set_timeout($conn, 5);
        $forsteLinje = fgets($conn);
        if ($forsteLinje === false) {
            return;
        }
        $dele = explode(' ', trim($forsteLinje));
        $metode = $dele[0] ?? '';
        $sti = $dele[1] ?? '';

        $headers = [];
        while (($h = fgets($conn)) !== false) {
            $h = rtrim($h, "\r\n");
            if ($h === '') {
                break;
            }
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $navn = strtolower(trim(substr($h, 0, $pos)));
                $vaerdi = trim(substr($h, $pos + 1));
                $headers[$navn] = $vaerdi;
            }
        }
        $n = (int) ($headers['content-length'] ?? '0');
        $raa = '';
        while ($n > 0 && strlen($raa) < $n) {
            $chunk = fread($conn, $n - strlen($raa));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raa .= $chunk;
        }

        $krop = null;
        if ($raa !== '') {
            $dekodet = json_decode($raa, true);
            $krop = ($dekodet !== null && (is_array($dekodet))) ? $dekodet : null;
        }

        // Optag paa disken, atomart pr. request (een linje).
        $post = json_encode([
            'method' => $metode,
            'path' => $sti,
            'headers' => $headers,
            'body' => $krop,
            'raw' => $raa,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $f = @fopen($this->captureFile, 'a');
        if ($f !== false) {
            @flock($f, LOCK_EX);
            fwrite($f, $post . "\n");
            fflush($f);
            @flock($f, LOCK_UN);
            fclose($f);
        }

        [$kropUd, $kode] = $this->svarFor($sti, $metode, $svar, $status, $krop);
        $data = json_encode($kropUd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statustekst = $kode === 201 ? 'Created' : 'OK';
        $ud = "HTTP/1.1 {$kode} {$statustekst}\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($data) . "\r\n"
            . "connection: close\r\n\r\n"
            . $data;
        @fwrite($conn, $ud);
        @fflush($conn);
    }

    /**
     * @param array<string,array<string,mixed>> $svar
     * @param array<string,int> $status
     * @param array<string,mixed>|list<mixed>|null $krop
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function svarFor(string $sti, string $metode, array $svar, array $status, $krop): array
    {
        $rensetSti = explode('?', $sti)[0];
        foreach ($svar as $praefiks => $krop2) {
            if (str_starts_with($rensetSti, $praefiks)) {
                return [$krop2, $status[$praefiks] ?? 200];
            }
        }
        if (str_starts_with($rensetSti, '/v1/calls/complete')) {
            $n = is_array($krop) && array_is_list($krop) ? count($krop) : 0;
            return [['accepted' => $n, 'rejected' => 0, 'ids' => [], 'errors' => []], 201];
        }
        if ($rensetSti === '/v1/calls' && $metode === 'POST') {
            return [['id' => 'c_test0000000000000001', 'recorded_at' => 0], 201];
        }
        if (str_starts_with($rensetSti, '/v1/calls/')) {
            $id = substr($rensetSti, (int) strrpos($rensetSti, '/') + 1);
            return [['id' => $id, 'duration_s' => 1, 'status' => 'completed'], 200];
        }
        return [['verdict' => 'run_batch'], 200];
    }

    /**
     * Alle optagne kald (i modtaget raekkefoelge).
     *
     * @return list<array{method:string,path:string,headers:array<string,string>,body:mixed,raw:string}>
     */
    public function kald(): array
    {
        if (!is_file($this->captureFile)) {
            return [];
        }
        $ud = [];
        foreach (file($this->captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linje) {
            $rec = json_decode($linje, true);
            if (is_array($rec)) {
                $ud[] = $rec;
            }
        }
        return $ud;
    }

    /**
     * Alle kald hvis sti starter med praefiks (og matcher metode, hvis givet).
     *
     * @return list<array{method:string,path:string,headers:array<string,string>,body:mixed,raw:string}>
     */
    public function kaldTil(string $praefiks, ?string $metode = null): array
    {
        return array_values(array_filter($this->kald(), function ($k) use ($praefiks, $metode) {
            return str_starts_with($k['path'], $praefiks)
                && ($metode === null || $k['method'] === $metode);
        }));
    }

    /**
     * Alt: stier, headere og kroppe, som een streng. Til en grov
     * "laek secreten"-scan.
     */
    public function altViModtog(): string
    {
        $dele = [];
        foreach ($this->kald() as $k) {
            $dele[] = $k['path'];
            $dele[] = json_encode($k['headers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $dele[] = $k['raw'];
        }
        return implode("\n", $dele);
    }

    public function luk(): void
    {
        @posix_kill($this->pid, SIGTERM);
        pcntl_waitpid($this->pid, $s, WNOHANG);
        // Giv den et oejeblik og reap haardt hvis den ikke er vaek.
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
 * Lytter, men svarer aldrig. Til at bevise at timeouten holder.
 *
 * En lukket port giver "connection refused" med det samme og tester dermed
 * ikke timeouten - kun fejlhaandteringen. Her accepterer vi aldrig
 * forbindelsen, saa klienten sidder og venter indtil den selv giver op.
 */
final class SortHul
{
    public int $port;
    public string $url;
    /** @var resource */
    private $srv;

    public function __construct()
    {
        // backlog=1: vi accepterer aldrig, saa efterfoelgende opkoblinger
        // haenger i OS-koeen praecis som en overbelastet server.
        $ctx = stream_context_create(['socket' => ['backlog' => 1]]);
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        if ($srv === false) {
            throw new \RuntimeException("sort hul kunne ikke binde: {$errstr}");
        }
        $this->srv = $srv;
        $navn = stream_socket_get_name($srv, false);
        $this->port = (int) substr($navn, (int) strrpos($navn, ':') + 1);
        $this->url = "http://127.0.0.1:{$this->port}";
        // Vi accepterer aldrig; forbindelser bliver liggende i OS-koeen.
    }

    public function luk(): void
    {
        @fclose($this->srv);
    }
}

/**
 * En port ingen lytter paa. Giver oejeblikkelig connection refused.
 */
function lukketPort(): string
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $navn = stream_socket_get_name($srv, false);
    $port = (int) substr($navn, (int) strrpos($navn, ':') + 1);
    fclose($srv);
    return "http://127.0.0.1:{$port}";
}

/**
 * En midlertidig mappe pr. testkoersel.
 */
function medTmp(callable $blok): void
{
    $dir = sys_get_temp_dir() . '/bw-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);
    try {
        $blok($dir);
    } finally {
        // Ryd op rekursivt.
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
 * Ryd de fire miljoevariabler, saa ingen test afhaenger af udviklerens egne.
 */
function reneOmgivelser(): void
{
    foreach (['BATCHWATCH_TOKEN', 'BATCHWATCH_URL', 'BATCHWATCH_TIMEOUT', 'BATCHWATCH_SPOOL'] as $navn) {
        putenv($navn);
        unset($_ENV[$navn], $_SERVER[$navn]);
    }
}
