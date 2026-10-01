<?php
// Transactional emails – every mail uses the branded layout (same visual system as the site):
// grey canvas, white card (radius 28), logo + mono meta line, two-tone headline, black pill buttons,
// black sign-off band, footer links. Each mail also has a plain-text part.
declare(strict_types=1);

function first_name(string $name): string {
    return explode(' ', trim($name))[0] ?: 'there';
}

function eh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

const MAIL_FONT = "Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
const MAIL_MONO = "'Geist Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace";

// ---------- building blocks ----------

/** Two-tone headline: grey lead-in, ink ending (the site's signature pattern). */
function m_h1(string $grey, string $ink): string {
    return '<h1 style="margin:0 0 16px;font-family:' . MAIL_FONT . ';font-size:30px;font-weight:600;letter-spacing:-.035em;line-height:1.08;color:#151515">'
        . ($grey !== '' ? '<span style="color:#9E9E9E">' . eh($grey) . '</span> ' : '') . eh($ink) . '</h1>';
}

function m_p(string $html, string $style = ''): string {
    return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#444;' . $style . '">' . $html . '</p>';
}

function email_button(string $href, string $label, bool $full = false): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" ' . ($full ? 'width="100%" ' : '') . 'style="margin:6px 0 4px"><tr><td style="background:#151515;border-radius:16px">'
        . '<a href="' . eh($href) . '" style="display:block;' . ($full ? 'text-align:center;' : '') . 'padding:15px 22px;font-family:' . MAIL_FONT . ';font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none">'
        . eh($label) . ' &nbsp;→</a></td></tr></table>';
}

/** Grey info box. $rows = [[label, value, bold?], …] */
function m_box(array $rows, string $title = ''): string {
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F4;border-radius:18px;margin:6px 0 16px"><tr><td style="padding:16px 18px">';
    if ($title !== '') $h .= '<div style="font-family:' . MAIL_MONO . ';font-size:11px;letter-spacing:.04em;color:#8A8A8A;margin-bottom:8px">' . eh(strtoupper($title)) . '</div>';
    foreach ($rows as $r) {
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:4px 0;font-size:14px;color:#6B6B6B">' . $r[0] . '</td>'
            . '<td align="right" style="padding:4px 0 4px 12px;font-size:' . (!empty($r[2]) ? '22px;font-weight:600;letter-spacing:-.02em' : '14px') . ';color:#151515;white-space:nowrap">' . $r[1] . '</td></tr></table>';
    }
    return $h . '</td></tr></table>';
}

function status_chip(string $status): array {
    return ['submitted' => ['Submitted', '#EEEEEE', '#555555', '#EEEEEE'], 'in_progress' => ['In progress', '#FFFFFF', '#151515', '#151515'],
        'removed' => ['✓ Removed', '#151515', '#FFFFFF', '#151515'], 'not_eligible' => ['Not eligible', '#FFFFFF', '#8A8A8A', '#D2D2D2'],
        'cancelled' => ['Cancelled', '#FFFFFF', '#8A8A8A', '#D2D2D2']][$status] ?? [$status, '#EEEEEE', '#555555', '#EEEEEE'];
}

function m_chip(string $status): string {
    [$label, $bg, $fg, $bd] = status_chip($status);
    return '<span style="display:inline-block;font-size:12px;font-weight:600;border-radius:9px;padding:5px 9px;background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $bd . ';white-space:nowrap">' . eh($label) . '</span>';
}

/** Review list: author · stars, excerpt, right column = chip or price. */
function m_reviews(array $reviews, callable $right, bool $strike = false): string {
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px">';
    foreach ($reviews as $r) {
        $short = $r['text'] !== '' ? mb_strimwidth($r['text'], 0, 90, '…') : ($r['link'] ? 'Review link' : 'Star rating only');
        $h .= '<tr><td style="border-top:1px solid #F0F0F0;padding:12px 0;font-size:14px;line-height:1.45">'
            . '<strong style="font-weight:600;color:#151515">' . eh($r['author'] ?: 'Review') . '</strong>' . (!empty($r['stars']) ? ' <span style="color:#6B6B6B;white-space:nowrap">· ' . (int)$r['stars'] . ' ★</span>' : '')
            . '<br><span style="color:' . ($strike ? '#9E9E9E;text-decoration:line-through' : '#6B6B6B') . '">' . eh($short) . '</span></td>'
            . '<td align="right" valign="top" style="border-top:1px solid #F0F0F0;padding:12px 0 12px 12px;font-size:14px;font-weight:600;white-space:nowrap">' . $right($r) . '</td></tr>';
    }
    return $h . '</table>';
}

