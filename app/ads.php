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
        foreach (['orderName', 'paidName'] as $k) if (isset($d[$k])) $s[$k] = clean($d[$k], 100) ?: ADS_DEFAULTS[$k];
        store_put('settings', 'ads', $s);
        log_event('ads settings: click IDs ' . ($s['clickIds'] ? 'on' : 'off'));
    }
    json_out(['ok' => true, 'ads' => ads_settings(), 'stats' => ads_stats()]);
}

/** CSV for Google Ads → Goals → Conversions → Uploads (template "Conversions from clicks").
 *  kind=order: every order that came from an ad click (value = list price of the submitted reviews).
 *  kind=paid:  paid orders (value = amount actually paid). Clicks older than 90 days are skipped (Google's limit). */
function action_admin_ads_export(): void {
    admin_required();
    $kind = ($_GET['kind'] ?? '') === 'paid' ? 'paid' : 'order';
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
    header('Content-Disposition: attachment; filename="google-ads-' . $kind . '-conversions-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, 'Parameters:TimeZone=' . $s['timezone'] . "\n");
    fputcsv($f, ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency'], ',', '"', '');
    foreach ($rows as $r) fputcsv($f, $r, ',', '"', '');
    fclose($f);
    exit;
}
