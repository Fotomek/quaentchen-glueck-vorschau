<?php
declare(strict_types=1);

require dirname(__DIR__) . '/qg-kontakt-lib.php';

$result = qg_kontakt_rate_allow($argv[1], $argv[2], $argv[3], (int)$argv[4]);
echo $result;
