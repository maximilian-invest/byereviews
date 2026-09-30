<?php
// Team admin: order list + order detail (review statuses, messages, payment). /order.php?a=admin
declare(strict_types=1);

require_once __DIR__ . '/api.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function admin_page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' – byereviews admin</title><link rel="icon" href="/assets/favicon.png"><link rel="stylesheet" href="/assets/fonts/fonts.css"><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#EFEFEF;font:15px/1.5 Geist,system-ui,sans-serif;color:#151515}a{color:#151515}'
        . '.w{max-width:1200px;margin:0 auto;padding:16px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border-radius:18px;padding:10px 16px;margin-bottom:16px}'
        . '.top img{height:20px;display:block}.card{background:#fff;border-radius:20px;padding:18px;margin-bottom:14px}h1{font-size:28px;font-weight:500;letter-spacing:-.03em;margin:4px 0 14px}h2{font-size:18px;font-weight:500;margin:0 0 10px}'
        . 'table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-top:1px solid #EFEFEF;vertical-align:top;font-size:14px}th{font-weight:600;border-top:0;color:#6B6B6B;font-size:12px;text-transform:uppercase;letter-spacing:.04em}'
        . 'input,select,textarea,button{font:inherit}input[type=text],input[type=password],input[type=url],textarea,select{border:1px solid #D2D2D2;background:#F7F7F7;border-radius:12px;padding:9px 12px;width:100%}'
        . 'button,.btn{border:0;background:#151515;color:#fff;border-radius:12px;padding:10px 16px;cursor:pointer;font-weight:500;text-decoration:none;display:inline-block}.btn.l{background:#EFEFEF;color:#151515}'
        . '.chip{display:inline-block;font-size:12px;font-weight:500;border-radius:8px;padding:3px 8px;background:#EFEFEF}.chip.removed{background:#151515;color:#fff}.chip.in_progress{border:1px solid #151515;background:#fff}.chip.not_eligible{color:#8A8A8A}.chip.paid{background:#151515;color:#fff}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.muted{color:#6B6B6B;font-size:13px}.msg{border-radius:14px;padding:10px 12px;margin:6px 0;max-width:80%}.msg.team{background:#F4F4F4}.msg.me{background:#151515;color:#fff;margin-left:auto}'
        . '.flash{background:#151515;color:#fff;border-radius:14px;padding:10px 14px;margin-bottom:14px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '</style></head><body><div class="w">' . $body . '</div></body></html>';
    exit;
}

function admin_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function admin_check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); admin_page('Error', '<div class="card">Session expired – please reload.</div>'); }
}

function admin_top(): string {
    return '<div class="top"><a href="/order.php?a=admin"><img src="/assets/byereviews-logo.png" alt="byereviews"></a><div class="row"><a href="/order.php?a=admin">Orders</a>'
        . '<form method="post" action="/order.php?a=admin" style="margin:0"><input type="hidden" name="csrf" value="' . h(admin_csrf()) . '"><input type="hidden" name="do" value="logout"><button class="btn l" type="submit">Log out</button></form></div></div>';
}

