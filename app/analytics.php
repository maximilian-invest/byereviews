<?php
// First-party funnel tracking (no cookies, no IP stored) + analytics for the admin panel.
declare(strict_types=1);

const FUNNEL = ['visit' => 'Visited order page', 'search' => 'Started search', 'profile' => 'Selected profile', 'review' => 'Selected ≥ 1 review',
    'contact' => 'Entered contact', 'submit' => 'Submitted order', 'paid' => 'Paid'];

function track_event(array $e): void {
    $line = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(data_dir('events') . '/' . date('Y-m-d') . '.jsonl', $line, FILE_APPEND | LOCK_EX);
}

/** POST /order.php?a=track  {sid, ev, src, region, land?, sel?, place?} */
function action_track(): void {
    $d = json_body();
    if (!rate_ok('track', 600)) json_out(['ok' => true]);
    $ev = (string)($d['ev'] ?? '');
    if (!isset(FUNNEL[$ev]) || $ev === 'paid') json_out(['ok' => true]);
    $e = ['t' => time(), 'sid' => preg_replace('/[^a-z0-9]/', '', strtolower((string)($d['sid'] ?? ''))) ?: 'anon', 'ev' => $ev,
        'src' => in_array($d['src'] ?? '', ['ads', 'google', 'blog', 'direct', 'instagram', 'other'], true) ? $d['src'] : 'other',
        'country' => country_name(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($d['region'] ?? '')), 0, 2)))];
    $land = substr(preg_replace('~[^a-z0-9/_.-]~', '', strtolower((string)($d['land'] ?? ''))) ?? '', 0, 120);
    if ($land !== '') $e['land'] = $land;
    if (in_array($ev, ['review', 'contact', 'submit'], true) && isset($d['sel'])) $e['sel'] = max(0, min(500, (int)$d['sel']));
    if (request_is_admin()) $e['int'] = 1;   // own tests while logged in to the admin: shown, but flagged and never alerted
    if ($ev === 'profile' && is_array($d['place'] ?? null)) {
        $p = $d['place'];
        $e['place'] = ['id' => clean($p['id'] ?? '', 200), 'name' => clean($p['name'] ?? '', 200), 'country' => clean($p['country'] ?? '', 80),
            'rating' => is_numeric($p['rating'] ?? null) ? (float)$p['rating'] : null, 'count' => (int)($p['count'] ?? 0),
            'low' => (int)($p['low'] ?? 0), 'mapsUrl' => clean($p['mapsUrl'] ?? '', 500)];
    }
    if ($ev === 'profile' && isset($d['low']) && !isset($e['place'])) $e['low'] = (int)$d['low'];
    track_event($e);
    if ($ev === 'profile' && !empty($e['place']['id']) && empty($e['int'])) {
        register_shutdown_function(function () use ($e) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try { alert_profile_check($e); } catch (Throwable $x) { log_event('check alert failed: ' . $x->getMessage()); }
        });
    }
    json_out(['ok' => true]);
}

/** Reads an existing session cookie (never creates one – the site sets no cookies for visitors). */
function request_is_admin(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) return !empty($_SESSION['admin']);
    if (empty($_COOKIE['br_session'])) return false;
    session_name('br_session');
    @session_start(['read_and_close' => true]);
    return !empty($_SESSION['admin']);
}

const SRC_NAMES = ['ads' => 'Google Ads', 'google' => 'Google search', 'blog' => 'Blog article', 'direct' => 'Direct', 'instagram' => 'Instagram', 'other' => 'Other'];

/** Email to the team when someone checks a Google profile on the order page (once per profile per 6 h). */
function alert_profile_check(array $e): void {
    if (!config('check_alerts', true)) return;
    $p = $e['place'];
    $key = 'check_alert|' . $p['id'];
    if (cache_get($key, 6 * 3600)) return;
    cache_put($key, ['t' => time()]);
    $maps = $p['mapsUrl'] ?: 'https://www.google.com/maps/search/' . rawurlencode($p['name']);
    $inner = m_p('Someone just looked up this business on the order page. No order yet – if they don\'t continue, this is a warm lead.')
        . m_box([['Business', eh($p['name'])], ['Country', eh($p['country'] ?: '—')],
            ['Rating', $p['rating'] !== null ? eh(number_format((float)$p['rating'], 1)) . ' ★ · ' . (int)$p['count'] . ' reviews' : '—'],
            ['1–3★ reviews with text', (string)(int)$p['low']],
            ['Came from', eh(SRC_NAMES[$e['src']] ?? $e['src'])], ['Landing page', eh($e['land'] ?? '—')], ['Visitor region', eh($e['country'] ?: '—')]], 'Profile check')
        . email_button($maps, 'Open Google profile')
        . email_button(SITE_URL . '/admin/#checks', 'All checks in admin');
    mail_team('Profile check: ' . $p['name'] . ($p['country'] ? ' (' . $p['country'] . ')' : ''), 'New profile check', $inner, '', '// profile check');
}

