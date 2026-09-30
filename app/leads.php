<?php
// Lead Finder for the admin panel: small UK/US businesses with a fresh bad Google review.
// Google Places API (New): one Text Search call returns 20 places incl. their (max. 5) reviews – no Place Details calls needed.
// A run is a queue of tasks that the admin page works through with short requests (admin-leads-step),
// so no request runs longer than ~20 s and the page can show progress and cancel.
declare(strict_types=1);

require_once __DIR__ . '/leads_catalog.php';

const LEADS_TEXT_MASK = 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.businessStatus,places.websiteUri,places.googleMapsUri,places.nationalPhoneNumber,places.internationalPhoneNumber,places.reviews,nextPageToken';
const LEADS_PAGES = 3;          // Text Search pages per query (20 places each, max. 60)
const LEADS_KEEP_DAYS = 30;     // Google Maps terms: Places content is not kept longer; only place ID + own status/notes stay
const LEADS_STEP_SECONDS = 20;  // work per request
const LEADS_CRIT = ['revMin' => 5, 'revMax' => 30, 'ratingMax' => 4.8, 'badMin' => 1, 'badMax' => 2, 'maxAge' => 28, 'skip' => 7];
const LEAD_STATUSES = ['New', 'Followed', 'Contacted', 'Won', 'Ignored'];
// chains / franchises – a single branch can't decide anything, so they are never leads (matched as whole words in the name)
const LEADS_CHAINS = ['five guys', "mcdonald's", 'mcdonalds', 'kfc', 'subway', "domino's", 'dominos', 'pizza hut', "papa john's", 'papa johns', 'burger king',
    "wendy's", 'taco bell', 'chipotle', "dunkin'", 'dunkin', 'starbucks', 'costa coffee', 'caffe nero', 'caffè nero', 'pret a manger', 'greggs', "nando's", 'nandos',
    'wingstop', 'popeyes', 'chick-fil-a', 'tim hortons', 'leon', 'itsu', 'wasabi', 'tortilla', 'gourmet burger kitchen', 'byron', 'wagamama', 'pizza express', 'franco manca',
    "frankie & benny's", 'harvester', 'toby carvery', 'wetherspoon', 'greene king', 'toni & guy', 'toni&guy', 'supercuts', 'great clips', 'sport clips', 'fantastic sams',
    'regis', 'rush hair', 'headmasters', 'jd sports', 'boots', 'superdrug', 'tesco', "sainsbury's", 'asda', 'aldi', 'lidl', 'co-op', 'walgreens', 'cvs', '7-eleven',
    'panera', "jersey mike's", 'jimmy john', 'firehouse subs', "arby's", 'sonic drive-in', "carl's jr", "hardee's", 'little caesars', 'jollibee', 'shake shack',
    'krispy kreme', 'baskin', 'dairy queen', 'cinnabon', 'auntie anne', 'jamba', 'smoothie king', 'tropical smoothie', 'boba guys', 'kung fu tea', 'gong cha', 'chatime',
    'coco fresh', 'sharetea', 'the alley', 'tiger sugar', 'european wax center', 'massage envy', 'hand & stone', 'drybar', 'orangetheory', 'anytime fitness',
    'planet fitness', 'puregym', 'the gym group', 'snap fitness', 'specsavers', 'vision express', 'timpson', 'mr. clean car wash', 'mister car wash', 'take 5', 'jiffy lube',
    'kwik fit', 'halfords', 'enterprise rent', 'hertz', 'avis', 'banfield', 'petsmart', 'petco', 'pets at home'];


/** No daily limit (unless set in the server config); the monthly limit is set in the admin (default = free tier). */
function leads_limits(): array {
    $monthly = (int)((store_get('leadsys', 'settings') ?? [])['monthlyLimit'] ?? config('leads_monthly_text', 930));
    return [
        'text' => ['day' => (int)config('leads_daily_text', PHP_INT_MAX), 'month' => $monthly],
        'details' => ['day' => (int)config('leads_daily_details', 30), 'month' => (int)config('leads_monthly_details', 930)],
    ];
}

// ---------- quota (counted before every paid call, stops before the free tier is used up) ----------

function leads_usage(): array {
    $u = store_get('leadsys', 'usage') ?? [];
    $m = date('Y-m'); $d = date('Y-m-d');
    $out = [];
    foreach (['text', 'details'] as $sku) $out[$sku] = ['month' => (int)($u['months'][$m][$sku] ?? 0), 'day' => (int)($u['days'][$d][$sku] ?? 0)];
    return $out;
}

