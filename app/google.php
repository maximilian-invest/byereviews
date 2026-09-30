<?php
// Google Business lookup: Places API (New) for search/details, SerpApi for the full review list.
declare(strict_types=1);

const PLACES_FIELDS = 'id,displayName,formattedAddress,rating,userRatingCount,nationalPhoneNumber,internationalPhoneNumber,addressComponents,googleMapsUri';

function cache_get(string $key, int $ttl): ?array {
    $f = data_dir('cache') . '/' . hash('sha256', $key) . '.json';
    if (!is_file($f) || filemtime($f) < time() - $ttl) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function cache_put(string $key, array $data): void {
    @file_put_contents(data_dir('cache') . '/' . hash('sha256', $key) . '.json', json_encode($data), LOCK_EX);
}

/** Turn a Google Maps / share.google link into a text query (+ optional location bias). */
function query_from_link(string $q): array {
    if (!function_exists('curl_init')) return ['', null];
    $ch = curl_init($q);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => false, CURLOPT_RANGE => '0-2000', CURLOPT_USERAGENT => 'Mozilla/5.0 (byereviews lookup)']);
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $url = $final ?: $q;
    $text = '';
    $bias = null;
    if (preg_match('~/maps/place/([^/@?]+)~', $url, $m)) $text = urldecode(str_replace('+', ' ', $m[1]));
    if (preg_match('~@(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) $bias = [(float)$m[1], (float)$m[2]];
    if ($text === '') {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $qs);
        $text = (string)($qs['q'] ?? $qs['query'] ?? '');
    }
    return [$text, $bias];
}

function place_summary(array $p): array {
    $comp = fn(string $type) => (function () use ($p, $type) {
        foreach ($p['addressComponents'] ?? [] as $c) if (in_array($type, $c['types'] ?? [], true)) return $c;
        return null;
    })();
    $route = $comp('route')['longText'] ?? '';
    $number = $comp('street_number')['longText'] ?? '';
    // English-speaking markets write the number first ("214 W Lake St"), Europe after ("Mariahilfer Str. 1")
    $numberFirst = in_array($comp('country')['shortText'] ?? '', ['US', 'GB', 'CA', 'AU', 'IE'], true);
    $street = trim($numberFirst ? "$number $route" : "$route $number");
    $city = trim(($comp('postal_code')['longText'] ?? '') . ' ' . ($comp('locality')['longText'] ?? $comp('postal_town')['longText'] ?? ''));
    $country = country_name($comp('country')['shortText'] ?? '');
    $rating = isset($p['rating']) ? (float)$p['rating'] : null;
    $count = (int)($p['userRatingCount'] ?? 0);
    return [
        'id' => $p['id'] ?? '',
        'name' => $p['displayName']['text'] ?? '',
        'address' => $p['formattedAddress'] ?? '',
        'rating' => $rating,
        'reviewCount' => $count,
        'phone' => $p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? '',
        'street' => $street,
        'city' => $city,
        'country' => $country,
        'mapsUrl' => $p['googleMapsUri'] ?? '',
        'meta' => trim(($rating !== null ? number_format($rating, 1) . ' ★ (' . $count . ' reviews) · ' : '') . ($p['formattedAddress'] ?? '')),
    ];
}

