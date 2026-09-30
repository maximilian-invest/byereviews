<?php
// Stripe Checkout for removed reviews + webhook verification.
declare(strict_types=1);

function stripe_request(string $method, string $path, array $params = []): array {
    $key = (string)config('stripe_secret_key', '');
    return http_json($method, 'https://api.stripe.com/v1/' . $path,
        ['Authorization: Bearer ' . $key, 'Content-Type: application/x-www-form-urlencoded'],
        $params ? http_build_query($params) : null, 30);
}

/**
 * Checkout URL for everything removed and unpaid in the order. Line items per tier, volume discount
 * applied to the unit price of each item so the total matches the invoice.
 * Returns null when Stripe isn't configured (the admin can then set a manual payment link).
 */
function stripe_checkout_url(array $order): ?string {
    if ((string)config('stripe_secret_key', '') === '') return null;
    $inv = invoice($order);
    if ($inv['total'] <= 0) return null;
    $cur = strtolower($order['currency']);
    $factor = 1 - $inv['rate'];
    $lines = [];
    if ($inv['recent']) $lines[] = ['Review removal (posted within 4 weeks)', PRICE_RECENT, $inv['recent']];
    if ($inv['older']) $lines[] = ['Review removal (older than 4 weeks)', PRICE_OLDER, $inv['older']];
    $params = [
        'mode' => 'payment',
        'customer_email' => $order['customer']['email'],
        'client_reference_id' => $order['id'],
        'metadata[order_id]' => $order['id'],
        'payment_intent_data[metadata][order_id]' => $order['id'],
        'success_url' => SITE_URL . '/dashboard/?paid=1',
        'cancel_url' => SITE_URL . '/dashboard/',
    ];
    foreach ($lines as $i => [$name, $price, $qty]) {
        $params["line_items[$i][price_data][currency]"] = $cur;
        $params["line_items[$i][price_data][product_data][name]"] = $name . ($inv['rate'] > 0 ? ' – ' . round($inv['rate'] * 100) . '% volume discount' : '');
        $params["line_items[$i][price_data][unit_amount]"] = (int)round($price * $factor * 100);
        $params["line_items[$i][quantity]"] = $qty;
    }
    $r = stripe_request('POST', 'checkout/sessions', $params);
    if ($r['code'] !== 200 || empty($r['data']['url'])) { log_event('stripe checkout failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return null; }
    return $r['data']['url'];
}

/** Verifies the Stripe-Signature header. Returns the decoded event or null. */
function stripe_verify_webhook(string $payload, string $sigHeader): ?array {
    $secret = (string)config('stripe_webhook_secret', '');
    if ($secret === '') return null;
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) { [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, ''); $parts[$k][] = $v; }
    $t = (int)($parts['t'][0] ?? 0);
    if (!$t || abs(time() - $t) > 600) return null;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) if (hash_equals($expected, $sig)) return json_decode($payload, true) ?: null;
    return null;
}

/**
 * Stripe Payment Link for the current invoice (removed reviews, volume discount applied).
 * Returns ['url' => …, 'id' => …] or null when Stripe isn't configured / the call failed.
 */
function stripe_payment_link(array $order): ?array {
    if (config('mock_stripe')) return ['url' => 'https://buy.stripe.com/test_' . strtolower($order['id']), 'id' => 'plink_test_' . $order['id']];
    if ((string)config('stripe_secret_key', '') === '') return null;
    $inv = invoice($order);
    if ($inv['total'] <= 0) return null;
    $name = 'Google review removal – order ' . $order['id'] . ' (' . $inv['n'] . ' removed' . ($inv['rate'] > 0 ? ', ' . round($inv['rate'] * 100) . '% volume discount' : '') . ')';
    $price = stripe_request('POST', 'prices', ['currency' => strtolower($order['currency']), 'unit_amount' => (int)round($inv['total'] * 100), 'product_data[name]' => $name]);
    if ($price['code'] !== 200 || empty($price['data']['id'])) { log_event('stripe price failed ' . $price['code'] . ' ' . substr((string)$price['raw'], 0, 300)); return null; }
    $link = stripe_request('POST', 'payment_links', [
        'line_items[0][price]' => $price['data']['id'], 'line_items[0][quantity]' => 1,
        'metadata[order_id]' => $order['id'], 'payment_intent_data[metadata][order_id]' => $order['id'],
        'after_completion[type]' => 'redirect', 'after_completion[redirect][url]' => SITE_URL . '/dashboard/?paid=1',
    ]);
    if ($link['code'] !== 200 || empty($link['data']['url'])) { log_event('stripe payment link failed ' . $link['code'] . ' ' . substr((string)$link['raw'], 0, 300)); return null; }
    return ['url' => $link['data']['url'], 'id' => $link['data']['id']];
}

function stripe_deactivate_link(string $id): void {
    if ($id !== '' && (string)config('stripe_secret_key', '') !== '') stripe_request('POST', 'payment_links/' . rawurlencode($id), ['active' => 'false']);
}

/** "Connected · acct_…" for the settings page. */
function stripe_status(): array {
    if ((string)config('stripe_secret_key', '') === '') return ['connected' => false, 'account' => ''];
    if ($c = cache_get('stripe_account', 3600)) return $c;
    $r = stripe_request('GET', 'account');
    $out = ['connected' => $r['code'] === 200, 'account' => (string)($r['data']['id'] ?? '')];
    cache_put('stripe_account', $out);
    return $out;
}