/** Books one call of $sku. Returns false (and books nothing) when the daily or monthly limit is reached. */
function leads_take(string $sku): bool {
    $lim = leads_limits()[$sku];
    $ok = false;
    store_update('leadsys', 'usage', function (?array $u) use ($sku, $lim, &$ok) {
        $u = $u ?? ['months' => [], 'days' => []];
        $m = date('Y-m'); $d = date('Y-m-d');
        $month = (int)($u['months'][$m][$sku] ?? 0); $day = (int)($u['days'][$d][$sku] ?? 0);
        if ($month >= $lim['month'] || $day >= $lim['day']) return $u;
        $u['months'][$m][$sku] = $month + 1;
        $u['days'][$d][$sku] = $day + 1;
        $u['days'] = array_slice($u['days'], -40, null, true);
        $u['months'] = array_slice($u['months'], -13, null, true);
        $ok = true;
        return $u;
    });
    return $ok;
}

function leads_quota_left(string $sku): int {
    $lim = leads_limits()[$sku]; $u = leads_usage()[$sku];
    return max(0, min($lim['day'] - $u['day'], $lim['month'] - $u['month']));
}

// ---------- Google ----------

function leads_google(string $method, string $url, string $mask, ?array $body = null): array {
    $key = (string)config('google_places_key', '');
    $r = http_json($method, $url, ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $mask],
        $body === null ? null : json_encode($body), 15);
    if ($r['code'] === 200 && is_array($r['data'])) return ['ok' => true, 'data' => $r['data']];
    $status = $r['data']['error']['status'] ?? '';
    $msg = $r['data']['error']['message'] ?? $r['error'];
    log_event('leads google ' . $r['code'] . ' ' . $status . ' ' . substr((string)$msg, 0, 200));
    // wrong/missing key, API not enabled, billing off → stop the run; anything else only skips this call
    $fatal = in_array($r['code'], [401, 403], true) || ($r['code'] === 400 && stripos((string)$msg, 'api key') !== false);
    return ['ok' => false, 'fatal' => $fatal, 'error' => $status ?: ('http_' . $r['code'])];
}

function leads_mock_search(string $q): array {
    $n = abs(crc32($q));
    $places = [];
    for ($i = 0; $i < 12; $i++) {
        $places[] = ['id' => 'mock_' . md5($q . $i), 'displayName' => ['text' => ucfirst(explode(',', $q)[0]) . ' ' . ['Corner', 'House', 'Studio', 'Co.', 'Bar', 'Kitchen'][$i % 6] . ' ' . ($i + 1)],
            'formattedAddress' => ($i + 3) . ' High St, ' . trim(explode(',', $q)[1] ?? 'London'), 'rating' => [4.3, 4.9, 4.5, 3.9][($n + $i) % 4],
            'userRatingCount' => [12, 250, 22, 9, 31][($n + $i) % 5], 'businessStatus' => 'OPERATIONAL', 'websiteUri' => '', 'googleMapsUri' => 'https://maps.google.com/?cid=' . ($n + $i),
            'internationalPhoneNumber' => '+44 20 5550 ' . (($n + $i) % 9000 + 1000),
            'reviews' => [['rating' => 5, 'publishTime' => gmdate('Y-m-d\TH:i:s.123456789\Z', time() - 86400)],
                ['rating' => 1 + ($n + $i) % 3, 'publishTime' => gmdate('Y-m-d\TH:i:s\Z', time() - [2, 40, 9, 19, 75, 120][($n + $i) % 6] * 86400), 'originalText' => ['text' => 'Waited ages and the food was cold.'], 'authorAttribution' => ['displayName' => 'Sam T.']]]];
    }
    return ['ok' => true, 'data' => ['places' => $places, 'nextPageToken' => substr($q, -1) === '1' ? 'tok' : '']];
}

function leads_find_instagram(string $website): string {
    $re = '~instagram\.com/([A-Za-z0-9_.]{2,30})~i';
    $skip = ['p', 'reel', 'reels', 'explore', 'accounts', 'stories', 'tv', 'share', 'about', 'developer', 'legal', 'direct', 'instagram'];
    $pick = function (string $html) use ($re, $skip): string {
        if (!preg_match_all($re, $html, $m)) return '';
        foreach ($m[1] as $h) { $h = rtrim($h, '.'); if (!in_array(strtolower($h), $skip, true)) return '@' . $h; }
        return '';
    };
    if ($website === '') return '';
    if (stripos($website, 'instagram.com') !== false) return $pick($website);
    if (!function_exists('curl_init') || config('mock_google')) return '';
    $ch = curl_init($website);
    $buf = '';
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; byereviews-leadfinder)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf) { $buf .= $chunk; return strlen($buf) > 600000 ? 0 : strlen($chunk); }]);
    curl_exec($ch);
    curl_close($ch);
    return $pick($buf);
}

