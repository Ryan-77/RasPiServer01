<?php
// ══════════════════════════════════════════════════════════════
//  CRYPTO DASHBOARD — thin HTML shell
//  All data access goes through db.php; API available at api.php
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';

// ── POST HANDLERS (form submissions from dashboard) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $action = $_POST['action'] ?? '';

    if ($action === 'upsert') {
        $coin   = strtolower(trim($_POST['coin']  ?? ''));
        $amount = (float)($_POST['amount']         ?? 0);
        $tgt    = isset($_POST['target_pct']) && $_POST['target_pct'] !== '' ? (float)$_POST['target_pct'] : null;
        if (!array_key_exists($coin, $SUPPORTED_COINS) || $amount <= 0) {
            $_SESSION['flash'] = ['type'=>'err','msg'=>'Invalid coin or amount.'];
        } else {
            upsertPortfolio($coin, $amount, $tgt);
            $_SESSION['flash'] = ['type'=>'ok','msg'=>strtoupper($coin).' updated.'];
        }
    } elseif ($action === 'delete') {
        $coin = strtolower(trim($_POST['coin'] ?? ''));
        deletePortfolioCoin($coin);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>strtoupper($coin).' removed.'];
    } elseif ($action === 'run_analysis') {
        global $LOG_FILE;
        shell_exec('python3 /var/www/html/crypto/crypto.py >> ' . escapeshellarg($LOG_FILE) . ' 2>&1 &');
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Analysis triggered — results will appear shortly.'];
        header('Location: ' . buildUrl(['view'=>'analysis'])); exit;
    } elseif ($action === 'dismiss_alert') {
        $id = (int)($_POST['alert_id'] ?? 0);
        if ($id > 0) dismissAlert($id);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Alert dismissed.'];
        header('Location: ' . buildUrl(['view'=>'alerts'])); exit;
    } elseif ($action === 'dismiss_all') {
        dismissAllAlerts();
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'All alerts cleared.'];
        header('Location: ' . buildUrl(['view'=>'alerts'])); exit;
    } elseif ($action === 'close_trade') {
        $id   = (int)($_POST['trade_id'] ?? 0);
        $coin = strtolower(trim($_POST['coin'] ?? ''));
        if ($id > 0 && $coin) {
            if (closeTrade($id, $coin)) {
                $exitPrice = getLatestPriceForCoin($coin);
                $_SESSION['flash'] = ['type'=>'ok','msg'=>'Trade closed at $' . number_format((float)$exitPrice, 2) . '.'];
            }
        }
        header('Location: ' . buildUrl(['view'=>'trades'])); exit;
    } elseif ($action === 'close_all_trades') {
        $count = closeAllTrades();
        $_SESSION['flash'] = ['type'=>'ok','msg'=>"Closed $count trade(s)."];
        header('Location: ' . buildUrl(['view'=>'trades'])); exit;
    } elseif ($action === 'reset_trades') {
        resetTrades();
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Paper trading history reset.'];
        header('Location: ' . buildUrl(['view'=>'trades'])); exit;
    } elseif ($action === 'fund_paper') {
        $amount = (float)($_POST['amount'] ?? 1000);
        if ($amount < 100) $amount = 100;
        fundPaperPortfolio($amount);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Paper portfolio funded with $'.number_format($amount,2).'.'];
        header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
    } elseif ($action === 'reset_paper') {
        resetPaperPortfolio();
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Paper portfolio reset.'];
        header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
    } elseif ($action === 'toggle_paper') {
        $status = $_POST['status'] ?? 'active';
        togglePaperPortfolio($status);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>"Paper portfolio {$status}."];
        header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
    } elseif ($action === 'update_paper_settings') {
        $settings = [];
        foreach (['stop_loss_pct','take_profit_pct','cash_reserve_pct'] as $k) {
            if (isset($_POST[$k]) && $_POST[$k] !== '') $settings[$k] = (float)$_POST[$k];
        }
        if ($settings) updatePaperSettings($settings);
        $_SESSION['flash'] = ['type'=>'ok','msg'=>'Paper portfolio settings updated.'];
        header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
    }

    header('Location: ' . buildUrl(['view'=>'portfolio'])); exit;
}

// ── URL HELPERS ──────────────────────────────────────────────
function buildUrl(array $p = []): string {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $q    = array_merge(['view' => $_GET['view'] ?? 'analysis'], $p);
    unset($q['page']);
    if (isset($p['page'])) $q['page'] = $p['page'];
    return $base . '?' . http_build_query($q);
}

// ── VIEW HELPERS ─────────────────────────────────────────────
function strategyColor(string $s): string {
    return match($s) {
        'arbitrage' => '#2563eb', 'pairs'  => '#7c3aed',
        'rebalance' => '#d97706', 'momentum' => '#ea580c',
        default     => '#78716c',
    };
}
function signalColor(string $sig): string {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '#16a34a';
    if (str_contains($sig,'sell')) return '#dc2626';
    if ($sig==='rebalance') return '#d97706';
    return '#78716c';
}
function signalIcon(string $sig): string {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '↑';
    if (str_contains($sig,'sell')) return '↓';
    if ($sig==='rebalance') return '⇄';
    return '—';
}

// ── LOAD VIEW DATA ───────────────────────────────────────────
$view = in_array($_GET['view'] ?? '', ['portfolio','analysis','log','alerts','trades']) ? $_GET['view'] : 'analysis';

$portfolio    = getPortfolio();
$latestPrices = getLatestPrices();
$totalUsd     = array_sum(array_map(fn($r) => $r['amount'] * ($latestPrices[$r['coin']] ?? 0), $portfolio));
$unseenCount  = getUnseenAlertCount();

// Signals
$signals = []; $sigError = null;
if ($view === 'analysis') {
    try { $signals = getSignals(null, 100); }
    catch (Exception $e) { $sigError = h($e->getMessage()); }
}

// Log
$logContent = $logError = null;
if ($view === 'log') {
    $logContent = getLogContent($LOG_LINES);
    if ($logContent === null) $logError = 'Log file not found or cannot be read.';
}

// Alerts
$alerts = [];
if ($view === 'alerts') {
    $alerts = getAlerts();
}

// Paper trades with P&L
$allTrades    = getTrades();
$allTrades    = computeTradePnl($allTrades, $latestPrices);
$openTrades   = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'open'));
$closedTrades = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'closed'));
$paperPnl     = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);
$openCount    = count($openTrades);

// Paper portfolio
$ppSummary = getPaperPortfolioSummary($latestPrices);
$ppFunded  = $ppSummary['funded'] ?? false;
$ppHistory = $ppFunded ? getPaperPortfolioHistory(720) : [];  // 30 days default (hourly)

// Paper trading history (for trades view equity curve)
$ptHistory = getPaperTradingHistory(720);

// Recommended allocations for user portfolio (always available)
$recAllocs = getPaperPortfolioAllocations();
$recAllocMap = [];
foreach ($recAllocs as $al) { $recAllocMap[$al['coin']] = (float)$al['recommended_pct']; }

