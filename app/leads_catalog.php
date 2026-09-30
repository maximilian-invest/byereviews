<?php
// Lead Finder catalog: categories and areas the admin picks with checkboxes.
// A run searches every picked category in every area of every picked city ("nail salon in Hackney, London").
declare(strict_types=1);

// id => [label, search term UK, search term US]
const LEADS_CATEGORIES = [
    'nails' => ['Nail salon', 'nail salon', 'nail salon'],
    'barber' => ['Barber', 'barber', 'barbershop'],
    'hair' => ['Hair salon', 'hair salon', 'hair salon'],
    'beauty' => ['Beauty salon', 'beauty salon', 'beauty salon'],
    'lashes' => ['Lashes & brows', 'lash and brow studio', 'lash and brow studio'],
    'tattoo' => ['Tattoo studio', 'tattoo studio', 'tattoo shop'],
    'massage' => ['Massage & spa', 'massage spa', 'massage spa'],
    'dentist' => ['Dentist', 'dentist', 'dentist'],
    'cafe' => ['Café', 'independent cafe', 'coffee shop'],
    'bakery' => ['Bakery', 'bakery', 'bakery'],
    'bubbletea' => ['Bubble tea', 'bubble tea', 'boba tea'],
    'dessert' => ['Dessert shop', 'dessert shop', 'dessert shop'],
    'restaurant' => ['Restaurant', 'restaurant', 'restaurant'],
    'takeaway' => ['Takeaway', 'takeaway', 'takeout restaurant'],
    'pizza' => ['Pizza', 'pizzeria', 'pizzeria'],
    'burger' => ['Burger', 'burger restaurant', 'burger joint'],
    'dog' => ['Dog groomer', 'dog groomer', 'dog groomer'],
    'carwash' => ['Car wash & detailing', 'car wash valeting', 'car wash detailing'],
    'mechanic' => ['Car mechanic', 'car garage mechanic', 'auto repair shop'],
    'gym' => ['Gym & PT studio', 'independent gym', 'boutique gym'],
    'florist' => ['Florist', 'florist', 'florist'],
    'drycleaner' => ['Dry cleaner', 'dry cleaners', 'dry cleaner'],
];

