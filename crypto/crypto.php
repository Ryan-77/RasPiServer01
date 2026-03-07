<?php
session_start();

// ══════════════════════════════════════════════════════════════
//  CONFIG
// ══════════════════════════════════════════════════════════════
$DB_FILE   = '/var/www/data/crypto.db';
$LOG_FILE  = '/var/www/html/crypto/log.txt';
$LOG_LINES = 200;
$PAGE_SIZE = 25;
$AUTH_PASS = 'changeme';  // ← change this

$SUPPORTED_COINS = [
    'btc'  => 'Bitcoin',    'eth'  => 'Ethereum',   'sol'  => 'Solana',
    'ltc'  => 'Litecoin',   'xrp'  => 'XRP',        'bch'  => 'Bitcoin Cash',
    'link' => 'Chainlink',  'ada'  => 'Cardano',     'avax' => 'Avalanche',
    'doge' => 'Dogecoin',   'dot'  => 'Polkadot',   'matic'=> 'Polygon',
];

// ══════════════════════════════════════════════════════════════
//  AUTH
// ══════════════════════════════════════════════════════════════
if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
if (!isset($_SESSION['auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['pass'] ?? '') === $AUTH_PASS) {
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } else {
        $err = $_SERVER['REQUEST_METHOD'] === 'POST';
        renderLogin($err); exit;
    }
}
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// ══════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════
function h($s)   { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf()  { return $_SESSION['csrf']; }
function checkCsrf() {
    if (!hash_equals($_SESSION['csrf'], $_POST['_csrf'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}
function buildUrl($p = []) {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $q    = array_merge(['view' => $_GET['view'] ?? 'analysis'], $p);
    unset($q['page']);
    if (isset($p['page'])) $q['page'] = $p['page'];
    return $base . '?' . http_build_query($q);
}
function tailFile($file, $n) {
    $f = fopen($file, 'rb'); if (!$f) return false;
    fseek($f, 0, SEEK_END); $pos = ftell($f); $data = ''; $found = 0;
    while ($pos > 0 && $found <= $n) {
        $read = min(4096, $pos); $pos -= $read; fseek($f, $pos);
        $data = fread($f, $read) . $data; $found = substr_count($data, "\n");
    }
    fclose($f);
    return implode("\n", array_slice(explode("\n", $data), -$n));
}

// ══════════════════════════════════════════════════════════════
//  DATABASE
// ══════════════════════════════════════════════════════════════
function db() {
    global $DB_FILE;
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO("sqlite:$DB_FILE");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

// ══════════════════════════════════════════════════════════════
//  PORTFOLIO CRUD (POST handlers)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    global $SUPPORTED_COINS;

    if ($action === 'upsert') {
        $coin   = strtolower(trim($_POST['coin']  ?? ''));
        $amount = (float)($_POST['amount']         ?? 0);
        $tgt    = isset($_POST['target_pct']) && $_POST['target_pct'] !== '' ? (float)$_POST['target_pct'] : null;
        if (!array_key_exists($coin, $SUPPORTED_COINS) || $amount <= 0) {
            $_SESSION['flash'] = ['type'=>'err','msg'=>'Invalid coin or amount.'];
        } else {
            db()->prepare("INSERT INTO portfolio (coin, amount, target_pct, updated_at)
                           VALUES (?, ?, ?, ?)
                           ON CONFLICT(coin) DO UPDATE SET amount=excluded.amount,
                           target_pct=excluded.target_pct, updated_at=excluded.updated_at")
               ->execute([$coin, $amount, $tgt, date('c')]);
            $_SESSION['flash'] = ['type'=>'ok','msg'=>strtoupper($coin).' updated.'];
        }
    } elseif ($action === 'delete') {
        $coin = strtolower(trim($_POST['coin'] ?? ''));
        db()->prepare("DELETE FROM portfolio WHERE coin = ?")->execute([$coin]);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>strtoupper($coin).' removed.'];
    }

    header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
}

// ══════════════════════════════════════════════════════════════
//  VIEW DATA
// ══════════════════════════════════════════════════════════════
$view = in_array($_GET['view'] ?? '', ['portfolio','analysis','log','db']) ? $_GET['view'] : 'analysis';
$page = max(1, (int)($_GET['page'] ?? 1));

// Portfolio
$portfolio = [];
if (file_exists($DB_FILE)) {
    try { $portfolio = db()->query("SELECT * FROM portfolio ORDER BY coin")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) {}
}

// Latest prices per coin (from price_history)
$latestPrices = [];
foreach ($portfolio as $row) {
    try {
        $r = db()->prepare("SELECT price_usd FROM price_history WHERE coin=? ORDER BY timestamp DESC LIMIT 1");
        $r->execute([$row['coin']]);
        $p = $r->fetchColumn();
        if ($p !== false) $latestPrices[$row['coin']] = (float)$p;
    } catch (Exception $e) {}
}

// Portfolio totals
$totalUsd = array_sum(array_map(fn($r) => $r['amount'] * ($latestPrices[$r['coin']] ?? 0), $portfolio));

// Analysis signals
$signals = []; $sigError = null;
if ($view === 'analysis' && file_exists($DB_FILE)) {
    try {
        $signals = db()->query(
            "SELECT * FROM analysis_signals ORDER BY timestamp DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $sigError = h($e->getMessage()); }
}

// Log
$logContent = $logError = null;
if ($view === 'log') {
    if (!file_exists($LOG_FILE)) $logError = 'Log file not found.';
    else { $logContent = tailFile($LOG_FILE, $LOG_LINES); if ($logContent === false) $logError = 'Cannot read log.'; }
}

// Raw DB
$dbRows = []; $dbCols = []; $dbTotal = 0; $dbError = null;
if ($view === 'db' && file_exists($DB_FILE)) {
    try {
        $dbTotal  = (int)db()->query("SELECT COUNT(*) FROM arbitrage_results")->fetchColumn();
        $offset   = ($page-1) * $PAGE_SIZE;
        $dbRows   = db()->query("SELECT * FROM arbitrage_results ORDER BY timestamp DESC LIMIT $PAGE_SIZE OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        if ($dbRows) $dbCols = array_keys($dbRows[0]);
    } catch (Exception $e) { $dbError = h($e->getMessage()); }
}
$totalPages = max(1, (int)ceil($dbTotal / $PAGE_SIZE));

// Flash message
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// Signal display helpers
function strategyColor($s) {
    return match($s) {
        'arbitrage' => '#00d4ff', 'pairs'  => '#a855f7',
        'rebalance' => '#ffd740', 'momentum' => '#f97316',
        default     => '#6888a8',
    };
}
function signalColor($sig) {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '#00e676';
    if (str_contains($sig,'sell')) return '#ff5252';
    if ($sig==='rebalance') return '#ffd740';
    return '#6888a8';
}
function signalIcon($sig) {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '↑';
    if (str_contains($sig,'sell')) return '↓';
    if ($sig==='rebalance') return '⇄';
    return '—';
}

function renderLogin($err=false) { ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Auth</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;
     background:#07090f;font-family:'Courier New',monospace}
.card{width:380px;padding:52px 44px;background:#0d1220;border:1px solid #1e2d45;
      border-radius:14px;box-shadow:0 0 80px rgba(0,180,255,.07)}
.logo{text-align:center;font-size:1.7rem;font-weight:700;letter-spacing:5px;
      color:#00d4ff;text-shadow:0 0 24px rgba(0,212,255,.5);margin-bottom:6px}
.sub{text-align:center;font-size:.65rem;letter-spacing:4px;color:#2a4060;margin-bottom:36px}
.err{background:rgba(255,60,60,.08);border:1px solid rgba(255,60,60,.25);color:#ff6b6b;
     padding:11px;border-radius:8px;font-size:.78rem;text-align:center;margin-bottom:18px}
label{font-size:.62rem;letter-spacing:3px;color:#3a5068;display:block;margin-bottom:8px}
input[type=password]{width:100%;padding:13px 16px;background:#07090f;border:1px solid #1e3050;
     border-radius:8px;color:#a0c4e8;font-family:inherit;font-size:.9rem;outline:none}
input[type=password]:focus{border-color:#00d4ff;box-shadow:0 0 0 3px rgba(0,212,255,.1)}
button{width:100%;margin-top:14px;padding:14px;background:linear-gradient(135deg,#0055aa,#0099ee);
       border:none;border-radius:8px;color:#fff;font-family:inherit;font-size:.8rem;
       letter-spacing:3px;cursor:pointer}
button:hover{opacity:.88}
.hint{text-align:center;margin-top:20px;font-size:.6rem;color:#1e3048;letter-spacing:3px}
</style></head><body>
<div class="card">
  <div class="logo">◈ ARBITRAGE</div>
  <div class="sub">SECURE DASHBOARD ACCESS</div>
  <?php if($err): ?><div class="err">⚠ Invalid credentials</div><?php endif ?>
  <form method="POST">
    <label>ACCESS KEY</label>
    <input type="password" name="pass" autofocus autocomplete="current-password" placeholder="••••••••••">
    <button type="submit">AUTHENTICATE →</button>
  </form>
  <div class="hint">RASPBERRY PI SERVER NODE</div>
</div></body></html>
<?php }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Crypto Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07090f; --s1:#0d1220; --s2:#0a101c; --s3:#111827;
  --b1:#1a2540; --b2:#243050;
  --ac:#00d4ff; --ac-d:rgba(0,212,255,.12);
  --gn:#00e676; --gn-d:rgba(0,230,118,.12);
  --rd:#ff5252; --yw:#ffd740; --pu:#a855f7; --or:#f97316;
  --t1:#c8d8f0; --t2:#6888a8; --t3:#354e6a;
  --mono:'Courier New',monospace; --r:10px;
}
html{scroll-behavior:smooth}
body{min-height:100vh;background:var(--bg);color:var(--t1);font-family:var(--mono);font-size:14px;line-height:1.6}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--b2);border-radius:3px}

/* Layout */
.wrap{max-width:1440px;margin:0 auto;padding:24px 20px}

/* Header */
.hdr{display:flex;align-items:center;justify-content:space-between;
     padding:20px 28px;margin-bottom:20px;
     background:linear-gradient(135deg,#0d1525,#091428 60%,#0d1525);
     border:1px solid var(--b1);border-radius:var(--r);position:relative;overflow:hidden}
.hdr::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
             background:linear-gradient(90deg,transparent,var(--ac),transparent)}
.hdr-l{display:flex;align-items:center;gap:16px}
.pulse{position:relative;width:12px;height:12px;flex-shrink:0}
.pulse .d{position:absolute;inset:3px;border-radius:50%;background:var(--gn);box-shadow:0 0 8px var(--gn)}
.pulse .r{position:absolute;inset:0;border-radius:50%;border:1px solid var(--gn);opacity:.6;
          animation:rp 2s ease-out infinite}
@keyframes rp{0%{transform:scale(.8);opacity:.8}100%{transform:scale(2);opacity:0}}
.logo{font-size:1.4rem;font-weight:700;letter-spacing:4px;color:var(--ac);text-shadow:0 0 20px rgba(0,212,255,.4)}
.logo-sub{font-size:.58rem;letter-spacing:3px;color:var(--t3);margin-top:2px}
.hdr-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.clock-box{padding:6px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;text-align:right}
.clock-t{font-size:1rem;letter-spacing:2px;color:var(--ac);font-variant-numeric:tabular-nums}
.clock-d{font-size:.58rem;letter-spacing:2px;color:var(--t3);margin-top:1px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;
     font-family:var(--mono);font-size:.7rem;letter-spacing:1.5px;cursor:pointer;
     text-decoration:none;border:1px solid transparent;transition:all .2s;white-space:nowrap}
.btn-g{background:transparent;border-color:var(--b2);color:var(--t2)}
.btn-g:hover{border-color:var(--ac);color:var(--ac);background:var(--ac-d)}
.btn-r{background:rgba(255,82,82,.08);border-color:rgba(255,82,82,.25);color:var(--rd)}
.btn-r:hover{background:rgba(255,82,82,.18)}

/* Portfolio summary bar */
.port-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
          gap:12px;margin-bottom:18px}
.pstat{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);
       padding:16px 18px;position:relative;overflow:hidden}
.pstat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;
              background:var(--ac);transform:scaleX(0);transform-origin:left;transition:transform .3s}
.pstat:hover::after{transform:scaleX(1)}
.pstat-l{font-size:.58rem;letter-spacing:3px;color:var(--t3);margin-bottom:8px}
.pstat-v{font-size:1.5rem;font-weight:700}
.pstat-s{font-size:.62rem;color:var(--t3);margin-top:4px}

/* Nav */
.nav{display:flex;align-items:center;gap:6px;margin-bottom:18px;
     background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:6px}
.nav a{padding:9px 22px;border-radius:7px;font-size:.7rem;letter-spacing:2px;
       text-decoration:none;color:var(--t2);transition:all .2s;border:1px solid transparent}
.nav a:hover{color:var(--t1);background:var(--s3)}
.nav a.active{background:var(--ac-d);color:var(--ac);border-color:rgba(0,212,255,.25)}
.nav-sp{flex:1}

/* Flash */
.flash{padding:12px 18px;border-radius:8px;margin-bottom:16px;font-size:.78rem;letter-spacing:1px}
.flash.ok{background:var(--gn-d);border:1px solid rgba(0,230,118,.25);color:var(--gn)}
.flash.err{background:rgba(255,82,82,.08);border:1px solid rgba(255,82,82,.25);color:var(--rd)}

/* Panel */
.panel{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;
       box-shadow:0 4px 40px rgba(0,0,0,.3)}
.ph{display:flex;align-items:center;justify-content:space-between;
    padding:14px 20px;border-bottom:1px solid var(--b1);background:var(--s2);flex-wrap:wrap;gap:10px}
.ph-t{font-size:.68rem;letter-spacing:3px;color:var(--t2)}
.ph-m{font-size:.62rem;color:var(--t3)}

/* Portfolio table */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.8rem}
thead tr{background:var(--s2)}
th{padding:11px 16px;text-align:left;font-size:.6rem;letter-spacing:2px;color:var(--t3);
   border-bottom:2px solid var(--b2);white-space:nowrap;font-weight:600}
tbody tr{border-bottom:1px solid rgba(26,37,64,.5);transition:background .12s}
tbody tr:hover{background:rgba(0,212,255,.04)}
td{padding:10px 16px;color:var(--t1);vertical-align:middle}
td.num{color:var(--gn);text-align:right;font-variant-numeric:tabular-nums}
td.muted{color:var(--t3);font-size:.75rem}
td.na{color:var(--t3);font-style:italic;font-size:.75rem}

/* Portfolio form */
.port-form{padding:20px;border-top:1px solid var(--b1);background:var(--s2)}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.form-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:140px}
.form-group label{font-size:.6rem;letter-spacing:2px;color:var(--t3)}
.form-group input,.form-group select{
  background:var(--bg);border:1px solid var(--b2);color:var(--t1);
  font-family:var(--mono);font-size:.82rem;padding:9px 12px;border-radius:7px;
  outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{border-color:var(--ac)}
.btn-primary{background:linear-gradient(135deg,#0055aa,#0099ee);border:none;
             color:#fff;font-family:var(--mono);font-size:.75rem;letter-spacing:2px;
             padding:10px 20px;border-radius:8px;cursor:pointer;transition:opacity .2s;
             white-space:nowrap;align-self:flex-end}
.btn-primary:hover{opacity:.85}
.btn-del{background:rgba(255,82,82,.08);border:1px solid rgba(255,82,82,.25);color:var(--rd);
         font-family:var(--mono);font-size:.65rem;padding:5px 10px;border-radius:6px;
         cursor:pointer;transition:all .2s}
.btn-del:hover{background:rgba(255,82,82,.2)}

/* Allocation bar */
.alloc-bar{height:4px;background:var(--b2);border-radius:2px;margin-top:4px;overflow:hidden}
.alloc-fill{height:100%;border-radius:2px;transition:width .3s}

/* Signal cards */
.signals-grid{padding:16px;display:flex;flex-direction:column;gap:10px}
.sig-card{background:var(--s2);border:1px solid var(--b1);border-radius:var(--r);
          overflow:hidden;transition:border-color .2s}
.sig-card:hover{border-color:var(--b2)}
.sig-top{display:flex;align-items:center;gap:12px;padding:14px 16px;flex-wrap:wrap}
.sig-rank{font-size:.65rem;color:var(--t3);min-width:28px}
.strat-badge{font-size:.6rem;letter-spacing:2px;padding:4px 10px;border-radius:20px;font-weight:600;white-space:nowrap}
.coin-badges{display:flex;gap:5px;flex-wrap:wrap}
.coin-badge{font-size:.65rem;padding:3px 8px;border-radius:5px;background:rgba(255,255,255,.05);
            border:1px solid var(--b2);color:var(--t2);letter-spacing:1px}
.sig-signal{font-size:.75rem;letter-spacing:1px;font-weight:600}
.sig-sp{flex:1}
.sig-usd{font-size:.85rem;color:var(--gn);font-variant-numeric:tabular-nums;white-space:nowrap}
.sig-usd.zero{color:var(--t3)}
.strength-wrap{display:flex;align-items:center;gap:8px;padding:0 16px 12px}
.strength-bar{flex:1;height:3px;background:var(--b1);border-radius:2px;overflow:hidden}
.strength-fill{height:100%;border-radius:2px}
.strength-val{font-size:.62rem;color:var(--t3);white-space:nowrap;min-width:60px;text-align:right}
.sig-detail{padding:0 16px 14px}
details summary{font-size:.65rem;letter-spacing:2px;color:var(--t3);cursor:pointer;
                list-style:none;padding:4px 0}
details summary::-webkit-details-marker{display:none}
details summary::before{content:'▶ ';font-size:.55rem}
details[open] summary::before{content:'▼ '}
.detail-json{margin-top:8px;padding:10px 12px;background:var(--bg);border:1px solid var(--b1);
             border-radius:6px;font-size:.72rem;color:var(--t2);white-space:pre-wrap;word-break:break-all;
             max-height:200px;overflow-y:auto;line-height:1.8}
.sig-ts{font-size:.6rem;color:var(--t3);padding:0 16px 10px;letter-spacing:1px}

/* Strategy filter pills */
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--b1)}
.filter-pill{font-size:.62rem;letter-spacing:1.5px;padding:5px 14px;border-radius:20px;
             cursor:pointer;text-decoration:none;transition:all .2s;border:1px solid var(--b1);color:var(--t2)}
.filter-pill:hover{border-color:var(--b2);color:var(--t1)}
.filter-pill.active{color:#000;font-weight:700}

/* Log */
.log-body{padding:18px 20px;max-height:68vh;overflow-y:auto;font-size:.78rem;line-height:2;
          white-space:pre-wrap;word-break:break-all}
.ll{display:block;padding:0 4px;border-radius:3px}
.ll:hover{background:rgba(255,255,255,.03)}
.ll-err{color:#ff6b6b}.ll-warn{color:var(--yw)}.ll-ok{color:var(--gn)}.ll-ts{color:#7aa8cc}.ll-d{color:#5a88a8}

/* Pagination */
.pgn{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;
     border-top:1px solid var(--b1);background:var(--s2);flex-wrap:wrap;gap:10px}
.pgn-info{font-size:.65rem;color:var(--t3)}
.pgn-links{display:flex;gap:5px;flex-wrap:wrap}
.pgn-links a,.pgn-links span{display:inline-flex;align-items:center;justify-content:center;
  min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:.72rem;
  text-decoration:none;border:1px solid var(--b1);transition:all .18s}
.pgn-links a{color:var(--t2)}
.pgn-links a:hover{border-color:var(--ac);color:var(--ac);background:var(--ac-d)}
.pgn-links .cur{background:var(--ac-d);border-color:rgba(0,212,255,.35);color:var(--ac);font-weight:700}
.pgn-links .dis{color:var(--t3)}

/* Empty/error states */
.state{padding:70px 20px;text-align:center}
.state-i{font-size:2.5rem;opacity:.35;margin-bottom:14px}
.state-t{font-size:.8rem;letter-spacing:3px;color:var(--t2)}
.state-s{font-size:.7rem;color:var(--t3);margin-top:8px}
.err-box{margin:18px;padding:14px 18px;background:rgba(255,82,82,.06);
         border:1px solid rgba(255,82,82,.2);border-radius:8px;color:#ff7070;font-size:.8rem}

/* Footer */
.footer{text-align:center;padding:20px;margin-top:18px;font-size:.58rem;letter-spacing:3px;color:var(--t3)}

/* Responsive */
@media(max-width:700px){
  .hdr{flex-direction:column;gap:14px;text-align:center}
  .hdr-r{justify-content:center}
  .hdr-l{flex-direction:column}
  .port-bar{grid-template-columns:1fr 1fr}
  .nav{flex-wrap:wrap}
  .nav-sp{display:none}
  .form-row{flex-direction:column}
}
</style>
</head>
<body>
<div class="wrap">

<!-- HEADER -->
<header class="hdr">
  <div class="hdr-l">
    <div class="pulse"><div class="d"></div><div class="r"></div></div>
    <div>
      <div class="logo">◈ ARBITRAGE DASHBOARD</div>
      <div class="logo-sub">RASPBERRY PI NODE · PORTFOLIO ANALYSIS SYSTEM</div>
    </div>
  </div>
  <div class="hdr-r">
    <div class="clock-box">
      <div class="clock-t" id="clock">──:──:──</div>
      <div class="clock-d" id="cdate">────────────</div>
    </div>
    <a href="?logout=1" class="btn btn-r">⏻ LOGOUT</a>
  </div>
</header>

<!-- PORTFOLIO SUMMARY BAR -->
<?php
$coinCount  = count($portfolio);
$hasTargets = (bool)array_filter($portfolio, fn($r) => $r['target_pct'] !== null);
$sigCount   = count($signals);
$recentSig  = $signals[0]['timestamp'] ?? null;
?>
<div class="port-bar">
  <div class="pstat">
    <div class="pstat-l">PORTFOLIO VALUE</div>
    <div class="pstat-v" style="color:var(--gn)">
      <?= $totalUsd > 0 ? '$'.number_format($totalUsd,2) : '<span style="color:var(--t3)">N/A</span>' ?>
    </div>
    <div class="pstat-s"><?= $coinCount ?> asset<?= $coinCount!==1?'s':'' ?> tracked</div>
  </div>
  <div class="pstat">
    <div class="pstat-l">OPEN SIGNALS</div>
    <div class="pstat-v" style="color:var(--ac)"><?= $sigCount ?></div>
    <div class="pstat-s">last 100 results</div>
  </div>
  <div class="pstat">
    <div class="pstat-l">LAST ANALYSIS</div>
    <div class="pstat-v" style="font-size:1rem;color:var(--t1)">
      <?= $recentSig ? date('H:i', strtotime($recentSig)) : '——' ?>
    </div>
    <div class="pstat-s"><?= $recentSig ? date('d M Y', strtotime($recentSig)) : 'no data yet' ?></div>
  </div>
  <div class="pstat">
    <div class="pstat-l">ALLOCATION</div>
    <div class="pstat-v" style="font-size:1rem;color:<?= $hasTargets ? 'var(--yw)' : 'var(--t3)' ?>">
      <?= $hasTargets ? 'TARGETS SET' : 'NO TARGETS' ?>
    </div>
    <div class="pstat-s">rebalance <?= $hasTargets ? 'active' : 'inactive' ?></div>
  </div>
</div>

<!-- NAV -->
<nav class="nav">
  <a href="<?= buildUrl(['view'=>'analysis']) ?>" class="<?= $view==='analysis'?'active':'' ?>">📊 ANALYSIS</a>
  <a href="<?= buildUrl(['view'=>'portfolio']) ?>" class="<?= $view==='portfolio'?'active':'' ?>">💼 PORTFOLIO</a>
  <a href="<?= buildUrl(['view'=>'log']) ?>" class="<?= $view==='log'?'active':'' ?>">📋 LOG</a>
  <a href="<?= buildUrl(['view'=>'db']) ?>" class="<?= $view==='db'?'active':'' ?>">🗄 RAW DB</a>
  <div class="nav-sp"></div>
  <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">CRON: */5 * * * *</span>
</nav>

<!-- FLASH -->
<?php if ($flash): ?>
<div class="flash <?= $flash['type'] ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<!-- ══ ANALYSIS VIEW ══════════════════════════════════════════════════════════ -->
<?php if ($view === 'analysis'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">📊 RANKED SIGNALS — ALL MODULES</div>
    <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
  </div>

  <?php
  $filterStrat = $_GET['strat'] ?? 'all';
  $stratCounts = [];
  foreach ($signals as $s) $stratCounts[$s['strategy']] = ($stratCounts[$s['strategy']] ?? 0) + 1;
  $displayed   = $filterStrat === 'all' ? $signals
               : array_values(array_filter($signals, fn($s) => $s['strategy'] === $filterStrat));
  ?>

  <div class="filter-bar">
    <?php
    $pills = ['all'=>'ALL'] + array_map(fn($k)=>strtoupper($k), array_combine(
        ['arbitrage','pairs','rebalance','momentum'],
        ['arbitrage','pairs','rebalance','momentum']
    ));
    $pillColors = ['arbitrage'=>'var(--ac)','pairs'=>'var(--pu)','rebalance'=>'var(--yw)','momentum'=>'var(--or)','all'=>'var(--t1)'];
    foreach ($pills as $key => $label):
      $cnt    = $key==='all' ? count($signals) : ($stratCounts[$key] ?? 0);
      $active = $filterStrat === $key ? 'active' : '';
      $col    = $pillColors[$key] ?? 'var(--t2)';
      $style  = $active ? "background:$col;border-color:$col;" : '';
    ?>
    <a href="<?= buildUrl(['view'=>'analysis','strat'=>$key]) ?>"
       class="filter-pill <?= $active ?>" style="<?= $style ?>">
      <?= $label ?> <span style="opacity:.6"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($sigError): ?>
    <div class="err-box">⚠ <?= $sigError ?></div>
  <?php elseif (empty($displayed)): ?>
    <div class="state">
      <div class="state-i">📭</div>
      <div class="state-t">NO SIGNALS YET</div>
      <div class="state-s">Add coins to your portfolio and wait for the cron job to run</div>
    </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($displayed as $i => $sig):
      $details  = json_decode($sig['details'] ?? '{}', true) ?? [];
      $sc       = strategyColor($sig['strategy']);
      $sigc     = signalColor($sig['signal']);
      $icon     = signalIcon($sig['signal']);
      $coins    = explode(',', $sig['coins']);
      $pct      = round(($sig['strength'] ?? 0) * 100);
      $ts       = date('d M H:i', strtotime($sig['timestamp']));
    ?>
    <div class="sig-card" style="border-left:3px solid <?= $sc ?>">
      <div class="sig-top">
        <div class="sig-rank">#<?= $i+1 ?></div>
        <div class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
          <?= strtoupper(h($sig['strategy'])) ?>
        </div>
        <div class="coin-badges">
          <?php foreach ($coins as $c): ?>
          <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="sig-signal" style="color:<?= $sigc ?>"><?= $icon ?> <?= h($sig['signal']) ?></div>
        <div class="sig-sp"></div>
        <?php if ($sig['expected_usd'] > 0): ?>
        <div class="sig-usd">~$<?= number_format((float)$sig['expected_usd'], 2) ?></div>
        <?php else: ?>
        <div class="sig-usd zero">—</div>
        <?php endif; ?>
      </div>
      <div class="strength-wrap">
        <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">STRENGTH</span>
        <div class="strength-bar">
          <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
        </div>
        <div class="strength-val"><?= $pct ?>% · score <?= number_format((float)($sig['strength']??0),3) ?></div>
      </div>
      <?php if (!empty($details)): ?>
      <div class="sig-detail">
        <details>
          <summary>DETAILS</summary>
          <div class="detail-json"><?= h(json_encode($details, JSON_PRETTY_PRINT)) ?></div>
        </details>
      </div>
      <?php endif; ?>
      <div class="sig-ts"><?= $ts ?> UTC</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ PORTFOLIO VIEW ═════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'portfolio'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">💼 PORTFOLIO HOLDINGS</div>
    <div class="ph-m">TOTAL: <?= $totalUsd>0 ? '$'.number_format($totalUsd,2) : 'N/A (run analysis first)' ?></div>
  </div>

  <?php if (empty($portfolio)): ?>
  <div class="state">
    <div class="state-i">💼</div>
    <div class="state-t">NO HOLDINGS YET</div>
    <div class="state-s">Add your first coin below</div>
  </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>AMOUNT</th><th>PRICE (USD)</th>
        <th>VALUE (USD)</th><th>ALLOCATION</th><th>TARGET %</th><th></th>
      </tr></thead>
      <tbody>
      <?php
      $totalPct = array_sum(array_column($portfolio, 'target_pct'));
      foreach ($portfolio as $row):
        $price    = $latestPrices[$row['coin']] ?? null;
        $val      = $price ? $row['amount'] * $price : null;
        $allocPct = ($val && $totalUsd > 0) ? ($val/$totalUsd)*100 : null;
        $drift    = ($allocPct !== null && $row['target_pct']) ? $allocPct - $row['target_pct'] : null;
        $driftCol = ($drift !== null) ? (abs($drift) > 5 ? ($drift>0?'var(--rd)':'var(--gn)') : 'var(--t3)') : 'var(--t3)';
      ?>
      <tr>
        <td>
          <span style="font-weight:700;color:var(--ac)"><?= strtoupper(h($row['coin'])) ?></span>
          <br><span class="muted"><?= h($SUPPORTED_COINS[$row['coin']] ?? '') ?></span>
        </td>
        <td class="num"><?= number_format((float)$row['amount'], 8) ?></td>
        <td class="num"><?= $price ? '$'.number_format($price, 2) : '<span class="na">no data</span>' ?></td>
        <td class="num"><?= $val ? '$'.number_format($val, 2) : '<span class="na">—</span>' ?></td>
        <td>
          <?php if ($allocPct !== null): ?>
          <span style="font-size:.78rem;color:var(--t2)"><?= round($allocPct,1) ?>%</span>
          <?php if ($row['target_pct']): ?>
          <span style="font-size:.65rem;color:<?= $driftCol ?>;margin-left:4px">
            (<?= $drift>0?'+':'' ?><?= round($drift,1) ?>%)
          </span>
          <?php endif; ?>
          <div class="alloc-bar"><div class="alloc-fill" style="width:<?= min($allocPct,100) ?>%;background:var(--ac)"></div></div>
          <?php else: ?><span class="na">—</span><?php endif; ?>
        </td>
        <td class="muted"><?= $row['target_pct'] !== null ? round($row['target_pct'],1).'%' : '—' ?></td>
        <td>
          <form method="POST" onsubmit="return confirm('Remove <?= strtoupper($row['coin']) ?>?')">
            <input type="hidden" name="_csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="coin" value="<?= h($row['coin']) ?>">
            <button type="submit" class="btn-del">✕ REMOVE</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($hasTargets): ?>
      <tr style="border-top:2px solid var(--b2)">
        <td colspan="5" style="font-size:.62rem;letter-spacing:2px;color:var(--t3)">TARGET TOTAL</td>
        <td class="muted" style="color:<?= abs($totalPct-100)<1?'var(--gn)':'var(--rd)' ?>">
          <?= round($totalPct,1) ?>%
          <?= abs($totalPct-100)<1 ? '✓' : '(should = 100%)' ?>
        </td>
        <td></td>
      </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add/Update Form -->
  <div class="port-form">
    <div style="font-size:.65rem;letter-spacing:3px;color:var(--t3);margin-bottom:14px">
      ADD / UPDATE HOLDING
    </div>
    <form method="POST">
      <input type="hidden" name="_csrf"  value="<?= csrf() ?>">
      <input type="hidden" name="action" value="upsert">
      <div class="form-row">
        <div class="form-group">
          <label>COIN</label>
          <select name="coin">
            <?php foreach ($SUPPORTED_COINS as $ticker => $name): ?>
            <option value="<?= $ticker ?>"><?= strtoupper($ticker) ?> — <?= $name ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>AMOUNT HELD</label>
          <input type="number" name="amount" step="0.00000001" min="0.00000001" placeholder="e.g. 0.05" required>
        </div>
        <div class="form-group">
          <label>TARGET % (optional)</label>
          <input type="number" name="target_pct" step="0.1" min="0" max="100" placeholder="e.g. 40">
        </div>
        <button type="submit" class="btn-primary">+ ADD / UPDATE</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ LOG VIEW ═══════════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'log'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">📋 SYSTEM LOG — LAST <?= $LOG_LINES ?> LINES</div>
    <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
  </div>
  <?php if ($logError): ?>
    <div class="err-box">⚠ <?= h($logError) ?></div>
  <?php elseif (!$logContent): ?>
    <div class="state"><div class="state-i">📭</div><div class="state-t">LOG IS EMPTY</div></div>
  <?php else: ?>
  <div class="log-body" id="log-body"><?php
    foreach (explode("\n", $logContent) as $line) {
      $cls = 'll-d';
      if (preg_match('/error|fail|exception|critical/i', $line))         $cls = 'll-err';
      elseif (preg_match('/warn/i', $line))                              $cls = 'll-warn';
      elseif (preg_match('/success|profit|opportunit|found/i', $line))   $cls = 'll-ok';
      elseif (preg_match('/\d{4}-\d{2}-\d{2}|\d{2}:\d{2}:\d{2}/', $line)) $cls = 'll-ts';
      echo '<span class="ll '.$cls.'">'.h($line).'</span>';
    }
  ?></div>
  <?php endif; ?>
</div>

<!-- ══ RAW DB VIEW ════════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'db'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">🗄 RAW ARBITRAGE RESULTS — <?= number_format($dbTotal) ?> RECORDS</div>
    <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
  </div>
  <?php if ($dbError): ?>
    <div class="err-box">⚠ <?= $dbError ?></div>
  <?php elseif (empty($dbRows)): ?>
    <div class="state"><div class="state-i">🗄</div><div class="state-t">NO RECORDS</div></div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><?php foreach ($dbCols as $c) echo '<th>'.h(strtoupper($c)).'</th>'; ?></tr></thead>
      <tbody>
        <?php foreach ($dbRows as $row): ?>
        <tr><?php foreach ($row as $v) echo '<td>'.h($v).'</td>'; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="pgn">
    <div class="pgn-info">
      <?= number_format(($page-1)*$PAGE_SIZE+1) ?>–<?= number_format(min($page*$PAGE_SIZE,$dbTotal)) ?>
      of <?= number_format($dbTotal) ?>
    </div>
    <div class="pgn-links">
      <?php if ($page>1): ?><a href="<?= buildUrl(['page'=>1]) ?>">«</a><a href="<?= buildUrl(['page'=>$page-1]) ?>">‹</a>
      <?php else: ?><span class="dis">«</span><span class="dis">‹</span><?php endif; ?>
      <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++):
        echo $i===$page ? "<span class='cur'>$i</span>" : '<a href="'.buildUrl(['page'=>$i]).'">'.$i.'</a>';
      endfor; ?>
      <?php if ($page<$totalPages): ?><a href="<?= buildUrl(['page'=>$page+1]) ?>">›</a><a href="<?= buildUrl(['page'=>$totalPages]) ?>">»</a>
      <?php else: ?><span class="dis">›</span><span class="dis">»</span><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="footer">◈ CRYPTO ARBITRAGE DASHBOARD &nbsp;·&nbsp; RASPBERRY PI NODE &nbsp;·&nbsp; <?= date('Y') ?></div>
</div>

<script>
const days=['SUN','MON','TUE','WED','THU','FRI','SAT'],months=['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
function pad(n){return String(n).padStart(2,'0')}
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
  document.getElementById('cdate').textContent=days[n.getDay()]+' · '+pad(n.getDate())+' '+months[n.getMonth()]+' '+n.getFullYear();
}
setInterval(tick,1000); tick();
const lb=document.getElementById('log-body');
if(lb) lb.scrollTop=lb.scrollHeight;
</script>
</body>
</html>
