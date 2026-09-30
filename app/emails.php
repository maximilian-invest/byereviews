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
        . "Manage: " . SITE_URL . "/admin/#{$order['id']}\n";
    send_mail(TEAM_EMAIL, "New order {$order['id']} – {$c['name']}", $body, $c['email']);
}

// ---------- designed HTML emails (design/HANDOFF.md C2 + C3) ----------

function eh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function status_chip(string $status): array {
    return ['submitted' => ['Submitted', '#EEEEEE', '#555555', '#EEEEEE'], 'in_progress' => ['In progress', '#FFFFFF', '#151515', '#151515'],
        'removed' => ['✓ Removed', '#151515', '#FFFFFF', '#151515'], 'not_eligible' => ['Not eligible', '#FFFFFF', '#8A8A8A', '#D2D2D2']][$status] ?? [$status, '#EEEEEE', '#555555', '#EEEEEE'];
}

function email_layout(string $inner): string {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#EEEEEE;font-family:Geist,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#151515">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEEEEE"><tr><td align="center" style="padding:32px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:24px"><tr><td style="padding:36px 32px">'
        . '<img src="' . SITE_URL . '/assets/byereviews-logo.png" alt="byereviews" height="20" style="height:20px;display:block;margin-bottom:22px">'
        . $inner
        . '<p style="margin:26px 0 0;padding-top:14px;border-top:1px solid #F0F0F0;font-size:12px;color:#8A8A8A">You only pay for reviews that were actually removed. Questions? Just reply to this email.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function email_button(string $href, string $label, bool $full = false): string {
    return '<a href="' . eh($href) . '" style="display:' . ($full ? 'block;text-align:center' : 'inline-block') . ';background:#151515;color:#FFFFFF;text-decoration:none;border-radius:14px;padding:' . ($full ? '18px 20px;font-size:16px' : '14px 20px;font-size:15px') . ';font-weight:600">' . eh($label) . '</a>';
}

/** C2 – "Status update on your order BR-xxxxx" for the reviews that changed. */
function mail_status_update(array $order, array $changed): void {
    $c = $order['customer'];
    $rows = ''; $lines = [];
    foreach ($changed as $r) {
        [$label, $bg, $fg, $bd] = status_chip($r['status']);
        $rows .= '<tr><td style="padding:0 0 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F4;border-radius:16px"><tr>'
            . '<td style="padding:14px 16px;font-size:14px"><strong style="font-weight:600">' . eh($r['author'] ?: 'Review') . '</strong>' . ($r['stars'] ? ' · <span style="white-space:nowrap">' . (int)$r['stars'] . ' ★</span>' : '') . '</td>'
            . '<td align="right" style="padding:14px 16px"><span style="display:inline-block;font-size:13px;font-weight:600;border-radius:9px;padding:5px 9px;background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $bd . '">' . eh($label) . '</span></td></tr></table></td></tr>';
        $lines[] = '· ' . ($r['author'] ?: 'Review') . ': ' . $label;
    }
    $html = email_layout('<h1 style="margin:0 0 14px;font-size:26px;font-weight:600;letter-spacing:-.03em;line-height:1.15">Update on your reviews</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#444">Hi ' . eh(first_name($c['name'])) . ', here\'s what changed on order ' . eh($order['id']) . ':</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
        . '<p style="margin:16px 0 0">' . email_button(SITE_URL . '/dashboard/', 'View in dashboard') . '</p>');
    $text = 'Hi ' . first_name($c['name']) . ",\n\nhere's what changed on order {$order['id']}:\n\n" . implode("\n", $lines) . "\n\nView in dashboard: " . SITE_URL . "/dashboard/\n\nYou only pay for reviews that were actually removed. Questions? Just reply to this email.\n";
    send_mail($c['email'], "Status update on your order {$order['id']}", $text, '', $html);
}