function places_search(string $q): array {
    $key = (string)config('google_places_key', '');
    if (config('mock_google')) return mock_places($q);
    if ($key === '') return ['ok' => false, 'error' => 'not_configured'];
    $cacheKey = 'search|' . mb_strtolower($q);
    if ($c = cache_get($cacheKey, 86400)) return $c;

    $body = ['textQuery' => $q, 'pageSize' => 5, 'languageCode' => 'en'];
    if (preg_match('~^https?://~i', $q) || preg_match('~^(share\.google|maps\.app\.goo\.gl|g\.page|goo\.gl)~i', $q)) {
        [$text, $bias] = query_from_link(preg_match('~^https?://~i', $q) ? $q : 'https://' . $q);
        if ($text === '') return ['ok' => true, 'places' => []];
        $body['textQuery'] = $text;
        if ($bias) $body['locationBias'] = ['circle' => ['center' => ['latitude' => $bias[0], 'longitude' => $bias[1]], 'radius' => 2000.0]];
    }
    $fieldMask = implode(',', array_map(fn($f) => 'places.' . $f, explode(',', PLACES_FIELDS)));
    $r = http_json('POST', 'https://places.googleapis.com/v1/places:searchText',
        ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $fieldMask], json_encode($body));
    if ($r['code'] !== 200) { log_event('places search failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return ['ok' => false, 'error' => 'lookup_failed']; }
    $out = ['ok' => true, 'places' => array_map('place_summary', $r['data']['places'] ?? [])];
    cache_put($cacheKey, $out);
    return $out;
}

/** As-you-type suggestions (Places Autocomplete, billed per session together with the details call). */
function places_autocomplete(string $q, string $session): array {
    if (config('mock_google')) {
        $all = mock_places('')['places'];
        $hits = array_values(array_filter($all, fn($p) => stripos($p['name'], $q) !== false));
        return ['ok' => true, 'places' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'meta' => $p['address'], 'partial' => true], $hits)];
    }
    $key = (string)config('google_places_key', '');
    if ($key === '') return ['ok' => false, 'error' => 'not_configured'];
    $cacheKey = 'ac|' . mb_strtolower($q);
    if ($c = cache_get($cacheKey, 86400)) return $c;
    $body = ['input' => $q, 'languageCode' => 'en', 'includeQueryPredictions' => false];
    if (preg_match('/^[A-Za-z0-9_-]{8,}$/', $session)) $body['sessionToken'] = $session;
    $r = http_json('POST', 'https://places.googleapis.com/v1/places:autocomplete', ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key], json_encode($body));
    if ($r['code'] !== 200) { log_event('places autocomplete failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); return ['ok' => false, 'error' => 'lookup_failed']; }
    $places = [];
    foreach ($r['data']['suggestions'] ?? [] as $sug) {
        $pp = $sug['placePrediction'] ?? null;
        if (!$pp || empty($pp['placeId'])) continue;
        $places[] = ['id' => $pp['placeId'], 'name' => $pp['structuredFormat']['mainText']['text'] ?? ($pp['text']['text'] ?? ''),
            'meta' => $pp['structuredFormat']['secondaryText']['text'] ?? '', 'partial' => true];
    }
    $out = ['ok' => true, 'places' => $places];
    cache_put($cacheKey, $out);
    return $out;
}

function place_details(string $id, string $session = ''): ?array {
    if (config('mock_google')) { foreach (mock_places('')['places'] as $p) if ($p['id'] === $id) return $p; return null; }
    $key = (string)config('google_places_key', '');
    if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{10,}$/', $id)) return null;
    if ($c = cache_get('details|' . $id, 86400)) return $c;
    $qs = 'languageCode=en' . (preg_match('/^[A-Za-z0-9_-]{8,}$/', $session) ? '&sessionToken=' . $session : '');
    $r = http_json('GET', 'https://places.googleapis.com/v1/places/' . $id . '?' . $qs,
        ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . PLACES_FIELDS]);
    if ($r['code'] !== 200 || !$r['data']) return null;
    $p = place_summary($r['data']);
    cache_put('details|' . $id, $p);
    return $p;
}

function days_since(?string $iso): int {
    if (!$iso) return 60;
    $t = strtotime($iso);
    return $t ? max(0, (int)floor((time() - $t) / 86400)) : 60;
}

