<?php

declare(strict_types=1);

// Bootstrap uden Composer.
//
// En klient man skal koere "composer install" for at proeve, bliver ikke
// proevet. Kraev denne fil, saa er baade klasserne (Client, Spool) og
// fri-funktionerne (Batchwatch\rens m.fl.) paa plads - ingen afhaengigheder,
// kun standardinstallationen.
//
//     require __DIR__ . '/clients/php/bootstrap.php';
//     $bw = new Batchwatch\Client(token: 'tk_...');
//
// Bruger du Composer, saa require i stedet 'vendor/autoload.php' - da loader
// PSR-4 klasserne og "files" fri-funktionerne, og du behoever ikke denne fil.

require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/Spool.php';
require_once __DIR__ . '/src/Client.php';