/**
 * The branded frame. $meta = mono line above the headline (e.g. "// order BR-12345"),
 * $preheader = inbox preview text.
 */
function email_layout(string $inner, string $meta = '', string $preheader = '', bool $customer = true): string {
    $foot = '<a href="' . SITE_URL . '/dashboard/" style="color:#151515;text-decoration:none">Dashboard</a> &nbsp;·&nbsp; '
        . '<a href="' . SITE_URL . '/blog/" style="color:#151515;text-decoration:none">Guides</a> &nbsp;·&nbsp; '
        . '<a href="mailto:' . TEAM_EMAIL . '" style="color:#151515;text-decoration:none">' . TEAM_EMAIL . '</a>';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>byereviews</title></head>'
        . '<body style="margin:0;padding:0;background:#E4E4E4;font-family:' . MAIL_FONT . ';color:#151515;-webkit-font-smoothing:antialiased">'
        . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . eh($preheader) . '</div>' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E4E4E4"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        // card
        . '<tr><td style="background:#FFFFFF;border-radius:28px;padding:34px 32px 30px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:26px"><tr>'
        . '<td><a href="' . SITE_URL . '/"><img src="' . SITE_URL . '/assets/byereviews-logo.png" alt="byereviews" width="107" height="22" style="display:block;height:22px;width:auto;border:0"></a></td>'
        . ($meta !== '' ? '<td align="right" style="font-family:' . MAIL_MONO . ';font-size:12px;color:#8A8A8A;white-space:nowrap">' . eh($meta) . '</td>' : '')
        . '</tr></table>'
        . $inner
        . '</td></tr>'
        // sign-off band
        . ($customer ? '<tr><td style="height:10px;line-height:10px;font-size:0">&nbsp;</td></tr>'
            . '<tr><td style="background:#151515;border-radius:24px;padding:22px 28px;color:#FFFFFF">'
            . '<span style="font-size:19px;font-weight:600;letter-spacing:-.02em;line-height:1.25"><span style="color:#7A7A7A">No cure, no pay.</span> You only pay for reviews that are actually removed.</span>'
            . '<div style="margin-top:8px;font-size:13px;color:#B5B5B5">Questions? Just reply to this email – a real person answers.</div></td></tr>' : '')
        // footer
        . '<tr><td align="center" style="padding:20px 12px 8px;font-size:13px;line-height:1.7;color:#6B6B6B">' . $foot
        . '<br><span style="font-size:12px;color:#8A8A8A">byereviews · Removal of individual false, unfair or policy-violating Google reviews. Not affiliated with Google.</span></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Sends the HTML part with a generated plain-text part. */
function send_branded(string $to, string $subject, string $inner, string $meta = '', string $preheader = '', string $replyTo = '', bool $customer = true): bool {
    $html = email_layout($inner, $meta, $preheader, $customer);
    $text = trim(html_entity_decode(strip_tags(preg_replace(['~<br\s*/?>~i', '~</(p|h1|tr|div|table)>~i', '~<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>~i'], ["\n", "\n\n", '$2 ($1)'], $inner)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)) . "\n\n– The byereviews team\n" . SITE_URL . "\n";
    return send_mail($to, $subject, $text, $replyTo, $html);
}

function price_note(array $r, string $cur): string {
    return eh(money(tier_price($r['tier']), $cur)) . '<br><span style="font-size:12px;font-weight:400;color:#8A8A8A">' . ($r['tier'] === 'older' ? '&gt; 4 weeks' : '≤ 4 weeks') . '</span>';
}

// ---------- customer emails ----------

function mail_order_confirmation(array $order, ?string $password): void {
    $c = $order['customer']; $cur = $order['currency'];
    $t = totals($order['reviews']);
    $biz = $order['business']['name'] ?: $c['company'];
    $inner = m_h1('Order received.', 'We\'re on it.')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', thanks for your order' . ($biz ? ' for <strong style="color:#151515">' . eh($biz) . '</strong>' : '') . '. We\'re checking every review against Google\'s policies now and will email you at every step.')
        . m_reviews($order['reviews'], fn($r) => price_note($r, $cur))
        . m_box(array_merge([['Subtotal', eh(money($t['subtotal'], $cur))]],
            $t['rate'] > 0 ? [['Volume discount ' . round($t['rate'] * 100) . '%', '– ' . eh(money($t['discount'], $cur))]] : [],
            [['Total if every review is removed', eh(money($t['total'], $cur)), true], ['Due today', eh(money(0, $cur))]]))
        . ($password !== null
            ? m_box([['Login', eh($c['email'])], ['Password', '<span style="font-family:' . MAIL_MONO . '">' . eh($password) . '</span>']], 'Your dashboard')
              . email_button(SITE_URL . '/login/', 'Open my dashboard')
            : m_p('This order was added to your existing dashboard – log in with your usual password.') . email_button(SITE_URL . '/login/', 'Open my dashboard'));
    send_branded($c['email'], "Your byereviews order {$order['id']}", $inner, '// order ' . $order['id'], 'We\'re checking your reviews now – nothing is charged today.');
}

/** C2 – status update for the reviews that changed. */
function mail_status_update(array $order, array $changed): void {
    $c = $order['customer'];
    $inner = m_h1('Update on', 'your reviews.')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', here\'s what changed on order ' . eh($order['id']) . ':')
        . m_reviews($changed, fn($r) => m_chip($r['status']))
        . email_button(SITE_URL . '/dashboard/', 'View in dashboard');
    send_branded($c['email'], "Status update on your order {$order['id']}", $inner, '// order ' . $order['id'], count($changed) . ' review' . (count($changed) === 1 ? '' : 's') . ' updated');
}

