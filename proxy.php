<?php
set_time_limit(120);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$CACHE_DIR = __DIR__ . '/cache';
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

$action = $_GET['action'] ?? '';
$ar     = $_GET['ar']     ?? '';

// ── Helpers ────────────────────────────────────────────────────────────────

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

function fetch_url($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (skolinsyn/3.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

function fetch_parallel($urls, $batch = 10) {
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
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (skolinsyn/3.0)',
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

// ── Municipality name → code + region ────────────────────────────────────

function get_muni_map() {
    $cached = cache_read('municipalities', 86400);
    if (!$cached) {
        $raw    = fetch_url('https://skolinspektionen.se/api/siris/counties/');
        $cached = json_decode($raw, true) ?: [];
        cache_write('municipalities', $cached);
    }
    $name_to_kod = [];
    foreach ($cached as $m) $name_to_kod[$m['namn']] = $m['kod'];
    return $name_to_kod;
}

function landsdel($kod) {
    $p = intval(substr($kod, 0, 2));
    if ($p >= 21 && $p <= 25) return 'Norrland';
    if (in_array($p, [1, 3, 4, 17, 18, 19, 20])) return 'Svealand';
    return 'Götaland';
}

// ── Parse titel from doksok_api ──────────────────────────────────────────
// titel format: "Beslutstyp [Dokumenttyp] [Skolform] Kommun Skolnamn, YYYY (pdf X kB)"

const TYPER = [
    'Tematisk kvalitetsgranskning','Tematisk granskning','Tematisk tillsyn',
    'Planerad kvalitetsgranskning','Planerad granskning','Planerad tillsyn',
    'Riktad tillsyn','Regelb. tillsyn','Regelb tillsyn',
    'Etableringskontroll','Uppföljningsbeslut',
];

const DOCTYPER = [
    'Skolbeslut','Granskningsbeslut','Huvudmannabeslut','Uppföljning beslut',
    'Uppföljning skolbeslut','Skolbeslutuppf',
];

const SKOLFORMER = [
    'Anpassad gymnasieskola','Anpassad grundskola','Kompletterande utbildning',
    'Internationell skola','Förskoleklass','Vuxenutbildning','Gymnasieskola',
    'Grundskola','Fritidshem','Förskola','Yrkeshögskola','Kulturskola',
];

function parse_titel($titel, $doktyp_raw, $name_to_kod) {
    // Strip year and pdf suffix
    $text = preg_replace('/,?\s*\d{4}\s*\(pdf[^)]*\)\s*$/', '', $titel);

    // Extract år from original titel
    preg_match('/,\s*(\d{4})\s*\(pdf/', $titel, $ym);
    $ar = isset($ym[1]) ? intval($ym[1]) : 0;

    // Clean up typ from doktyp_raw: "Riktad tillsyn (Ansvarig myndighet - Skolinspektionen)"
    $typ = preg_replace('/\s*\(.*\)$/', '', $doktyp_raw);

    // Remove typ from beginning
    foreach (TYPER as $t)
        $text = preg_replace('/^'.preg_quote($t,'/').'[\s,]*/i', '', $text);

    // Remove doctype keywords
    foreach (DOCTYPER as $d)
        $text = preg_replace('/^'.preg_quote($d,'/').'[\s,]*/i', '', $text);
    $text = trim($text);

    // Match skolform at start
    $skolform = 'Okänd';
    foreach (SKOLFORMER as $sf) {
        if (stripos($text, $sf) === 0) {
            $skolform = $sf;
            $text = trim(substr($text, strlen($sf)));
            break;
        }
    }

    // Match municipality name (longest match first to avoid partial matches)
    $kommun = '';
    $kod    = '';
    $munis  = array_keys($name_to_kod);
    usort($munis, fn($a,$b) => strlen($b)-strlen($a));
    foreach ($munis as $namn) {
        if (stripos($text, $namn) === 0) {
            $kommun = $namn;
            $kod    = $name_to_kod[$namn];
            $text   = trim(substr($text, strlen($namn)));
            break;
        }
    }

    // Remaining text is school name
    $skola = trim($text, " ,\t");
    if (strlen($skola) < 2) $skola = $kommun;

    return [
        'skola'   => $skola,
        'kommun'  => $kommun,
        'kod'     => $kod,
        'region'  => $kod ? landsdel($kod) : 'Okänd',
        'typ'     => $typ,
        'skolform'=> $skolform,
        'ar'      => $ar,
    ];
}

// ── Approximate date from docID ──────────────────────────────────────────
function approx_datum($docid) {
    $anchor_id   = 666489;
    $anchor_date = mktime(0, 0, 0, 6, 16, 2026);
    $rate        = 16.0;
    $ts = $anchor_date + (int)(($docid - $anchor_id) / $rate * 86400);
    return date('Y-m', $ts) . '-01';
}

// ── Extract beslutsdatum from PDF binary ──────────────────────────────────
function extract_date_from_pdf($body) {
    if (!$body) return null;
    if (preg_match('/<xmp:ModifyDate>(20\d{2}-\d{2}-\d{2})/', $body, $m)) return $m[1];
    if (preg_match('/ModDate\(D:(20\d{6})/', $body, $m)) {
        $s = $m[1];
        return substr($s,0,4).'-'.substr($s,4,2).'-'.substr($s,6,2);
    }
    return null;
}

// ── Action: municipalities ─────────────────────────────────────────────────

if ($action === 'municipalities') {
    $cached = cache_read('municipalities', 86400);
    if (!$cached) {
        $raw    = fetch_url('https://skolinspektionen.se/api/siris/counties/');
        $cached = json_decode($raw, true) ?: [];
        cache_write('municipalities', $cached);
    }
    echo json_encode($cached);
    exit;
}

// ── Action: decisions ─────────────────────────────────────────────────────

if ($action === 'decisions' && $ar) {
    $years = array_values(array_filter(
        array_map('intval', preg_split('/[,\s]+/', $ar)),
        fn($y) => $y >= 2020 && $y <= 2030
    ));
    sort($years);
    $cache_key = 'decisions_v3_' . implode('_', $years);

    $cached = cache_read($cache_key, 21600);
    if ($cached && !isset($_GET['force'])) { echo json_encode($cached); exit; }

    $name_to_kod = get_muni_map();

    // Fetch from doksok_api endpoints (single requests, much faster than 291 county calls)
    $SIRIS = 'https://siris.skolverket.se';
    $endpoints = [
        "$SIRIS/siris/reports/doksok_api/dokument_rit/?pSkolform=&pKommun=&pHman=&pSkolkod=",
        "$SIRIS/siris/reports/doksok_api/dokument_kg/?pSkolform=&pKommun=&pHman=&pSkolkod=",
    ];

    $all  = [];
    $seen = [];

    foreach ($endpoints as $url) {
        $body = fetch_url($url);
        if (!$body) continue;
        $data = json_decode($body, true);
        if (!$data || !isset($data['dokument'])) continue;

        foreach ($data['dokument'] as $doc) {
            if (!preg_match('/docID=(\d+)/', $doc['link'] ?? '', $m)) continue;
            $docid = intval($m[1]);
            if (isset($seen[$docid])) continue;

            $parsed = parse_titel($doc['titel'] ?? '', $doc['doktyp'] ?? '', $name_to_kod);
            if (!$parsed['ar'] || !in_array($parsed['ar'], $years)) continue;
            if (!$parsed['kommun']) continue;

            $seen[$docid] = true;
            $all[] = [
                'skola'   => $parsed['skola'],
                'kommun'  => $parsed['kommun'],
                'kod'     => $parsed['kod'],
                'region'  => $parsed['region'],
                'typ'     => $parsed['typ'],
                'skolform'=> $parsed['skolform'],
                'ar'      => $parsed['ar'],
                'datum'   => approx_datum($docid),
                'datum_approx' => true,
                'drift'   => null,
                'url'     => 'http:'.$doc['link'],
                'docid'   => $docid,
            ];
        }
    }

    usort($all, fn($a,$b) => $b['docid'] - $a['docid']);
    cache_write($cache_key, $all);
    echo json_encode($all, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Action: fetch_dates ──────────────────────────────────────────────────

if ($action === 'fetch_dates') {
    $raw_ids = preg_split('/[,\s]+/', $_GET['docids'] ?? '');
    $docids  = array_filter(array_map('intval', $raw_ids), fn($d) => $d > 100000);

    $results  = [];
    $to_fetch = [];

    foreach ($docids as $docid) {
        $cached = cache_read('pdfdate_'.$docid, 86400 * 60);
        if ($cached !== null) {
            $results[$docid] = $cached['d'];
        } else {
            $to_fetch[$docid] = "http://siris.skolverket.se/siris/ris.openfile?docID=$docid";
        }
    }

    if ($to_fetch) {
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
