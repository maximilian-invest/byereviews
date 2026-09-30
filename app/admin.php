<?php
// JSON API for the admin panel (public/admin/, built from design/admin.dc.html).
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/inbox.php';

const NOTIFY_DELAY = 60; // seconds: status changes within this window go out as one email

function admin_required(): void {
    start_session();
    if (empty($_SESSION['admin'])) fail(401, 'not_authed');
}

function ms(?string $iso): ?int {
    return $iso ? strtotime($iso) * 1000 : null;
}

/** Order in the shape the admin design works with. */
function admin_order_view(array $o): array {
    $c = $o['customer']; $b = $o['business']; $p = $o['payment'] ?? [];
    // every review needs a link for the WhatsApp hand-off: review link → Google profile → Maps search
    $profile = $b['mapsUrl'] ?: ($b['placeId'] ? 'https://www.google.com/maps/place/?q=place_id:' . $b['placeId']
        : ($b['name'] ? 'https://www.google.com/maps/search/' . rawurlencode(trim($b['name'] . ' ' . $b['address'])) : ''));
    $status = ($p['status'] ?? 'unpaid') === 'paid' ? 'paid' : ((($p['status'] ?? '') === 'link_sent') ? 'link_sent' : 'unpaid');
    return [
        'id' => $o['id'], 'date' => ms($o['createdAt']), 'currency' => $o['currency'],
        'cancelled' => ($o['status'] ?? '') === 'cancelled', 'cancelledAt' => ms($o['cancelledAt'] ?? null), 'cancelledBy' => $o['cancelledBy'] ?? '', 'cancelReason' => $o['cancelReason'] ?? '',
        'cust' => ['name' => $c['name'], 'company' => $c['company'] ?: $b['name'], 'email' => $c['email'], 'phone' => $c['phone'],
            'address' => trim(implode(', ', array_filter([$c['street'], $c['city']]))), 'country' => $c['country']],
        'biz' => ['name' => $b['name'] ?: ($c['company'] ?: '—'), 'address' => $b['address'], 'profile' => $profile,
            'rating' => $b['rating'], 'count' => $b['reviewCount']],
        'reviews' => array_map(fn($r) => ['id' => $r['id'], 'author' => $r['author'] ?: 'Review', 'stars' => (int)$r['stars'], 'age' => $r['tier'] === 'older' ? 'older' : 'recent',
            'text' => (string)$r['text'], 'status' => $r['status'], 'link' => $r['link'] ?: $profile, 'sentAt' => ms($r['sentAt'] ?? null), 'updatedAt' => ms($r['updatedAt'] ?? null)], $o['reviews']),
        'payment' => ['status' => $status, 'link' => $p['link'] ?? '', 'linkSentAt' => ms($p['linkSentAt'] ?? null), 'paidAt' => ms($p['paidAt'] ?? null), 'via' => $p['via'] ?? '', 'amount' => $p['amount'] ?? null],
        'msgs' => array_map(fn($m) => ['from' => $m['from'] === 'team' ? 'team' : 'cust', 'text' => $m['text'], 'at' => ms($m['at'])], $o['messages']),
    ];
}