/** C3 – removed reviews + payment link. */
function mail_payment_link(array $order, string $link): void {
    $c = $order['customer']; $cur = $order['currency'];
    $rem = array_values(array_filter($order['reviews'], fn($r) => $r['status'] === 'removed'));
    $inv = invoice($order); $n = count($rem);
    $word = $n === 1 ? '1 review is' : "$n reviews are";
    $inner = m_h1('Good news –', $word . ' gone.')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', we\'ve removed the following from ' . eh($order['business']['name'] ?: $c['company']) . '\'s Google profile:')
        . m_reviews($rem, fn($r) => eh(money(tier_price($r['tier']), $cur)), true)
        . m_box(array_merge($inv['rate'] > 0 ? [['Volume discount ' . round($inv['rate'] * 100) . '%', '– ' . eh(money($inv['discount'], $cur))]] : [],
            [['Total due today', eh(money($inv['total'], $cur)), true]]))
        . email_button($link, 'Pay now', true)
        . '<p style="margin:14px 0 0;text-align:center"><a href="' . SITE_URL . '/dashboard/" style="font-size:14px;color:#6B6B6B;text-decoration:underline">View in dashboard</a></p>';
    send_branded($c['email'], "$n review" . ($n === 1 ? '' : 's') . ' removed – ' . money($inv['total'], $cur) . ' due', $inner, '// order ' . $order['id'], 'Secure checkout via Stripe – Apple Pay or card.');
}

function mail_paid(array $order): void {
    $c = $order['customer'];
    $inv = invoice($order);
    $amount = $order['payment']['amount'] ?? $inv['total'];
    $inner = m_h1('Paid –', 'thank you.')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', we\'ve received your payment for order ' . eh($order['id']) . '.')
        . m_box(array_merge([['Amount', eh(money($amount, $order['currency'])), true], ['Date', eh(date('j M Y'))]],
            !empty($order['payment']['invoiceNumber']) ? [['Invoice', eh($order['payment']['invoiceNumber'])]] : []))
        . email_button(SITE_URL . '/dashboard/', 'View in dashboard')
        . (!empty($order['payment']['invoicePdf']) ? '<p style="margin:14px 0 0;text-align:center"><a href="' . eh($order['payment']['invoicePdf']) . '" style="font-size:14px;color:#6B6B6B;text-decoration:underline">Download invoice (PDF)</a></p>' : '');
    send_branded($c['email'], "Payment received – order {$order['id']}", $inner, '// order ' . $order['id'], 'Your profile is cleaner – thanks for your payment.');
}

function mail_team_message(array $order, string $text): void {
    $c = $order['customer'];
    $inner = m_h1('New message', 'from the byereviews team.')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', about your order ' . eh($order['id']) . ':')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px"><tr><td style="background:#F4F4F4;border-radius:18px;padding:16px 18px;font-size:15px;line-height:1.6;color:#151515">' . nl2br(eh($text)) . '</td></tr></table>'
        . email_button(SITE_URL . '/dashboard/', 'Reply in dashboard')
        . m_p('Or simply answer this email.', 'margin-top:12px;font-size:13px;color:#8A8A8A');
    send_branded($c['email'], "New message about order {$order['id']}", $inner, '// order ' . $order['id'], mb_strimwidth($text, 0, 90, '…'));
}