// ---------- storage ----------

function leads_settings(): array {
    $s = store_get('leadsys', 'settings') ?? [];
    $sel = [];
    foreach (['GB', 'US'] as $r) $sel[$r] = isset($s['sel'][$r]) ? leads_clean_sel($r, $s['sel'][$r]) : LEADS_DEFAULT_SEL[$r];
    return ['region' => $s['region'] ?? 'GB', 'sel' => $sel, 'crit' => ($s['crit'] ?? []) + LEADS_CRIT, 'ig' => $s['ig'] ?? true,
        'exclude' => $s['exclude'] ?? '', 'target' => (int)($s['target'] ?? 500)];
}

/** Chain / franchise branch? Built-in list + own exclude list (name), store-locator style website, or a website shared with another place of this run. */
function leads_is_chain(array $p, array &$run): bool {
    $name = ' ' . preg_replace('/\s+/', ' ', mb_strtolower((string)($p['displayName']['text'] ?? ''))) . ' ';
    $words = array_merge(LEADS_CHAINS, array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)($run['exclude'] ?? '')))));
    foreach ($words as $w) if ($w !== '' && preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($w, '/') . '($|[^\p{L}\p{N}])/u', $name)) return true;
    $web = (string)($p['websiteUri'] ?? '');
    if ($web === '') return false;
    if (preg_match('~/(locations?|stores?|restaurants?|store-?locator|find-?(us|a-store|a-location)|branches|shops?|salons?|studios?|clinics?)/[^?#]+~i', (string)parse_url($web, PHP_URL_PATH) . '/')) return true;
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($web, PHP_URL_HOST)));
    if ($host === '' || preg_match('/(facebook|instagram|linktr|business\.site|wixsite|square\.site|toasttab|order\.online|ubereats|deliveroo|just-eat|doordash|grubhub)/', $host)) return false;
    $run['hosts'][$host] = ($run['hosts'][$host] ?? 0) + 1;
    return $run['hosts'][$host] > 1;
}

function leads_clean_crit($c): array {
    $c = is_array($c) ? $c : [];
    $out = [];
    foreach (LEADS_CRIT as $k => $def) {
        $v = isset($c[$k]) && is_numeric($c[$k]) ? (float)$c[$k] : $def;
        $out[$k] = $k === 'ratingMax' ? max(1.0, min(5.0, round($v, 1))) : (int)max(0, min(10000, $v));
    }
    if ($out['revMin'] > $out['revMax']) [$out['revMin'], $out['revMax']] = [$out['revMax'], $out['revMin']];
    if ($out['badMin'] > $out['badMax']) [$out['badMin'], $out['badMax']] = [$out['badMax'], $out['badMin']];
    $out['badMin'] = max(1, min(5, $out['badMin'])); $out['badMax'] = max(1, min(5, $out['badMax']));
    return $out;
}

/** Lead record → what the admin page renders. Records whose Google content is older than LEADS_KEEP_DAYS are stripped. */
function lead_view(array $l): ?array {
    if (empty($l['content'])) return null;
    return ['id' => $l['placeId'], 'region' => $l['region'], 'status' => $l['status'], 'notes' => $l['notes'] ?? '', 'foundAt' => $l['foundAt'], 'query' => $l['query'] ?? ''] + $l['content'];
}

function leads_purge(): void {
    $cut = time() - LEADS_KEEP_DAYS * 86400;
    foreach (store_list('leads') as $l) {
        if (!empty($l['content']) && strtotime($l['foundAt']) < $cut)
            store_update('leads', $l['placeId'], function (?array $x) { if ($x) $x['content'] = null; return $x; });
    }
}

// ---------- run ----------

function leads_new_stats(): array {
    return ['searches' => 0, 'places' => 0, 'small' => 0, 'rev' => 0, 'leads' => 0, 'skip' => 0, 'chains' => 0];
}

