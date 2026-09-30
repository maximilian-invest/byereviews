<?php
// Transactional email texts.
declare(strict_types=1);

function first_name(string $name): string {
    return explode(' ', trim($name))[0] ?: 'there';
}

function review_lines(array $reviews, string $cur): string {
    $out = [];
    foreach ($reviews as $i => $r) {
        $parts = [($i + 1) . '. ' . ($r['author'] ?: 'Review') . ' · ' . ($r['tier'] === 'older' ? 'older than 4 weeks' : 'last 4 weeks') . ' · ' . money(tier_price($r['tier']), $cur) . ' if removed'];
        if (!empty($r['stars'])) $parts[] = '   Stars: ' . $r['stars'];
        if (!empty($r['link'])) $parts[] = '   Link: ' . $r['link'];
        if (!empty($r['text'])) $parts[] = '   Text: ' . mb_substr($r['text'], 0, 600);
        $out[] = implode("\n", $parts);
    }
    return implode("\n\n", $out);
}

function mail_order_confirmation(array $order, ?string $password): void {
    $c = $order['customer'];
    $t = totals($order['reviews']);
    $cur = $order['currency'];
    $body = 'Hi ' . first_name($c['name']) . ",\n\n"
        . "thank you for your order {$order['id']}" . ($order['business']['name'] ? " for {$order['business']['name']}" : '') . ". "
        . "We'll check every review against Google's policies and keep you posted at every step.\n\n"
        . "Nothing is charged today – you only pay for reviews that are actually removed.\n\n"
        . "— YOUR REVIEWS —\n" . review_lines($order['reviews'], $cur) . "\n\n"
        . "— IF EVERY REVIEW IS REMOVED —\n"
        . "Subtotal: " . money($t['subtotal'], $cur) . ($t['rate'] > 0 ? "\nVolume discount " . round($t['rate'] * 100) . "%: – " . money($t['discount'], $cur) : '')
        . "\nTotal: " . money($t['total'], $cur) . "\n\n"
        . ($password !== null
            ? "— YOUR DASHBOARD —\nFollow every review live at " . SITE_URL . "/login/\nLogin: {$c['email']}\nPassword: $password\n\n"
            : "This order has been added to your existing dashboard: " . SITE_URL . "/login/\n\n")
        . "Questions? Just reply to this email.\n\nBest regards\nThe byereviews team\n" . SITE_URL . "\n";
    send_mail($c['email'], "Your byereviews order {$order['id']}", $body);
}

function mail_team_new_order(array $order): void {
    $c = $order['customer'];
    $b = $order['business'];
    $t = totals($order['reviews']);
    $body = "New order {$order['id']}\n\n"
        . "— CONTACT —\nName: {$c['name']}\nEmail: {$c['email']}\nCompany: {$c['company']}\nPhone: {$c['phone']}\nAddress: {$c['street']}, {$c['city']}, {$c['country']}\n\n"
        . "— BUSINESS —\n{$b['name']}\n{$b['address']}\n" . ($b['placeId'] ? "Place ID: {$b['placeId']}\n" : '') . ($b['mapsUrl'] ? "{$b['mapsUrl']}\n" : '') . ($b['query'] ? "Entered: {$b['query']}\n" : '') . "\n"
        . "— REVIEWS —\n" . review_lines($order['reviews'], $order['currency']) . "\n\n"
        . "Potential total: " . money($t['total'], $order['currency']) . "\n\n"
        . "Manage: " . SITE_URL . "/order.php?a=admin&order={$order['id']}\n";
    send_mail(TEAM_EMAIL, "New order {$order['id']} – {$c['name']}", $body, $c['email']);
}

function mail_status_update(array $order, array $changed): void {
    $c = $order['customer'];
    $cur = $order['currency'];
    $labels = ['in_progress' => 'is now in progress', 'removed' => 'has been removed ✓', 'not_eligible' => "doesn't qualify for removal – cancelled free of charge", 'submitted' => 'is back in the queue'];
    $lines = array_map(fn($r) => '· ' . ($r['author'] ?: 'Review') . ' ' . ($labels[$r['status']] ?? $r['status']), $changed);
    $inv = invoice($order);
    $pay = ($inv['total'] > 0 && ($order['payment']['status'] ?? '') !== 'paid')
        ? "\nAmount due for removed reviews: " . money($inv['total'], $cur) . "\nPay securely here: " . SITE_URL . "/order.php?a=pay&order={$order['id']}&t=" . ($order['payToken'] ?? '') . "\n(or from your dashboard)\n"
        : '';
    $body = 'Hi ' . first_name($c['name']) . ",\n\nthere's an update on your order {$order['id']}:\n\n" . implode("\n", $lines) . "\n" . $pay
        . "\nSee all details in your dashboard: " . SITE_URL . "/login/\n\nBest regards\nThe byereviews team\n";
    send_mail($c['email'], "Update on your byereviews order {$order['id']}", $body);
}

function mail_team_message(array $order, string $text): void {
    $body = 'Hi ' . first_name($order['customer']['name']) . ",\n\n$text\n\nReply in your dashboard: " . SITE_URL . "/login/ (Support tab) or just answer this email.\n\nThe byereviews team\n";
    send_mail($order['customer']['email'], "New message about order {$order['id']}", $body);
}

function mail_customer_message(array $order, string $text): void {
    $body = "Message from {$order['customer']['name']} <{$order['customer']['email']}> about {$order['id']}:\n\n$text\n\nReply: " . SITE_URL . "/order.php?a=admin&order={$order['id']}\n";
    send_mail(TEAM_EMAIL, "Support message {$order['id']} – {$order['customer']['name']}", $body, $order['customer']['email']);
}

function mail_password_reset(array $customer, string $password): void {
    $body = 'Hi ' . first_name($customer['name']) . ",\n\nhere is your new password for the byereviews dashboard:\n\nLogin: {$customer['email']}\nPassword: $password\n\n" . SITE_URL . "/login/\n\nIf you didn't ask for this, you can ignore this email – only you receive the new password.\n\nThe byereviews team\n";
    send_mail($customer['email'], 'Your new byereviews password', $body);
}

function mail_paid(array $order): void {
    $inv = invoice($order);
    $body = 'Hi ' . first_name($order['customer']['name']) . ",\n\nwe've received your payment of " . money($inv['total'], $order['currency']) . " for order {$order['id']}. Thank you!\n\nThe byereviews team\n";
    send_mail($order['customer']['email'], "Payment received – order {$order['id']}", $body);
}
