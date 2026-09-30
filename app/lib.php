<?php
// Shared helpers: config, storage, pricing, mail, HTTP.
declare(strict_types=1);

const TEAM_EMAIL = 'info@byereviews.com';
const FROM_EMAIL = 'info@byereviews.com';
const FROM_NAME  = 'byereviews';
const SITE_URL   = 'https://byereviews.com';

const PRICE_RECENT = 90;   // review ≤ 4 weeks old
const PRICE_OLDER  = 125;  // review older than 4 weeks

const EU_COUNTRIES = ['Austria','Belgium','Bulgaria','Croatia','Cyprus','Czech Republic','Denmark','Estonia','Finland','France','Germany','Greece','Hungary','Ireland','Italy','Latvia','Lithuania','Luxembourg','Malta','Netherlands','Poland','Portugal','Romania','Slovakia','Slovenia','Spain','Sweden'];

/**
 * Server config lives outside the repo in /etc/byereviews/config.php (see deploy/config.example.php).
 * Keys: google_places_key, serpapi_key, stripe_secret_key, stripe_webhook_secret, admin_password_hash, data_dir, mock_google.
 */
function config(string $key, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        foreach ([getenv('BYEREVIEWS_CONFIG') ?: '', '/etc/byereviews/config.php', dirname(__DIR__) . '/config.local.php'] as $f) {
            if ($f !== '' && is_readable($f)) { $c = require $f; if (is_array($c)) { $cfg = $c; break; } }
        }
    }
    return $cfg[$key] ?? $default;
}

function data_dir(string $sub = ''): string {
    $base = rtrim((string)config('data_dir', dirname(__DIR__) . '/public/orders'), '/');
    $dir = $base . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

// ---------- JSON storage (one file per record, locked writes) ----------

function store_path(string $kind, string $id): string {
    return data_dir($kind) . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) . '.json';
}

function store_get(string $kind, string $id): ?array {
    $f = store_path($kind, $id);
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function store_put(string $kind, string $id, array $data): void {
    $f = store_path($kind, $id);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f);
}