// Flash message
session_start();
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Crypto Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:   #1a1918; --s1:#242220; --s2:#2c2a28; --s3:#333130;
  --b1:   #3d3b38; --b2:#4a4845;
  --ac:   #c96442; --ac-d:rgba(201,100,66,.15);
  --gn:   #4ade80; --gn-d:rgba(74,222,128,.12);
  --rd:   #f87171; --yw:#fbbf24; --pu:#a78bfa; --or:#fb923c;
  --t1:   #f0ede8; --t2:#a8a29e; --t3:#6b6966;
  --mono: 'JetBrains Mono','Fira Code','Courier New',monospace;
  --font: system-ui,-apple-system,'Segoe UI',sans-serif;
  --r:    12px;
}
html{scroll-behavior:smooth}
body{min-height:100vh;background:var(--bg);color:var(--t1);font-family:var(--font);font-size:14px;line-height:1.6}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--b2);border-radius:3px}

/* Layout */
.wrap{max-width:1440px;margin:0 auto;padding:24px 20px}

/* Header */
.hdr{display:flex;align-items:center;justify-content:space-between;
     padding:18px 24px;margin-bottom:20px;
     background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);
     box-shadow:0 1px 4px rgba(0,0,0,.06)}
.hdr-l{display:flex;align-items:center;gap:14px}
.pulse{position:relative;width:10px;height:10px;flex-shrink:0}
.pulse .d{position:absolute;inset:2px;border-radius:50%;background:#4ade80}
.pulse .r{position:absolute;inset:0;border-radius:50%;border:1px solid #4ade80;opacity:.5;
          animation:rp 2.5s ease-out infinite}
@keyframes rp{0%{transform:scale(.8);opacity:.7}100%{transform:scale(2.2);opacity:0}}
.logo{font-size:1.1rem;font-weight:700;color:var(--t1);letter-spacing:-.01em}
.logo-sub{font-size:.75rem;color:var(--t3);margin-top:2px}
.hdr-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.clock-box{padding:6px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;text-align:right}
.clock-t{font-size:.9rem;color:var(--t1);font-variant-numeric:tabular-nums;font-family:var(--mono)}
.clock-d{font-size:.7rem;color:var(--t3);margin-top:1px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;
     font-family:var(--font);font-size:.82rem;cursor:pointer;
     text-decoration:none;border:1px solid transparent;transition:all .2s;white-space:nowrap}
.btn-g{background:var(--s2);border-color:var(--b1);color:var(--t2)}
.btn-g:hover{border-color:var(--b2);color:var(--t1);background:var(--s3)}

/* Portfolio summary bar */
.port-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
          gap:12px;margin-bottom:18px}
.pstat{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);
       padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.pstat-l{font-size:.72rem;color:var(--t3);margin-bottom:8px;font-weight:500;text-transform:uppercase;letter-spacing:.05em}
.pstat-v{font-size:1.5rem;font-weight:700;line-height:1.2}
.pstat-s{font-size:.75rem;color:var(--t3);margin-top:6px}

/* Nav */
.nav{display:flex;align-items:center;gap:4px;margin-bottom:18px;
     background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:5px;
     box-shadow:0 1px 3px rgba(0,0,0,.04)}
.nav a{padding:8px 18px;border-radius:8px;font-size:.84rem;
       text-decoration:none;color:var(--t2);transition:all .18s;border:1px solid transparent}
.nav a:hover{color:var(--t1);background:var(--s2)}
.nav a.active{background:var(--s3);color:var(--t1);border-color:var(--b1);font-weight:600}
.nav-sp{flex:1}

/* Flash */
.flash{padding:12px 18px;border-radius:8px;margin-bottom:16px;font-size:.84rem}
.flash.ok{background:var(--gn-d);border:1px solid rgba(22,163,74,.25);color:var(--gn)}
.flash.err{background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);color:var(--rd)}

/* Panel */
.panel{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;
       box-shadow:0 1px 4px rgba(0,0,0,.05)}
.ph{display:flex;align-items:center;justify-content:space-between;
    padding:14px 20px;border-bottom:1px solid var(--b1);background:var(--s2);flex-wrap:wrap;gap:10px}
.ph-t{font-size:.88rem;font-weight:600;color:var(--t1)}
.ph-m{font-size:.8rem;color:var(--t3)}

/* Portfolio table */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.85rem}
thead tr{background:var(--s2)}
th{padding:11px 16px;text-align:left;font-size:.72rem;color:var(--t3);
   border-bottom:1px solid var(--b1);white-space:nowrap;font-weight:600;
   text-transform:uppercase;letter-spacing:.05em}
tbody tr{border-bottom:1px solid var(--b1);transition:background .12s}
tbody tr:hover{background:var(--s2)}
td{padding:10px 16px;color:var(--t1);vertical-align:middle}
td.num{text-align:right;font-variant-numeric:tabular-nums;font-family:var(--mono);font-size:.82rem}
td.muted{color:var(--t2);font-size:.82rem}
td.na{color:var(--t3);font-style:italic;font-size:.82rem}

/* Portfolio form */
.port-form{padding:20px;border-top:1px solid var(--b1);background:var(--s2)}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.form-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px}
.form-group label{font-size:.78rem;color:var(--t2);font-weight:500}
.form-group input,.form-group select{
  background:var(--s1);border:1px solid var(--b2);color:var(--t1);
  font-family:var(--font);font-size:.85rem;padding:8px 12px;border-radius:8px;
  outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{
  border-color:var(--ac);box-shadow:0 0 0 3px rgba(201,100,66,.12)}
.btn-primary{background:var(--ac);border:none;
             color:#fff;font-family:var(--font);font-size:.84rem;font-weight:600;
             padding:9px 20px;border-radius:8px;cursor:pointer;transition:opacity .2s;
             white-space:nowrap;align-self:flex-end}
.btn-primary:hover{opacity:.88}
.btn-del{background:transparent;border:1px solid var(--b2);color:var(--t3);
         font-family:var(--font);font-size:.78rem;padding:5px 10px;border-radius:6px;
         cursor:pointer;transition:all .2s}
.btn-del:hover{border-color:var(--rd);color:var(--rd);background:rgba(220,38,38,.06)}

/* Allocation bar */
.alloc-bar{height:3px;background:var(--b1);border-radius:2px;margin-top:5px;overflow:hidden}
.alloc-fill{height:100%;border-radius:2px;transition:width .3s}

/* Signal cards */
.signals-grid{padding:14px;display:flex;flex-direction:column;gap:8px}
.sig-card{background:var(--s2);border:1px solid var(--b1);border-radius:var(--r);
          overflow:hidden;transition:box-shadow .2s}
.sig-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08)}
.sig-top{display:flex;align-items:center;gap:12px;padding:14px 16px;flex-wrap:wrap}
.sig-rank{font-size:.78rem;color:var(--t3);min-width:28px;font-weight:500}
.strat-badge{font-size:.72rem;letter-spacing:.02em;padding:3px 10px;border-radius:20px;font-weight:600;white-space:nowrap}
.coin-badges{display:flex;gap:5px;flex-wrap:wrap}
.coin-badge{font-size:.72rem;padding:3px 8px;border-radius:5px;background:var(--s1);
            border:1px solid var(--b1);color:var(--t2);font-weight:500}
