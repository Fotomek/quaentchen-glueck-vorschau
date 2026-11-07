<?php
declare(strict_types=1);

require __DIR__ . '/qg-kontakt-lib.php';

function qg_kontakt_load_config(): array
{
    $path = __DIR__ . '/qg-kontakt.config.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function qg_kontakt_wants_json(): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

function qg_kontakt_respond(int $status, array $body): void
{
    http_response_code($status);
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    if (qg_kontakt_wants_json() || (($_GET['token'] ?? '') === '1')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    $text = htmlspecialchars(implode(' ', $body['errors'] ?? []), ENT_QUOTES, 'UTF-8');
    if (!empty($body['ok'])) {
        $text = 'Der Server hat die Nachricht zum Versand angenommen. Das ist noch keine Zustellbestätigung.';
    }
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Kontakt</title><p>' . $text . '</p><p><a href="./#kontakt">Zurück</a></p></html>';
}

$config = qg_kontakt_load_config();
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (($_GET['token'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = bin2hex(random_bytes(16));
    $_SESSION['qg_csrf'] = $token;
    $_SESSION['qg_issued'] = time();
    qg_kontakt_respond(200, ['ok' => true, 'csrf' => $token, 'configured' => qg_kontakt_config_ready($config)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qg_kontakt_respond(405, ['ok' => false, 'errors' => ['Diese Adresse nimmt nur das Formular entgegen.']]);
    exit;
}

$result = qg_kontakt_handle($_POST, $_SERVER, [
    'csrf' => (string)($_SESSION['qg_csrf'] ?? ''),
    'issued' => (int)($_SESSION['qg_issued'] ?? 0),
], $config + ['rate_dir' => __DIR__ . '/qg-kontakt-rate'], static function (string $to, string $subject, string $text, string $from, string $reply): bool {
    $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $reply . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    return mail($to, $subject, $text, $headers);
}, time());
qg_kontakt_respond($result['status'], $result['body']);
