<?php
set_time_limit(300);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$CACHE_DIR = __DIR__ . '/cache';
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

$action = $_GET['action'] ?? '';
$ar     = $_GET['ar']     ?? '';

// ── helpers ────────────────────────────────────────────────────────────────

function cache_path($key) {
    global $CACHE_DIR;
    return "$CACHE_DIR/siris_$key.json";
}

function cache_read($key, $ttl = 21600) {
    $p = cache_path($key);
    if (file_exists($p) && (time() - filemtime($p)) < $ttl)
        return json_decode(file_get_contents($p), true);
    return null;
}

function cache_write($key, $data) {
    file_put_contents(cache_path($key), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function fetch_single($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (skolinsyn/2.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

// Parallel fetch for all municipalities using curl_multi
function fetch_parallel($urls, $batch = 30) {
    $results = array_fill_keys(array_keys($urls), null);
    $keys    = array_keys($urls);

    for ($offset = 0; $offset < count($keys); $offset += $batch) {
        $slice = array_slice($keys, $offset, $batch);
        $mh    = curl_multi_init();
        $chs   = [];

        foreach ($slice as $k) {
            $ch = curl_init($urls[$k]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (skolinsyn/2.0)',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[$k] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.5);
        } while ($active && $status == CURLM_OK);

        foreach ($chs as $k => $ch) {
            $results[$k] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

// ── Text parsing ──────────────────────────────────────────────────────────

const EXCLUDE = ['Skolenkäten','Skolenhetsrapport','Huvudmannarapport',
                 'Läsa, skriva, räkna','Omrättningsbeslut','Läsa skriva'];

const TYPER = [
    'Tematisk kvalitetsgranskning','Tematisk granskning',
    'Tematisk tillsyn','Planerad kvalitetsgranskning',
    'Planerad granskning','Planerad tillsyn',
    'Regelb. tillsyn','Regelb tillsyn',
    'Riktad tillsyn','Etableringskontroll',
];

const SKOLFORMER = [
    'Anpassad gymnasieskola','Anpassad grundskola','Kompletterande utbildning',
    'Internationell skola','Förskoleklass','Vuxenutbildning','Gymnasieskola',
    'Grundskola','Fritidshem','Förskola','Yrkeshögskola','Kulturskola','Fritidshem',
];

function parse_doc($text, $municipality) {
    foreach (EXCLUDE as $ex)
        if (stripos($text, $ex) !== false) return null;

    if (!preg_match('/tillsyn|granskning|kontroll|etableringskontroll/i', $text))
        return null;

    $typ = '';
    foreach (TYPER as $t)
        if (stripos($text, $t) !== false) { $typ = $t; break; }

    $skolform = '';
    foreach (SKOLFORMER as $sf)
        if (stripos($text, $sf) !== false) { $skolform = $sf; break; }

    // School name: strip known keywords and the trailing ", YYYY (pdf...)"
    $clean = preg_replace('/,?\s*\d{4}\s*\(pdf[^)]*\)\s*$/', '', $text);
    $clean = preg_replace('/,?\s*\d{4}\s*$/', '', $clean);
    $skola = $clean;
    foreach (array_merge(TYPER, SKOLFORMER,
        ['Skolbeslut','Granskningsbeslut','Uppföljning beslut','Uppföljning skolbeslut',
         'Beslut','Skolbeslutuppf','FKGY','Uppföljning',',']) as $rm)
        $skola = str_ireplace($rm, ' ', $skola);

    // Remove municipality name
    if ($municipality)
        $skola = preg_replace('/\b'.preg_quote($municipality,'/').'[\w\s-]*\b/i', ' ', $skola);

    $skola = trim(preg_replace('/\s{2,}/', ' ', $skola));
    if (strlen($skola) < 3 || strlen($skola) > 100) $skola = $municipality;

    return ['typ' => $typ, 'skolform' => $skolform ?: 'Okänd', 'skola' => $skola];
}

// ── Approximate date from docID ──────────────────────────────────────────
// Anchor: docID 666489 = 2026-06-16. Rate ≈ 16 docIDs/day across all SIRIS docs.
function approx_datum($docid) {
    $anchor_id   = 666489;
    $anchor_date = mktime(0, 0, 0, 6, 16, 2026);
    $rate        = 16.0; // docIDs per day
    $days_offset = ($docid - $anchor_id) / $rate;
    $ts = $anchor_date + (int)($days_offset * 86400);
    // Return YYYY-MM-01 (day set to 1 since it's an estimate)
    return date('Y-m', $ts) . '-01';
}

// ── Region from county code ───────────────────────────────────────────────

function landsdel($kod) {
    $p = intval(substr($kod, 0, 2));
    // Norrland: Gävleborg(21), Västernorrland(22), Jämtland(23), Västerbotten(24), Norrbotten(25)
    if ($p >= 21 && $p <= 25) return 'Norrland';
    // Svealand: Stockholm(01), Uppsala(03), Södermanland(04), Värmland(17), Örebro(18), Västmanland(19), Dalarna(20)
    if (in_array($p, [1, 3, 4, 17, 18, 19, 20])) return 'Svealand';
    // Götland county is also Götaland (09)
    return 'Götaland';
}

// ── Extract beslutsdatum from PDF binary ──────────────────────────────────
function extract_date_from_pdf($body) {
    if (!$body) return null;
    // XMP format: <xmp:ModifyDate>2026-06-15T...
    if (preg_match('/<xmp:ModifyDate>(20\d{2}-\d{2}-\d{2})/', $body, $m)) return $m[1];
    // Traditional PDF: ModDate(D:20260616...
    if (preg_match('/ModDate\(D:(20\d{6})/', $body, $m)) {
        $s = $m[1];
        return substr($s,0,4).'-'.substr($s,4,2).'-'.substr($s,6,2);
    }
    return null;
}

// ── Actions ───────────────────────────────────────────────────────────────

if ($action === 'municipalities') {
    $cached = cache_read('municipalities', 86400);
    if ($cached) { echo json_encode($cached); exit; }
    $raw  = fetch_single('https://skolinspektionen.se/api/siris/counties/');
    $data = json_decode($raw, true) ?: [];
    cache_write('municipalities', $data);
    echo json_encode($data);
    exit;
}

if ($action === 'decisions' && $ar) {
    $years = array_filter(
        array_map('intval', preg_split('/[,\s]+/', $ar)),
        fn($y) => $y >= 2020 && $y <= 2030
    );
    $cache_key = 'decisions_v2_' . implode('_', $years);

    $cached = cache_read($cache_key, 21600);
    if ($cached && !isset($_GET['force'])) { echo json_encode($cached); exit; }

    // Fetch municipality list
    $muni_raw = cache_read('municipalities', 86400)
        ?? json_decode(fetch_single('https://skolinspektionen.se/api/siris/counties/'), true);
    if (!$muni_raw) { echo json_encode([]); exit; }

    // Build URL map: kod → url
    $url_map = [];
    $kod_map = []; // kod → namn
    foreach ($muni_raw as $m) {
        $url_map[$m['kod']] = "https://skolinspektionen.se/api/siris/counties/{$m['kod']}/documents";
        $kod_map[$m['kod']] = $m['namn'];
    }

    // Parallel fetch (batches of 30)
    $raw_results = fetch_parallel($url_map);

    $all = [];
    $seen = [];

    foreach ($raw_results as $kod => $body) {
        if (!$body) continue;
        $docs = json_decode($body, true);
        if (!$docs || !isset($docs['svar'])) continue;
        $namn = $kod_map[$kod];

        foreach ($docs['svar'] as $doc) {
            $yr = intval($doc['år'] ?? 0);
            if (!in_array($yr, $years)) continue;

            if (!preg_match('/docID=(\d+)/', $doc['url'] ?? '', $m)) continue;
            $docid = intval($m[1]);
            if (isset($seen[$docid])) continue;
            $seen[$docid] = true;

            $parsed = parse_doc($doc['text'] ?? '', $namn);
            if (!$parsed) continue;

            $all[] = [
                'skola'   => $parsed['skola'],
                'kommun'  => $namn,
                'kod'     => $kod,
                'region'  => landsdel($kod),
                'typ'     => $parsed['typ'],
                'skolform'=> $parsed['skolform'],
                'ar'      => $yr,
                'datum'   => approx_datum($docid), // approximate; day is always 01
                'datum_approx' => true,
                'drift'   => null,
                'url'     => $doc['url'],
                'docid'   => $docid,
            ];
        }
    }

    usort($all, fn($a,$b) => $b['docid'] - $a['docid']);
    cache_write($cache_key, $all);
    echo json_encode($all, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'fetch_dates') {
    $raw_ids = preg_split('/[,\s]+/', $_GET['docids'] ?? '');
    $docids  = array_filter(array_map('intval', $raw_ids), fn($d) => $d > 100000);

    $results  = [];
    $to_fetch = [];

    foreach ($docids as $docid) {
        $cached = cache_read('pdfdate_'.$docid, 86400 * 60); // cache 60 days
        if ($cached !== null) {
            $results[$docid] = $cached['d'];
        } else {
            $to_fetch[$docid] = "http://siris.skolverket.se/siris/ris.openfile?docID=$docid";
        }
    }

    if ($to_fetch) {
        // Fetch in batches of 10 (PDFs are large)
        $raw = fetch_parallel($to_fetch, 10);
        foreach ($raw as $docid => $body) {
            $date = extract_date_from_pdf($body);
            cache_write('pdfdate_'.$docid, ['d' => $date]);
            $results[$docid] = $date;
        }
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
