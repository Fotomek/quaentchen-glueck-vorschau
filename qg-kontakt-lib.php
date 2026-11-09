<?php
declare(strict_types=1);

const QG_KONTAKT_ANLIEGEN = [
    'patenschaft' => 'Patenschaft',
    'sponsoring' => 'Sponsoring',
    'allgemein' => 'Allgemein',
];

const QG_KONTAKT_MAX_BODY = 16384;
const QG_KONTAKT_MAX_EMAIL = 120;
const QG_KONTAKT_MAX_NAME = 80;
const QG_KONTAKT_MAX_PHONE = 40;
const QG_KONTAKT_MAX_MESSAGE = 2000;

function qg_kontakt_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function qg_kontakt_field(array $in, string $key): array
{
    if (!array_key_exists($key, $in) || $in[$key] === null) {
        return ['ok' => true, 'value' => ''];
    }
    if (!is_string($in[$key])) {
        return ['ok' => false, 'errors' => ['Bitte nur Text in die Felder schreiben.']];
    }
    return ['ok' => true, 'value' => $in[$key]];
}

function qg_kontakt_plain(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

function qg_kontakt_validate(array $in): array
{
    $errors = [];
    $email = qg_kontakt_field($in, 'email');
    $name = qg_kontakt_field($in, 'name');
    $phone = qg_kontakt_field($in, 'phone');
    $message = qg_kontakt_field($in, 'message');
    $anliegenIn = qg_kontakt_field($in, 'anliegen');
    foreach ([$email, $name, $phone, $message, $anliegenIn] as $field) {
        if (!$field['ok']) {
            return $field;
        }
    }
    $emailRaw = $email['value'];
    $nameRaw = $name['value'];
    $phoneRaw = $phone['value'];
    $messageRaw = $message['value'];
    $anliegen = $anliegenIn['value'];
    if (qg_kontakt_len($emailRaw) > QG_KONTAKT_MAX_EMAIL) {
        $errors[] = 'Die E-Mail ist zu lang.';
    }
    if (qg_kontakt_len($nameRaw) > QG_KONTAKT_MAX_NAME) {
        $errors[] = 'Der Name ist zu lang.';
    }
    if (qg_kontakt_len($phoneRaw) > QG_KONTAKT_MAX_PHONE) {
        $errors[] = 'Die Telefonnummer ist zu lang.';
    }
    if (str_contains($messageRaw, "\0")) {
        $errors[] = 'Die Nachricht enthält unzulässige Zeichen.';
    } elseif (qg_kontakt_len($messageRaw) > QG_KONTAKT_MAX_MESSAGE) {
        $errors[] = 'Die Nachricht ist zu lang.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $emailValue = qg_kontakt_plain($emailRaw);
    $nameValue = qg_kontakt_plain($nameRaw);
    $phoneValue = qg_kontakt_plain($phoneRaw);
    $messageValue = trim($messageRaw);
    if ($emailValue === '' || filter_var($emailValue, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte eine gültige E-Mail angeben.';
    }
    if (!isset(QG_KONTAKT_ANLIEGEN[$anliegen])) {
        $errors[] = 'Bitte ein Anliegen wählen.';
    }
    if (qg_kontakt_len($messageValue) < 10) {
        $errors[] = 'Die Nachricht ist noch zu kurz.';
    }
    if ($phoneValue !== '' && !preg_match('/^[0-9+()\\/.\\- ]+$/', $phoneValue)) {
        $errors[] = 'Die Telefonnummer enthält unzulässige Zeichen.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    return ['ok' => true, 'data' => [
        'email' => $emailValue,
        'name' => $nameValue,
        'phone' => $phoneValue,
        'anliegen' => $anliegen,
        'anliegen_label' => QG_KONTAKT_ANLIEGEN[$anliegen],
        'message' => $messageValue,
    ]];
}

function qg_kontakt_mailbox(string $value): bool
{
    return !preg_match('/[\r\n\0]/', $value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function qg_kontakt_origin_value(string $value): bool
{
    return preg_match('#\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $value) === 1
        || preg_match('#\Ahttp://(127\.0\.0\.1|localhost)(?::[0-9]{1,5})?\z#', $value) === 1;
}

function qg_kontakt_config_errors(array $config): array
{
    $errors = [];
    if (!empty($config['dry_run'])) {
        $errors[] = 'dry_run';
    }
    foreach (['mail_to', 'mail_from'] as $key) {
        $value = $config[$key] ?? '';
        if (!is_string($value) || !qg_kontakt_mailbox($value)) {
            $errors[] = $key;
        }
    }
    $salt = $config['rate_salt'] ?? '';
    if (!is_string($salt) || strlen($salt) < 16) {
        $errors[] = 'rate_salt';
    }
    $origins = $config['allowed_origins'] ?? null;
    if (!is_array($origins) || $origins === []) {
        $errors[] = 'allowed_origins';
    } else {
        foreach ($origins as $origin) {
            if (!is_string($origin) || !qg_kontakt_origin_value($origin)) {
                $errors[] = 'allowed_origins';
                break;
            }
        }
    }
    return $errors;
}

function qg_kontakt_config_ready(array $config): bool
{
    return qg_kontakt_config_errors($config) === [];
}

function qg_kontakt_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return -1;
    }
    if (!preg_match('/\A(\d+)([KMG])?\z/i', $value, $match)) {
        return -1;
    }
    $number = (int)$match[1];
    $unit = strtoupper($match[2] ?? '');
    return match ($unit) {
        'G' => $number * 1024 * 1024 * 1024,
        'M' => $number * 1024 * 1024,
        'K' => $number * 1024,
        default => $number,
    };
}

function qg_kontakt_read_body(int $max): string
{
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        return str_repeat('x', $max + 1);
    }
    $data = '';
    $cap = $max + 1;
    while (strlen($data) < $cap) {
        $part = fread($stream, $cap - strlen($data));
        if ($part === false) {
            fclose($stream);
            return str_repeat('x', $max + 1);
        }
        if ($part === '') {
            break;
        }
        $data .= $part;
    }
    fclose($stream);
    return $data;
}

function qg_kontakt_post_bytes(array $post): int
{
    $total = 0;
    $stack = [$post];
    while ($stack) {
        foreach (array_pop($stack) as $value) {
            if (is_array($value)) {
                $stack[] = $value;
                continue;
            }
            if (is_string($value)) {
                $total += strlen($value);
                if ($total > QG_KONTAKT_MAX_BODY) {
                    return $total;
                }
            }
        }
    }
    return $total;
}

function qg_kontakt_request_guard(array $server, int $postMaxBytes, string $body, int $parsedBytes = 0): ?array
{
    if ($postMaxBytes !== 0 && $postMaxBytes < QG_KONTAKT_MAX_BODY) {
        return ['status' => 503, 'body' => ['ok' => false, 'errors' => ['Der Versand ist noch nicht eingerichtet.']]];
    }
    if (array_key_exists('CONTENT_LENGTH', $server)) {
        $raw = $server['CONTENT_LENGTH'];
        if (is_int($raw)) {
            $raw = (string)$raw;
        }
        if (!is_string($raw) || preg_match('/\A\d+\z/', $raw) !== 1) {
            return ['status' => 413, 'body' => ['ok' => false, 'errors' => ['Die Anfrage ist zu groß.']]];
        }
        $declared = (int)$raw;
        if ($declared > QG_KONTAKT_MAX_BODY || ($body !== '' && $declared !== strlen($body))) {
            return ['status' => 413, 'body' => ['ok' => false, 'errors' => ['Die Anfrage ist zu groß.']]];
        }
    }
    if (strlen($body) > QG_KONTAKT_MAX_BODY || $parsedBytes > QG_KONTAKT_MAX_BODY) {
        return ['status' => 413, 'body' => ['ok' => false, 'errors' => ['Die Anfrage ist zu groß.']]];
    }
    return null;
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

function qg_kontakt_rate_purge(string $dir, int $now, int $window): bool
{
    $list = scandir($dir);
    if ($list === false) {
        return false;
    }
    foreach ($list as $name) {
        if ($name === '.' || $name === '..' || $name === '.lock' || $name === '.htaccess' || !str_ends_with($name, '.json')) {
            continue;
        }
        $path = $dir . '/' . $name;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $decoded = json_decode($raw, true);
        $start = is_array($decoded) && isset($decoded['start']) ? (int)$decoded['start'] : 0;
        if ($start === 0 || $now - $start >= $window) {
            if (!@unlink($path) || is_file($path)) {
                return false;
            }
        }
    }
    return true;
}

function qg_kontakt_rate_update($fp, int $now, int $limit, int $window): string
{
    $raw = stream_get_contents($fp);
    if ($raw === false) {
        return 'error';
    }
    $state = ['start' => $now, 'count' => 0];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['start'], $decoded['count'])) {
            return 'error';
        }
        $state = ['start' => (int)$decoded['start'], 'count' => (int)$decoded['count']];
    }
    if ($now - $state['start'] >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    $state['count']++;
    $payload = json_encode($state);
    if (!is_string($payload)) {
        return 'error';
    }
    if (!rewind($fp) || !ftruncate($fp, 0)) {
        return 'error';
    }
    $written = fwrite($fp, $payload);
    if ($written !== strlen($payload) || !fflush($fp)) {
        return 'error';
    }
    return $state['count'] <= $limit ? 'allow' : 'limit';
}

function qg_kontakt_rate_allow(string $dir, string $salt, string $ip, int $now, int $limit = 5, int $window = 3600): string
{
    if ($ip === '' || $salt === '' || $limit < 1 || $window < 1) {
        return 'error';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return 'error';
    }
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return 'error';
    }
    try {
        if (!qg_kontakt_rate_purge($dir, $now, $window)) {
            return 'error';
        }
        $file = $dir . '/' . hash('sha256', $salt . '|' . $ip) . '.json';
        $fp = @fopen($file, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return 'error';
        }
        $result = qg_kontakt_rate_update($fp, $now, $limit, $window);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function qg_kontakt_bind_token(array $body, array &$session, int $now): array
{
    if (empty($body['ok'])) {
        return $body;
    }
    $token = bin2hex(random_bytes(16));
    $session['csrf'] = $token;
    $session['issued'] = $now;
    $body['csrf'] = $token;
    return $body;
}

function qg_kontakt_handle(array $post, array $server, array $session, array $config, callable $mailer, int $now): array
{
    if (!qg_kontakt_config_ready($config)) {
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
    if (!is_string($post['website'] ?? '') || trim($post['website']) !== '') {
        return ['status' => 400, 'body' => ['ok' => false, 'errors' => ['Die Nachricht wurde nicht angenommen.']]];
    }
    $valid = qg_kontakt_validate($post);
    if (!$valid['ok']) {
        return ['status' => 400, 'body' => ['ok' => false, 'errors' => $valid['errors']]];
    }
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    $rate = qg_kontakt_rate_allow((string)$config['rate_dir'], (string)$config['rate_salt'], $ip, $now);
    if ($rate === 'error') {
        return ['status' => 503, 'body' => ['ok' => false, 'errors' => ['Der Versand ist vorübergehend nicht möglich.']]];
    }
    if ($rate !== 'allow') {
        return ['status' => 429, 'body' => ['ok' => false, 'errors' => ['Zu viele Nachrichten. Bitte später erneut versuchen.']]];
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