function leads_run_view(?array $r): ?array {
    if (!$r) return null;
    return ['id' => $r['id'], 'status' => $r['status'], 'region' => $r['region'], 'target' => (int)($r['target'] ?? 0),
        'current' => $r['current'] ?? '', 'startedAt' => $r['startedAt'], 'endedAt' => $r['endedAt'] ?? null, 'stopReason' => $r['stopReason'] ?? ''] + $r['stats'] + ['searches' => 0];
}

/** Up to 5 reviews per place come back with the search (Google picks them by relevance) → fresh bad ones. */
function leads_bad_reviews(array $p, array $crit): array {
    $bad = [];
    foreach ($p['reviews'] ?? [] as $r) {
        $stars = (int)($r['rating'] ?? 0); $at = strtotime((string)($r['publishTime'] ?? '')) ?: 0;
        if ($stars < $crit['badMin'] || $stars > $crit['badMax'] || !$at || $at < time() - $crit['maxAge'] * 86400) continue;
        $bad[] = ['stars' => $stars, 'at' => $at * 1000, 'author' => (string)($r['authorAttribution']['displayName'] ?? 'Google user'),
            'text' => (string)($r['originalText']['text'] ?? $r['text']['text'] ?? ''), 'link' => (string)($r['googleMapsUri'] ?? '')];
    }
    usort($bad, fn($a, $b) => $b['at'] <=> $a['at']);
    return $bad;
}

/** Executes one task. Returns false when the run has to stop (quota / key error). */
function leads_task(array &$run, array $t): bool {
    $crit = $run['crit'];
    if ($t['t'] === 'ig') { // Instagram handle from the business website (no Google call)
        $ig = leads_find_instagram($t['web']);
        if ($ig !== '') store_update('leads', $t['id'], function (?array $l) use ($ig) { if ($l && !empty($l['content'])) $l['content']['ig'] = $ig; return $l; });
        return true;
    }
    if ($t['t'] !== 'search') return true; // e.g. a queued task from an older version
    // one Text Search call = 20 places incl. rating, website, phone and their reviews (SKU Text Search Enterprise + Atmosphere)
    if (!leads_take('text')) { $run['stopReason'] = 'quota_text'; return false; }
    $run['current'] = $t['q'] . (($t['page'] ?? 1) > 1 ? ' · page ' . $t['page'] : '');
    $body = ['textQuery' => $t['q'], 'pageSize' => 20, 'regionCode' => $run['region'], 'languageCode' => 'en'];
    if (!empty($t['token'])) $body['pageToken'] = $t['token'];
    $res = config('mock_google') ? leads_mock_search($t['q'] . ($t['page'] ?? 1))
        : leads_google('POST', 'https://places.googleapis.com/v1/places:searchText', LEADS_TEXT_MASK, $body);
    $page = (int)($t['page'] ?? 1);
    $run['stats']['searches'] = ($run['stats']['searches'] ?? 0) + 1;
    if (!$res['ok']) { if (!empty($res['fatal'])) { $run['stopReason'] = 'api_error'; return false; } return true; }
    foreach ($res['data']['places'] ?? [] as $p) {
        $run['stats']['places']++;
        $id = (string)($p['id'] ?? ''); $n = (int)($p['userRatingCount'] ?? 0); $rating = (float)($p['rating'] ?? 0);
        if ($id === '' || ($p['businessStatus'] ?? 'OPERATIONAL') !== 'OPERATIONAL' || in_array($id, $run['queued'], true)) continue;
        $run['queued'][] = $id;
        if ($n < $crit['revMin'] || $n > $crit['revMax'] || $rating > $crit['ratingMax']) continue;
        if (leads_is_chain($p, $run)) { $run['stats']['chains'] = ($run['stats']['chains'] ?? 0) + 1; continue; }
        $run['stats']['small']++;
        $run['stats']['rev'] += count($p['reviews'] ?? []);
        $existing = store_get('leads', $id);
        if ($existing && !empty($existing['content'])) { $run['stats']['skip']++; continue; }
        $bad = leads_bad_reviews($p, $crit);
        if (!$bad) continue;
        $website = (string)($p['websiteUri'] ?? '');
        $content = ['name' => (string)($p['displayName']['text'] ?? ''), 'address' => (string)($p['formattedAddress'] ?? ''),
            'rating' => $rating, 'count' => $n, 'phone' => (string)($p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? ''), 'web' => $website,
            'maps' => (string)($p['googleMapsUri'] ?? ''), 'ig' => stripos($website, 'instagram.com') !== false ? leads_find_instagram($website) : '', 'reviews' => $bad];
        store_update('leads', $id, function (?array $l) use ($id, $run, $content, $t) {
            return ['placeId' => $id, 'region' => $run['region'], 'status' => $l['status'] ?? 'New', 'notes' => $l['notes'] ?? '',
                'foundAt' => date('c'), 'query' => $t['q'], 'content' => $content];
        });
        $run['stats']['leads']++;
        $run['newIds'][] = $id;
        if ($run['ig'] && $website !== '' && $content['ig'] === '') array_unshift($run['tasks'], ['t' => 'ig', 'id' => $id, 'web' => $website]);
    }
    $next = (string)($res['data']['nextPageToken'] ?? '');
    if ($next !== '' && $page < LEADS_PAGES) $run['tasks'][] = ['t' => 'search', 'q' => $t['q'], 'page' => $page + 1, 'token' => $next];
    return true;
}

