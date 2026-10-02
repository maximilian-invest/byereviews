<?php
// Stripe Checkout for removed reviews + webhook verification.
declare(strict_types=1);

/** Stripe keys: server config (/etc/byereviews/config.php) first, otherwise the ones saved in Admin → Settings. */
function stripe_key(): string {
    $k = (string)config('stripe_secret_key', '');
    return $k !== '' ? $k : (string)((store_get('settings', 'stripe') ?? [])['secretKey'] ?? '');
}
function stripe_webhook_key(): string {
    $k = (string)config('stripe_webhook_secret', '');
    return $k !== '' ? $k : (string)((store_get('settings', 'stripe') ?? [])['webhookSecret'] ?? '');
}

function stripe_request(string $method, string $path, array $params = [], string $idempotencyKey = ''): array {
    $key = stripe_key();
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/x-www-form-urlencoded'];
    if ($idempotencyKey !== '') $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    if ($method === 'GET' && $params) { $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($params); $params = []; }
    return http_json($method, 'https://api.stripe.com/v1/' . $path, $headers, $params ? http_build_query($params) : null, 30);
}

const STRIPE_COUNTRY_CODES = ['United States' => 'US', 'United Kingdom' => 'GB', 'Canada' => 'CA', 'Australia' => 'AU', 'Ireland' => 'IE',
    'Austria' => 'AT', 'Germany' => 'DE', 'Switzerland' => 'CH', 'France' => 'FR', 'Italy' => 'IT', 'Spain' => 'ES', 'Netherlands' => 'NL'];

