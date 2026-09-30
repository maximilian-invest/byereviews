<?php
// Receives an order from the website, stores it and emails it to the team
// plus a confirmation to the customer.

declare(strict_types=1);

const TEAM_EMAIL = 'info@byereviews.com';
const FROM_EMAIL = 'info@byereviews.com';
const FROM_NAME  = 'byereviews';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function clean($v, int $max = 500): string {
    $v = is_scalar($v) ? (string)$v : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function header_safe(string $v): string {
    return str_replace(["\r", "\n"], ' ', $v);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed');

$raw = file_get_contents('php://input', false, null, 0, 100000);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) fail(400, 'Invalid JSON');

$contact = is_array($data['contact'] ?? null) ? $data['contact'] : [];
$business = is_array($data['business'] ?? null) ? $data['business'] : [];
$pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : [];
$reviews = is_array($data['reviews'] ?? null) ? array_slice($data['reviews'], 0, 50) : [];

$orderId = clean($data['orderId'] ?? '', 20);
$name    = clean($contact['name'] ?? '', 120);
$email   = clean($contact['email'] ?? '', 200);

if (!preg_match('/^BR-\d{5}$/', $orderId)) fail(400, 'Invalid order id');
if ($name === '') fail(400, 'Name missing');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Invalid email');
if (!$reviews) fail(400, 'No reviews');

// Simple per-IP rate limit: max 10 orders per hour.
$dataDir = __DIR__ . '/orders';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
$ipKey = $dataDir . '/.rl_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$hits = array_filter(
    is_file($ipKey) ? (array)json_decode((string)file_get_contents($ipKey), true) : [],
    fn($t) => is_int($t) && $t > time() - 3600
);
if (count($hits) >= 10) fail(429, 'Too many requests');
$hits[] = time();
@file_put_contents($ipKey, json_encode(array_values($hits)));

$currency = clean($data['currency'] ?? '', 3) === 'EUR' ? 'EUR' : 'USD';
$money = fn($n) => $currency === 'EUR'
    ? number_format((float)$n, 0, '.', ',') . ' €'
    : '$' . number_format((float)$n, 0, '.', ',');

$lines = [];
foreach ($reviews as $i => $r) {
    if (!is_array($r)) continue;
    $age = clean($r['age'] ?? '', 10) === 'older' ? 'older than 4 weeks' : 'last 4 weeks';
    $parts = [($i + 1) . '. ' . (clean($r['author'] ?? '', 120) ?: 'Unknown author') . " ($age)"];
    if (!empty($r['stars']))  $parts[] = '   Stars: ' . (int)$r['stars'];
    if (!empty($r['posted'])) $parts[] = '   Posted: ' . clean($r['posted'], 40);
    if (!empty($r['link']))   $parts[] = '   Link: ' . clean($r['link'], 1000);
    if (!empty($r['text']))   $parts[] = '   Text: ' . clean($r['text'], 2000);
    $lines[] = implode("\n", $parts);
}
$reviewText = implode("\n\n", $lines);

$pricingText = sprintf(
    "Recent reviews (≤ 4 weeks): %d\nOlder reviews: %d\nSubtotal: %s\nVolume discount: %d%% (– %s)\nEstimated total if all removed: %s",
    (int)($pricing['nRecent'] ?? 0), (int)($pricing['nOlder'] ?? 0),
    $money($pricing['subtotal'] ?? 0), (int)($pricing['discountPct'] ?? 0),
    $money($pricing['discount'] ?? 0), $money($pricing['total'] ?? 0)
);

$contactText = implode("\n", array_filter([
    'Name: ' . $name,
    'Email: ' . $email,
    ($v = clean($contact['company'] ?? '', 200)) ? 'Company: ' . $v : '',
    ($v = clean($contact['phone'] ?? '', 60)) ? 'Phone: ' . $v : '',
    ($v = clean($contact['street'] ?? '', 200)) ? 'Street: ' . $v : '',
    ($v = clean($contact['city'] ?? '', 200)) ? 'City: ' . $v : '',
    'Country: ' . clean($contact['country'] ?? '', 80),
]));

$businessText = implode("\n", array_filter([
    ($v = clean($business['name'] ?? '', 200)) ? 'Business: ' . $v : '',
    ($v = clean($business['query'] ?? '', 500)) ? 'Search / link entered: ' . $v : '',
    ($v = clean($business['profileUrl'] ?? '', 1000)) ? 'Profile URL: ' . $v : '',
    !empty($business['manual']) ? 'Business entered manually (no profile lookup)' : '',
]));

$record = [
    'orderId'  => $orderId,
    'received' => date('c'),
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    'currency' => $currency,
    'contact'  => $contact,
    'business' => $business,
    'reviews'  => $reviews,
    'pricing'  => $pricing,
];
@file_put_contents($dataDir . '/' . $orderId . '_' . date('Ymd-His') . '.json',
    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$encSubject = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
$headers = fn(string $replyTo) => implode("\r\n", [
    'From: ' . $encSubject(FROM_NAME) . ' <' . FROM_EMAIL . '>',
    'Reply-To: ' . header_safe($replyTo),
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

// Team notification
$teamBody = "New order $orderId\n\n"
    . "— CONTACT —\n$contactText\n\n"
    . ($businessText ? "— BUSINESS —\n$businessText\n\n" : '')
    . "— REVIEWS —\n$reviewText\n\n"
    . "— PRICING —\n$pricingText\n";
$teamOk = @mail(TEAM_EMAIL, $encSubject("New order $orderId – $name"), $teamBody,
    $headers($email), '-f' . FROM_EMAIL);

// Customer confirmation
$first = explode(' ', $name)[0];
$password = clean($data['portalPassword'] ?? '', 20);
$customerBody = "Hi $first,\n\n"
    . "thank you for your order $orderId. We have received it and will start reviewing your case shortly. "
    . "We'll email you at every step.\n\n"
    . "Nothing is charged today – you only pay for reviews that are actually removed.\n\n"
    . "— YOUR REVIEWS —\n$reviewText\n\n"
    . "— PRICING —\n$pricingText\n\n"
    . ($password !== '' ? "— DASHBOARD LOGIN —\nEmail: $email\nPassword: $password\nhttps://byereviews.com/\n\n" : '')
    . "Questions? Just reply to this email or write to " . TEAM_EMAIL . ".\n\n"
    . "Best regards\nThe byereviews team\nhttps://byereviews.com\n";
$customerOk = @mail($email, $encSubject("Your byereviews order $orderId"), $customerBody,
    $headers(TEAM_EMAIL), '-f' . FROM_EMAIL);

echo json_encode(['ok' => $teamOk, 'confirmationSent' => $customerOk]);