/** Every checked Google profile (one row per visitor session + profile) for the admin "Checks" view. */
function checks_data(int $days): array {
    $now = time();
    if ($days > 0) $from = strtotime(date('Y-m-d', $now - ($days - 1) * 86400));
    else { $files = glob(data_dir('events') . '/*.jsonl') ?: []; sort($files); $from = $files ? strtotime(basename($files[0], '.jsonl')) : $now; }
    $events = load_events($from, $now + 1);
    $steps = array_keys(FUNNEL);
    $sess = [];
    foreach ($events as $e) {
        $x = &$sess[$e['sid']];
        $x['step'] = max($x['step'] ?? 0, (int)array_search($e['ev'], $steps, true));
        $x['sel'] = max($x['sel'] ?? 0, (int)($e['sel'] ?? 0));
        $x['src'] ??= $e['src'];
        $x['land'] ??= $e['land'] ?? null;
        $x['country'] ??= $e['country'] ?: null;
        if (!empty($e['int'])) $x['int'] = true;
        unset($x);
    }
    $byPlace = [];
    foreach (store_list('order') as $o) if (!empty($o['business']['placeId'])) $byPlace[$o['business']['placeId']][] = $o;
    $rows = [];
    foreach ($events as $e) {
        if ($e['ev'] !== 'profile' || empty($e['place']['id'])) continue;
        $k = $e['sid'] . '|' . $e['place']['id'];
        if (isset($rows[$k])) { $rows[$k]['t'] = $e['t']; continue; }
        $p = $e['place']; $ss = $sess[$e['sid']];
        $order = null;
        foreach ($byPlace[$p['id']] ?? [] as $o) if (strtotime($o['createdAt']) >= $e['t'] - 3600) { $order = $o; break; }
        $reached = $order ? 'submit' : $steps[$ss['step']] ?? 'profile';
        $rows[$k] = ['name' => $p['name'], 'placeId' => $p['id'], 'country' => $p['country'] ?: '', 'visitor' => $ss['country'] ?? '',
            'rating' => $p['rating'], 'count' => $p['count'], 'low' => $p['low'], 'mapsUrl' => $p['mapsUrl'] ?: 'https://www.google.com/maps/search/' . rawurlencode($p['name']),
            'first' => $e['t'], 't' => $e['t'], 'src' => $ss['src'] ?? 'other', 'srcName' => SRC_NAMES[$ss['src'] ?? 'other'] ?? 'Other', 'land' => $ss['land'] ?? '',
            'reachedKey' => $reached, 'reached' => FUNNEL[$reached] ?? '', 'sel' => $ss['sel'] ?? 0,
            'orderId' => $order['id'] ?? null, 'internal' => !empty($ss['int'])];
    }
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => $b['t'] <=> $a['t']);
    $ext = array_filter($rows, fn($r) => !$r['internal']);
    return ['days' => $days, 'rows' => $rows, 'stats' => ['total' => count($ext), 'ordered' => count(array_filter($ext, fn($r) => $r['orderId'])),
        'hot' => count(array_filter($ext, fn($r) => !$r['orderId'])), 'companies' => count(array_unique(array_map(fn($r) => $r['placeId'], $ext)))]];
}

function load_events(int $from, int $to): array {
    $out = [];
    for ($day = strtotime(date('Y-m-d', $from)); $day <= $to; $day += 86400) {
        $f = data_dir('events') . '/' . date('Y-m-d', $day) . '.jsonl';
        if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $e = json_decode($l, true);
            if (is_array($e) && $e['t'] >= $from && $e['t'] < $to) $out[] = $e;
        }
    }
    return $out;
}

