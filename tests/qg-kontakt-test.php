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
$failMail = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.6'], $session, $config, static fn(): bool => false, 110);
check($failMail['body']['ok'] === false && $failMail['status'] === 502, 'fehlender Mailer ist kein Erfolg');
for ($i = 0; $i < 5; $i++) {
    qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.9'], $session, $config, $mailer, 200);
}
$limited = qg_kontakt_handle($post, ['HTTP_ORIGIN' => 'https://example.org', 'REMOTE_ADDR' => '203.0.113.9'], $session, $config, $mailer, 200);
check($limited['status'] === 429, 'Mengengrenze');

$html = file_get_contents(dirname(__DIR__) . '/index.html');
check(is_string($html) && !str_contains($html, 'sessionStorage.getItem') && !str_contains($html, 'sessionStorage.setItem') && !str_contains($html, 'localStorage.getItem') && !str_contains($html, 'localStorage.setItem'), 'Seite ohne Speicherzugriff');
check(is_string($html) && str_contains($html, 'mailto:p.andersen@quäntchenglück.de') && str_contains($html, 'tel:+491797656862'), 'Kartenkontakt sichtbar');
check(is_string($html) && !str_contains($html, 'info@') && !str_contains($html, 'gmail') && !str_contains($html, 'Hundshagen') && !str_contains($html, 'VR 34726') && !str_contains($html, 'VR34726'), 'keine unbelegten Kontakte oder Vorstände');
check(is_string($html) && str_contains($html, 'nicht das Ziel des Formulars') || str_contains((string)$html, 'nicht das Ziel des Formulars'), 'Karte nicht als Formularziel');
$example = file_get_contents(dirname(__DIR__) . '/qg-kontakt.config.example.php');
check(is_string($example) && !str_contains($example, 'p.andersen') && str_contains($example, "'mail_to' => ''"), 'Beispielkonfiguration ohne Empfänger');

echo $failed === 0 ? "ALLE_OK\n" : "FEHLER $failed\n";
exit($failed === 0 ? 0 : 1);