/** Order cancelled – by the customer ('customer') or by us ('team'). */
function mail_order_cancelled(array $order, string $by, string $reason = ''): void {
    $c = $order['customer']; $cur = $order['currency'];
    $inv = invoice($order);
    $open = array_values(array_filter($order['reviews'], fn($r) => $r['status'] !== 'removed'));
    $inner = m_h1('Order cancelled.', $by === 'customer' ? 'As requested.' : '')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', ' . ($by === 'customer'
            ? 'you\'ve cancelled order ' . eh($order['id']) . '. We\'ve stopped all work on the reviews that were still open.'
            : 'we\'ve cancelled your order ' . eh($order['id']) . ' and stopped all work on the reviews that were still open.'))
        . ($reason !== '' ? m_box([[nl2br(eh($reason)), '']], 'Reason') : '')
        . ($open ? m_reviews($open, fn($r) => m_chip('cancelled')) : '')
        . ($inv['total'] > 0 && ($order['payment']['status'] ?? '') !== 'paid'
            ? m_p('Reviews that were already removed before the cancellation are still billed:') . m_box([['Removed reviews', (string)$inv['n']], ['Amount due', eh(money($inv['total'], $cur)), true]])
            : m_p('Nothing is charged for this order.', 'color:#151515;font-weight:600'))
        . email_button(SITE_URL . '/dashboard/', 'View in dashboard');
    send_branded($c['email'], "Order {$order['id']} cancelled", $inner, '// order ' . $order['id'], 'Order ' . $order['id'] . ' was cancelled.');
}

function mail_order_deleted(array $order, string $reason = ''): void {
    $c = $order['customer'];
    $inner = m_h1('Order deleted.', '')
        . m_p('Hi ' . eh(first_name($c['name'])) . ', we\'ve deleted order ' . eh($order['id']) . ($order['business']['name'] ? ' for ' . eh($order['business']['name']) : '') . ' from your account. No further work is done on it and nothing is charged for it.')
        . ($reason !== '' ? m_box([[nl2br(eh($reason)), '']], 'Reason') : '')
        . m_p('Questions about this? Just reply to this email.');
    send_branded($c['email'], "Order {$order['id']} deleted", $inner, '// order ' . $order['id'], 'Order ' . $order['id'] . ' was deleted.');
}

/** Short branded notice: headline, paragraphs, optional button, small print. */
function mail_notice(string $to, string $subject, string $title, array $paras, ?array $button = null, string $foot = '', array $box = []): void {
    $inner = m_h1('', $title);
    foreach ($paras as $p) $inner .= m_p($p);
    if ($box) $inner .= m_box($box);
    if ($button) $inner .= email_button($button[1], $button[0]);
    if ($foot !== '') $inner .= m_p($foot, 'margin-top:16px;font-size:13px;color:#8A8A8A');
    send_branded($to, $subject, $inner, '// account', strip_tags($paras[0] ?? ''));
}

function mail_reset_link(array $cust, string $link): void {
    mail_notice($cust['email'], 'Reset your byereviews password', 'Reset your password.',
        ['Hi ' . eh(first_name($cust['name'])) . ', click the button to choose a new password for your byereviews dashboard. The link is valid for 1 hour.'],
        ['Set a new password', $link], "Didn't ask for this? Ignore this email – your password stays the same.");
}

function mail_confirm_email(array $cust, string $newEmail, string $link): void {
    mail_notice($newEmail, 'Confirm your new email address', 'Confirm your new email address.',
        ['Hi ' . eh(first_name($cust['name'])) . ', please confirm that <strong style="color:#151515">' . eh($newEmail) . '</strong> should be the new login for your byereviews account. The link is valid for 24 hours.'],
        ['Confirm email address', $link], "Didn't ask for this? Ignore this email – nothing changes.");
}

function mail_when(): string { return eh(date('j M Y, H:i') . ' UTC'); }

/** "Chrome on macOS" from the User-Agent of the request that triggered the mail. */
function device_label(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')));
    $o = preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Linux/', $ua) ? 'Linux' : 'unknown device'))));
    return "$b on $o";
}

function mail_email_changed(array $cust, string $oldEmail): void {
    mail_notice($oldEmail, 'Your byereviews email address was changed', 'Your email address was changed.',
        ['Hi ' . eh(first_name($cust['name'])) . ', the login email of your byereviews account was changed. All further emails go to the new address.'],
        null, 'Wasn\'t you? Contact us right away at <a href="mailto:' . TEAM_EMAIL . '" style="color:#151515">' . TEAM_EMAIL . '</a>.',
        [['Old', eh($oldEmail)], ['New', eh($cust['email'])], ['When', mail_when()]]);
}

