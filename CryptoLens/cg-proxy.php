<?php
// ══════════════════════════════════════════════════════════════
//  CoinGecko API Proxy — keeps the API key server-side
//  Called by CryptoLens JS as: /cryptolens/cg-proxy.php?endpoint=/coins/markets&...
// ══════════════════════════════════════════════════════════════

// ── API key (never exposed to the browser) ───────────────────
$CG_KEY  = 'CG-aQzxrfUMSk8gSSKBgmpA3Uht';
$CG_BASE = 'https://api.coingecko.com/api/v3';

// ── Whitelist of allowed endpoints ───────────────────────────
$ALLOWED = [
    '/coins/markets',
    '/coins/list',
    '/search/trending',
    '/global',
    '/news',
];

function allowed(string $endpoint, array $whitelist): bool {
    foreach ($whitelist as $prefix) {
        if (str_starts_with($endpoint, $prefix)) return true;
    }
    return false;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ryanbayles.duckdns.org');

$endpoint = $_GET['endpoint'] ?? '';
if (!$endpoint || !allowed($endpoint, $ALLOWED)) {
    http_response_code(403);
    echo json_encode(['error' => 'Endpoint not allowed']);
    exit;
}

// Build query — pass through all params except 'endpoint', inject key
$params = $_GET;
unset($params['endpoint']);
$params['x_cg_demo_api_key'] = $CG_KEY;

$url = $CG_BASE . $endpoint . '?' . http_build_query($params);

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: CryptoLens-Proxy/1.0\r\n",
]]);

$body = @file_get_contents($url, false, $ctx);
if ($body === false) {
    http_response_code(502);
    echo json_encode(['error' => 'CoinGecko request failed']);
    exit;
}

// Forward CoinGecko's status code
$status = 200;
foreach ($http_response_header as $h) {
    if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1];
}
http_response_code($status);
echo $body;
