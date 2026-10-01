<?php
// Google Ads conversions without cookies: the Google click ID (gclid) from the ad's landing URL is
// kept in memory by the site (no cookie, no local storage), sent along with the order and stored
// with it. The admin exports orders / payments as a CSV for Google Ads "offline conversion import".
// Off by default: turned on in Admin → Settings (settings/ads.json).
declare(strict_types=1);

const ADS_DEFAULTS = ['clickIds' => false, 'orderName' => 'byereviews order', 'paidName' => 'byereviews payment', 'timezone' => 'Europe/Vienna'];

function ads_settings(): array {
    return (store_get('settings', 'ads') ?? []) + ADS_DEFAULTS;
}

/** Click IDs + UTM tags from the order request, or null if the visitor didn't come from a campaign. */
function ads_capture(array $d): ?array {
    $a = is_array($d['ads'] ?? null) ? $d['ads'] : [];
    $out = [];
    foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
        $v = (string)($a[$k] ?? '');
        if (preg_match('/^[A-Za-z0-9_\-]{8,300}$/', $v)) $out[$k] = $v;
    }
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term'] as $k) {
        $v = clean($a[$k] ?? '', 120);
        if ($v !== '') $out[$k] = $v;
    }
    if (!$out) return null;
    $landing = clean($a['landing'] ?? '', 200);
    if (preg_match('#^/[A-Za-z0-9/_\-.]*$#', $landing)) $out['landing'] = $landing;
    $out['at'] = date('c');
    return $out;
}

function ads_stats(): array {
    $n = ['orders' => 0, 'paid' => 0, 'value' => 0];
    foreach (store_list('order') as $o) {
        if (empty($o['ads']['gclid'])) continue;
        $n['orders']++;
        if (($o['payment']['status'] ?? '') === 'paid') { $n['paid']++; $n['value'] += (float)($o['payment']['amount'] ?? invoice($o)['total']); }
    }
    return $n;
}

/** GET: settings + counts. POST {clickIds, orderName, paidName}: save. */
function action_admin_ads(): void {
    admin_required();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_body();
        $s = ads_settings();
        if (array_key_exists('clickIds', $d)) $s['clickIds'] = (bool)$d['clickIds'];
        if ($s['clickIds'] && empty($s['feedToken'])) $s['feedToken'] = bin2hex(random_bytes(16));
        foreach (['orderName', 'paidName'] as $k) if (isset($d[$k])) $s[$k] = clean($d[$k], 100) ?: ADS_DEFAULTS[$k];
        store_put('settings', 'ads', $s);
        log_event('ads settings: click IDs ' . ($s['clickIds'] ? 'on' : 'off'));
    }
    $s = ads_settings();
    $feed = $s['clickIds'] && !empty($s['feedToken']) ? ['user' => 'googleads', 'password' => $s['feedToken'],
        'order' => 'https://byereviews.com/order.php?a=ads-feed&kind=order&file=orders.csv', 'paid' => 'https://byereviews.com/order.php?a=ads-feed&kind=paid&file=payments.csv'] : null;
    unset($s['feedToken']);
    json_out(['ok' => true, 'ads' => $s, 'stats' => ads_stats(), 'feed' => $feed]);
}

/** CSV for Google Ads → Goals → Conversions → Uploads (template "Conversions from clicks").
 *  kind=order: every order that came from an ad click (value = list price of the submitted reviews).
 *  kind=paid:  paid orders (value = amount actually paid). Clicks older than 90 days are skipped (Google's limit). */
function ads_csv(string $kind, bool $download): void {
    $s = ads_settings();
    $tz = new DateTimeZone($s['timezone']);
    $rows = [];
    foreach (store_list('order') as $o) {
        $gclid = $o['ads']['gclid'] ?? '';
        if ($gclid === '' || ($o['status'] ?? '') === 'cancelled') continue;
        if ($kind === 'paid') {
            if (($o['payment']['status'] ?? '') !== 'paid' || empty($o['payment']['paidAt'])) continue;
            $at = $o['payment']['paidAt'];
            $value = (float)($o['payment']['amount'] ?? invoice($o)['total']);
        } else {
            $at = $o['createdAt'];
            $value = (float)totals($o['reviews'])['total'];
        }
        if (strtotime($o['ads']['at'] ?? $o['createdAt']) < time() - 89 * 86400) continue;
        $t = (new DateTime($at))->setTimezone($tz)->format('Y-m-d H:i:s');
        $rows[] = [$gclid, $kind === 'paid' ? $s['paidName'] : $s['orderName'], $t, number_format($value, 2, '.', ''), $o['currency'] ?? 'USD'];
    }
    usort($rows, fn($a, $b) => strcmp($a[2], $b[2]));
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store');
    if ($download) header('Content-Disposition: attachment; filename="google-ads-' . $kind . '-conversions-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, 'Parameters:TimeZone=' . $s['timezone'] . "\n");
    fputcsv($f, ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency'], ',', '"', '');
    foreach ($rows as $r) fputcsv($f, $r, ',', '"', '');
    fclose($f);
    exit;
}

function action_admin_ads_export(): void {
    admin_required();
    ads_csv(($_GET['kind'] ?? '') === 'paid' ? 'paid' : 'order', true);
}

/** Same CSV for Google Ads scheduled uploads (source "HTTPS"): HTTP Basic auth with user "googleads"
 *  and the feed password shown in Admin → Settings. Only while click IDs are switched on. */
function action_ads_feed(): void {
    $s = ads_settings();
    $user = $_SERVER['PHP_AUTH_USER'] ?? ''; $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($user === '' && stripos($h, 'basic ') === 0) [$user, $pass] = array_pad(explode(':', (string)base64_decode(substr($h, 6)), 2), 2, '');
    $token = (string)($s['feedToken'] ?? '');
    if (!$s['clickIds'] || $token === '' || !rate_ok('ads_feed', 60)) fail(404, 'not_found');
    if ($user !== 'googleads' || !hash_equals($token, $pass)) {
        header('WWW-Authenticate: Basic realm="byereviews conversions"');
        log_event('ads feed: auth failed (' . ($user === '' ? 'no credentials received' : 'wrong ' . ($user !== 'googleads' ? 'user' : 'password, length ' . strlen($pass))) . ')');
        fail(401, $user === '' ? 'no_credentials' : 'unauthorized');
    }
    ads_csv(($_GET['kind'] ?? '') === 'paid' ? 'paid' : 'order', false);
}
