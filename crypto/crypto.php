<?php
// ══════════════════════════════════════════════════════════════
//  CRYPTO DASHBOARD — Router + Layout Shell
//  All data access goes through db.php; API available at api.php
//  Page templates live in pages/*.php
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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

// ── ROUTING ─────────────────────────────────────────────────
$validViews = ['portfolio','analysis','log','alerts','trades'];
$view = in_array($_GET['view'] ?? '', $validViews) ? $_GET['view'] : 'portfolio';

// ── PER-PAGE DATA LOADING ───────────────────────────────────
// Always loaded (needed for nav badges on all pages):
$portfolio    = getPortfolio();
$latestPrices = getLatestPrices();
$totalUsd     = array_sum(array_map(fn($r) => $r['amount'] * ($latestPrices[$r['coin']] ?? 0), $portfolio));
$unseenCount  = getUnseenAlertCount();

// Trades needed for nav badge + some pages
$allTrades  = getTrades();
$openCount  = count(array_filter($allTrades, fn($t) => $t['status'] === 'open'));

// Page-specific loading
if ($view === 'portfolio') {
    $allTrades    = computeTradePnl($allTrades, $latestPrices);
    $paperPnl     = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);
    $ppSummary    = getPaperPortfolioSummary($latestPrices);
    $ppFunded     = $ppSummary['funded'] ?? false;
    $ppHistory    = $ppFunded ? getPaperPortfolioHistory(720) : [];
    $recAllocs    = getPaperPortfolioAllocations();
    $recAllocMap  = [];
    foreach ($recAllocs as $al) { $recAllocMap[$al['coin']] = (float)$al['recommended_pct']; }
} elseif ($view === 'analysis') {
    $signals = []; $sigError = null;
    try { $signals = getSignals(null, 100); }
    catch (Exception $e) { $sigError = h($e->getMessage()); }
} elseif ($view === 'alerts') {
    $alerts       = getAlerts();
    $allTrades    = computeTradePnl($allTrades, $latestPrices);
    $openTrades   = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'open'));
    $closedTrades = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'closed'));
    $paperPnl     = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);
} elseif ($view === 'trades') {
    $allTrades    = computeTradePnl($allTrades, $latestPrices);
    $openTrades   = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'open'));
    $closedTrades = array_values(array_filter($allTrades, fn($t) => $t['status'] === 'closed'));
    $paperPnl     = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);
    $ptHistory    = getPaperTradingHistory(720);
    $ppSummary    = getPaperPortfolioSummary($latestPrices);
} elseif ($view === 'log') {
    $logContent = getLogContent($LOG_LINES);
    $logError   = $logContent === null ? 'Log file not found or cannot be read.' : null;
}

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

/* ── NEW: Portfolio Hero ─────────────────────────────────── */
.hero{text-align:center;padding:36px 20px 28px}
.hero-value{font-size:2.8rem;font-weight:800;color:var(--t1);
            font-variant-numeric:tabular-nums;letter-spacing:-.02em;font-family:var(--mono)}
.hero-meta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;
           font-size:.82rem;color:var(--t3);margin-top:10px}
.hero-sep{color:var(--b2)}

/* ── NEW: Sub-tabs (portfolio page) ──────────────────────── */
.sub-tabs{display:flex;gap:4px;margin-bottom:18px;
          background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:4px}
.sub-tab{flex:1;padding:10px 18px;border-radius:8px;text-align:center;
         font-size:.84rem;font-weight:600;cursor:pointer;
         background:transparent;border:none;color:var(--t3);
         font-family:var(--font);transition:all .18s}