// region => city => areas (used as "<term> in <area>")
const LEADS_AREAS = [
    'GB' => [
        'London' => ['Hackney, London', 'Shoreditch, London', 'Camden, London', 'Islington, London', 'Brixton, London', 'Peckham, London', 'Croydon, London',
            'Walthamstow, London', 'Leyton, London', 'Stratford, London', 'Ealing, London', 'Wembley, London', 'Tooting, London', 'Clapham, London', 'Lewisham, London',
            'Wimbledon, London', 'Kingston upon Thames', 'Bromley, London', 'Harrow, London', 'Ilford, London', 'Romford, London', 'Enfield, London', 'Wood Green, London',
            'Hammersmith, London', 'Fulham, London', 'Notting Hill, London', 'Greenwich, London', 'Woolwich, London', 'Richmond, London', 'Southall, London'],
        'Manchester' => ['Northern Quarter, Manchester', 'Ancoats, Manchester', 'Didsbury, Manchester', 'Chorlton, Manchester', 'Fallowfield, Manchester', 'Rusholme, Manchester',
            'Levenshulme, Manchester', 'Salford', 'Stockport', 'Altrincham', 'Sale, Manchester', 'Prestwich, Manchester'],
        'Birmingham' => ['Digbeth, Birmingham', 'Jewellery Quarter, Birmingham', 'Moseley, Birmingham', 'Kings Heath, Birmingham', 'Harborne, Birmingham', 'Edgbaston, Birmingham',
            'Erdington, Birmingham', 'Selly Oak, Birmingham', 'Solihull', 'Sutton Coldfield'],
        'Leeds' => ['Leeds city centre', 'Headingley, Leeds', 'Chapel Allerton, Leeds', 'Roundhay, Leeds', 'Horsforth, Leeds', 'Kirkstall, Leeds', 'Armley, Leeds', 'Morley, Leeds'],
        'Liverpool' => ['Liverpool city centre', 'Bold Street, Liverpool', 'Allerton, Liverpool', 'Woolton, Liverpool', 'Wavertree, Liverpool', 'Crosby, Liverpool', 'Anfield, Liverpool'],
        'Bristol' => ['Clifton, Bristol', 'Stokes Croft, Bristol', 'Bedminster, Bristol', 'Southville, Bristol', 'Bishopston, Bristol', 'Redland, Bristol', 'Easton, Bristol', 'Fishponds, Bristol'],
        'Sheffield' => ['Sheffield city centre', 'Kelham Island, Sheffield', 'Ecclesall Road, Sheffield', 'Broomhill, Sheffield', 'Hillsborough, Sheffield', 'Crookes, Sheffield'],
        'Newcastle' => ['Newcastle city centre', 'Jesmond, Newcastle', 'Heaton, Newcastle', 'Gosforth, Newcastle', 'Ouseburn, Newcastle'],
        'Nottingham' => ['Nottingham city centre', 'Hockley, Nottingham', 'Beeston, Nottingham', 'West Bridgford', 'Sherwood, Nottingham', 'Mapperley, Nottingham'],
        'Leicester' => ['Leicester city centre', 'Belgrave, Leicester', 'Clarendon Park, Leicester', 'Oadby', 'Evington, Leicester'],
        'Brighton' => ['The Lanes, Brighton', 'North Laine, Brighton', 'Hove', 'Kemptown, Brighton', 'Preston Park, Brighton'],
        'Southampton' => ['Southampton city centre', 'Portswood, Southampton', 'Shirley, Southampton'],
        'Coventry' => ['Coventry city centre', 'Earlsdon, Coventry'],
        'Reading' => ['Reading town centre', 'Caversham, Reading'],
        'Oxford' => ['Oxford city centre', 'Cowley Road, Oxford', 'Jericho, Oxford'],
        'Cambridge' => ['Cambridge city centre', 'Mill Road, Cambridge'],
        'York' => ['York city centre', 'Acomb, York'],
    ],
    'US' => [
        'New York' => ['Harlem, Manhattan, NY', 'Upper West Side, Manhattan, NY', 'East Village, Manhattan, NY', 'Lower East Side, Manhattan, NY', 'Williamsburg, Brooklyn, NY',
            'Bushwick, Brooklyn, NY', 'Park Slope, Brooklyn, NY', 'Bed-Stuy, Brooklyn, NY', 'Crown Heights, Brooklyn, NY', 'Bay Ridge, Brooklyn, NY', 'Flatbush, Brooklyn, NY',
            'Astoria, Queens, NY', 'Flushing, Queens, NY', 'Jackson Heights, Queens, NY', 'Long Island City, Queens, NY', 'Forest Hills, Queens, NY', 'Fordham, Bronx, NY',
            'Riverdale, Bronx, NY', 'Staten Island, NY'],
        'Los Angeles' => ['Silver Lake, Los Angeles, CA', 'Echo Park, Los Angeles, CA', 'Koreatown, Los Angeles, CA', 'Hollywood, CA', 'Venice, CA', 'Santa Monica, CA', 'Culver City, CA',
            'Pasadena, CA', 'Glendale, CA', 'Burbank, CA', 'Long Beach, CA', 'Downtown Los Angeles, CA', 'Highland Park, Los Angeles, CA', 'West Hollywood, CA'],
        'Chicago' => ['Pilsen, Chicago, IL', 'Logan Square, Chicago, IL', 'Wicker Park, Chicago, IL', 'Lincoln Park, Chicago, IL', 'Lakeview, Chicago, IL', 'Hyde Park, Chicago, IL',
            'Andersonville, Chicago, IL', 'West Loop, Chicago, IL', 'Bridgeport, Chicago, IL', 'Evanston, IL'],
        'Houston' => ['Montrose, Houston, TX', 'Houston Heights, TX', 'Midtown, Houston, TX', 'EaDo, Houston, TX', 'Bellaire, TX', 'Katy, TX', 'Sugar Land, TX', 'The Woodlands, TX', 'Pearland, TX'],
        'Dallas' => ['Deep Ellum, Dallas, TX', 'Bishop Arts District, Dallas, TX', 'Uptown, Dallas, TX', 'Lower Greenville, Dallas, TX', 'Plano, TX', 'Frisco, TX', 'Richardson, TX', 'Irving, TX', 'Garland, TX'],
        'Austin' => ['East Austin, TX', 'South Congress, Austin, TX', 'Hyde Park, Austin, TX', 'Mueller, Austin, TX', 'Round Rock, TX', 'Cedar Park, TX', 'Pflugerville, TX'],
        'Miami' => ['Wynwood, Miami, FL', 'Little Havana, Miami, FL', 'Brickell, Miami, FL', 'Coral Gables, FL', 'Doral, FL', 'Hialeah, FL', 'Miami Beach, FL', 'Kendall, FL'],
        'Atlanta' => ['Midtown, Atlanta, GA', 'Old Fourth Ward, Atlanta, GA', 'Decatur, GA', 'Buckhead, Atlanta, GA', 'Inman Park, Atlanta, GA', 'Marietta, GA', 'Sandy Springs, GA', 'Duluth, GA'],
        'Phoenix' => ['Downtown Phoenix, AZ', 'Scottsdale, AZ', 'Tempe, AZ', 'Mesa, AZ', 'Chandler, AZ', 'Glendale, AZ', 'Gilbert, AZ'],
        'Philadelphia' => ['Fishtown, Philadelphia, PA', 'Northern Liberties, Philadelphia, PA', 'South Philadelphia, PA', 'Center City, Philadelphia, PA', 'University City, Philadelphia, PA',
            'Manayunk, Philadelphia, PA', 'Kensington, Philadelphia, PA'],
        'San Diego' => ['North Park, San Diego, CA', 'Hillcrest, San Diego, CA', 'Pacific Beach, San Diego, CA', 'Chula Vista, CA', 'La Jolla, CA', 'Ocean Beach, San Diego, CA', 'Escondido, CA'],
        'Seattle' => ['Capitol Hill, Seattle, WA', 'Ballard, Seattle, WA', 'Fremont, Seattle, WA', 'University District, Seattle, WA', 'Columbia City, Seattle, WA', 'Bellevue, WA', 'Redmond, WA'],
        'Denver' => ['RiNo, Denver, CO', 'LoHi, Denver, CO', 'Capitol Hill, Denver, CO', 'Highlands, Denver, CO', 'Aurora, CO', 'Lakewood, CO', 'Littleton, CO'],
        'Boston' => ['Back Bay, Boston, MA', 'South End, Boston, MA', 'Jamaica Plain, Boston, MA', 'Somerville, MA', 'Cambridge, MA', 'Allston, Boston, MA', 'Quincy, MA'],
        'Las Vegas' => ['Henderson, NV', 'Summerlin, Las Vegas, NV', 'Spring Valley, Las Vegas, NV', 'Chinatown, Las Vegas, NV'],
        'Nashville' => ['East Nashville, TN', 'The Gulch, Nashville, TN', '12 South, Nashville, TN', 'Germantown, Nashville, TN', 'Franklin, TN'],
    ],
];