/** Queues the next search of the picked list (continues where the last run stopped). False when every search was done once in this run. */
function leads_next_query(array &$run): bool {
    $n = count($run['queries']);
    if (!$n || $run['visited'] >= $n) return false;
    $run['tasks'][] = ['t' => 'search', 'q' => $run['queries'][$run['qi'] % $n]];
    $run['qi'] = ($run['qi'] + 1) % $n;
    $run['visited']++;
    return true;
}

/** Runs tasks for up to LEADS_STEP_SECONDS; a run ends once `target` places were checked. */
function leads_work(array $run): array {
    $until = microtime(true) + LEADS_STEP_SECONDS;
    while ($run['status'] === 'running' && microtime(true) < $until) {
        if ($run['stats']['places'] >= $run['target']) // enough profiles: finish only the Instagram lookups
            $run['tasks'] = array_values(array_filter($run['tasks'], fn($t) => $t['t'] !== 'search'));
        if (!$run['tasks'] && ($run['stats']['places'] >= $run['target'] || !leads_next_query($run))) {
            $run['status'] = 'done'; $run['endedAt'] = date('c'); $run['current'] = '';
            break;
        }
        $t = array_shift($run['tasks']);
        if (!leads_task($run, $t)) {
            array_unshift($run['tasks'], $t); // retried when the run is continued
            $run['status'] = $run['stopReason'] === 'api_error' ? 'error' : 'quota';
            $run['endedAt'] = date('c');
            break;
        }
    }
    if ($run['status'] !== 'running') leads_save_cursor($run);
    return $run;
}

/** Where the next run with the same selection starts. */
function leads_save_cursor(array $run): void {
    $qi = $run['qi'];
    foreach ($run['tasks'] as $t) if ($t['t'] === 'search' && empty($t['page'])) { $qi = ($qi - 1 + count($run['queries'])) % max(1, count($run['queries'])); }
    store_update('leadsys', 'settings', function (?array $s) use ($run, $qi) { $s = $s ?? []; $s['cursors'][$run['key']] = $qi; return $s; });
}

// ---------- actions ----------

function leads_admin(): void {
    admin_required();
    session_write_close(); // a run step takes a while – don't block the other admin requests
}

function leads_state_payload(): array {
    $leads = array_values(array_filter(array_map('lead_view', store_list('leads'))));
    usort($leads, fn($a, $b) => ($b['reviews'][0]['at'] ?? 0) <=> ($a['reviews'][0]['at'] ?? 0));
    $run = store_get('leadsys', 'run');
    return ['ok' => true, 'configured' => (string)config('google_places_key', '') !== '' || (bool)config('mock_google'),
        'leads' => $leads, 'settings' => leads_settings(), 'catalog' => leads_catalog(), 'usage' => leads_usage(), 'limits' => leads_limits(), 'run' => leads_run_view($run)];
}

function action_admin_leads(): void {
    leads_admin();
    leads_purge();
    json_out(leads_state_payload());
}