.sig-signal{font-size:.82rem;font-weight:600}
.sig-sp{flex:1}
.sig-usd{font-size:.9rem;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap;color:var(--t1)}
.sig-usd.zero{color:var(--t3)}
.strength-wrap{display:flex;align-items:center;gap:8px;padding:0 16px 12px}
.strength-bar{flex:1;height:3px;background:var(--b1);border-radius:2px;overflow:hidden}
.strength-fill{height:100%;border-radius:2px}
.strength-val{font-size:.74rem;color:var(--t3);white-space:nowrap;min-width:60px;text-align:right}
.sig-detail{padding:0 16px 14px}
details summary{font-size:.78rem;color:var(--t2);cursor:pointer;list-style:none;padding:4px 0}
details summary::-webkit-details-marker{display:none}
details summary::before{content:'▶ ';font-size:.6rem;color:var(--t3)}
details[open] summary::before{content:'▼ '}
.detail-json{margin-top:8px;padding:10px 12px;background:var(--bg);border:1px solid var(--b1);
             border-radius:6px;font-size:.75rem;font-family:var(--mono);color:var(--t2);
             white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto;line-height:1.8}
.sig-ts{font-size:.72rem;color:var(--t3);padding:0 16px 10px}

/* Strategy filter pills */
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--b1)}
.filter-pill{font-size:.78rem;padding:5px 14px;border-radius:20px;
             cursor:pointer;text-decoration:none;transition:all .18s;
             border:1px solid var(--b1);color:var(--t2);background:var(--s1)}
