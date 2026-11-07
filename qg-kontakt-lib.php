<?php
declare(strict_types=1);

const QG_KONTAKT_ANLIEGEN = [
    'hilfe' => 'Ich brauche Hilfe',
    'mithelfen' => 'Ich möchte helfen',
    'allgemein' => 'Allgemeine Frage',
];

function qg_kontakt_clean(string $value, int $max): string
{
    $value = str_replace(["\r", "\n", "\0"], '', $value);
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function qg_kontakt_validate(array $in): array
{
    $errors = [];
    $email = qg_kontakt_clean((string)($in['email'] ?? ''), 120);
    $name = qg_kontakt_clean((string)($in['name'] ?? ''), 80);
    $phone = qg_kontakt_clean((string)($in['phone'] ?? ''), 40);
    $anliegen = (string)($in['anliegen'] ?? '');
    $message = trim(str_replace("\0", '', (string)($in['message'] ?? '')));
    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, 2000);
    } else {
        $message = substr($message, 0, 2000);
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte eine gültige E-Mail angeben.';
    }
    if (!isset(QG_KONTAKT_ANLIEGEN[$anliegen])) {
        $errors[] = 'Bitte ein Anliegen wählen.';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($len < 10) {
        $errors[] = 'Die Nachricht ist noch zu kurz.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\\/.\\- ]+$/', $phone)) {
        $errors[] = 'Die Telefonnummer enthält unzulässige Zeichen.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    return ['ok' => true, 'data' => [
        'email' => $email,
        'name' => $name,
        'phone' => $phone,
        'anliegen' => $anliegen,
        'anliegen_label' => QG_KONTAKT_ANLIEGEN[$anliegen],
        'message' => $message,
    ]];
}

function qg_kontakt_config_ready(array $config): bool
{
    return ($config['mail_to'] ?? '') !== ''
        && ($config['mail_from'] ?? '') !== ''
        && ($config['rate_salt'] ?? '') !== ''
        && !empty($config['allowed_origins']);
}

function qg_kontakt_origin(array $server): string
{
    $origin = (string)($server['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        return $origin;
    }
    $referer = (string)($server['HTTP_REFERER'] ?? '');
    if ($referer === '') {
        return '';
    }
    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $value = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $value .= ':' . $parts['port'];
    }
    return $value;
}

function qg_kontakt_rate_allow(string $dir, string $salt, string $ip, int $now, int $limit = 5, int $window = 3600): bool
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $file = $dir . '/' . hash('sha256', $salt . '|' . $ip) . '.json';
    $state = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
            $state = $decoded;
        }
    }
    if ($now - (int)$state['start'] >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    $state['count'] = (int)$state['count'] + 1;
    file_put_contents($file, json_encode($state), LOCK_EX);
    return $state['count'] <= $limit;
}

function qg_kontakt_handle(array $post, array $server, array $session, array $config, callable $mailer, int $now): array
{
    if (!qg_kontakt_config_ready($config) || !empty($config['dry_run'])) {
        return ['status' => 503, 'body' => ['ok' => false, 'errors' => ['Der Versand ist noch nicht eingerichtet.']]];
    }
    $origin = qg_kontakt_origin($server);
    if (!in_array($origin, $config['allowed_origins'], true)) {
        return ['status' => 403, 'body' => ['ok' => false, 'errors' => ['Die Anfrage kommt nicht von der vorgesehenen Website.']]];
    }
    $csrf = (string)($post['csrf'] ?? '');
    $expected = (string)($session['csrf'] ?? '');
    $issued = (int)($session['issued'] ?? 0);
    if ($expected === '' || $csrf === '' || !hash_equals($expected, $csrf) || $now - $issued < 3) {
        return ['status' => 403, 'body' => ['ok' => false, 'errors' => ['Das Formular ist nicht mehr gültig. Bitte die Seite neu laden.']]];
    }
    if (trim((string)($post['website'] ?? '')) !== '') {
        return ['status' => 400, 'body' => ['ok' => false, 'errors' => ['Die Nachricht wurde nicht angenommen.']]];
    }
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    if ($ip === '' || !qg_kontakt_rate_allow((string)$config['rate_dir'], (string)$config['rate_salt'], $ip, $now)) {
        return ['status' => 429, 'body' => ['ok' => false, 'errors' => ['Zu viele Nachrichten. Bitte später erneut versuchen.']]];
    }
    $valid = qg_kontakt_validate($post);
    if (!$valid['ok']) {
        return ['status' => 400, 'body' => ['ok' => false, 'errors' => $valid['errors']]];
    }
    $data = $valid['data'];
    $subject = 'QG Kontakt: ' . $data['anliegen_label'];
    $text = "Anliegen: {$data['anliegen_label']}\nName: {$data['name']}\nTelefon: {$data['phone']}\nE-Mail: {$data['email']}\n\n{$data['message']}\n";
    $sent = $mailer($config['mail_to'], $subject, $text, $config['mail_from'], $data['email']);
    if ($sent !== true) {
        return ['status' => 502, 'body' => ['ok' => false, 'errors' => ['Der Server konnte die Nachricht nicht annehmen.']]];
    }
    return ['status' => 200, 'body' => ['ok' => true]];
}
