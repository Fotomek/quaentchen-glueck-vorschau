<?php
declare(strict_types=1);

$qgRemote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$qgDev = PHP_SAPI === 'cli-server'
    && getenv('QG_KONTAKT_DEV') === '1'
    && ($qgRemote === '127.0.0.1' || $qgRemote === '::1');
if (!$qgDev) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'nicht verfügbar';
    return;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/tests(?:/|$)#', (string)$uri) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'nicht verfügbar';
    return true;
}
if ($uri !== '/qg-kontakt.php') {
    return false;
}

require dirname(__DIR__) . '/qg-kontakt-lib.php';

$host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$rate = getenv('QG_KONTAKT_MOCK_DIR');
if (!is_string($rate) || $rate === '') {
    $rate = sys_get_temp_dir() . '/qg-kontakt-http-mock-' . getmypid();
}
if (!is_dir($rate) && !mkdir($rate, 0700, true) && !is_dir($rate)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'errors' => ['Der Versand ist vorübergehend nicht möglich.']]);
    return true;
}
session_save_path($rate);
session_start();
$config = [
    'mail_to' => 'verein@example.org',
    'mail_from' => 'website@example.org',
    'rate_salt' => 'testsalt-testsalt',
    'allowed_origins' => ['http://' . $host],
    'rate_dir' => $rate . '/limits',
    'dry_run' => false,
];
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
if (($_GET['token'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = bin2hex(random_bytes(16));
    $_SESSION['qg_csrf'] = $token;
    $_SESSION['qg_issued'] = time();
    http_response_code(200);
    echo json_encode(['ok' => true, 'csrf' => $token, 'configured' => qg_kontakt_config_ready($config)], JSON_UNESCAPED_UNICODE);
    return true;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['Diese Adresse nimmt nur das Formular entgegen.']]);
    return true;
}
$guard = qg_kontakt_request_guard($_SERVER, qg_kontakt_ini_bytes((string)ini_get('post_max_size')), qg_kontakt_read_body(QG_KONTAKT_MAX_BODY), qg_kontakt_post_bytes($_POST));
if ($guard !== null) {
    http_response_code($guard['status']);
    echo json_encode($guard['body'], JSON_UNESCAPED_UNICODE);
    return true;
}
$result = qg_kontakt_handle($_POST, $_SERVER, [
    'csrf' => (string)($_SESSION['qg_csrf'] ?? ''),
    'issued' => (int)($_SESSION['qg_issued'] ?? 0),
], $config, static function (string $to, string $subject, string $text, string $from, string $reply) use ($rate): bool {
    $log = $rate . '/mailer.log';
    return file_put_contents($log, "mock\n", FILE_APPEND) !== false;
}, time());
if ($result['status'] === 200) {
    $session = [];
    $result['body'] = qg_kontakt_bind_token($result['body'], $session, time());
    $_SESSION['qg_csrf'] = $session['csrf'];
    $_SESSION['qg_issued'] = $session['issued'];
}
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
return true;