function admin_orders_payload(): array {
    $orders = store_list('order');
    usort($orders, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return array_map('admin_order_view', $orders);
}

/** Queue a status email for these reviews; sent once the order has been quiet for NOTIFY_DELAY. */
function queue_notification(string $orderId, array $reviewIds): void {
    store_update('notify', $orderId, function (?array $n) use ($reviewIds) {
        $n = $n ?? ['ids' => [], 'since' => time()];
        $n['ids'] = array_values(array_unique(array_merge($n['ids'], $reviewIds)));
        $n['last'] = time();
        return $n;
    });
}

/** Sends queued status emails whose last change is older than NOTIFY_DELAY. Called on every request (cheap glob). */
function flush_notifications(bool $force = false): void {
    foreach (glob(data_dir('notify') . '/*.json') ?: [] as $f) {
        $n = json_decode((string)file_get_contents($f), true);
        if (!is_array($n) || (!$force && ($n['last'] ?? 0) > time() - NOTIFY_DELAY)) continue;
        @unlink($f);
        $o = store_get('order', basename($f, '.json'));
        if (!$o) continue;
        $changed = array_values(array_filter($o['reviews'], fn($r) => in_array($r['id'], $n['ids'], true)));
        if ($changed) mail_status_update($o, $changed);
    }
}

// ---------- actions ----------

function action_admin_page(): void {
    header('Location: /admin/');
    exit;
}

function action_admin_me(): void {
    start_session();
    json_out(['ok' => true, 'authed' => !empty($_SESSION['admin']), 'email' => $_SESSION['admin_email'] ?? '', 'configured' => (string)config('admin_password_hash', '') !== '']);
}

function action_admin_login(): void {
    $d = json_body();
    if (!rate_ok('admin_login', 10, 900)) fail(429, 'too_many_requests');
    $hash = (string)config('admin_password_hash', '');
    if ($hash === '') fail(503, 'not_configured');
    $email = strtolower(clean($d['email'] ?? '', 200));
    $want = strtolower((string)config('admin_email', ''));
    if (($want !== '' && $email !== $want) || !password_verify((string)($d['password'] ?? ''), $hash)) fail(401, 'invalid_login');
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_email'] = $email;
    json_out(['ok' => true, 'email' => $email]);
}

function action_admin_logout(): void {
    json_body();
    start_session();
    unset($_SESSION['admin'], $_SESSION['admin_email']);
    json_out(['ok' => true]);
}

function action_admin_orders(): void {
    admin_required();
    $s = app_settings();
    json_out(['ok' => true, 'orders' => admin_orders_payload(),
        'settings' => ['partnerNo' => $s['partnerNo'], 'template' => $s['template'], 'sender' => $s['sender'], 'stripe' => stripe_status()]]);
}

function admin_order_update(string $id, callable $fn): array {
    $o = store_update('order', $id, function (?array $o) use ($fn) { return $o ? $fn($o) : null; });
    if (!$o) fail(404, 'order_not_found');
    return $o;
}

/** Sets one review's status (segment control / "Mark as removed"). */
function action_admin_status(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20); $rid = clean($d['review'] ?? '', 20); $st = (string)($d['status'] ?? '');
    if (!in_array($st, ['submitted', 'in_progress', 'removed', 'not_eligible'], true)) fail(400, 'bad_status');
    $oldLink = '';
    $o = admin_order_update($id, function (array $o) use ($rid, $st, &$oldLink) {
        $now = date('c');
        foreach ($o['reviews'] as &$r) if ($r['id'] === $rid && $r['status'] !== $st) {
            $wasRemoved = $r['status'] === 'removed';
            $r['status'] = $st; $r['updatedAt'] = $now;
            if ($st === 'in_progress' && empty($o['timeline']['review'])) $o['timeline']['review'] = $now;
            if ($st === 'removed' && empty($o['timeline']['removed'])) $o['timeline']['removed'] = $now;
            // the invoice changed: an open payment link no longer matches → back to unpaid, new link needed
            if (($o['payment']['status'] ?? '') === 'link_sent' && ($wasRemoved || $st === 'removed')) {
                $oldLink = (string)($o['payment']['linkId'] ?? '');
                $o['payment'] = ['status' => 'unpaid', 'manualLink' => $o['payment']['manualLink'] ?? ''];
            }
            if (($o['payment']['status'] ?? '') === 'paid' && $st === 'removed') { $o['payment']['status'] = 'unpaid'; $o['payment']['note'] = 'Re-opened: new removal after an earlier payment'; }
        }
        unset($r);
        return $o;
    });
    if ($oldLink !== '') stripe_deactivate_link($oldLink);
    queue_notification($id, [$rid]);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

/** Reviews forwarded to the removal partner via WhatsApp → In progress + sentAt. */
function action_admin_wa(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20);
    $ids = array_map(fn($x) => clean($x, 20), is_array($d['reviews'] ?? null) ? $d['reviews'] : []);
    $changed = [];
    $o = admin_order_update($id, function (array $o) use ($ids, &$changed) {
        $now = date('c');
        foreach ($o['reviews'] as &$r) if (in_array($r['id'], $ids, true)) {
            $r['sentAt'] = $now;
            if ($r['status'] !== 'in_progress') { $r['status'] = 'in_progress'; $r['updatedAt'] = $now; $changed[] = $r['id']; }
        }
        unset($r);
        if ($changed && empty($o['timeline']['review'])) $o['timeline']['review'] = $now;
        return $o;
    });
    if ($changed) queue_notification($id, $changed);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

function action_admin_message(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20); $text = clean($d['text'] ?? '', 4000);
    if ($text === '') fail(400, 'empty');
    $o = admin_order_update($id, function (array $o) use ($text) { $o['messages'][] = ['from' => 'team', 'text' => $text, 'at' => date('c')]; return $o; });
    mail_team_message($o, $text);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

/** Creates (or resends) the Stripe Payment Link for the removed reviews and emails it (C3). */
function action_admin_paylink(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20);
    $o = store_get('order', $id) ?? fail(404, 'order_not_found');
    if (invoice($o)['total'] <= 0) fail(400, 'nothing_billable');
    $resend = !empty($d['resend']) && ($o['payment']['status'] ?? '') === 'link_sent' && !empty($o['payment']['link']);
    if ($resend) {
        $link = ['url' => $o['payment']['link'], 'id' => $o['payment']['linkId'] ?? ''];
    } else {
        $link = stripe_payment_link($o);
        if (!$link && !empty($o['payment']['manualLink'])) $link = ['url' => $o['payment']['manualLink'], 'id' => ''];
        if (!$link) fail(503, (string)config('stripe_secret_key', '') === '' ? 'stripe_not_configured' : 'stripe_failed');
    }
    $o = admin_order_update($id, function (array $o) use ($link) {
        $o['payment'] = array_merge($o['payment'], ['status' => 'link_sent', 'link' => $link['url'], 'linkId' => $link['id'], 'linkSentAt' => date('c')]);
        return $o;
    });
    flush_notifications(true); // status emails first, then the payment email
    mail_payment_link($o, $link['url']);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

function action_admin_markpaid(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20);
    $o = admin_order_update($id, function (array $o) {
        $now = date('c');
        $o['payment'] = array_merge($o['payment'], ['status' => 'paid', 'paidAt' => $now, 'amount' => invoice($o)['total'], 'via' => 'manual']);
        $o['timeline']['paid'] = $now;
        return $o;
    });
    mail_paid($o);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

function action_admin_resetpw(): void {
    admin_required();
    $d = json_body();
    $o = store_get('order', clean($d['order'] ?? '', 20)) ?? fail(404, 'order_not_found');
    $cust = get_customer($o['customer']['email']);
    if (!$cust) fail(404, 'customer_not_found');
    send_reset_link($cust); // the customer sets a new password via the link
    json_out(['ok' => true, 'email' => $cust['email']]);
}

function action_admin_settings(): void {
    admin_required();
    $d = json_body();
    $sender = strtolower(clean($d['sender'] ?? '', 200));
    if ($sender !== '' && !is_email($sender)) fail(400, 'invalid_sender');
    $s = ['partnerNo' => clean($d['partnerNo'] ?? '', 40), 'template' => mb_substr(str_replace("\r", '', (string)($d['template'] ?? '')), 0, 2000), 'sender' => $sender ?: TEAM_EMAIL];
    store_put('settings', 'app', $s);
    json_out(['ok' => true]);
}

function action_admin_analytics(): void {
    admin_required();
    $range = (int)($_GET['range'] ?? 30);
    json_out(['ok' => true] + analytics_data(in_array($range, [7, 30, 90], true) ? $range : 0));
}

function action_admin_cancel(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20);
    $reason = clean($d['reason'] ?? '', 1000);
    $o = cancel_order_record($id, 'team', $reason);
    if (!$o) fail(409, store_get('order', $id) ? 'already_cancelled' : 'order_not_found');
    @unlink(store_path('notify', $id));
    log_event("order $id cancelled by admin");
    if (!empty($d['notify'])) mail_order_cancelled($o, 'team', $reason);
    json_out(['ok' => true, 'order' => admin_order_view($o)]);
}

/** Removes the order from the admin list and the customer's account. The record is archived
 *  (public/orders/deleted/) because invoices must be kept – it is not destroyed. */
function action_admin_delete(): void {
    admin_required();
    $d = json_body();
    $id = clean($d['order'] ?? '', 20);
    $o = store_get('order', $id) ?? fail(404, 'order_not_found');
    if (!empty($o['payment']['linkId']) && ($o['payment']['status'] ?? '') === 'link_sent') stripe_deactivate_link($o['payment']['linkId']);
    $o['deletedAt'] = date('c');
    $o['deleteReason'] = clean($d['reason'] ?? '', 1000);
    store_put('deleted', $id, $o);
    @unlink(store_path('order', $id));
    @unlink(store_path('notify', $id));
    store_update('customers', customer_key($o['customer']['email']), function (?array $c) use ($id) {
        if (!$c) return null;
        $c['orders'] = array_values(array_filter($c['orders'] ?? [], fn($x) => $x !== $id));
        return $c;
    });
    log_event("order $id deleted by admin");
    if (!empty($d['notify'])) mail_order_deleted($o, $o['deleteReason']);
    json_out(['ok' => true]);
}