/** Read-modify-write under an exclusive lock. */
function store_update(string $kind, string $id, callable $fn): ?array {
    $lock = fopen(store_path($kind, $id) . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $cur = store_get($kind, $id);
        $new = $fn($cur);
        if (is_array($new)) store_put($kind, $id, $new);
        return $new;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function store_list(string $kind): array {
    $out = [];
    foreach (glob(data_dir($kind) . '/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}

function customer_key(string $email): string {
    return hash('sha256', strtolower(trim($email)));
}

function get_customer(string $email): ?array {
    return store_get('customers', customer_key($email));
}

function new_order_id(): string {
    do { $id = 'BR-' . random_int(10000, 99999); } while (is_file(store_path('order', $id)));
    return $id;
}

function new_password(): string {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 12; $i++) $pw .= ($i === 4 || $i === 8) ? '-' : $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}

// ---------- pricing ----------

function currency_for(string $country): string {
    return in_array($country, EU_COUNTRIES, true) ? 'EUR' : 'USD';
}

function money($n, string $cur): string {
    return $cur === 'EUR' ? number_format((float)$n, 0, '.', ',') . ' €' : '$' . number_format((float)$n, 0, '.', ',');
}

function discount_rate(int $n): float {
    return $n >= 6 ? 0.15 : ($n >= 3 ? 0.10 : ($n === 2 ? 0.05 : 0.0));
}

function tier_price(string $tier): int {
    return $tier === 'older' ? PRICE_OLDER : PRICE_RECENT;
}

/** Totals for a list of reviews: ['n','recent','older','subtotal','rate','discount','total']. */
function totals(array $reviews): array {
    $recent = count(array_filter($reviews, fn($r) => ($r['tier'] ?? 'recent') !== 'older'));
    $older = count($reviews) - $recent;
    $sub = $recent * PRICE_RECENT + $older * PRICE_OLDER;
    $rate = discount_rate(count($reviews));
    $disc = (int)round($sub * $rate);
    return ['n' => count($reviews), 'recent' => $recent, 'older' => $older, 'subtotal' => $sub, 'rate' => $rate, 'discount' => $disc, 'total' => $sub - $disc];
}

function invoice(array $order): array {
    return totals(array_values(array_filter($order['reviews'], fn($r) => $r['status'] === 'removed')));
}

// ---------- misc ----------

function clean($v, int $max = 500): string {
    $v = is_scalar($v) ? (string)$v : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function is_email(string $e): bool {
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Simple sliding-window rate limit per IP and bucket. Returns false when exceeded. */
function rate_ok(string $bucket, int $max, int $window = 3600): bool {
    $f = data_dir('ratelimit') . '/' . hash('sha256', $bucket . '|' . client_ip()) . '.json';
    $now = time();
    $hits = is_file($f) ? array_filter((array)json_decode((string)file_get_contents($f), true), fn($t) => is_int($t) && $t > $now - $window) : [];
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    @file_put_contents($f, json_encode(array_values($hits)), LOCK_EX);
    return true;
}

function http_json(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array {
    if (!function_exists('curl_init')) {
        // fallback when php-curl isn't installed
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];
        $data = is_string($res) ? json_decode($res, true) : null;
        return ['code' => $code, 'data' => is_array($data) ? $data : null, 'error' => $res === false ? 'request_failed' : '', 'raw' => $res];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    return ['code' => $code, 'data' => is_array($data) ? $data : null, 'error' => $err, 'raw' => $res];
}

function log_event(string $msg): void {
    @file_put_contents(data_dir() . '/app.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ---------- mail ----------
// SMTP credentials: /etc/byereviews/smtp.php returning ['host','port','user','pass']. Without it PHP mail() is used.

/** Admin settings (removal partner, WhatsApp template, sender) stored next to the data. */
function app_settings(): array {
    $d = store_get('settings', 'app') ?? [];
    return $d + ['partnerNo' => '', 'sender' => TEAM_EMAIL,
        'template' => "Hi! New removal request – {order_id}\n\nCompany: {company}\nGoogle profile: {profile_link}\n\n{reviews}\n\nThanks!"];
}

function smtp_config(): ?array {
    foreach (['/etc/byereviews/smtp.php', dirname(__DIR__) . '/smtp-config.php'] as $f) {
        if (is_readable($f)) { $c = require $f; if (is_array($c) && !empty($c['host'])) return $c; }
    }
    return null;
}

function smtp_send(array $c, string $to, string $subject, string $body, string $headers): bool {
    $port = (int)($c['port'] ?? 465);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $en, $es, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function (string $line, string $expect) use ($fp, $read) { fwrite($fp, $line . "\r\n"); return strncmp($read(), $expect, 3) === 0; };
    $ok = strncmp($read(), '220', 3) === 0 && $cmd('EHLO byereviews.com', '250');
    if ($ok && $port !== 465) {
        $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO byereviews.com', '250');
    }
    $ok = $ok && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($c['user']), '334') && $cmd(base64_encode($c['pass']), '235')
        && $cmd('MAIL FROM:<' . FROM_EMAIL . '>', '250') && $cmd('RCPT TO:<' . $to . '>', '250') && $cmd('DATA', '354');
    if ($ok) {
        $msg = 'To: <' . $to . ">\r\nSubject: $subject\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@byereviews.com>\r\n"
            . $headers . "\r\n\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
        $msg = preg_replace('/^\./m', '..', $msg);
        $ok = $cmd($msg . "\r\n.", '250');
    }
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}

/** Plain-text mail, or multipart/alternative when $html is given. */
function send_mail(string $to, string $subject, string $body, string $replyTo = '', ?string $html = null): bool {
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    if ($replyTo === '') $replyTo = (string)(app_settings()['sender'] ?? TEAM_EMAIL) ?: TEAM_EMAIL;
    $head = [
        'From: ' . $enc(FROM_NAME) . ' <' . FROM_EMAIL . '>',
        'Reply-To: ' . str_replace(["\r", "\n"], ' ', $replyTo),
        'MIME-Version: 1.0',
    ];
    if ($html !== null) {
        $b = 'br_' . bin2hex(random_bytes(8));
        $head[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body))
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
    } else {
        $head[] = 'Content-Type: text/plain; charset=UTF-8';
        $head[] = 'Content-Transfer-Encoding: 8bit';
    }
    $headers = implode("\r\n", $head);
    if (config('mail_log_only')) { // local testing: log instead of sending, keep HTML for previewing
        log_event("MAIL to=$to subject=$subject");
        $base = data_dir('mails') . '/' . date('His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', $subject);
        $html !== null ? @file_put_contents("$base.html", $html) : @file_put_contents("$base.txt", "To: $to\n\n$body");
        return true;
    }
    $c = smtp_config();
    $ok = $c ? smtp_send($c, $to, $enc($subject), $body, $headers) : @mail($to, $enc($subject), $body, $headers, '-f' . FROM_EMAIL);
    if (!$ok) log_event("MAIL FAILED to=$to subject=$subject");
    return $ok;
}