function serpapi_usage(): array {
    $key = (string)config('serpapi_key', '');
    if ($key === '') return ['used' => 0, 'max' => 0, 'configured' => false];
    if ($c = cache_get('serpapi_account', 3600)) return $c;
    $r = http_json('GET', 'https://serpapi.com/account.json?api_key=' . urlencode($key));
    $d = $r['data'] ?? [];
    $out = ['used' => (int)($d['this_month_usage'] ?? 0), 'max' => (int)($d['searches_per_month'] ?? 0), 'configured' => true];
    cache_put('serpapi_account', $out);
    return $out;
}

/** Everything the Analytics view needs for the last $days days (0 = all time) plus the previous period for deltas. */
function analytics_data(int $days): array {
    $now = time();
    $orders = store_list('order');
    $firstT = min(array_merge([$now], array_map(fn($o) => strtotime($o['createdAt']), $orders)));
    if ($days <= 0) {
        $evFiles = glob(data_dir('events') . '/*.jsonl') ?: [];
        if ($evFiles) { sort($evFiles); $firstT = min($firstT, strtotime(basename($evFiles[0], '.jsonl'))); }
        $days = max(7, (int)ceil(($now - $firstT) / 86400));
    }
    $from = strtotime(date('Y-m-d', $now - ($days - 1) * 86400));
    $prevFrom = $from - $days * 86400;
    $events = load_events($prevFrom, $now + 1);
    $cur = array_values(array_filter($events, fn($e) => $e['t'] >= $from));
    $prev = array_values(array_filter($events, fn($e) => $e['t'] < $from));

    $inRange = fn(?string $iso, int $a, int $b) => $iso && ($t = strtotime($iso)) >= $a && $t < $b;
    $ordersIn = fn(int $a, int $b) => array_values(array_filter($orders, fn($o) => $inRange($o['createdAt'], $a, $b)));
    $paidIn = fn(int $a, int $b) => array_values(array_filter($orders, fn($o) => ($o['payment']['status'] ?? '') === 'paid' && $inRange($o['payment']['paidAt'] ?? null, $a, $b)));
    $uniqProfiles = fn(array $ev) => count(array_unique(array_map(fn($e) => $e['sid'] . '|' . ($e['place']['id'] ?? ''), array_filter($ev, fn($e) => $e['ev'] === 'profile'))));
    $revenue = fn(array $os) => array_sum(array_map(fn($o) => (int)($o['payment']['amount'] ?? invoice($o)['total']), $os));
    $removal = function (array $os) { $rem = 0; $fin = 0; foreach ($os as $o) foreach ($o['reviews'] as $r) { if ($r['status'] === 'removed') { $rem++; $fin++; } elseif ($r['status'] === 'not_eligible') $fin++; } return $fin ? $rem / $fin : null; };

    $C = $uniqProfiles($cur); $Cp = $uniqProfiles($prev);
    $O = count($ordersIn($from, $now + 1)); $Op = count($ordersIn($prevFrom, $from));
    $R = $revenue($paidIn($from, $now + 1)); $Rp = $revenue($paidIn($prevFrom, $from));
    $RR = $removal($ordersIn($from, $now + 1)); $RRp = $removal($ordersIn($prevFrom, $from));

    // per-day series
    $dayIdx = fn(int $t) => (int)floor(($t - $from) / 86400);
    $checks = array_fill(0, $days, 0); $ordD = array_fill(0, $days, 0); $revD = array_fill(0, $days, 0);
    $seen = [];
    foreach ($cur as $e) if ($e['ev'] === 'profile') { $k = $e['sid'] . '|' . ($e['place']['id'] ?? ''); if (!isset($seen[$k])) { $seen[$k] = 1; $i = $dayIdx($e['t']); if ($i >= 0 && $i < $days) $checks[$i]++; } }
    foreach ($ordersIn($from, $now + 1) as $o) { $i = $dayIdx(strtotime($o['createdAt'])); if ($i >= 0 && $i < $days) $ordD[$i]++; }
    foreach ($paidIn($from, $now + 1) as $o) { $i = $dayIdx(strtotime($o['payment']['paidAt'])); if ($i >= 0 && $i < $days) $revD[$i] += (int)($o['payment']['amount'] ?? invoice($o)['total']); }

    // funnel: unique sessions per step (paid = paid orders created in range)
    $sidsBy = [];
    foreach ($cur as $e) $sidsBy[$e['ev']][$e['sid']] = 1;
    $funnel = [];
    foreach (FUNNEL as $k => $label) $funnel[] = [$label, $k === 'paid' ? count(array_filter($ordersIn($from, $now + 1), fn($o) => ($o['payment']['status'] ?? '') === 'paid')) : count($sidsBy[$k] ?? [])];

    // weekly revenue: paid vs open (removed but unpaid, by order date)
    $wk = max(2, min(13, (int)ceil($days / 7)));
    $wStart = $now - $wk * 7 * 86400;
    $weeks = array_fill(0, $wk, ['paid' => 0, 'open' => 0]);
    foreach ($orders as $o) {
        $inv = invoice($o)['total'];
        if (($o['payment']['status'] ?? '') === 'paid' && !empty($o['payment']['paidAt'])) { $i = (int)floor((strtotime($o['payment']['paidAt']) - $wStart) / (7 * 86400)); if ($i >= 0 && $i < $wk) $weeks[$i]['paid'] += (int)($o['payment']['amount'] ?? $inv); }
        elseif ($inv > 0) { $i = (int)floor((strtotime($o['createdAt']) - $wStart) / (7 * 86400)); if ($i >= 0 && $i < $wk) $weeks[$i]['open'] += $inv; }
    }

    // countries + sources (unique sessions that visited)
    $sessCountry = []; $sessSrc = [];
    foreach ($cur as $e) { $sessCountry[$e['sid']] ??= ($e['country'] ?: 'Other'); $sessSrc[$e['sid']] ??= $e['src']; }
    $cc = array_count_values(array_map(fn($c) => $c ?: 'Other', $sessCountry)); arsort($cc);
    $sc = array_count_values($sessSrc);

    // reviews donut + avg days to removal (orders in range)
    $st = ['submitted' => 0, 'in_progress' => 0, 'removed' => 0, 'not_eligible' => 0]; $durs = [];
    foreach ($ordersIn($from, $now + 1) as $o) foreach ($o['reviews'] as $r) {
        $st[$r['status']] = ($st[$r['status']] ?? 0) + 1;
        if ($r['status'] === 'removed' && !empty($r['updatedAt'])) $durs[] = (strtotime($r['updatedAt']) - strtotime($o['createdAt'])) / 86400;
    }

    // recently checked profiles (latest 12)
    $steps = array_keys(FUNNEL);
    $maxStep = [];
    foreach ($cur as $e) $maxStep[$e['sid']] = max($maxStep[$e['sid']] ?? 0, array_search($e['ev'], $steps, true));
    $recent = [];
    foreach (array_reverse($cur) as $e) {
        if ($e['ev'] !== 'profile' || empty($e['place']['id'])) continue;
        $k = $e['sid'] . '|' . $e['place']['id'];
        if (isset($recent[$k])) continue;
        $recent[$k] = $e + ['reached' => $steps[$maxStep[$e['sid']] ?? 2]];
        if (count($recent) >= 12) break;
    }

    return ['days' => $days, 'from' => $from, 'hasData' => (bool)$events || (bool)$orders,
        'kpi' => ['checks' => [$C, $Cp], 'orders' => [$O, $Op], 'conv' => [$C ? $O / $C : 0, $Cp ? $Op / $Cp : 0], 'revenue' => [$R, $Rp], 'removal' => [$RR, $RRp]],
        'series' => ['checks' => $checks, 'orders' => $ordD, 'revenue' => $revD],
        'funnel' => $funnel, 'weeks' => $weeks, 'countries' => $cc, 'sources' => $sc, 'reviews' => $st,
        'avgDays' => $durs ? array_sum($durs) / count($durs) : null,
        'recent' => array_values(array_map(fn($e) => ['name' => $e['place']['name'], 'country' => $e['place']['country'] ?: $e['country'], 'rating' => $e['place']['rating'],
            'count' => $e['place']['count'], 'low' => $e['place']['low'], 'reached' => FUNNEL[$e['reached']] ?? '', 'ordered' => $e['reached'] === 'submit' || $e['reached'] === 'paid',
            'mapsUrl' => $e['place']['mapsUrl'] ?: 'https://www.google.com/maps/search/' . rawurlencode($e['place']['name']), 't' => $e['t']], $recent)),
        'serp' => serpapi_usage()];
}