function action_admin(): void {
    start_session();
    $hash = (string)config('admin_password_hash', '');
    if ($hash === '') admin_page('Setup', '<div class="card"><h1>Admin not configured</h1><p>Set <code>admin_password_hash</code> in <code>/etc/byereviews/config.php</code> (see <code>deploy/config.example.php</code>).</p></div>');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
        if (!rate_ok('admin_login', 10, 900)) admin_page('Login', '<div class="card">Too many attempts. Try again later.</div>');
        if (password_verify((string)($_POST['password'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /order.php?a=admin'); exit; }
        $err = 'Wrong password.';
    }
    if (empty($_SESSION['admin'])) {
        admin_page('Login', '<div class="card" style="max-width:420px;margin:60px auto"><h1>Admin</h1>' . (isset($err) ? '<p class="muted">' . h($err) . '</p>' : '')
            . '<form method="post" action="/order.php?a=admin"><input type="hidden" name="do" value="login"><p><input type="password" name="password" placeholder="Password" autofocus required></p><button type="submit">Log in</button></form></div>');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_check_csrf();
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'logout') { $_SESSION = []; session_destroy(); header('Location: /order.php?a=admin'); exit; }
        $id = clean($_POST['order'] ?? '', 20);
        $flash = admin_handle_post($do, $id);
        $_SESSION['flash'] = $flash;
        header('Location: /order.php?a=admin&order=' . urlencode($id));
        exit;
    }

    $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
    $flashHtml = $flash ? '<div class="flash">' . h($flash) . '</div>' : '';
    $id = clean($_GET['order'] ?? '', 20);
    if ($id !== '' && ($o = store_get('order', $id))) admin_page($o['id'], admin_top() . $flashHtml . admin_order_html($o));
    admin_page('Orders', admin_top() . $flashHtml . admin_list_html());
}

function admin_list_html(): string {
    $orders = store_list('order');
    usort($orders, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    $q = mb_strtolower(clean($_GET['q'] ?? '', 100));
    $rows = '';
    foreach ($orders as $o) {
        $hay = mb_strtolower($o['id'] . ' ' . $o['customer']['email'] . ' ' . $o['customer']['name'] . ' ' . $o['business']['name']);
        if ($q !== '' && !str_contains($hay, $q)) continue;
        $count = array_count_values(array_column($o['reviews'], 'status'));
        $inv = invoice($o);
        $rows .= '<tr><td><a href="/order.php?a=admin&order=' . h($o['id']) . '"><b>' . h($o['id']) . '</b></a><div class="muted">' . h(date('M j, Y H:i', strtotime($o['createdAt']))) . '</div></td>'
            . '<td>' . h($o['customer']['name']) . '<div class="muted">' . h($o['customer']['email']) . '</div></td><td>' . h($o['business']['name']) . '</td>'
            . '<td>' . count($o['reviews']) . ' total<div class="muted">' . (int)($count['submitted'] ?? 0) . ' new · ' . (int)($count['in_progress'] ?? 0) . ' in progress · ' . (int)($count['removed'] ?? 0) . ' removed</div></td>'
            . '<td>' . (($o['payment']['status'] ?? '') === 'paid' ? '<span class="chip paid">Paid</span>' : ($inv['total'] > 0 ? h(money($inv['total'], $o['currency'])) . ' due' : '–')) . '</td></tr>';
    }
    return '<h1>Orders</h1><div class="card"><form class="row" method="get" action="/order.php"><input type="hidden" name="a" value="admin"><input type="text" name="q" value="' . h($q) . '" placeholder="Search order, email, name, business" style="max-width:420px"><button type="submit">Search</button></form></div>'
        . '<div class="card" style="overflow:auto"><table><tr><th>Order</th><th>Customer</th><th>Business</th><th>Reviews</th><th>Payment</th></tr>' . ($rows ?: '<tr><td colspan="5" class="muted">No orders yet.</td></tr>') . '</table></div>';
}

function admin_order_html(array $o): string {
    $c = $o['customer']; $b = $o['business']; $cur = $o['currency'];
    $csrf = '<input type="hidden" name="csrf" value="' . h(admin_csrf()) . '"><input type="hidden" name="order" value="' . h($o['id']) . '">';
    $statuses = ['submitted' => 'Submitted', 'in_progress' => 'In progress', 'removed' => 'Removed', 'not_eligible' => 'Not eligible'];
    $rows = '';
    foreach ($o['reviews'] as $r) {
        $opts = '';
        foreach ($statuses as $k => $label) $opts .= '<option value="' . $k . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . $label . '</option>';
        $rows .= '<tr><td><b>' . h($r['author'] ?: 'Review') . '</b><div class="muted">' . ($r['stars'] ? str_repeat('★', (int)$r['stars']) . ' · ' : '') . h($r['tier'] === 'older' ? '> 4 weeks' : '≤ 4 weeks') . ' · ' . h(money(tier_price($r['tier']), $cur)) . '</div></td>'
            . '<td style="max-width:460px">' . nl2br(h($r['text'] ?: '—')) . ($r['link'] ? '<div><a href="' . h($r['link']) . '" target="_blank" rel="noopener">Open review ↗</a></div>' : '') . '</td>'
            . '<td><select name="status[' . h($r['id']) . ']">' . $opts . '</select></td></tr>';
    }
    $msgs = '';
    foreach ($o['messages'] as $m) $msgs .= '<div class="msg ' . ($m['from'] === 'team' ? 'team' : 'me') . '">' . nl2br(h($m['text'])) . '<div class="muted" style="' . ($m['from'] === 'team' ? '' : 'color:#B5B5B5') . '">' . ($m['from'] === 'team' ? 'Team' : h($c['name'])) . ' · ' . h(date('M j, H:i', strtotime($m['at']))) . '</div></div>';
    $inv = invoice($o);
    $paid = ($o['payment']['status'] ?? '') === 'paid';

    return '<div class="row" style="justify-content:space-between"><h1>' . h($o['id']) . ' · ' . h($b['name'] ?: $c['company']) . '</h1><span class="muted">' . h(date('M j, Y H:i', strtotime($o['createdAt']))) . ' · ' . h($cur) . '</span></div>'
        . '<div class="grid"><div class="card"><h2>Customer</h2>' . h($c['name']) . '<br><a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a><br>' . h($c['phone']) . '<br>' . h($c['company']) . '<br>' . h($c['street']) . ', ' . h($c['city']) . '<br>' . h($c['country'])
        . '<form method="post" action="/order.php?a=admin" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="reset_password"><button class="btn l" type="submit" onclick="return confirm(\'Send a new password to the customer?\')">Send new password</button></form></div>'
        . '<div class="card"><h2>Business</h2>' . h($b['name']) . '<br><span class="muted">' . h($b['address']) . '</span><br>' . ($b['rating'] !== null ? h(number_format((float)$b['rating'], 1)) . ' ★ (' . (int)$b['reviewCount'] . ') at order time<br>' : '')
        . ($b['mapsUrl'] ? '<a href="' . h($b['mapsUrl']) . '" target="_blank" rel="noopener">Google profile ↗</a><br>' : '') . ($b['query'] ? '<span class="muted">Entered: ' . h($b['query']) . '</span>' : '') . '</div>'
        . '<div class="card"><h2>Payment</h2>' . ($paid ? '<span class="chip paid">Paid</span> ' . h(date('M j, Y', strtotime($o['payment']['paidAt']))) : 'Due now: <b>' . h(money($inv['total'], $cur)) . '</b> <span class="muted">(' . $inv['n'] . ' removed' . ($inv['rate'] ? ', ' . round($inv['rate'] * 100) . '% off' : '') . ')</span>')
        . '<form method="post" action="/order.php?a=admin" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="payment"><p><input type="url" name="manual_link" value="' . h($o['payment']['manualLink'] ?? '') . '" placeholder="Manual payment link (only if Stripe is not set up)"></p>'
        . '<div class="row"><button type="submit" name="mark" value="link">Save link</button>' . ($paid ? '<button class="btn l" type="submit" name="mark" value="unpaid">Mark unpaid</button>' : '<button class="btn l" type="submit" name="mark" value="paid">Mark as paid</button>') . '</div></form></div></div>'
        . '<form method="post" action="/order.php?a=admin" class="card" style="overflow:auto">' . $csrf . '<input type="hidden" name="do" value="statuses"><h2>Reviews</h2><table><tr><th>Review</th><th>Text</th><th>Status</th></tr>' . $rows . '</table>'
        . '<div class="row" style="margin-top:12px"><button type="submit">Save statuses</button><label class="row muted"><input type="checkbox" name="notify" value="1" checked> Email the customer about changes</label></div></form>'
        . '<div class="card"><h2>Support</h2>' . $msgs . '<form method="post" action="/order.php?a=admin" style="margin-top:10px">' . $csrf . '<input type="hidden" name="do" value="message"><textarea name="text" rows="3" placeholder="Reply to the customer (also sent by email)" required></textarea><p><button type="submit">Send</button></p></form></div>';
}

function admin_handle_post(string $do, string $id): string {
    if (!store_get('order', $id)) return 'Order not found.';
    $now = date('c');
    if ($do === 'statuses') {
        $new = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
        $changed = [];
        $o = store_update('order', $id, function (?array $o) use ($new, $now, &$changed) {
            foreach ($o['reviews'] as &$r) {
                $s = (string)($new[$r['id']] ?? $r['status']);
                if (!in_array($s, ['submitted', 'in_progress', 'removed', 'not_eligible'], true) || $s === $r['status']) continue;
                $r['status'] = $s; $r['updatedAt'] = $now; $changed[] = $r;
                if ($s === 'in_progress' && empty($o['timeline']['review'])) $o['timeline']['review'] = $now;
                if ($s === 'removed' && empty($o['timeline']['removed'])) $o['timeline']['removed'] = $now;
            }
            unset($r);
            // a newly removed review after payment re-opens the balance
            if ($changed && ($o['payment']['status'] ?? '') === 'paid' && array_filter($changed, fn($r) => $r['status'] === 'removed')) {
                $o['payment']['status'] = 'unpaid'; $o['payment']['note'] = 'Re-opened: new removal after an earlier payment';
            }
            return $o;
        });
        if (!$changed) return 'No changes.';
        if (!empty($_POST['notify'])) mail_status_update($o, $changed);
        return count($changed) . ' review(s) updated' . (!empty($_POST['notify']) ? ' – customer notified.' : '.');
    }
    if ($do === 'message') {
        $text = clean($_POST['text'] ?? '', 4000);
        if ($text === '') return 'Empty message.';
        $o = store_update('order', $id, function (?array $o) use ($text, $now) { $o['messages'][] = ['from' => 'team', 'text' => $text, 'at' => $now]; return $o; });
        mail_team_message($o, $text);
        return 'Message sent.';
    }
    if ($do === 'payment') {
        $mark = (string)($_POST['mark'] ?? 'link');
        $link = clean($_POST['manual_link'] ?? '', 1000);
        if ($link !== '' && !preg_match('~^https://~', $link)) return 'Payment link must start with https://';
        $o = store_update('order', $id, function (?array $o) use ($mark, $link, $now) {
            $o['payment']['manualLink'] = $link;
            if ($mark === 'paid') { $o['payment']['status'] = 'paid'; $o['payment']['paidAt'] = $now; $o['timeline']['paid'] = $now; }
            if ($mark === 'unpaid') { $o['payment']['status'] = 'unpaid'; unset($o['timeline']['paid']); }
            return $o;
        });
        if ($mark === 'paid') mail_paid($o);
        return $mark === 'paid' ? 'Marked as paid – customer notified.' : ($mark === 'unpaid' ? 'Marked as unpaid.' : 'Payment link saved.');
    }
    if ($do === 'reset_password') {
        $o = store_get('order', $id);
        $pw = new_password();
        $cust = store_update('customers', customer_key($o['customer']['email']), function (?array $c) use ($pw) { if (!$c) return null; $c['passwordHash'] = password_hash($pw, PASSWORD_DEFAULT); return $c; });
        if ($cust) { mail_password_reset($cust, $pw); return 'New password emailed to the customer.'; }
        return 'Customer account not found.';
    }
    return 'Unknown action.';
}