const LEADS_DEFAULT_SEL = [
    'GB' => ['cats' => ['nails', 'barber', 'beauty', 'cafe'], 'cities' => ['London']],
    'US' => ['cats' => ['nails', 'barber', 'beauty', 'cafe'], 'cities' => ['New York']],
];

/** Catalog for the admin page. */
function leads_catalog(): array {
    $cats = [];
    foreach (LEADS_CATEGORIES as $id => $c) $cats[] = ['id' => $id, 'label' => $c[0]];
    $cities = [];
    foreach (LEADS_AREAS as $region => $list) foreach ($list as $city => $areas) $cities[$region][] = ['id' => $city, 'areas' => count($areas)];
    return ['cats' => $cats, 'cities' => $cities];
}

function leads_clean_sel(string $region, $sel): array {
    $sel = is_array($sel) ? $sel : [];
    $cats = array_values(array_filter(array_unique(array_map('strval', (array)($sel['cats'] ?? []))), fn($c) => isset(LEADS_CATEGORIES[$c])));
    $cities = array_values(array_filter(array_unique(array_map('strval', (array)($sel['cities'] ?? []))), fn($c) => isset(LEADS_AREAS[$region][$c])));
    return ['cats' => $cats, 'cities' => $cities];
}

/** Picked categories × all areas of the picked cities, area by area (so a stop midway still covers every category somewhere). */
function leads_build_queries(string $region, array $sel): array {
    $out = [];
    foreach ($sel['cities'] as $city) foreach (LEADS_AREAS[$region][$city] as $area)
        foreach ($sel['cats'] as $c) $out[] = LEADS_CATEGORIES[$c][$region === 'US' ? 2 : 1] . ' in ' . $area;
    return $out;
}