.filter-pill:hover{border-color:var(--b2);color:var(--t1)}
.filter-pill.active{color:#fff;font-weight:600}

/* Log */
.log-body{padding:18px 20px;max-height:68vh;overflow-y:auto;font-size:.78rem;line-height:2;
          white-space:pre-wrap;word-break:break-all;font-family:var(--mono)}
.ll{display:block;padding:0 4px;border-radius:3px}
.ll:hover{background:rgba(0,0,0,.03)}
.ll-err{color:#dc2626}.ll-warn{color:#d97706}.ll-ok{color:#16a34a}.ll-ts{color:var(--t2)}.ll-d{color:var(--t3)}

/* Empty/error states */
.state{padding:70px 20px;text-align:center}
.state-i{font-size:2.5rem;margin-bottom:14px}
.state-t{font-size:.9rem;font-weight:600;color:var(--t2)}
.state-s{font-size:.82rem;color:var(--t3);margin-top:8px}
.err-box{margin:18px;padding:14px 18px;background:rgba(220,38,38,.05);
         border:1px solid rgba(220,38,38,.18);border-radius:8px;color:#dc2626;font-size:.84rem}

/* Footer */
.footer{text-align:center;padding:20px;margin-top:18px;font-size:.75rem;color:var(--t3)}

/* Alert badge */
.badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;
       padding:0 5px;border-radius:9px;font-size:.6rem;font-weight:700;
       background:var(--rd);color:#fff;margin-left:5px;vertical-align:middle}

/* Alert cards */
.alert-card{background:var(--s2);border:1px solid var(--b1);border-radius:var(--r);
            overflow:hidden;transition:box-shadow .2s;border-left:3px solid var(--rd)}
.alert-card.seen{border-left-color:var(--b2);opacity:.6}
.alert-top{display:flex;align-items:center;gap:10px;padding:12px 16px;flex-wrap:wrap}

/* P&L colors */
.pnl-pos{color:var(--gn)}
.pnl-neg{color:var(--rd)}
.pnl-zero{color:var(--t3)}

/* Paper Portfolio */
.pp-divider{margin:28px 0 18px;border:0;border-top:2px dashed var(--b2)}
.pp-header{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.pp-dot{width:10px;height:10px;border-radius:50%;background:var(--pu)}
.pp-title{font-size:1rem;font-weight:700;color:var(--pu)}
.pp-status{font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;letter-spacing:.02em}
.pp-status.active{background:rgba(74,222,128,.12);color:var(--gn)}
.pp-status.paused{background:rgba(251,191,36,.12);color:var(--yw)}
.pp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.pp-stat{background:var(--s2);border:1px solid var(--b1);border-radius:8px;padding:12px 14px}
.pp-stat-l{font-size:.68rem;color:var(--t3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;font-weight:500}
.pp-stat-v{font-size:1.15rem;font-weight:700;line-height:1.2}
.pp-stat-s{font-size:.72rem;color:var(--t3);margin-top:4px}
.pp-alloc-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--b1)}
.pp-alloc-row:last-child{border-bottom:none}
.pp-alloc-coin{font-weight:700;color:var(--pu);min-width:50px;font-size:.82rem}
.pp-alloc-bars{flex:1;display:flex;flex-direction:column;gap:3px}
.pp-bar{height:4px;border-radius:2px;position:relative}
.pp-bar-rec{background:var(--pu)}
.pp-bar-act{background:rgba(167,139,250,.35)}
.pp-alloc-pcts{font-size:.72rem;color:var(--t2);min-width:120px;text-align:right;font-family:var(--mono)}
.pp-drift{font-size:.68rem;font-weight:600;padding:2px 6px;border-radius:4px}
.pp-drift.over{background:rgba(248,113,113,.1);color:var(--rd)}
.pp-drift.under{background:rgba(74,222,128,.1);color:var(--gn)}
.pp-drift.ok{color:var(--t3)}
.margin-bar{height:6px;background:var(--b1);border-radius:3px;overflow:hidden;position:relative}
.margin-fill{height:100%;border-radius:3px;transition:width .3s}
.equity-chart{width:100%;height:160px;border:1px solid var(--b1);border-radius:8px;background:var(--bg);overflow:hidden}
.pp-cta{padding:40px 20px;text-align:center}
.pp-cta-t{font-size:.95rem;font-weight:600;color:var(--pu);margin-bottom:8px}
.pp-cta-s{font-size:.82rem;color:var(--t3);margin-bottom:18px}

/* Timeframe buttons */
.tf-btns{display:flex;gap:4px;margin-bottom:10px}
.tf-btn{padding:4px 10px;border:1px solid var(--b1);border-radius:6px;background:transparent;
        color:var(--t3);font-size:.72rem;font-family:var(--mono);font-weight:600;cursor:pointer;
        transition:all .15s;letter-spacing:.02em}
.tf-btn:hover{border-color:var(--pu);color:var(--pu)}
.tf-btn.active{background:var(--pu);color:#fff;border-color:var(--pu)}

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
      <div class="logo">Crypto Dashboard</div>
      <div class="logo-sub">Raspberry Pi · Portfolio Analysis</div>
    </div>
  </div>
  <div class="hdr-r">
    <div class="clock-box">
      <div class="clock-t" id="clock">──:──:──</div>
      <div class="clock-d" id="cdate">────────────</div>
    </div>
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
  <div class="pstat">
    <div class="pstat-l">PAPER P&amp;L</div>
    <div class="pstat-v" style="color:<?= $paperPnl > 0 ? 'var(--gn)' : ($paperPnl < 0 ? 'var(--rd)' : 'var(--t3)') ?>">
      <?= $paperPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($paperPnl), 2) ?>
    </div>
    <div class="pstat-s"><?= $openCount ?> open · <?= count($closedTrades) ?> closed · <?= $unseenCount ?> new alert<?= $unseenCount !== 1 ? 's' : '' ?></div>
  </div>
  <div class="pstat">
    <div class="pstat-l">PAPER PORTFOLIO</div>
    <?php if ($ppFunded): ?>
    <div class="pstat-v" style="color:var(--pu)">$<?= number_format($ppSummary['config']['total_value'], 2) ?></div>
    <div class="pstat-s" style="color:<?= ($ppSummary['analytics']['cumulative_return'] ?? 0) >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
      <?= ($ppSummary['analytics']['cumulative_return'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($ppSummary['analytics']['cumulative_return'] ?? 0, 1) ?>% · <?= $ppSummary['config']['status'] ?>
    </div>
    <?php else: ?>
    <div class="pstat-v" style="color:var(--t3);font-size:1rem">NOT FUNDED</div>
    <div class="pstat-s">fund to activate</div>
    <?php endif; ?>
  </div>
</div>

<!-- NAV -->
<nav class="nav">
  <a href="<?= buildUrl(['view'=>'analysis']) ?>" class="<?= $view==='analysis'?'active':'' ?>">ANALYSIS</a>
  <a href="<?= buildUrl(['view'=>'alerts']) ?>" class="<?= $view==='alerts'?'active':'' ?>">
    ALERTS<?php if ($unseenCount > 0): ?><span class="badge"><?= $unseenCount ?></span><?php endif ?>
  </a>
  <a href="<?= buildUrl(['view'=>'trades']) ?>" class="<?= $view==='trades'?'active':'' ?>">
    TRADES<?php if ($openCount > 0): ?><span class="badge" style="background:var(--gn);color:#000"><?= $openCount ?></span><?php endif ?>
  </a>
  <a href="<?= buildUrl(['view'=>'portfolio']) ?>" class="<?= $view==='portfolio'?'active':'' ?>">PORTFOLIO</a>
  <a href="<?= buildUrl(['view'=>'log']) ?>" class="<?= $view==='log'?'active':'' ?>">LOG</a>
  <div class="nav-sp"></div>
  <span style="font-size:.75rem;color:var(--t3)">cron: */5 * * * *</span>
</nav>

<!-- FLASH -->
<?php if ($flash): ?>
<div class="flash <?= $flash['type'] ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<!-- ══ ANALYSIS VIEW ══════════════════════════════════════════════════════════ -->
<?php if ($view === 'analysis'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">RANKED SIGNALS — ALL MODULES</div>
    <div style="display:flex;align-items:center;gap:12px">
      <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="run_analysis">
        <button type="submit" class="btn btn-g">RUN NOW</button>
      </form>
    </div>
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
    <div class="err-box"><?= $sigError ?></div>
  <?php elseif (empty($displayed)): ?>
    <div class="state">
      <div class="state-i"></div>
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
    <div class="ph-t">PORTFOLIO HOLDINGS</div>
    <div class="ph-m">TOTAL: <?= $totalUsd>0 ? '$'.number_format($totalUsd,2) : 'N/A (run analysis first)' ?></div>
  </div>

  <?php if (empty($portfolio)): ?>
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO HOLDINGS YET</div>
    <div class="state-s">Add your first coin below</div>
  </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>AMOUNT</th><th>PRICE (USD)</th>
        <th>VALUE (USD)</th><th>ALLOCATION</th><th>TARGET %</th><th>REC %</th><th></th>
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
          <?php
            $recPct = $recAllocMap[$row['coin']] ?? null;
            $recDrift = ($allocPct !== null && $recPct !== null) ? $allocPct - $recPct : null;
            $recCol = ($recDrift !== null) ? (abs($recDrift) > 5 ? ($recDrift>0?'var(--rd)':'var(--gn)') : 'var(--pu)') : 'var(--t3)';
          ?>
          <?php if ($recPct !== null): ?>
          <span style="font-size:.78rem;color:var(--pu)"><?= number_format($recPct, 1) ?>%</span>
          <?php if ($recDrift !== null): ?>
          <span style="font-size:.65rem;color:<?= $recCol ?>;margin-left:3px">
            (<?= $recDrift>0?'+':'' ?><?= round($recDrift,1) ?>%)
          </span>
          <?php endif; ?>
          <?php else: ?><span class="na">—</span><?php endif; ?>
        </td>
        <td>
          <form method="POST" onsubmit="return confirm('Remove <?= strtoupper($row['coin']) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="coin" value="<?= h($row['coin']) ?>">
            <button type="submit" class="btn-del">REMOVE</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($hasTargets): ?>
      <tr style="border-top:2px solid var(--b2)">
        <td colspan="5" style="font-size:.62rem;letter-spacing:2px;color:var(--t3)">TARGET TOTAL</td>
        <td class="muted" style="color:<?= abs($totalPct-100)<1?'var(--gn)':'var(--rd)' ?>">
          <?= round($totalPct,1) ?>%
          <?= abs($totalPct-100)<1 ? '' : '(should = 100%)' ?>
        </td>
        <td></td><td></td>
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

<!-- ══ PAPER PORTFOLIO (inside Portfolio view) ══════════════════════════════ -->
<hr class="pp-divider">

<?php if (!$ppFunded): ?>
<!-- Not funded CTA -->
<div class="panel">
  <div class="pp-cta">
    <div class="pp-cta-t">PAPER PORTFOLIO</div>
    <div class="pp-cta-s">Fund a virtual portfolio and let the analysis engine manage it autonomously.<br>
      Includes stop-loss, take-profit, recommended allocations, and performance tracking.</div>
    <form method="POST" style="display:inline-flex;gap:10px;align-items:flex-end;flex-wrap:wrap;justify-content:center">
      <input type="hidden" name="action" value="fund_paper">
      <div class="form-group" style="min-width:160px">
        <label style="font-size:.72rem;color:var(--t3)">STARTING CAPITAL (USD)</label>
        <input type="number" name="amount" value="1000" min="100" max="1000000" step="100"
               style="background:var(--s1);border:1px solid var(--b2);color:var(--t1);font-family:var(--font);font-size:.85rem;padding:8px 12px;border-radius:8px">
      </div>
      <button type="submit" class="btn-primary" style="background:var(--pu)">FUND PORTFOLIO</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- Paper Portfolio Funded -->
<?php
  $ppCfg  = $ppSummary['config'];
  $ppSet  = $ppSummary['settings'];
  $ppHold = $ppSummary['holdings'];
  $ppAlloc= $ppSummary['allocations'];
  $ppAn   = $ppSummary['analytics'];
  $ppRisk = $ppSummary['risk'];
  $ppRet  = $ppAn['cumulative_return'];
  $ppDaily= $ppAn['daily_return'];
  $sharpe = $ppAn['sharpe_ratio'];
?>

<div class="pp-header">
  <div class="pp-dot"></div>
  <div class="pp-title">PAPER PORTFOLIO</div>
  <span class="pp-status <?= $ppCfg['status'] ?>"><?= strtoupper($ppCfg['status']) ?></span>
  <div style="flex:1"></div>
  <form method="POST" style="display:inline;margin:0">
    <input type="hidden" name="action" value="toggle_paper">
    <input type="hidden" name="status" value="<?= $ppCfg['status'] === 'active' ? 'paused' : 'active' ?>">
    <button type="submit" class="btn btn-g" style="font-size:.72rem;padding:5px 12px">
      <?= $ppCfg['status'] === 'active' ? 'PAUSE' : 'RESUME' ?>
    </button>
  </form>
  <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Reset paper portfolio? Holdings and history will be deleted.')">
    <input type="hidden" name="action" value="reset_paper">
    <button type="submit" class="btn-del" style="font-size:.72rem">RESET</button>
  </form>
</div>

<!-- Summary stats -->
<div class="pp-stats">
  <div class="pp-stat">
    <div class="pp-stat-l">TOTAL VALUE</div>
    <div class="pp-stat-v" style="color:var(--pu)">$<?= number_format($ppCfg['total_value'], 2) ?></div>
    <div class="pp-stat-s">funded $<?= number_format($ppCfg['funded_amount'], 0) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">CASH BALANCE</div>
    <div class="pp-stat-v" style="color:<?= $ppCfg['cash_balance'] >= 0 ? 'var(--t1)' : 'var(--yw)' ?>">
      <?= $ppCfg['cash_balance'] < 0 ? '-' : '' ?>$<?= number_format(abs($ppCfg['cash_balance']), 2) ?>
    </div>
    <div class="pp-stat-s"><?= $ppRisk['is_on_margin'] ? '<span style="color:var(--yw)">ON MARGIN</span>' : 'cash reserve' ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">RETURN</div>
    <div class="pp-stat-v" style="color:<?= $ppRet >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
      <?= $ppRet >= 0 ? '+' : '' ?><?= number_format($ppRet, 2) ?>%
    </div>
    <div class="pp-stat-s">daily: <?= $ppDaily >= 0 ? '+' : '' ?><?= number_format($ppDaily, 2) ?>%</div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">MAX DRAWDOWN</div>
    <div class="pp-stat-v" style="color:<?= $ppCfg['max_drawdown'] > 5 ? 'var(--rd)' : 'var(--t2)' ?>">
      -<?= number_format($ppCfg['max_drawdown'], 1) ?>%
    </div>
    <div class="pp-stat-s">HWM: $<?= number_format($ppCfg['high_water_mark'], 2) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">SHARPE RATIO</div>
    <div class="pp-stat-v" style="color:<?= $sharpe > 1 ? 'var(--gn)' : ($sharpe > 0.5 ? 'var(--yw)' : 'var(--rd)') ?>">
      <?= number_format($sharpe, 2) ?>
    </div>
    <div class="pp-stat-s"><?= $sharpe > 1 ? 'good' : ($sharpe > 0.5 ? 'moderate' : ($sharpe == 0 ? 'n/a' : 'low')) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">VS BENCHMARKS</div>
    <div class="pp-stat-v" style="font-size:.82rem">
      <span style="color:var(--or)">BTC <?= ($ppAn['btc_return'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($ppAn['btc_return'] ?? 0, 1) ?>%</span>
    </div>
    <div class="pp-stat-s">EQ-WT <?= ($ppAn['equal_weight_return'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($ppAn['equal_weight_return'] ?? 0, 1) ?>%</div>
  </div>
</div>

<!-- Holdings Table -->
<?php if (!empty($ppHold)): ?>
<div class="panel" style="margin-bottom:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">HOLDINGS</div>
    <div class="ph-m"><?= count($ppHold) ?> POSITION<?= count($ppHold) !== 1 ? 'S' : '' ?></div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>AMOUNT</th><th>AVG ENTRY</th><th>CURRENT</th>
        <th>VALUE</th><th>P&amp;L</th><th>P&amp;L %</th><th>ALLOC</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ppHold as $h):
        $pnlClass = $h['unrealized_pnl'] > 0 ? 'pnl-pos' : ($h['unrealized_pnl'] < 0 ? 'pnl-neg' : 'pnl-zero');
      ?>
      <tr>
        <td>
          <span style="font-weight:700;color:var(--pu)"><?= strtoupper(h($h['coin'])) ?></span>
          <br><span class="muted"><?= h($SUPPORTED_COINS[$h['coin']] ?? '') ?></span>
        </td>
        <td class="num"><?= number_format($h['amount'], 6) ?></td>
        <td class="num">$<?= number_format($h['avg_entry_price'], 2) ?></td>
        <td class="num">$<?= number_format($h['current_price'], 2) ?></td>
        <td class="num">$<?= number_format($h['current_value'], 2) ?></td>
        <td class="num <?= $pnlClass ?>"><?= $h['unrealized_pnl'] >= 0 ? '+' : '' ?>$<?= number_format(abs($h['unrealized_pnl']), 2) ?></td>
        <td class="num <?= $pnlClass ?>"><?= $h['unrealized_pct'] >= 0 ? '+' : '' ?><?= $h['unrealized_pct'] ?>%</td>
        <td class="num" style="color:var(--pu)"><?= $h['actual_pct'] ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Recommended Allocations -->
<?php if (!empty($ppAlloc)): ?>
<div class="panel" style="margin-bottom:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">RECOMMENDED ALLOCATIONS</div>
    <div class="ph-m">CASH RESERVE: <?= number_format($ppSet['cash_reserve_pct'], 0) ?>%</div>
  </div>
  <div style="padding:14px 16px">
    <?php foreach ($ppAlloc as $al):
      $recPct = (float)$al['recommended_pct'];
      $actPct = (float)$al['actual_pct'];
      $drift  = (float)$al['drift_pct'];
      $driftClass = abs($drift) < 2 ? 'ok' : ($drift > 0 ? 'over' : 'under');
    ?>
    <div class="pp-alloc-row">
      <div class="pp-alloc-coin"><?= strtoupper(h($al['coin'])) ?></div>
      <div class="pp-alloc-bars">
        <div class="pp-bar pp-bar-rec" style="width:<?= min($recPct * 3, 100) ?>%"></div>
        <div class="pp-bar pp-bar-act" style="width:<?= min($actPct * 3, 100) ?>%"></div>
      </div>
      <div class="pp-alloc-pcts">
        <span style="color:var(--pu)"><?= number_format($recPct, 1) ?>%</span> /
        <span style="color:var(--t2)"><?= number_format($actPct, 1) ?>%</span>
      </div>
      <span class="pp-drift <?= $driftClass ?>">
        <?= abs($drift) < 0.1 ? '=' : ($drift > 0 ? '+' : '') . number_format($drift, 1) . '%' ?>
      </span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:8px;font-size:.68rem;color:var(--t3)">
      <span style="color:var(--pu)">purple</span> = recommended &nbsp;
      <span style="color:var(--t2)">gray</span> = actual
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Equity Curve (JS Canvas) -->
<div class="panel" style="margin-bottom:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">EQUITY CURVE</div>
    <div class="ph-m"><?= count($ppHistory) ?> DATA POINT<?= count($ppHistory) !== 1 ? 'S' : '' ?></div>
  </div>
  <div style="padding:14px 16px">
    <div class="tf-btns" id="pp-tf-btns">
      <button class="tf-btn" data-hours="24">24H</button>
      <button class="tf-btn" data-hours="168">1W</button>
      <button class="tf-btn active" data-hours="720">1M</button>
      <button class="tf-btn" data-hours="2160">3M</button>
      <button class="tf-btn" data-hours="4380">6M</button>
      <button class="tf-btn" data-hours="8760">1Y</button>
    </div>
    <canvas id="pp-equity-chart" style="width:100%;height:180px"></canvas>
    <div style="display:flex;gap:14px;justify-content:center;font-size:.68rem;color:var(--t3);margin-top:6px">
      <span><span style="color:var(--pu)">●</span> portfolio</span>
      <span><span style="color:var(--or)">●</span> BTC benchmark</span>
      <span><span style="color:#60a5fa">●</span> equal-weight</span>
      <span><span style="color:var(--b2)">- -</span> funded ($<?= number_format($ppCfg['funded_amount'] ?? 0, 0) ?>)</span>
    </div>
  </div>
</div>

<!-- Risk & Margin Panel -->
<div class="panel" style="margin-bottom:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">RISK MANAGEMENT</div>
  </div>
  <div style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    <!-- Margin bar -->
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">MARGIN USAGE</div>
      <?php
        $marginTotal = $ppSet['margin_limit'] * $ppCfg['funded_amount'];
        $cashPct = $marginTotal > 0 ? min(100, max(0, ($ppCfg['cash_balance'] / ($ppCfg['funded_amount'] + $marginTotal)) * 100)) : 0;
        $barColor = $ppRisk['is_on_margin'] ? 'var(--rd)' : ($ppCfg['cash_balance'] < $ppCfg['funded_amount'] * 0.1 ? 'var(--yw)' : 'var(--gn)');
      ?>
      <div class="margin-bar">
        <div class="margin-fill" style="width:<?= max(5, $cashPct) ?>%;background:<?= $barColor ?>"></div>
      </div>
      <div style="font-size:.68rem;color:var(--t3);margin-top:4px">
        Cash: $<?= number_format(max(0, $ppCfg['cash_balance']), 2) ?> ·
        Margin avail: $<?= number_format($ppRisk['margin_available'], 2) ?>
      </div>
    </div>
    <!-- Settings -->
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">CURRENT SETTINGS</div>
      <div style="font-size:.82rem;color:var(--t2);line-height:2">
        Stop-Loss: <strong style="color:var(--rd)"><?= number_format($ppSet['stop_loss_pct'], 0) ?>%</strong> ·
        Take-Profit: <strong style="color:var(--gn)"><?= number_format($ppSet['take_profit_pct'], 0) ?>%</strong> ·
        Cash Reserve: <strong style="color:var(--t1)"><?= number_format($ppSet['cash_reserve_pct'], 0) ?>%</strong>
      </div>
    </div>
    <!-- Update settings form -->
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">ADJUST SETTINGS</div>
      <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="update_paper_settings">
        <input type="number" name="stop_loss_pct" placeholder="SL %" step="1" min="1" max="50" value="<?= (int)$ppSet['stop_loss_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <input type="number" name="take_profit_pct" placeholder="TP %" step="1" min="5" max="200" value="<?= (int)$ppSet['take_profit_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <input type="number" name="cash_reserve_pct" placeholder="Cash %" step="1" min="0" max="50" value="<?= (int)$ppSet['cash_reserve_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <button type="submit" class="btn btn-g" style="font-size:.72rem;padding:5px 10px">SAVE</button>
      </form>
    </div>
  </div>
</div>

<!-- Performance Attribution -->
<?php
$ppPerf = getPaperPortfolioPerformance();
if (!empty($ppPerf)):
?>
<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">STRATEGY ATTRIBUTION</div>
    <div class="ph-m">SINCE <?= $ppCfg['created_at'] ? strtoupper(date('d M Y', strtotime($ppCfg['created_at']))) : '—' ?></div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;padding:14px 16px">
    <?php foreach ($ppPerf as $sp): $sc = strategyColor($sp['strategy']); ?>
    <div style="padding:10px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;min-width:140px;flex:1">
      <div style="font-size:.68rem;color:<?= $sc ?>;font-weight:600;letter-spacing:.04em;margin-bottom:6px"><?= strtoupper(h($sp['strategy'])) ?></div>
      <div style="font-size:1rem;font-weight:700;color:<?= $sp['total_pnl'] >= 0 ? 'var(--gn)' : 'var(--rd)' ?>;margin-bottom:4px">
        <?= $sp['total_pnl'] >= 0 ? '+' : '' ?>$<?= number_format(abs($sp['total_pnl']), 2) ?>
      </div>
      <div style="font-size:.72rem;color:var(--t3);line-height:1.8">
        <?= $sp['total_trades'] ?> trades · <?= $sp['win_rate'] ?>% win rate<br>
        W<?= $sp['wins'] ?> / L<?= $sp['losses'] ?> · <?= $sp['open_trades'] ?> open
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; // ppFunded ?>

<!-- ══ LOG VIEW ═══════════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'log'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">SYSTEM LOG — LAST <?= $LOG_LINES ?> LINES</div>
    <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
  </div>
  <?php if ($logError): ?>
    <div class="err-box"><?= h($logError) ?></div>
  <?php elseif (!$logContent): ?>
    <div class="state"><div class="state-i"></div><div class="state-t">LOG IS EMPTY</div></div>
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

<!-- ══ ALERTS VIEW ════════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'alerts'): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">ALERTS<?php if ($unseenCount > 0): ?> <span style="color:var(--rd)">(<?= $unseenCount ?> NEW)</span><?php endif ?></div>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="ph-m"><?= count($alerts) ?> TOTAL</div>
      <?php if ($unseenCount > 0): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="dismiss_all">
        <button type="submit" class="btn btn-g">DISMISS ALL</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <?php if (empty($alerts)): ?>
    <div class="state">
      <div class="state-i"></div>
      <div class="state-t">NO ALERTS YET</div>
      <div class="state-s">Alerts fire when signal strength exceeds the threshold — run the analysis first</div>
    </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($alerts as $al):
      $sc    = strategyColor($al['strategy']);
      $sigc  = signalColor($al['signal']);
      $icon  = signalIcon($al['signal']);
      $coins = explode(',', $al['coins']);
      $pct   = round($al['strength'] * 100);
      $ts    = date('d M H:i', strtotime($al['timestamp']));
      $isNew = $al['status'] === 'new';
    ?>
    <div class="alert-card <?= $isNew ? '' : 'seen' ?>">
      <div class="alert-top">
        <?php if ($isNew): ?>
        <span style="font-size:.58rem;letter-spacing:2px;color:var(--rd);font-weight:700">NEW</span>
        <?php endif ?>
        <div class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
          <?= strtoupper(h($al['strategy'])) ?>
        </div>
        <div class="coin-badges">
          <?php foreach ($coins as $c): ?>
          <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
          <?php endforeach ?>
        </div>
        <div class="sig-signal" style="color:<?= $sigc ?>"><?= $icon ?> <?= h($al['signal']) ?></div>
        <div class="sig-sp"></div>
        <?php if ($al['expected_usd'] > 0): ?>
        <div class="sig-usd">~$<?= number_format((float)$al['expected_usd'], 2) ?></div>
        <?php endif ?>
        <?php if ($isNew): ?>
        <form method="POST" style="margin:0">
          <input type="hidden" name="action" value="dismiss_alert">
          <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
          <button type="submit" class="btn-del">SEEN</button>
        </form>
        <?php endif ?>
      </div>
      <div class="strength-wrap">
        <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">STRENGTH</span>
        <div class="strength-bar">
          <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
        </div>
        <div class="strength-val"><?= $pct ?>% · score <?= number_format((float)$al['strength'], 3) ?></div>
      </div>
      <div class="sig-ts"><?= $ts ?> UTC · alert #<?= $al['id'] ?></div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>

<?php if (!empty($allTrades)): ?>
<div style="margin-top:14px;padding:12px 16px;background:var(--s2);border:1px solid var(--b1);border-radius:var(--r);display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:.84rem;color:var(--t2)">
    <?= $openCount ?> open trade<?= $openCount !== 1 ? 's' : '' ?> · <?= count($closedTrades) ?> closed ·
    Total P&amp;L: <strong style="color:<?= $paperPnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>"><?= $paperPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($paperPnl), 2) ?></strong>
  </span>
  <a href="<?= buildUrl(['view'=>'trades']) ?>" class="btn" style="font-size:.78rem;padding:6px 14px">View Trades</a>
</div>
<?php endif ?>

<!-- ══ TRADES VIEW ════════════════════════════════════════════════════════════ -->
<?php elseif ($view === 'trades'): ?>

<?php
// P&L breakdown by strategy for the summary bar
$pnlByStrategy = [];
foreach ($allTrades as $t) {
    $strat = $t['strategy'] ?? 'unknown';
    if (!isset($pnlByStrategy[$strat])) $pnlByStrategy[$strat] = 0.0;
    $pnlByStrategy[$strat] += $t['pnl_usd'];
}

function tradeRow($t, $showClose = false) {
    $pnlClass  = $t['pnl_usd'] > 0 ? 'pnl-pos' : ($t['pnl_usd'] < 0 ? 'pnl-neg' : 'pnl-zero');
    $actColor  = $t['action'] === 'buy' ? 'var(--gn)' : 'var(--rd)';
    $strat     = $t['strategy'] ?? 'unknown';
    $stratColor = strategyColor($strat);
    $openTs    = strtotime($t['timestamp']);
    $closeTs   = $t['closed_at'] ? strtotime($t['closed_at']) : time();
    $dur       = $closeTs - $openTs;
    $durLabel  = $dur < 3600 ? round($dur/60).'m' : ($dur < 86400 ? round($dur/3600).'h' : round($dur/86400).'d');
    ?>
    <tr>
      <td>
        <span style="font-size:.68rem;padding:2px 7px;border-radius:4px;background:<?= $stratColor ?>22;color:<?= $stratColor ?>;font-weight:600;letter-spacing:.04em"><?= strtoupper(h($strat)) ?></span>
      </td>
      <td style="font-weight:700;color:var(--ac)"><?= strtoupper(h($t['coin'])) ?></td>
      <td style="color:<?= $actColor ?>;font-weight:600;font-size:.78rem"><?= strtoupper(h($t['action'])) ?></td>
      <td class="num">$<?= number_format((float)$t['entry_price'], 2) ?></td>
      <td class="num"><?= $t['status'] === 'closed' ? '<span style="color:var(--t3);font-size:.72rem">EXIT&nbsp;</span>' : '' ?>$<?= number_format((float)$t['current_price'], 2) ?></td>
      <td class="num"><?= number_format((float)$t['amount_usd'], 0) ?></td>
      <td class="num <?= $pnlClass ?>"><?= $t['pnl_usd'] >= 0 ? '+' : '' ?>$<?= number_format(abs($t['pnl_usd']), 2) ?></td>
      <td class="num <?= $pnlClass ?>" style="font-size:.78rem"><?= $t['pnl_pct'] >= 0 ? '+' : '' ?><?= $t['pnl_pct'] ?>%</td>
      <td class="muted" style="font-size:.72rem"><?= $durLabel ?></td>
      <?php if ($showClose): ?>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="close_trade">
          <input type="hidden" name="trade_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="coin" value="<?= h($t['coin']) ?>">
          <button type="submit" style="font-size:.72rem;padding:3px 10px;background:var(--s3);border:1px solid var(--b1);border-radius:5px;color:var(--t2);cursor:pointer;font-family:inherit">Close</button>
        </form>
      </td>
      <?php else: ?>
      <td class="muted" style="font-size:.72rem"><?= $t['closed_at'] ? date('d M H:i', strtotime($t['closed_at'])) : '—' ?></td>
      <?php endif ?>
    </tr>
    <?php
}
?>

<!-- Summary bar -->
<div class="panel">
  <div class="ph">
    <div class="ph-t">Paper Trades</div>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:.82rem;font-weight:700;color:<?= $paperPnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
        Total P&L: <?= $paperPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($paperPnl), 2) ?>
      </span>
      <?php if (!empty($allTrades)): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Reset all paper trade history?')">
        <input type="hidden" name="action" value="reset_trades">
        <button type="submit" style="font-size:.75rem;padding:5px 12px;background:transparent;border:1px solid var(--rd);border-radius:6px;color:var(--rd);cursor:pointer;font-family:inherit">Reset</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <?php if (!empty($pnlByStrategy)): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px 0">
    <?php foreach ($pnlByStrategy as $strat => $spnl): $sc = strategyColor($strat); ?>
    <div style="padding:8px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;min-width:130px">
      <div style="font-size:.68rem;color:<?= $sc ?>;font-weight:600;letter-spacing:.04em;margin-bottom:4px"><?= strtoupper(h($strat)) ?></div>
      <div style="font-size:.95rem;font-weight:700;color:<?= $spnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
        <?= $spnl >= 0 ? '+' : '' ?>$<?= number_format(abs($spnl), 2) ?>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <!-- Paper Trading Equity Curve -->
  <?php if (!empty($ptHistory) && count($ptHistory) > 1): ?>
  <div style="padding:14px 16px">
    <div style="font-size:.72rem;font-weight:600;letter-spacing:.05em;color:var(--t3);margin-bottom:8px">P&L EQUITY CURVE</div>
    <div class="tf-btns" id="pt-tf-btns">
      <button class="tf-btn" data-hours="24">24H</button>
      <button class="tf-btn" data-hours="168">1W</button>
      <button class="tf-btn active" data-hours="720">1M</button>
      <button class="tf-btn" data-hours="2160">3M</button>
      <button class="tf-btn" data-hours="4380">6M</button>
      <button class="tf-btn" data-hours="8760">1Y</button>
    </div>
    <canvas id="pt-equity-chart" style="width:100%;height:160px"></canvas>
    <div style="display:flex;gap:14px;justify-content:center;font-size:.68rem;color:var(--t3);margin-top:6px">
      <span><span style="color:var(--ac)">●</span> P&L</span>
      <span><span style="color:var(--b2)">- -</span> breakeven ($0)</span>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($allTrades)): ?>
    <div class="state"><div class="state-i"></div><div class="state-t">No paper trades yet</div><div class="state-s">Trades are created automatically when signals fire above the strength threshold.</div></div>
  <?php else: ?>

  <!-- Open trades -->
  <?php if (!empty($openTrades)): ?>
  <div style="padding:16px 16px 0;display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:.82rem;font-weight:600;color:var(--gn)">OPEN (<?= count($openTrades) ?>)</div>
    <form method="post" onsubmit="return confirm('Close all open trades at current prices?')">
      <input type="hidden" name="action" value="close_all_trades">
      <button type="submit" style="font-size:.75rem;padding:5px 12px;background:transparent;border:1px solid var(--b1);border-radius:6px;color:var(--t2);cursor:pointer;font-family:inherit">Close All</button>
    </form>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>STRATEGY</th><th>COIN</th><th>ACTION</th>
        <th>ENTRY</th><th>CURRENT</th><th>SIZE (USD)</th><th>P&amp;L $</th><th>P&amp;L %</th><th>AGE</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($openTrades as $t): tradeRow($t, true); endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <!-- Closed trades -->
  <?php if (!empty($closedTrades)): ?>
  <div style="padding:16px 16px 0">
    <div style="font-size:.82rem;font-weight:600;color:var(--t3)">CLOSED (<?= count($closedTrades) ?>)</div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>STRATEGY</th><th>COIN</th><th>ACTION</th>
        <th>ENTRY</th><th>EXIT</th><th>SIZE (USD)</th><th>P&amp;L $</th><th>P&amp;L %</th><th>HELD</th><th>CLOSED</th>
      </tr></thead>
      <tbody>
      <?php foreach ($closedTrades as $t): tradeRow($t, false); endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <?php endif ?>
</div>

<?php endif; ?>

<div class="footer">Crypto Dashboard · Raspberry Pi · <?= date('Y') ?></div>
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

/* ── Equity Chart Engine ──────────────────────────────────────────────── */
function drawEquityChart(canvas, data, opts) {
  if (!canvas || !data || data.length < 2) return;
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = rect.height * dpr;
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  const W = rect.width, H = rect.height;
  const pad = {t:12, b:22, l:52, r:12};
  const cW = W - pad.l - pad.r, cH = H - pad.t - pad.b;

  // Extract values
  const vals = data.map(d => d.total_value);
  let minV = Math.min(...vals), maxV = Math.max(...vals);
  if (opts.baseline !== undefined) {
    minV = Math.min(minV, opts.baseline);
    maxV = Math.max(maxV, opts.baseline);
  }
  const range = maxV - minV || 1;
  minV -= range * 0.04; maxV += range * 0.04;
  const rng = maxV - minV;

  const toX = i => pad.l + (i / (data.length - 1)) * cW;
  const toY = v => pad.t + (1 - (v - minV) / rng) * cH;

  // Clear
  ctx.clearRect(0, 0, W, H);

  // Grid lines
  ctx.strokeStyle = '#333130'; ctx.lineWidth = 0.5;
  for (let i = 0; i < 5; i++) {
    const y = pad.t + (i / 4) * cH;
    ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W - pad.r, y); ctx.stroke();
    const v = maxV - (i / 4) * rng;
    ctx.fillStyle = '#6b6966'; ctx.font = '10px system-ui';
    ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    ctx.fillText('$' + v.toFixed(0), pad.l - 6, y);
  }

  // Baseline
  if (opts.baseline !== undefined) {
    const by = toY(opts.baseline);
    ctx.setLineDash([4, 3]); ctx.strokeStyle = '#4a4845'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(pad.l, by); ctx.lineTo(W - pad.r, by); ctx.stroke();
    ctx.setLineDash([]);
  }

  // Draw line helper
  function drawLine(values, color, width) {
    if (!values || values.length < 2) return;
    ctx.strokeStyle = color; ctx.lineWidth = width; ctx.beginPath();
    values.forEach((v, i) => { if (v === null) return; const fn = i === 0 ? 'moveTo' : 'lineTo'; ctx[fn](toX(i), toY(v)); });
    ctx.stroke();
  }

  // Benchmark lines (convert return % to dollar value based on funded)
  if (opts.showBenchmarks && opts.baseline) {
    const btcVals = data.map(d => d.btc_return_pct !== undefined ? opts.baseline * (1 + d.btc_return_pct / 100) : null);
    const eqVals  = data.map(d => d.equal_weight_return_pct !== undefined ? opts.baseline * (1 + d.equal_weight_return_pct / 100) : null);
    drawLine(btcVals, '#fb923c', 1);
    drawLine(eqVals, '#60a5fa', 1);
  }

  // Main line
  drawLine(vals, opts.color || '#a78bfa', 2);

  // X-axis labels
  ctx.fillStyle = '#6b6966'; ctx.font = '10px system-ui'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
  const labelCount = Math.min(5, data.length);
  for (let i = 0; i < labelCount; i++) {
    const idx = Math.floor(i * (data.length - 1) / (labelCount - 1));
    const d = data[idx];
    const ts = d.recorded_at || d.date || '';
    const label = ts.length >= 13 ? ts.substring(5, 13) : ts.substring(0, 10);
    ctx.fillText(label, toX(idx), H - pad.b + 6);
  }

  // Hover tooltip
  canvas.onmousemove = function(e) {
    const br = canvas.getBoundingClientRect();
    const mx = e.clientX - br.left;
    const idx = Math.round(((mx - pad.l) / cW) * (data.length - 1));
    if (idx < 0 || idx >= data.length) return;
    const d = data[idx];
    const ts = d.recorded_at || d.date || '';
    canvas.title = ts + '  $' + d.total_value.toFixed(2);
  };
}

function setupChartButtons(btnContainerId, canvasId, apiEndpoint, opts) {
  const container = document.getElementById(btnContainerId);
  const canvas = document.getElementById(canvasId);
  if (!container || !canvas) return;

  function loadChart(hours) {
    fetch(apiEndpoint + '&hours=' + hours)
      .then(r => r.json())
      .then(resp => {
        const data = resp.data || resp;
        if (Array.isArray(data) && data.length > 1) {
          // Ensure numeric
          data.forEach(d => {
            d.total_value = parseFloat(d.total_value) || 0;
            if (d.btc_return_pct !== undefined) d.btc_return_pct = parseFloat(d.btc_return_pct) || 0;
            if (d.equal_weight_return_pct !== undefined) d.equal_weight_return_pct = parseFloat(d.equal_weight_return_pct) || 0;
          });
          drawEquityChart(canvas, data, opts);
        }
      })
      .catch(err => console.warn('Chart fetch error:', err));
  }

  container.querySelectorAll('.tf-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      container.querySelectorAll('.tf-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      loadChart(parseInt(this.dataset.hours));
    });
  });

  // Initial load with default (active button)
  const activeBtn = container.querySelector('.tf-btn.active');
  if (activeBtn) loadChart(parseInt(activeBtn.dataset.hours));
}

// Initialize charts on page load
document.addEventListener('DOMContentLoaded', function() {
  // Paper Portfolio equity curve
  if (document.getElementById('pp-equity-chart')) {
    setupChartButtons('pp-tf-btns', 'pp-equity-chart',
      'api.php?action=paper_portfolio_history',
      { color: '#a78bfa', baseline: <?= json_encode(isset($ppCfg) ? ($ppCfg['funded_amount'] ?? 1000) : 1000) ?>, showBenchmarks: true }
    );
  }
  // Paper Trading P&L equity curve
  if (document.getElementById('pt-equity-chart')) {
    setupChartButtons('pt-tf-btns', 'pt-equity-chart',
      'api.php?action=paper_trading_history',
      { color: '#c96442', baseline: 0, showBenchmarks: false }
    );
  }
});
</script>
</body>
</html>