/** Starts a run (or a dry-run estimate). Checks `target` places; continues the picked list where the last run stopped. */
function action_admin_leads_run(): void {
    leads_admin();
    $d = json_body();
    $region = ($d['region'] ?? 'GB') === 'US' ? 'US' : 'GB';
    $sel = leads_clean_sel($region, $d['sel'] ?? []);
    $queries = leads_build_queries($region, $sel);
    $crit = leads_clean_crit($d['crit'] ?? []);
    $ig = !empty($d['ig']);
    $exclude = clean($d['exclude'] ?? '', 1000);
    $target = (int)max(20, min(100000, (int)($d['target'] ?? 500)));
    $key = md5($region . json_encode($sel));
    $saved = store_get('leadsys', 'settings') ?? [];
    $cursor = (int)($saved['cursors'][$key] ?? 0) % max(1, count($queries));
    store_update('leadsys', 'settings', function (?array $s) use ($region, $sel, $crit, $ig, $exclude, $target) {
        $s = $s ?? [];
        $s['region'] = $region; $s['sel'][$region] = $sel; $s['crit'] = $crit; $s['ig'] = $ig; $s['exclude'] = $exclude; $s['target'] = $target;
        return $s;
    });
    if (!(string)config('google_places_key', '') && !config('mock_google')) fail(503, 'not_configured');
    if (!empty($d['dry'])) {
        $calls = (int)ceil($target / 20);
        json_out(['ok' => true, 'dry' => ['searches' => count($queries), 'cursor' => $cursor, 'calls' => $calls, 'left' => leads_quota_left('text')]]);
    }
    $prev = store_get('leadsys', 'run');
    if ($prev && $prev['status'] === 'running' && strtotime($prev['touchedAt'] ?? $prev['startedAt']) > time() - 120) fail(409, 'already_running');
    $run = ['id' => bin2hex(random_bytes(6)), 'status' => 'running', 'region' => $region, 'key' => $key, 'queries' => $queries, 'qi' => $cursor, 'visited' => 0,
        'target' => $target, 'crit' => $crit, 'ig' => $ig, 'exclude' => $exclude, 'hosts' => [], 'tasks' => [], 'queued' => [], 'newIds' => [],
        'stats' => leads_new_stats(), 'startedAt' => date('c'), 'stopReason' => '', 'touchedAt' => date('c')];
    // a run stopped by the monthly limit with this selection: pick up its open pages first
    if ($prev && $prev['status'] === 'quota' && ($prev['key'] ?? '') === $key) $run['tasks'] = array_values(array_filter($prev['tasks'] ?? [], fn($t) => !empty($t['page']) || $t['t'] === 'ig'));
    store_put('leadsys', 'run', $run);
    json_out(['ok' => true, 'run' => leads_run_view($run)]);
}

function action_admin_leads_step(): void {
    leads_admin();
    $d = json_body();
    @set_time_limit(LEADS_STEP_SECONDS + 40);
    $id = clean($d['run'] ?? '', 20);
    $run = store_update('leadsys', 'run', function (?array $r) use ($id) {
        if (!$r || $r['id'] !== $id || $r['status'] !== 'running') return $r;
        if (!isset($r['qi'])) { $r['status'] = 'cancelled'; $r['endedAt'] = date('c'); return $r; } // run from an older version
        $r = leads_work($r);
        $r['touchedAt'] = date('c');
        return $r;
    });
    if (!$run || $run['id'] !== $id) fail(404, 'run_not_found');
    json_out(leads_state_payload());
}

function action_admin_leads_cancel(): void {
    leads_admin();
    json_body();
    store_update('leadsys', 'run', function (?array $r) {
        if ($r && $r['status'] === 'running') { $r['status'] = 'cancelled'; $r['endedAt'] = date('c'); $r['current'] = ''; }
        return $r;
    });
    json_out(leads_state_payload());
}

/** Monthly search limit (Lead Finder quota card). */
function action_admin_leads_limit(): void {
    leads_admin();
    $d = json_body();
    $n = (int)max(0, min(1000000, (int)($d['monthly'] ?? 930)));
    store_update('leadsys', 'settings', function (?array $s) use ($n) { $s = $s ?? []; $s['monthlyLimit'] = $n; return $s; });
    json_out(['ok' => true, 'limits' => leads_limits()]);
}

/** Status / notes of one lead. */
function action_admin_lead(): void {
    leads_admin();
    $d = json_body();
    $id = clean($d['id'] ?? '', 300);
    $l = store_update('leads', $id, function (?array $l) use ($d) {
        if (!$l) return null;
        if (isset($d['status']) && in_array($d['status'], LEAD_STATUSES, true)) $l['status'] = $d['status'];
        if (isset($d['notes'])) $l['notes'] = clean($d['notes'], 2000);
        $l['updatedAt'] = date('c');
        return $l;
    });
    if (!$l) fail(404, 'lead_not_found');
    json_out(['ok' => true, 'lead' => lead_view($l)]);
}
