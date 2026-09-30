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