function mail_password_changed(array $cust): void {
    mail_notice($cust['email'], 'Your byereviews password was changed', 'Your password was changed.',
        ['Hi ' . eh(first_name($cust['name'])) . ', the password of your byereviews account was just changed. Other devices were logged out.'],
        ['Reset password', SITE_URL . '/login/?forgot=1'], 'Wasn\'t you? Reset your password now and contact us at <a href="mailto:' . TEAM_EMAIL . '" style="color:#151515">' . TEAM_EMAIL . '</a>.',
        [['When', mail_when()], ['Device', eh(device_label())]]);
}

function mail_deletion_requested(array $cust): void {
    mail_notice($cust['email'], 'Deletion request received', 'Deletion request received.',
        ['Hi ' . eh(first_name($cust['name'])) . ', we\'ve received your request to delete your byereviews account.',
         'Open orders and invoices must be kept for legal reasons; your personal data is deleted as soon as that\'s no longer required. We\'ll confirm by email once it\'s done.'],
        null, 'Changed your mind? Just reply to this email.',
        [['Requested on', eh(date('j M Y', strtotime($cust['deletionRequestedAt'] ?? 'now')))], ['Account', eh($cust['email'])]]);
    mail_team('Account deletion requested – ' . $cust['email'], 'Deletion request.',
        m_box([['Customer', eh($cust['name'])], ['Email', eh($cust['email'])], ['Orders', eh(implode(', ', $cust['orders'] ?? []) ?: '—')]]), $cust['email']);
}

// ---------- team emails (same branding, no sign-off band) ----------

function mail_team(string $subject, string $title, string $inner, string $replyTo = '', string $meta = '// team'): void {
    send_branded(TEAM_EMAIL, $subject, m_h1('', $title) . $inner, $meta, $subject, $replyTo, false);
}

function mail_team_new_order(array $order): void {
    $c = $order['customer']; $b = $order['business']; $cur = $order['currency'];
    $t = totals($order['reviews']);
    $inner = m_box([['Name', eh($c['name'])], ['Email', '<a href="mailto:' . eh($c['email']) . '" style="color:#151515">' . eh($c['email']) . '</a>'], ['Company', eh($c['company'] ?: '—')],
            ['Phone', eh($c['phone'] ?: '—')], ['Address', eh(trim($c['street'] . ', ' . $c['city'] . ', ' . $c['country'], ', ')) ?: '—']], 'Customer')
        . m_box([['Business', eh($b['name'] ?: '—')], ['Address', eh($b['address'] ?: '—')], ['Rating at order', $b['rating'] !== null ? eh(number_format((float)$b['rating'], 1)) . ' ★ (' . (int)$b['reviewCount'] . ')' : '—']], 'Google profile')
        . m_reviews($order['reviews'], fn($r) => price_note($r, $cur))
        . m_box([['Potential total', eh(money($t['total'], $cur)), true]])
        . email_button(SITE_URL . '/admin/#' . $order['id'], 'Open in admin');
    mail_team("New order {$order['id']} – {$c['name']}", 'New order ' . $order['id'], $inner, $c['email'], '// ' . $order['id']);
}

function mail_customer_message(array $order, string $text): void {
    $c = $order['customer'];
    $inner = m_p('<strong style="color:#151515">' . eh($c['name']) . '</strong> &lt;' . eh($c['email']) . '&gt; wrote about ' . eh($order['id']) . ':')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px"><tr><td style="background:#151515;color:#FFFFFF;border-radius:18px;padding:16px 18px;font-size:15px;line-height:1.6">' . nl2br(eh($text)) . '</td></tr></table>'
        . email_button(SITE_URL . '/admin/#' . $order['id'], 'Reply in admin');
    mail_team("Support message {$order['id']} – {$c['name']}", 'New support message', $inner, $c['email'], '// ' . $order['id']);
}

function mail_team_order_cancelled(array $order, string $reason = ''): void {
    $c = $order['customer'];
    $inv = invoice($order);
    $inner = m_p(eh($c['name']) . ' cancelled order ' . eh($order['id']) . ' in the dashboard. Open reviews were set to cancelled – tell the removal partner to stop.')
        . ($reason !== '' ? m_box([[nl2br(eh($reason)), '']], 'Reason') : '')
        . m_box([['Removed before cancelling', (string)$inv['n']], ['Still billable', eh(money($inv['total'], $order['currency'])), true]])
        . email_button(SITE_URL . '/admin/#' . $order['id'], 'Open in admin');
    mail_team("Order {$order['id']} cancelled by customer", 'Order cancelled by customer', $inner, $c['email'], '// ' . $order['id']);
}