/** Find (by email) or create the Stripe customer for an order and keep name/address current. Returns the customer id or ''. */
function stripe_customer(array $order): string {
    $c = $order['customer'];
    $params = ['email' => $c['email'], 'name' => $c['company'] !== '' ? $c['company'] : $c['name'],
        'metadata[contact_name]' => $c['name'], 'preferred_locales[0]' => 'en'];
    if ($c['company'] !== '') $params['description'] = $c['name'];
    $cc = STRIPE_COUNTRY_CODES[$c['country'] ?? ''] ?? '';
    if ($cc !== '') {
        $params['address[country]'] = $cc;
        if (($c['street'] ?? '') !== '') $params['address[line1]'] = $c['street'];
        if (($c['city'] ?? '') !== '') $params['address[city]'] = $c['city'];
    }
    $found = stripe_request('GET', 'customers', ['email' => $c['email'], 'limit' => 1]);
    $id = (string)($found['data']['data'][0]['id'] ?? '');
    $r = $id !== '' ? stripe_request('POST', 'customers/' . rawurlencode($id), $params) : stripe_request('POST', 'customers', $params, 'cust-' . sha1(strtolower($c['email'])));
    if ($r['code'] !== 200 || empty($r['data']['id'])) { log_event('stripe customer failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return ''; }
    return (string)$r['data']['id'];
}

/**
 * Stripe invoice (Invoicing API) for everything removed in the order: one line per price tier plus a negative
 * line for the volume discount, so the total matches invoice() exactly. Finalized right away so it gets a number,
 * a PDF and a hosted payment page (card, Apple/Google Pay, bank transfer – whatever is enabled in the Dashboard).
 * Optional Stripe Tax ('stripe_tax' => true in the server config; needs Stripe Tax set up in the Dashboard).
 * Returns ['url' => hosted_invoice_url, 'id' => in_…, 'number' => …, 'pdf' => …] or null.
 */
function stripe_invoice(array $order): ?array {
    if (config('mock_stripe')) return ['url' => 'https://invoice.stripe.com/i/test_' . strtolower($order['id']), 'id' => 'in_test_' . $order['id'], 'number' => 'TEST-' . $order['id'], 'pdf' => ''];
    if (stripe_key() === '') return null;
    $inv = invoice($order);
    if ($inv['total'] <= 0) return null;
    $customer = stripe_customer($order);
    if ($customer === '') return null;
    $cur = strtolower($order['currency']);
    // same order + same billable reviews → same idempotency key (safe to retry)
    $billable = implode(',', array_map(fn($r) => $r['id'], array_filter($order['reviews'], fn($r) => $r['status'] === 'removed')));
    $key = 'inv-' . $order['id'] . '-' . substr(sha1($billable . '|' . $inv['total'] . '|' . ($order['payment']['invoiceRound'] ?? 0)), 0, 16);
    $params = [
        'customer' => $customer, 'currency' => $cur, 'collection_method' => 'send_invoice', 'days_until_due' => 14,
        'auto_advance' => 'false', 'pending_invoice_items_behavior' => 'exclude',
        'description' => 'Google review removal – order ' . $order['id'] . ' (' . ($order['business']['name'] ?: $order['customer']['company']) . ')',
        'footer' => 'Thank you! You only pay for reviews that have been removed. Questions: ' . TEAM_EMAIL,
        'metadata[order_id]' => $order['id'],
    ];
    if (config('stripe_tax')) $params['automatic_tax[enabled]'] = 'true';
    $r = stripe_request('POST', 'invoices', $params, $key);
    if ($r['code'] !== 200 || empty($r['data']['id'])) { log_event('stripe invoice failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return null; }
    $inId = (string)$r['data']['id'];
    if (($r['data']['status'] ?? '') === 'draft') {   // fresh invoice (an idempotent replay returns the earlier one)
        $lines = [];
        if ($inv['recent']) $lines[] = ['Review removal – posted within 4 weeks', PRICE_RECENT, $inv['recent']];
        if ($inv['older']) $lines[] = ['Review removal – older than 4 weeks', PRICE_OLDER, $inv['older']];
        if ($inv['discount'] > 0) $lines[] = ['Volume discount ' . round($inv['rate'] * 100) . '%', -$inv['discount'], 1];
        foreach ($lines as $i => [$desc, $unit, $qty]) {
            $li = stripe_request('POST', 'invoiceitems', ['customer' => $customer, 'invoice' => $inId, 'currency' => $cur,
                'description' => $qty > 1 ? "$qty × $desc" : $desc, 'amount' => (int)round($unit * $qty * 100), 'metadata[order_id]' => $order['id']], "$key-line$i");
            if ($li['code'] !== 200) { log_event('stripe invoice item failed ' . $li['code'] . ' ' . substr((string)$li['raw'], 0, 300)); return null; }
        }
        $r = stripe_request('POST', 'invoices/' . rawurlencode($inId) . '/finalize', ['auto_advance' => 'false'], "$key-finalize");
        if ($r['code'] !== 200) { log_event('stripe invoice finalize failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return null; }
    }
    $d = $r['data'];
    if (empty($d['hosted_invoice_url'])) { $g = stripe_request('GET', 'invoices/' . rawurlencode($inId)); $d = $g['data'] ?? $d; }
    if (empty($d['hosted_invoice_url'])) return null;
    return ['url' => $d['hosted_invoice_url'], 'id' => $inId, 'number' => (string)($d['number'] ?? ''), 'pdf' => (string)($d['invoice_pdf'] ?? '')];
}

/** Voids an open invoice (the billable reviews changed) or deactivates an old Payment Link. */
function stripe_cancel_payment(string $id): void {
    if ($id === '' || stripe_key() === '' || config('mock_stripe')) return;
    if (str_starts_with($id, 'in_')) stripe_request('POST', 'invoices/' . rawurlencode($id) . '/void');
    elseif (str_starts_with($id, 'plink_')) stripe_request('POST', 'payment_links/' . rawurlencode($id), ['active' => 'false']);
}

/**
 * Checkout URL for everything removed and unpaid in the order. Line items per tier, volume discount
 * applied to the unit price of each item so the total matches the invoice.
 * Returns null when Stripe isn't configured (the admin can then set a manual payment link).
 */
function stripe_checkout_url(array $order): ?string {
    if (stripe_key() === '') return null;
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
    $secret = stripe_webhook_key();
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
    if (stripe_key() === '') return null;
    $inv = invoice($order);
    if ($inv['total'] <= 0) return null;
    $name = 'Google review removal – order ' . $order['id'] . ' (' . $inv['n'] . ' removed' . ($inv['rate'] > 0 ? ', ' . round($inv['rate'] * 100) . '% volume discount' : '') . ')';
    $price = stripe_request('POST', 'prices', ['currency' => strtolower($order['currency']), 'unit_amount' => (int)round($inv['total'] * 100), 'product_data[name]' => $name]);
    if ($price['code'] !== 200 || empty($price['data']['id'])) { log_event('stripe price failed ' . $price['code'] . ' ' . substr((string)$price['raw'], 0, 300)); return null; }
    $link = stripe_request('POST', 'payment_links', [
        'line_items[0][price]' => $price['data']['id'], 'line_items[0][quantity]' => 1,
        'metadata[order_id]' => $order['id'], 'payment_intent_data[metadata][order_id]' => $order['id'],
        'after_completion[type]' => 'redirect', 'after_completion[redirect][url]' => SITE_URL . '/dashboard/?paid=1',
    ] + (config('stripe_invoice_pdf', true) ? [   // Stripe creates a numbered invoice + PDF after payment
        'invoice_creation[enabled]' => 'true',
        'invoice_creation[invoice_data][description]' => 'Google review removal – order ' . $order['id'] . ' (' . ($order['business']['name'] ?: $order['customer']['company']) . ')',
        'invoice_creation[invoice_data][footer]' => 'Thank you! You only pay for reviews that have been removed. Questions: ' . TEAM_EMAIL,
        'invoice_creation[invoice_data][metadata][order_id]' => $order['id'],
    ] : []) + (config('stripe_tax') ? ['automatic_tax[enabled]' => 'true', 'billing_address_collection' => 'required'] : []));
    if ($link['code'] !== 200 || empty($link['data']['url'])) { log_event('stripe payment link failed ' . $link['code'] . ' ' . substr((string)$link['raw'], 0, 300)); return null; }
    return ['url' => $link['data']['url'], 'id' => $link['data']['id']];
}

function stripe_deactivate_link(string $id): void {
    if ($id !== '' && stripe_key() !== '') stripe_request('POST', 'payment_links/' . rawurlencode($id), ['active' => 'false']);
}

/** "Connected · acct_…" for the settings page. */
function stripe_status(): array {
    if (stripe_key() === '') return ['connected' => false, 'account' => '', 'webhook' => stripe_webhook_key() !== ''];
    $ck = 'stripe_account:' . hash('sha256', stripe_key());   // a new key never sees the old key's cached status
    if ($c = cache_get($ck, 3600)) return $c + ['webhook' => stripe_webhook_key() !== ''];
    $r = stripe_request('GET', 'account');
    $out = ['connected' => $r['code'] === 200, 'account' => (string)($r['data']['id'] ?? '')];
    if (!$out['connected']) {   // restricted keys may not read the account – Payment Links access is what matters
        $t = stripe_request('GET', 'payment_links', ['limit' => 1]);
        $out = ['connected' => $t['code'] === 200, 'account' => $t['code'] === 200 ? substr(stripe_key(), 0, 8) . '…' . substr(stripe_key(), -4) : ''];
    }
    if ($out['connected']) cache_put($ck, $out);
    return $out + ['webhook' => stripe_webhook_key() !== ''];
}
