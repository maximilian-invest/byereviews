<?php
// Customer account: profile, email change (confirmed by link), password change, reset by link,
// log out everywhere, deletion request. Sessions carry a version that password changes invalidate.
declare(strict_types=1);

const RESET_TTL = 3600;        // password reset link: 1 h
const EMAIL_TTL = 86400;       // email change confirmation: 24 h
const MIN_PASSWORD = 10;

// ---------- one-time tokens (only the hash is stored) ----------

function token_create(string $type, array $data, int $ttl): string {
    $token = bin2hex(random_bytes(24));
    store_put('tokens', hash('sha256', $token), $data + ['type' => $type, 'exp' => time() + $ttl]);
    return $token;
}

function token_get(string $type, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $t = store_get('tokens', hash('sha256', $token));
    return $t && $t['type'] === $type && $t['exp'] > time() ? $t : null;
}

function token_delete(string $token): void {
    @unlink(store_path('tokens', hash('sha256', $token)));
}

// ---------- sessions ----------

function login_customer(array $cust): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['email'] = $cust['email'];
    $_SESSION['sv'] = (int)($cust['sessionVersion'] ?? 0);
}

/** Customer profile as shown in Settings (falls back to the contact data of the latest order). */
function customer_profile(array $cust): array {
    $p = $cust['profile'] ?? null;
    if (!$p) {
        $last = ($cust['orders'] ?? []) ? store_get('order', end($cust['orders'])) : null;
        $c = $last['customer'] ?? [];
        $p = ['company' => $c['company'] ?? '', 'phone' => $c['phone'] ?? '', 'street' => $c['street'] ?? '', 'city' => $c['city'] ?? '', 'country' => $c['country'] ?? ''];
    }
    return ['email' => $cust['email'], 'name' => $cust['name']] + $p + [
        'pendingEmail' => (($cust['pendingEmail']['exp'] ?? 0) > time()) ? $cust['pendingEmail']['email'] : '',
        'deletionRequestedAt' => $cust['deletionRequestedAt'] ?? null,
    ];
}

function require_customer(): array {
    $cust = current_customer();
    if (!$cust) fail(401, 'not_logged_in');
    return $cust;
}

function check_new_password(string $pw): void {
    if (mb_strlen($pw) < MIN_PASSWORD) fail(400, 'password_too_short');
}

// ---------- actions ----------

function action_account_profile(): void {
    $d = json_body();
    $cust = require_customer();
    $name = clean($d['name'] ?? '', 120);
    if ($name === '') fail(400, 'name_missing');
    $profile = ['company' => clean($d['company'] ?? '', 200), 'phone' => clean($d['phone'] ?? '', 60), 'street' => clean($d['street'] ?? '', 200),
        'city' => clean($d['city'] ?? '', 200), 'country' => clean($d['country'] ?? '', 80)];
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) use ($name, $profile) {
        if (!$c) return null;
        $c['name'] = $name; $c['profile'] = $profile;
        return $c;
    });
    json_out(['ok' => true, 'account' => customer_profile($cust)]);
}

function action_account_password(): void {
    $d = json_body();
    $cust = require_customer();
    if (!rate_ok('account_pw', 20, 900)) fail(429, 'too_many_requests');
    if (!password_verify((string)($d['current'] ?? ''), $cust['passwordHash'])) fail(401, 'wrong_password');
    $new = (string)($d['password'] ?? '');
    check_new_password($new);
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) use ($new) {
        $c['passwordHash'] = password_hash($new, PASSWORD_DEFAULT);
        $c['sessionVersion'] = (int)($c['sessionVersion'] ?? 0) + 1; // logs out every other device
        return $c;
    });
    login_customer($cust);
    mail_password_changed($cust);
    json_out(['ok' => true]);
}

function action_account_logout_all(): void {
    json_body();
    $cust = require_customer();
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) { $c['sessionVersion'] = (int)($c['sessionVersion'] ?? 0) + 1; return $c; });
    login_customer($cust); // this device stays logged in
    json_out(['ok' => true]);
}

