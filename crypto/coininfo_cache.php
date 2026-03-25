<?php
// coininfo_cache.php — Server-side proxy + cache for CoinGecko API
// Protects against rate limits by caching responses as JSON files for 1 hour.
// Endpoints: ?action=markets | ?action=chart&coin=X | ?action=news

header('Content-Type: application/json');

$TTL      = 3600; // seconds
$CACHE_DIR = sys_get_temp_dir() . '/coininfo_cache';
$VALID_COINS = ['bitcoin', 'ethereum', 'ripple'];
$BASE = 'https://api.coingecko.com/api/v3';

if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

$action = $_GET['action'] ?? '';
$coin   = preg_replace('/[^a-z]/', '', strtolower($_GET['coin'] ?? ''));

// ── Cache helpers ────────────────────────────────────────────
function cacheFile(string $key): string {
    global $CACHE_DIR;
    return "$CACHE_DIR/$key.json";
}

function getCached(string $key): ?string {
    global $TTL;
    $f = cacheFile($key);
    return (file_exists($f) && time() - filemtime($f) < $TTL) ? file_get_contents($f) : null;
}

function fetchAndCache(string $key, string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'header'  => "User-Agent: CryptoDashboard/1.0\r\n"
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        http_response_code(502);
        return json_encode(['error' => 'upstream fetch failed']);
    }
    file_put_contents(cacheFile($key), $data);
    return $data;
}

// ── Route ────────────────────────────────────────────────────
if ($action === 'markets') {
    echo getCached('markets') ?? fetchAndCache('markets',
        "$BASE/coins/markets?vs_currency=usd&ids=bitcoin,ethereum,ripple" .
        "&order=market_cap_desc&per_page=3&page=1&sparkline=false&price_change_percentage=7d"
    );
} elseif ($action === 'chart' && in_array($coin, $VALID_COINS)) {
    $key = "chart_$coin";
    echo getCached($key) ?? fetchAndCache($key,
        "$BASE/coins/$coin/market_chart?vs_currency=usd&days=365&interval=weekly"
    );
} elseif ($action === 'news') {
    echo getCached('news') ?? fetchAndCache('news', "$BASE/news");
} else {
    http_response_code(400);
    echo json_encode(['error' => 'invalid action']);
}