/** C3 – "N reviews removed – €X due" with the payment link. */
function mail_payment_link(array $order, string $link): void {
    $c = $order['customer']; $cur = $order['currency'];
    $rem = array_values(array_filter($order['reviews'], fn($r) => $r['status'] === 'removed'));
    $inv = invoice($order);
    $n = count($rem);
    $rows = ''; $lines = [];
    foreach ($rem as $r) {
        $short = $r['text'] !== '' ? mb_strimwidth($r['text'], 0, 70, '…') : 'Star rating only';
        $rows .= '<tr><td style="border-top:1px solid #F0F0F0;padding:12px 0;font-size:14px"><strong style="font-weight:600">' . eh($r['author'] ?: 'Review') . '</strong>' . ($r['stars'] ? ' · ' . (int)$r['stars'] . ' ★' : '')
            . ' <span style="color:#9E9E9E;text-decoration:line-through">' . eh($short) . '</span></td><td align="right" style="border-top:1px solid #F0F0F0;padding:12px 0 12px 12px;font-size:14px;font-weight:600;white-space:nowrap">' . eh(money(tier_price($r['tier']), $cur)) . '</td></tr>';
        $lines[] = '· ' . ($r['author'] ?: 'Review') . ' – ' . money(tier_price($r['tier']), $cur);
    }
    $word = $n === 1 ? '1 review is' : "$n reviews are";
    $html = email_layout('<h1 style="margin:0 0 14px;font-size:26px;font-weight:600;letter-spacing:-.03em;line-height:1.15">Good news – ' . $word . ' gone.</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#444">Hi ' . eh(first_name($c['name'])) . ', we\'ve removed the following from ' . eh($order['business']['name'] ?: $c['company']) . '\'s Google profile:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F4;border-radius:16px;margin:12px 0 16px"><tr><td style="padding:16px">'
        . ($inv['rate'] > 0 ? '<table role="presentation" width="100%"><tr><td style="font-size:14px;color:#6B6B6B">Volume discount ' . round($inv['rate'] * 100) . '%</td><td align="right" style="font-size:14px">– ' . eh(money($inv['discount'], $cur)) . '</td></tr></table>' : '')
        . '<table role="presentation" width="100%"><tr><td style="font-size:14px;font-weight:600">Total due today</td><td align="right" style="font-size:28px;font-weight:600;letter-spacing:-.03em">' . eh(money($inv['total'], $cur)) . '</td></tr></table>'
        . '</td></tr></table>'
        . email_button($link, 'Pay now', true)
        . '<p style="margin:14px 0 0;text-align:center"><a href="' . SITE_URL . '/dashboard/" style="font-size:14px;color:#6B6B6B;text-decoration:underline">View in dashboard</a></p>');
    $text = 'Hi ' . first_name($c['name']) . ",\n\ngood news – $word gone:\n\n" . implode("\n", $lines) . "\n"
        . ($inv['rate'] > 0 ? 'Volume discount ' . round($inv['rate'] * 100) . '%: – ' . money($inv['discount'], $cur) . "\n" : '')
        . 'Total due today: ' . money($inv['total'], $cur) . "\n\nPay now: $link\nDashboard: " . SITE_URL . "/dashboard/\n\nYou only pay for reviews that were actually removed. Questions? Just reply to this email.\n";
    send_mail($c['email'], "$n review" . ($n === 1 ? '' : 's') . ' removed – ' . money($inv['total'], $cur) . ' due', $text, '', $html);
}

function mail_team_message(array $order, string $text): void {
    $body = 'Hi ' . first_name($order['customer']['name']) . ",\n\n$text\n\nReply in your dashboard: " . SITE_URL . "/login/ (Support tab) or just answer this email.\n\nThe byereviews team\n";
    send_mail($order['customer']['email'], "New message about order {$order['id']}", $body);
}

function mail_customer_message(array $order, string $text): void {
    $body = "Message from {$order['customer']['name']} <{$order['customer']['email']}> about {$order['id']}:\n\n$text\n\nReply: " . SITE_URL . "/admin/#{$order['id']}\n";
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
