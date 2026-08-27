<?php

declare(strict_types=1);

// Koerer alle tre testsuiter i separate processer og samler resultatet.
//
// Hver suite koerer i sin egen PHP-proces, saa en suite der forker servere
// ikke kan forstyrre en anden. run.php slutter med exit-kode 0 kun hvis ALLE
// suiter er groenne.
//
// Brug:  php tests/run.php

$suiter = [
    __DIR__ . '/test_fail_open.php',
    __DIR__ . '/test_no_content.php',
    __DIR__ . '/test_spool.php',
];

$php = PHP_BINARY;
$fejlet = 0;
$bestaaedeSuiter = 0;

foreach ($suiter as $suite) {
    $kode = 0;
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($suite), $kode);
    if ($kode === 0) {
        $bestaaedeSuiter++;
    } else {
        $fejlet++;
    }
}

$ialt = count($suiter);
fwrite(STDOUT, "\n================================\n");
fwrite(STDOUT, sprintf(
    "I ALT: %d/%d suiter groenne%s\n",
    $bestaaedeSuiter,
    $ialt,
    $fejlet === 0 ? ' - ALT BESTAAET' : " - {$fejlet} FEJLEDE",
));
fwrite(STDOUT, "================================\n");

exit($fejlet === 0 ? 0 : 1);
