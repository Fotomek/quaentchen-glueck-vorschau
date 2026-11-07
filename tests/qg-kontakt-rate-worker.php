<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'nicht verfügbar';
    return;
}

require dirname(__DIR__) . '/qg-kontakt-lib.php';

$result = qg_kontakt_rate_allow($argv[1], $argv[2], $argv[3], (int)$argv[4]);
echo $result;
