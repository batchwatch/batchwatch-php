<?php

declare(strict_types=1);

// Bootstrap without Composer.
//
// A client you have to run "composer install" to try is a client nobody tries.
// Require this file and both the classes (Client, Spool) and the free functions
// (Batchwatch\sanitize and friends) are in place - no dependencies, just the
// standard installation.
//
//     require __DIR__ . '/clients/php/bootstrap.php';
//     $bw = new Batchwatch\Client(token: 'bw_...');
//
// If you use Composer, require 'vendor/autoload.php' instead - that loads the
// PSR-4 classes and the "files" free functions, and you do not need this file.

require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/Spool.php';
require_once __DIR__ . '/src/Client.php';
