<?php
declare(strict_types=1);

require dirname(__DIR__) . '/qg-kontakt-lib.php';

$failed = 0;
function check(bool $ok, string $name): void
{
    global $failed;
    if ($ok) {
        echo "OK  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
}

$valid = qg_kontakt_validate([
    'email' => 'ada@example.org',
    'name' => "Ada\n",
    'phone' => '',
    'anliegen' => 'hilfe',
    'message' => 'Bitte um einen Rückruf wegen einer allgemeinen Frage.',
]);
check($valid['ok'] === true && $valid['data']['name'] === 'Ada', 'gültige Anfrage ohne Zeilenumbruch im Namen');
$injected = qg_kontakt_validate([
    'email' => "ada@example.org\r\nBcc: x",
    'anliegen' => 'hilfe',
    'message' => 'Bitte um einen Rückruf wegen einer allgemeinen Frage.',
]);
check($injected['ok'] === false, 'Mail-Header werden nicht übernommen');
check(qg_kontakt_validate(['email' => 'nein', 'anliegen' => 'hilfe', 'message' => '1234567890'])['ok'] === false, 'ungültige E-Mail');
check(qg_kontakt_validate(['email' => 'ada@example.org', 'anliegen' => 'geheim', 'message' => '1234567890'])['ok'] === false, 'unbekanntes Anliegen');
check(qg_kontakt_validate(['email' => 'ada@example.org', 'anliegen' => 'hilfe', 'message' => 'kurz'])['ok'] === false, 'zu kurze Nachricht');

$longMessage = str_repeat('a', 2001);
$tooLong = qg_kontakt_validate(['email' => 'ada@example.org', 'anliegen' => 'hilfe', 'message' => $longMessage]);
check($tooLong['ok'] === false && !isset($tooLong['data']) && in_array('Die Nachricht ist zu lang.', $tooLong['errors'], true), 'Überlänge Nachricht wird abgelehnt');
check(qg_kontakt_validate(['email' => str_repeat('a', 109) . '@example.org', 'anliegen' => 'hilfe', 'message' => '1234567890'])['ok'] === false, 'Überlänge E-Mail wird abgelehnt');
check(qg_kontakt_validate(['email' => 'ada@example.org', 'name' => str_repeat('A', 81), 'anliegen' => 'hilfe', 'message' => '1234567890'])['ok'] === false, 'Überlänge Name wird abgelehnt');
check(qg_kontakt_validate(['email' => 'ada@example.org', 'phone' => str_repeat('1', 41), 'anliegen' => 'hilfe', 'message' => '1234567890'])['ok'] === false, 'Überlänge Telefon wird abgelehnt');
$asArray = qg_kontakt_validate(['email' => ['ada@example.org'], 'anliegen' => 'hilfe', 'message' => '1234567890']);
check($asArray['ok'] === false && !isset($asArray['data']), 'Array-Eingabe wird abgelehnt');
check(qg_kontakt_validate(['email' => 'ada@example.org', 'anliegen' => ['hilfe'], 'message' => '1234567890'])['ok'] === false, 'Array-Anliegen wird abgelehnt');

check(qg_kontakt_request_guard(['CONTENT_LENGTH' => '16384'], 8 * 1024 * 1024) === null, 'Body an der Grenze erlaubt');
$tooBig = qg_kontakt_request_guard(['CONTENT_LENGTH' => '16385'], 8 * 1024 * 1024);
check($tooBig !== null && $tooBig['status'] === 413, 'Body über 16 KiB abgelehnt');
check(qg_kontakt_request_guard(['CONTENT_LENGTH' => '10'], 1024)['status'] === 503, 'zu kleines post_max_size sperrt');
check(qg_kontakt_request_guard(['CONTENT_LENGTH' => '10'], 0) === null, 'post_max_size 0 gilt als unbegrenzt');
check(qg_kontakt_ini_bytes('16K') === 16384 && qg_kontakt_ini_bytes('8M') === 8388608, 'Serverlimit wird gelesen');
check(qg_kontakt_config_errors(['mail_to' => 'kein', 'mail_from' => 'website@example.org', 'rate_salt' => 'testsalt-testsalt', 'allowed_origins' => ['https://example.org']]) !== [], 'ungültiger Empfänger sperrt');
check(qg_kontakt_config_errors(['mail_to' => 'verein@example.org', 'mail_from' => 'website@example.org', 'rate_salt' => 'testsalt-testsalt', 'allowed_origins' => ['http://example.org']]) !== [], 'öffentliches HTTP ist kein Ursprung');
check(qg_kontakt_config_ready(['mail_to' => 'verein@example.org', 'mail_from' => 'website@example.org', 'rate_salt' => 'testsalt-testsalt', 'allowed_origins' => ['http://127.0.0.1:8765']]), 'Loopback-HTTP nur für den lokalen Test');

$dir = sys_get_temp_dir() . '/qg-kontakt-test-' . getmypid();
$config = [
    'mail_to' => 'verein@example.org',
    'mail_from' => 'website@example.org',
    'rate_salt' => 'testsalt-testsalt',
    'allowed_origins' => ['https://example.org'],
    'rate_dir' => $dir,
    'dry_run' => false,
];
$server = ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.5'];
$session = ['csrf' => 'token', 'issued' => 100];
$post = ['csrf' => 'token', 'website' => '', 'email' => 'ada@example.org', 'name' => '', 'phone' => '', 'anliegen' => 'allgemein', 'message' => 'Eine allgemeine Frage ohne Gesundheitsdaten.'];
$calls = 0;
$mailer = static function () use (&$calls): bool {
    $calls++;
    return true;
};
check(qg_kontakt_handle($post, $server, $session, ['dry_run' => true] + $config, $mailer, 110)['body']['ok'] === false && $calls === 0, 'Testmodus verschickt nicht');
check(qg_kontakt_handle($post, $server, $session, ['mail_to' => ''] + $config, $mailer, 110)['status'] === 503 && $calls === 0, 'ohne Empfänger kein Versand');
check(qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://evil.test', 'REMOTE_ADDR' => '203.0.113.5'], $session, $config, $mailer, 110)['status'] === 403, 'fremde Herkunft');
check(qg_kontakt_handle($post, $server, ['csrf' => 'anders', 'issued' => 100], $config, $mailer, 110)['status'] === 403, 'falsches Token');
$honeypot = $post;
$honeypot['website'] = 'http://spam.test';
check(qg_kontakt_handle($honeypot, $server, $session, $config, $mailer, 110)['status'] === 400 && $calls === 0, 'Köderfeld');
$ok = qg_kontakt_handle($post, $server, $session, $config, $mailer, 110);
check($ok['status'] === 200 && $ok['body'] === ['ok' => true] && $calls === 1, 'Mock-Versand einmal');
$boundSession = ['csrf' => 'alt', 'issued' => 100];
$bound = qg_kontakt_bind_token($ok['body'], $boundSession, 120);
check($bound['ok'] === true && $bound['csrf'] !== 'alt' && $boundSession['csrf'] === $bound['csrf'] && $boundSession['issued'] === 120, 'Erfolg erneuert das Token');
$failMail = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.6'], $session, $config, static fn(): bool => false, 110);
check($failMail['body']['ok'] === false && $failMail['status'] === 502, 'fehlender Mailer ist kein Erfolg');
for ($i = 0; $i < 5; $i++) {
    qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.9'], $session, $config, $mailer, 200);
}
$limited = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.9'], $session, $config, $mailer, 200);
check($limited['status'] === 429, 'Mengengrenze');

$ttlDir = sys_get_temp_dir() . '/qg-kontakt-ttl-' . getmypid();
mkdir($ttlDir, 0700, true);
$expired = $ttlDir . '/expired.json';
$fresh = $ttlDir . '/fresh.json';
file_put_contents($expired, json_encode(['start' => 1000, 'count' => 2]));
file_put_contents($fresh, json_encode(['start' => 9000, 'count' => 1]));
$ttlConfig = $config;
$ttlConfig['rate_dir'] = $ttlDir;
$ttl = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.20'], $session, $ttlConfig, $mailer, 5000);
check($ttl['status'] === 200 && !is_file($expired) && is_file($fresh), 'abgelaufene Zählerdatei wird gelöscht, frische bleibt');

$ioFile = sys_get_temp_dir() . '/qg-kontakt-io-file-' . getmypid();
file_put_contents($ioFile, 'x');
$ioConfig = $config;
$ioConfig['rate_dir'] = $ioFile;
$beforeIo = $calls;
$io = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.21'], $session, $ioConfig, $mailer, 110);
check($io['status'] === 503 && $calls === $beforeIo, 'Ratepfad ohne Verzeichnis fail-closed');

$ioDir = sys_get_temp_dir() . '/qg-kontakt-io-dir-' . getmypid();
mkdir($ioDir, 0700, true);
$hash = hash('sha256', $config['rate_salt'] . '|203.0.113.22');
mkdir($ioDir . '/' . $hash . '.json', 0700, true);
$ioConfig['rate_dir'] = $ioDir;
$blocked = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.22'], $session, $ioConfig, $mailer, 110);
check($blocked['status'] === 503 && $calls === $beforeIo, 'nicht beschreibbare Zählerdatei fail-closed');

$purgeDir = sys_get_temp_dir() . '/qg-kontakt-purge-' . getmypid();
mkdir($purgeDir, 0700, true);
mkdir($purgeDir . '/broken.json', 0700, true);
$ioConfig['rate_dir'] = $purgeDir;
$purgeFail = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.23'], $session, $ioConfig, $mailer, 5000);
check($purgeFail['status'] === 503 && $calls === $beforeIo, 'Bereinigungsfehler fail-closed');

$parallelDir = sys_get_temp_dir() . '/qg-kontakt-parallel-' . getmypid();
mkdir($parallelDir, 0700, true);
$worker = dirname(__FILE__) . '/qg-kontakt-rate-worker.php';
$procs = [];
for ($i = 0; $i < 8; $i++) {
    $procs[] = proc_open([PHP_BINARY, $worker, $parallelDir, 'testsalt-testsalt', '203.0.113.30', '1000'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[array_key_last($procs)] = ['proc' => $procs[array_key_last($procs)], 'pipes' => $pipes];
}
$allows = 0;
$limits = 0;
$errors = 0;
foreach ($procs as $item) {
    $out = stream_get_contents($item['pipes'][1]);
    fclose($item['pipes'][1]);
    fclose($item['pipes'][2]);
    proc_close($item['proc']);
    if ($out === 'allow') {
        $allows++;
    } elseif ($out === 'limit') {
        $limits++;
    } else {
        $errors++;
    }
}
$parallelFile = $parallelDir . '/' . hash('sha256', 'testsalt-testsalt|203.0.113.30') . '.json';
$parallelState = json_decode((string)file_get_contents($parallelFile), true);
check($errors === 0 && $allows === 5 && $limits === 3 && is_array($parallelState) && (int)$parallelState['count'] === 8, 'parallele Zähler ohne verlorene Updates');

$html = file_get_contents(dirname(__DIR__) . '/index.html');
check(is_string($html) && !str_contains($html, 'sessionStorage.getItem') && !str_contains($html, 'sessionStorage.setItem') && !str_contains($html, 'localStorage.getItem') && !str_contains($html, 'localStorage.setItem'), 'Seite ohne Speicherzugriff');
check(is_string($html) && !str_contains($html, 'mailto:') && !str_contains($html, 'tel:') && !str_contains($html, 'p.andersen') && !str_contains($html, '+49 179'), 'Kartenkontakte nicht veröffentlicht');
check(is_string($html) && str_contains($html, 'Websitefreigabe') && str_contains($html, 'nicht das Ziel des Formulars'), 'Kartenlücke benannt, nicht als Freigabe');
check(is_string($html) && !str_contains($html, 'info@') && !str_contains($html, 'gmail') && !str_contains($html, 'Hundshagen') && !str_contains($html, 'VR 34726') && !str_contains($html, 'VR34726'), 'keine unbelegten Kontakte oder Vorstände');
check(is_string($html) && !preg_match('/<form[^>]*action="qg-kontakt.php"/', $html) && !preg_match('/<form[^>]*method="post"/i', $html) && str_contains($html, 'onsubmit="return false"') && preg_match('/<button[^>]*id="kontakt-senden"[^>]*disabled/', $html) === 1, 'statisches Formular ist nicht sendbar');
check(is_string($html) && str_contains($html, 'data.configured!==true') && str_contains($html, "fetch('qg-kontakt.php'"), 'Aktivierung nur nach konfiguriertem Backend');
$successAt = is_string($html) ? strpos($html, 'zum Versand angenommen') : false;
$afterSuccess = $successAt === false ? '' : substr($html, $successAt, 700);
check($successAt !== false && !str_contains($afterSuccess, 'preview(') && !str_contains($afterSuccess, 'lockPreview(') && str_contains($afterSuccess, 'csrf'), 'Erfolg bleibt stehen und übernimmt das neue Token');
check(is_string($html) && str_contains($html, 'fehler&&!fehler.hidden'), 'Tokenabruf überschreibt keine Fehlermeldung');
check(is_string($html) && str_contains($html, 'https://docs.github.com/en/pages/getting-started-with-github-pages/what-is-github-pages') && str_contains($html, 'auch ohne Anmeldung bei GitHub') && !str_contains($html, 'bis zu einer Stunde'), 'Pages-IP-Log mit Quelle, ohne erfundene Frist');
check(is_string($html) && str_contains($html, 'keinen eigenen Zeitplan') && str_contains($html, '16 KiB'), 'Bereinigung und Bodygrenze ehrlich beschrieben');
$example = file_get_contents(dirname(__DIR__) . '/qg-kontakt.config.example.php');
check(is_string($example) && !str_contains($example, 'p.andersen') && str_contains($example, "'mail_to' => ''") && str_contains($example, 'post_max_size') && str_contains($example, 'LimitRequestBody'), 'Beispielkonfiguration ohne Empfänger, mit Serverlimits');
$router = file_get_contents(__DIR__ . '/qg-kontakt-router.php');
check(is_string($router) && !str_contains($router, 'mail('), 'lokaler HTTP-Mock ruft mail nicht auf');
$endpoint = file_get_contents(dirname(__DIR__) . '/qg-kontakt.php');
check(is_string($endpoint) && str_contains($endpoint, 'qg_kontakt_request_guard') && str_contains($endpoint, 'qg_kontakt_bind_token'), 'Endpunkt prüft Body und erneuert Token');

function qg_http_start(string $cmd): array
{
    $log = tempnam(sys_get_temp_dir(), 'qghttp');
    $proc = proc_open($cmd . ' > ' . escapeshellarg((string)$log) . ' 2>&1', [], $pipes);
    $port = 0;
    $start = microtime(true);
    while (microtime(true) - $start < 5) {
        $text = (string)@file_get_contents((string)$log);
        if (preg_match('/Development Server \(http:\/\/127\.0\.0\.1:(\d+)\)/', $text, $match)) {
            $port = (int)$match[1];
            break;
        }
        usleep(50000);
    }
    return ['proc' => $proc, 'log' => $log, 'port' => $port];
}
function qg_http_stop(array $server): void
{
    if (is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    if (!empty($server['log']) && $server['port'] === 0) {
        echo "SERVERLOG " . file_get_contents((string)$server['log']) . "\n";
    }
    if (!empty($server['log'])) {
        @unlink((string)$server['log']);
    }
}
function qg_curl(string $url, array $args = []): array
{
    $cmd = array_merge(['curl', '-sS', '-D', '-', '--max-time', '5'], $args, [$url]);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $parts = preg_split("/\r\n\r\n/", $raw, 2);
    $status = 0;
    if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $parts[0] ?? '', $match)) {
        $status = (int)$match[1];
    }
    return ['status' => $status, 'body' => $parts[1] ?? ''];
}

$root = dirname(__DIR__);
$plain = qg_http_start(PHP_BINARY . ' -S 127.0.0.1:0 -t ' . escapeshellarg($root));
check($plain['port'] > 0, 'lokaler PHP-Server ohne Konfiguration');
if ($plain['port'] > 0) {
    $base = 'http://127.0.0.1:' . $plain['port'];
    $page = qg_curl($base . '/index.html');
    check($page['status'] === 200 && preg_match('/<button[^>]*id="kontakt-senden"[^>]*disabled/', $page['body']) === 1 && !str_contains($page['body'], 'action="qg-kontakt.php"'), 'HTTP liefert das gesperrte Formular');
    $missing = qg_curl($base . '/qg-kontakt.php', ['-H', 'Accept: application/json', '-H', 'Origin: http://127.0.0.1:' . $plain['port'], '--data', 'email=ada@example.org']);
    check($missing['status'] === 503 && str_contains($missing['body'], '"ok":false'), 'unkonfigurierter POST bleibt 503');
    $big = qg_curl($base . '/qg-kontakt.php', ['-H', 'Accept: application/json', '-H', 'Content-Type: application/octet-stream', '--data-binary', str_repeat('a', 17000)]);
    check($big['status'] === 413, 'HTTP-Body über 16 KiB wird 413');
    $other = qg_curl($base . '/qg-kontakt.php', ['-X', 'PUT']);
    check($other['status'] === 405, 'fremde Methode bleibt 405');
}
qg_http_stop($plain);

$mockDir = sys_get_temp_dir() . '/qg-kontakt-http-mock-' . getmypid();
$mock = qg_http_start('QG_KONTAKT_MOCK_DIR=' . escapeshellarg($mockDir) . ' ' . PHP_BINARY . ' -S 127.0.0.1:0 -t ' . escapeshellarg($root) . ' ' . escapeshellarg($root . '/tests/qg-kontakt-router.php'));
check($mock['port'] > 0, 'lokaler Mock-Server');
if ($mock['port'] > 0) {
    $base = 'http://127.0.0.1:' . $mock['port'];
    $cookie = sys_get_temp_dir() . '/qg-kontakt-cookie-' . getmypid() . '.txt';
    $token = qg_curl($base . '/qg-kontakt.php?token=1', ['-c', $cookie, '-H', 'Accept: application/json']);
    $tokenBody = json_decode($token['body'], true);
    check($token['status'] === 200 && is_array($tokenBody) && $tokenBody['configured'] === true && is_string($tokenBody['csrf'] ?? null), 'Mock-Token nur bei fertiger Testkonfiguration');
    sleep(3);
    $bad = qg_curl($base . '/qg-kontakt.php', ['-b', $cookie, '-c', $cookie, '-H', 'Accept: application/json', '-H', 'Origin: ' . $base, '--data-urlencode', 'csrf=' . ($tokenBody['csrf'] ?? ''), '--data-urlencode', 'website=', '--data-urlencode', 'email[]=ada@example.org', '--data-urlencode', 'anliegen=hilfe', '--data-urlencode', 'message=1234567890']);
    check($bad['status'] === 400 && str_contains($bad['body'], 'Bitte nur Text'), 'HTTP lehnt Array-Mail ab');
    $token = qg_curl($base . '/qg-kontakt.php?token=1', ['-b', $cookie, '-c', $cookie, '-H', 'Accept: application/json']);
    $tokenBody = json_decode($token['body'], true);
    sleep(3);
    $over = qg_curl($base . '/qg-kontakt.php', ['-b', $cookie, '-c', $cookie, '-H', 'Accept: application/json', '-H', 'Origin: ' . $base, '--data-urlencode', 'csrf=' . ($tokenBody['csrf'] ?? ''), '--data-urlencode', 'website=', '--data-urlencode', 'email=ada@example.org', '--data-urlencode', 'anliegen=hilfe', '--data-urlencode', 'message=' . str_repeat('a', 2001)]);
    check($over['status'] === 400 && str_contains($over['body'], 'zu lang') && !str_contains($over['body'], '"ok":true'), 'HTTP lehnt Überlänge ab');
    $token = qg_curl($base . '/qg-kontakt.php?token=1', ['-b', $cookie, '-c', $cookie, '-H', 'Accept: application/json']);
    $tokenBody = json_decode($token['body'], true);
    sleep(3);
    @unlink($mockDir . '/mailer.log');
    $sent = qg_curl($base . '/qg-kontakt.php', ['-b', $cookie, '-c', $cookie, '-H', 'Accept: application/json', '-H', 'Origin: ' . $base, '--data-urlencode', 'csrf=' . ($tokenBody['csrf'] ?? ''), '--data-urlencode', 'website=', '--data-urlencode', 'email=ada@example.org', '--data-urlencode', 'name=', '--data-urlencode', 'phone=', '--data-urlencode', 'anliegen=allgemein', '--data-urlencode', 'message=Eine allgemeine Frage ohne Gesundheitsdaten.']);
    $sentBody = json_decode($sent['body'], true);
    $log = (string)@file_get_contents($mockDir . '/mailer.log');
    check($sent['status'] === 200 && is_array($sentBody) && $sentBody['ok'] === true && is_string($sentBody['csrf'] ?? null) && $sentBody['csrf'] !== ($tokenBody['csrf'] ?? '') && $log === "mock\n", 'lokaler Mock nimmt an und erneuert das Token');
    @unlink($cookie);
}
qg_http_stop($mock);

echo $failed === 0 ? "ALLE_OK\n" : "FEHLER $failed\n";
exit($failed === 0 ? 0 : 1);