.sub-tab:hover{color:var(--t1);background:var(--s2)}
.sub-tab.active[data-tab="holdings"]{background:var(--ac);color:#fff}
.sub-tab.active[data-tab="paper"]{background:var(--pu);color:#fff}

/* ── NEW: Stat cards row (trades page) ───────────────────── */
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.pstat{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);
       padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.pstat-l{font-size:.72rem;color:var(--t3);margin-bottom:8px;font-weight:500;text-transform:uppercase;letter-spacing:.05em}
.pstat-v{font-size:1.5rem;font-weight:700;line-height:1.2}
.pstat-s{font-size:.75rem;color:var(--t3);margin-top:6px}

/* Responsive */
@media(max-width:700px){
  .hdr{flex-direction:column;gap:14px;text-align:center}
  .hdr-r{justify-content:center}
  .hdr-l{flex-direction:column}
  .stat-row{grid-template-columns:1fr 1fr}
  .nav{flex-wrap:wrap}
  .nav-sp{display:none}
  .form-row{flex-direction:column}
  .hero-value{font-size:2rem}
  .sub-tabs{flex-direction:column}
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
    <form method="POST" style="margin:0">
      <input type="hidden" name="action" value="run_analysis">
      <button type="submit" class="btn btn-g">▶ RUN</button>
    </form>
    <div class="clock-box">
      <div class="clock-t" id="clock">──:──:──</div>
      <div class="clock-d" id="cdate">────────────</div>
    </div>
  </div>
</header>

<!-- NAV — Portfolio first -->
<nav class="nav">
  <a href="<?= buildUrl(['view'=>'portfolio']) ?>" class="<?= $view==='portfolio'?'active':'' ?>">PORTFOLIO</a>
  <a href="<?= buildUrl(['view'=>'analysis']) ?>" class="<?= $view==='analysis'?'active':'' ?>">ANALYSIS</a>
  <a href="<?= buildUrl(['view'=>'alerts']) ?>" class="<?= $view==='alerts'?'active':'' ?>">
    ALERTS<?php if ($unseenCount > 0): ?><span class="badge"><?= $unseenCount ?></span><?php endif ?>
  </a>
  <a href="<?= buildUrl(['view'=>'trades']) ?>" class="<?= $view==='trades'?'active':'' ?>">
    TRADES<?php if ($openCount > 0): ?><span class="badge" style="background:var(--gn);color:#000"><?= $openCount ?></span><?php endif ?>
  </a>
  <a href="<?= buildUrl(['view'=>'log']) ?>" class="<?= $view==='log'?'active':'' ?>">LOG</a>
  <div class="nav-sp"></div>
  <span style="font-size:.75rem;color:var(--t3)">cron: */5 * * * *</span>
</nav>

<!-- FLASH -->
<?php if ($flash): ?>
<div class="flash <?= $flash['type'] ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<!-- PAGE CONTENT -->
<?php require __DIR__ . "/pages/{$view}.php"; ?>

<div class="footer">Crypto Dashboard · Raspberry Pi · <?= date('Y') ?></div>
</div>

<script>
/* ── Clock ──────────────────────────────────────────────────── */
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

/* ── Sub-tab Switching (portfolio page) ─────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.sub-tab').forEach(b => b.classList.remove('active'));
  const btn = document.querySelector('.sub-tab[data-tab="' + tab + '"]');
  if (btn) btn.classList.add('active');
  const h = document.getElementById('tab-holdings');
  const p = document.getElementById('tab-paper');
  if (h) h.style.display = tab === 'holdings' ? 'block' : 'none';
  if (p) p.style.display = tab === 'paper' ? 'block' : 'none';
  history.replaceState(null, '', location.pathname + location.search + '#' + tab);
  // Canvas charts need visible parent to render — trigger redraw on paper tab
  if (tab === 'paper') {
    const activeBtn = document.querySelector('#pp-tf-btns .tf-btn.active');
    if (activeBtn) activeBtn.click();
  }
}

/* ── Equity Chart Engine ──────────────────────────────────────── */
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

  // Benchmark lines
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

  const activeBtn = container.querySelector('.tf-btn.active');
  if (activeBtn) loadChart(parseInt(activeBtn.dataset.hours));
}

/* ── Initialize charts on page load ────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  // Check URL hash for direct tab linking
  if (window.location.hash === '#paper') switchTab('paper');

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
