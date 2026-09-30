<?php
// JSON API used by the site. Reached via /order.php?a=<action> (the only PHP entry point nginx executes).
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/emails.php';
require_once __DIR__ . '/account.php';

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $error): void {
    json_out(['ok' => false, 'error' => $error], $code);
}

/** JSON body of a state-changing request. Requiring application/json blocks cross-site form posts. */
function json_body(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method_not_allowed');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail(415, 'json_required');
    $d = json_decode((string)file_get_contents('php://input', false, null, 0, 200000), true);
    if (!is_array($d)) fail(400, 'invalid_json');
    return $d;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('br_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function current_customer(): ?array {
    start_session();
    $email = $_SESSION['email'] ?? null;
    $cust = $email ? get_customer($email) : null;
    // password changes / "log out everywhere" bump the version and invalidate older sessions
    if ($cust && (int)($_SESSION['sv'] ?? 0) !== (int)($cust['sessionVersion'] ?? 0)) { $_SESSION = []; return null; }
    return $cust;
}

/** What the customer dashboard gets to see of an order. */
function order_view(array $o): array {
    $inv = invoice($o);
    $elapsed = (int)floor((time() - strtotime($o['createdAt'])) / 86400);
    $now = $o['business']['placeId'] ? place_details($o['business']['placeId']) : null;
    return [
        'id' => $o['id'],
        'createdAt' => $o['createdAt'],
        'currency' => $o['currency'],
        'business' => ['name' => $o['business']['name'], 'address' => $o['business']['address'],
            'ratingBefore' => $o['business']['rating'], 'ratingNow' => $now['rating'] ?? $o['business']['rating']],
        'reviews' => array_map(fn($r) => ['id' => $r['id'], 'author' => $r['author'], 'stars' => $r['stars'], 'days' => $r['days'] + $elapsed,
            'text' => $r['text'], 'link' => $r['link'], 'tier' => $r['tier'], 'status' => $r['status'], 'updatedAt' => $r['updatedAt'] ?? null], $o['reviews']),
        'messages' => $o['messages'],
        'timeline' => $o['timeline'] ?? [],
        'payment' => ['status' => $o['payment']['status'] ?? 'unpaid', 'paidAt' => $o['payment']['paidAt'] ?? null,
            'canPay' => $inv['total'] > 0 && ($o['payment']['status'] ?? 'unpaid') !== 'paid'],
        'invoice' => $inv,
    ];
}

function customer_orders(array $customer): array {
    $orders = [];
    foreach (array_reverse($customer['orders'] ?? []) as $id) if ($o = store_get('order', $id)) $orders[] = order_view($o);
    return $orders;
}

// ---------- actions ----------

function action_places(): void {
    $q = clean($_GET['q'] ?? '', 300);
    if (mb_strlen($q) < 2) fail(400, 'query_too_short');
    if (!rate_ok('places', 60)) fail(429, 'too_many_requests');
    $r = places_search($q);
    json_out($r, $r['ok'] ? 200 : 503);
}

function action_suggest(): void {
    $q = clean($_GET['q'] ?? '', 200);
    if (mb_strlen($q) < 3) json_out(['ok' => true, 'places' => []]);
    if (!rate_ok('suggest', 400)) fail(429, 'too_many_requests');
    $r = places_autocomplete($q, clean($_GET['session'] ?? '', 64));
    json_out($r, $r['ok'] ? 200 : 503);
}

function action_place(): void {
    if (!rate_ok('place', 120)) fail(429, 'too_many_requests');
    $p = place_details(clean($_GET['id'] ?? '', 200), clean($_GET['session'] ?? '', 64));
    $p ? json_out(['ok' => true, 'place' => $p]) : fail(404, 'not_found');
}

function action_reviews(): void {
    $id = clean($_GET['place'] ?? '', 200);
    if (!rate_ok('reviews', 40)) fail(429, 'too_many_requests');
    $r = place_reviews($id);
    json_out($r, $r['ok'] ? 200 : 503);
}

function action_check_email(): void {
    $email = strtolower(clean($_GET['email'] ?? '', 200));
    if (!is_email($email)) json_out(['ok' => true, 'exists' => false]);
    if (!rate_ok('check_email', 120)) fail(429, 'too_many_requests');
    json_out(['ok' => true, 'exists' => get_customer($email) !== null]);
}

function action_order(): void {
    $d = json_body();
    if (!rate_ok('order', 10)) fail(429, 'too_many_requests');
    $c = is_array($d['contact'] ?? null) ? $d['contact'] : [];
    $b = is_array($d['business'] ?? null) ? $d['business'] : [];
    $email = strtolower(clean($c['email'] ?? '', 200));
    $name = clean($c['name'] ?? '', 120);
    if ($name === '') fail(400, 'name_missing');
    if (!is_email($email)) fail(400, 'invalid_email');
    if (empty($d['agree'])) fail(400, 'terms_not_accepted');

    $country = clean($c['country'] ?? '', 80);
    $placeId = preg_match('/^[A-Za-z0-9_-]{10,}$/', (string)($b['placeId'] ?? '')) ? (string)$b['placeId'] : '';
    $place = $placeId ? place_details($placeId) : null;
    $known = [];
    if ($placeId && ($pr = place_reviews($placeId)) && $pr['ok']) foreach ($pr['reviews'] as $rv) $known[$rv['id']] = $rv;

    $reviews = [];
    foreach (array_slice(is_array($d['reviews'] ?? null) ? $d['reviews'] : [], 0, 60) as $r) {
        if (!is_array($r)) continue;
        if (($r['source'] ?? '') === 'profile') {
            $gid = clean($r['googleId'] ?? '', 300);
            $src = $known[$gid] ?? ['id' => $gid, 'name' => clean($r['author'] ?? '', 120), 'stars' => (int)($r['stars'] ?? 0), 'days' => (int)($r['days'] ?? 60), 'text' => clean($r['text'] ?? '', 4000), 'link' => ''];
            if (trim($src['text']) === '') continue; // star-only ratings can't be removed
            $reviews[] = ['id' => 'r' . (count($reviews) + 1), 'source' => 'profile', 'googleId' => $gid, 'author' => $src['name'], 'stars' => $src['stars'],
                'days' => $src['days'], 'text' => $src['text'], 'link' => $src['link'], 'tier' => $src['days'] <= 28 ? 'recent' : 'older', 'status' => 'submitted'];
        } else {
            $link = clean($r['link'] ?? '', 1000);
            $text = clean($r['text'] ?? '', 4000);
            if ($link === '' && mb_strlen($text) < 3) continue;
            $tier = ($r['tier'] ?? '') === 'older' ? 'older' : 'recent';
            $reviews[] = ['id' => 'r' . (count($reviews) + 1), 'source' => 'link', 'googleId' => '', 'author' => clean($r['author'] ?? '', 120), 'stars' => 0,
                'days' => $tier === 'older' ? 60 : 14, 'text' => $text, 'link' => $link, 'tier' => $tier, 'status' => 'submitted'];
        }
    }
    if (!$reviews) fail(400, 'no_reviews');

    $now = date('c');
    $order = [
        'id' => new_order_id(),
        'createdAt' => $now,
        'currency' => currency_for($country ?: ($place['country'] ?? '')),
        'customer' => ['email' => $email, 'name' => $name, 'company' => clean($c['company'] ?? '', 200), 'phone' => clean($c['phone'] ?? '', 60),
            'street' => clean($c['street'] ?? '', 200), 'city' => clean($c['city'] ?? '', 200), 'country' => $country],
        'business' => ['placeId' => $placeId, 'name' => $place['name'] ?? clean($b['name'] ?? '', 200), 'address' => $place['address'] ?? '',
            'mapsUrl' => $place['mapsUrl'] ?? clean($b['profileUrl'] ?? '', 1000), 'query' => clean($b['query'] ?? '', 500),
            'rating' => $place['rating'] ?? null, 'reviewCount' => $place['reviewCount'] ?? null],
        'reviews' => $reviews,
        'messages' => [['from' => 'team', 'text' => 'Hi ' . first_name($name) . ", thanks for your order! We're checking your reviews now. Message us here anytime.", 'at' => $now]],
        'timeline' => ['received' => $now],
        'payment' => ['status' => 'unpaid'],
        'payToken' => bin2hex(random_bytes(16)),
        'ip' => client_ip(),
    ];

    $password = null;
    $newAccount = false;
    store_update('customers', customer_key($email), function (?array $cust) use ($email, $name, $order, &$password, &$newAccount) {
        if (!$cust) {
            $newAccount = true;
            $password = new_password();
            $cust = ['email' => $email, 'name' => $name, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'), 'orders' => []];
        }
        $cust['orders'][] = $order['id'];
        return $cust;
    });
    store_put('order', $order['id'], $order);
    log_event("order {$order['id']} from $email (" . count($reviews) . ' reviews)');

    mail_team_new_order($order);
    mail_order_confirmation($order, $password);
    json_out(['ok' => true, 'orderId' => $order['id'], 'newAccount' => $newAccount, 'currency' => $order['currency']]);
}

function action_login(): void {
    $d = json_body();
    if (!rate_ok('login', 20, 900)) fail(429, 'too_many_requests');
    $email = strtolower(clean($d['email'] ?? '', 200));
    $cust = is_email($email) ? get_customer($email) : null;
    if (!$cust || !password_verify((string)($d['password'] ?? ''), $cust['passwordHash'])) fail(401, 'invalid_login');
    login_customer($cust);
    json_out(['ok' => true, 'customer' => ['email' => $cust['email'], 'name' => $cust['name']], 'account' => customer_profile($cust), 'orders' => customer_orders($cust)]);
}

function action_logout(): void {
    json_body();
    start_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

function action_me(): void {
    $cust = current_customer();
    if (!$cust) json_out(['ok' => false, 'error' => 'not_logged_in']);
    json_out(['ok' => true, 'customer' => ['email' => $cust['email'], 'name' => $cust['name']], 'account' => customer_profile($cust), 'orders' => customer_orders($cust)]);
}

function action_message(): void {
    $d = json_body();
    $cust = current_customer();
    if (!$cust) fail(401, 'not_logged_in');
    $id = clean($d['order'] ?? '', 20);
    $text = clean($d['text'] ?? '', 4000);
    if ($text === '' || !in_array($id, $cust['orders'] ?? [], true)) fail(400, 'bad_request');
    if (!rate_ok('message', 60)) fail(429, 'too_many_requests');
    $o = store_update('order', $id, function (?array $o) use ($text) {
        if (!$o) return null;
        $o['messages'][] = ['from' => 'me', 'text' => $text, 'at' => date('c')];
        return $o;
    });
    if ($o) mail_customer_message($o, $text);
    json_out(['ok' => true, 'orders' => customer_orders($cust)]);
}

/** Redirects to Stripe Checkout (or the manual payment link) for the unpaid removed reviews. */
function action_pay(): void {
    $id = clean($_GET['order'] ?? '', 20);
    $o = store_get('order', $id);
    // allowed for the logged-in owner, or with the secret token from the payment email
    $cust = current_customer();
    $owner = $o && $cust && in_array($o['id'], $cust['orders'] ?? [], true);
    $tokenOk = $o && !empty($o['payToken']) && hash_equals($o['payToken'], (string)($_GET['t'] ?? ''));
    if (!$o || (!$owner && !$tokenOk)) { header('Location: /login/'); exit; }
    if (($o['payment']['status'] ?? '') === 'paid' || invoice($o)['total'] <= 0) { header('Location: /dashboard/'); exit; }
    $url = (($o['payment']['status'] ?? '') === 'link_sent' && !empty($o['payment']['link'])) ? $o['payment']['link'] : (stripe_checkout_url($o) ?? ($o['payment']['manualLink'] ?? null));
    if (!$url) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Online payment isn't available right now. We'll email you a payment link – or write to " . TEAM_EMAIL . '.';
        exit;
    }
    header('Location: ' . $url);
    exit;
}

function action_stripe_webhook(): void {
    $payload = (string)file_get_contents('php://input');
    $event = stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    if (!$event) fail(400, 'bad_signature');
    if (($event['type'] ?? '') === 'checkout.session.completed' && ($event['data']['object']['payment_status'] ?? '') === 'paid') {
        $obj = $event['data']['object'];
        $id = (string)($obj['metadata']['order_id'] ?? '');
        if ($id === '' && !empty($obj['payment_link'])) foreach (store_list('order') as $x) if (($x['payment']['linkId'] ?? '') === $obj['payment_link']) { $id = $x['id']; break; }
        $o = store_update('order', $id, function (?array $o) use ($event) {
            if (!$o) return null;
            $o['payment'] = array_merge($o['payment'], ['status' => 'paid', 'paidAt' => date('c'), 'amount' => ($event['data']['object']['amount_total'] ?? 0) / 100, 'stripeSession' => $event['data']['object']['id'] ?? '']);
            $o['timeline']['paid'] = date('c');
            $o['payment']['via'] = 'Stripe';
            return $o;
        });
        if ($o) { log_event("order $id paid via stripe"); mail_paid($o); }
    }
    json_out(['ok' => true]);
}