/** Start an email change: confirmation link goes to the new address. */
function action_account_email(): void {
    $d = json_body();
    $cust = require_customer();
    if (!rate_ok('account_email', 10)) fail(429, 'too_many_requests');
    $new = strtolower(clean($d['email'] ?? '', 200));
    if (!is_email($new)) fail(400, 'invalid_email');
    if ($new === $cust['email']) fail(400, 'same_email');
    if (!password_verify((string)($d['password'] ?? ''), $cust['passwordHash'])) fail(401, 'wrong_password');
    if (get_customer($new)) fail(409, 'email_taken');
    $token = token_create('email', ['email' => $cust['email'], 'newEmail' => $new], EMAIL_TTL);
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) use ($new) { $c['pendingEmail'] = ['email' => $new, 'exp' => time() + EMAIL_TTL]; return $c; });
    mail_confirm_email($cust, $new, SITE_URL . '/order.php?a=confirm-email&t=' . $token);
    json_out(['ok' => true, 'account' => customer_profile($cust)]);
}

function action_account_email_cancel(): void {
    json_body();
    $cust = require_customer();
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) { unset($c['pendingEmail']); return $c; });
    json_out(['ok' => true, 'account' => customer_profile($cust)]);
}

/** GET link from the confirmation email: moves the account to the new address. */
function action_confirm_email(): void {
    $token = (string)($_GET['t'] ?? '');
    $t = token_get('email', $token);
    $cust = $t ? get_customer($t['email']) : null;
    if (!$t || !$cust || ($cust['pendingEmail']['email'] ?? '') !== $t['newEmail'] || get_customer($t['newEmail'])) {
        header('Location: /dashboard/?email=invalid'); exit;
    }
    $old = $cust['email']; $new = $t['newEmail'];
    unset($cust['pendingEmail']);
    $cust['email'] = $new;
    store_put('customers', customer_key($new), $cust);
    @unlink(store_path('customers', customer_key($old)));
    foreach ($cust['orders'] ?? [] as $oid) {
        store_update('order', $oid, function (?array $o) use ($new) { if (!$o) return null; $o['customer']['email'] = $new; return $o; });
    }
    token_delete($token);
    log_event("account email changed $old -> $new");
    mail_email_changed($cust, $old);
    login_customer($cust);
    header('Location: /dashboard/?email=changed'); exit;
}

/** Forgot password: always the same answer; the link goes to /login/?reset=… */
function action_reset(): void {
    $d = json_body();
    if (!rate_ok('reset', 5)) fail(429, 'too_many_requests');
    $email = strtolower(clean($d['email'] ?? '', 200));
    $cust = is_email($email) ? get_customer($email) : null;
    if ($cust) send_reset_link($cust);
    json_out(['ok' => true]);
}

function send_reset_link(array $cust): void {
    $token = token_create('reset', ['email' => $cust['email']], RESET_TTL);
    mail_reset_link($cust, SITE_URL . '/login/?reset=' . $token);
}

function action_reset_check(): void {
    $t = token_get('reset', (string)($_GET['t'] ?? ''));
    json_out(['ok' => (bool)$t, 'email' => $t['email'] ?? '']);
}

function action_reset_confirm(): void {
    $d = json_body();
    if (!rate_ok('reset_confirm', 20, 900)) fail(429, 'too_many_requests');
    $token = (string)($d['token'] ?? '');
    $t = token_get('reset', $token);
    if (!$t) fail(410, 'link_expired');
    $new = (string)($d['password'] ?? '');
    check_new_password($new);
    $cust = store_update('customers', customer_key($t['email']), function (?array $c) use ($new) {
        if (!$c) return null;
        $c['passwordHash'] = password_hash($new, PASSWORD_DEFAULT);
        $c['sessionVersion'] = (int)($c['sessionVersion'] ?? 0) + 1;
        return $c;
    });
    if (!$cust) fail(410, 'link_expired');
    token_delete($token);
    login_customer($cust);
    mail_password_changed($cust);
    json_out(['ok' => true, 'customer' => ['email' => $cust['email'], 'name' => $cust['name']], 'account' => customer_profile($cust), 'orders' => customer_orders($cust)]);
}

function action_account_delete(): void {
    $d = json_body();
    $cust = require_customer();
    if (!password_verify((string)($d['password'] ?? ''), $cust['passwordHash'])) fail(401, 'wrong_password');
    $cust = store_update('customers', customer_key($cust['email']), function (?array $c) { $c['deletionRequestedAt'] = $c['deletionRequestedAt'] ?? date('c'); return $c; });
    log_event("account deletion requested {$cust['email']}");
    mail_deletion_requested($cust);
    json_out(['ok' => true, 'account' => customer_profile($cust)]);
}