/** Reviews of a place, lowest rating first. SerpApi (full list) if configured, else Places API (max 5). */
function place_reviews(string $id): array {
    if (config('mock_google')) return mock_reviews($id);
    if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $id)) return ['ok' => false, 'error' => 'bad_id'];
    if ($c = cache_get('reviews|' . $id, 6 * 3600)) return $c;

    $reviews = [];
    $source = 'places';
    $serp = (string)config('serpapi_key', '');
    if ($serp !== '') {
        $source = 'serpapi';
        $token = null;
        for ($page = 0; $page < (int)config('serpapi_pages', 4); $page++) {
            $params = ['engine' => 'google_maps_reviews', 'place_id' => $id, 'sort_by' => 'ratingLow', 'hl' => 'en', 'api_key' => $serp];
            if ($token) { $params['next_page_token'] = $token; $params['num'] = 20; }
            $r = http_json('GET', 'https://serpapi.com/search.json?' . http_build_query($params), [], null, 30);
            if ($r['code'] !== 200 || !$r['data']) { log_event('serpapi failed ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300)); break; }
            foreach ($r['data']['reviews'] ?? [] as $rv) {
                $reviews[] = [
                    'id' => (string)($rv['review_id'] ?? md5(json_encode($rv))),
                    'name' => (string)($rv['user']['name'] ?? 'Google user'),
                    'stars' => (int)round((float)($rv['rating'] ?? 0)),
                    'days' => days_since($rv['iso_date'] ?? null),
                    'text' => (string)($rv['extracted_snippet']['original'] ?? $rv['snippet'] ?? ''),
                    'link' => (string)($rv['link'] ?? ''),
                ];
            }
            $token = $r['data']['serpapi_pagination']['next_page_token'] ?? null;
            if (!$token) break;
        }
    }
    if (!$reviews) {
        $source = 'places';
        $key = (string)config('google_places_key', '');
        if ($key === '') return ['ok' => false, 'error' => 'not_configured'];
        $r = http_json('GET', 'https://places.googleapis.com/v1/places/' . $id . '?languageCode=en', ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: reviews']);
        foreach ($r['data']['reviews'] ?? [] as $rv) {
            $reviews[] = [
                'id' => (string)($rv['name'] ?? md5(json_encode($rv))),
                'name' => (string)($rv['authorAttribution']['displayName'] ?? 'Google user'),
                'stars' => (int)($rv['rating'] ?? 0),
                'days' => days_since($rv['publishTime'] ?? null),
                'text' => (string)($rv['originalText']['text'] ?? $rv['text']['text'] ?? ''),
                'link' => (string)($rv['googleMapsUri'] ?? ''),
            ];
        }
    }
    usort($reviews, fn($a, $b) => $a['stars'] <=> $b['stars'] ?: $a['days'] <=> $b['days']);
    $out = ['ok' => true, 'source' => $source, 'complete' => $source === 'serpapi', 'reviews' => $reviews];
    cache_put('reviews|' . $id, $out);
    return $out;
}

function country_name(string $code): string {
    static $map = ['US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'CH' => 'Switzerland',
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark',
        'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IE' => 'Ireland',
        'IT' => 'Italy', 'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands', 'PL' => 'Poland',
        'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden'];
    return $map[strtoupper($code)] ?? ($code === '' ? '' : 'Other country');
}

// ---------- local test fixtures (config mock_google => true) ----------

function mock_places(string $q): array {
    if (preg_match('/notfound/i', $q)) return ['ok' => true, 'places' => []];
    return ['ok' => true, 'places' => [
        ['id' => 'ChIJmockOsteria0001', 'name' => 'Osteria Mock', 'address' => 'Mariahilfer Str. 1, 1060 Wien, Austria', 'rating' => 4.2, 'reviewCount' => 128,
         'phone' => '+43 1 234567', 'street' => 'Mariahilfer Str. 1', 'city' => '1060 Wien', 'country' => 'Austria', 'mapsUrl' => '', 'meta' => '4.2 ★ (128 reviews) · Mariahilfer Str. 1, 1060 Wien, Austria'],
        ['id' => 'ChIJmockOsteria0002', 'name' => 'Osteria Mock Two', 'address' => '214 W Lake St, Chicago, IL 60606, USA', 'rating' => 3.9, 'reviewCount' => 40,
         'phone' => '+1 312-555-0147', 'street' => '214 W Lake St', 'city' => '60606 Chicago', 'country' => 'United States', 'mapsUrl' => '', 'meta' => '3.9 ★ (40 reviews) · 214 W Lake St, Chicago, IL 60606, USA'],
    ]];
}

function mock_reviews(string $id): array {
    $rows = [['Mark T.', 1, 12, "Never been here but heard it's terrible."], ['Sarah K.', 1, 5, 'Rude staff, go across the street.'], ['Jonas B.', 1, 19, ''],
        ['D. Miller', 1, 95, 'Complete scam. The owner is a criminal.'], ['Alex R.', 2, 210, 'Worst experience of my life.'], ['Lena M.', 5, 8, 'Wonderful evening.']];
    return ['ok' => true, 'source' => 'mock', 'complete' => true, 'reviews' => array_map(fn($r, $i) => ['id' => "rv$i", 'name' => $r[0], 'stars' => $r[1], 'days' => $r[2], 'text' => $r[3], 'link' => ''], $rows, array_keys($rows))];
}
