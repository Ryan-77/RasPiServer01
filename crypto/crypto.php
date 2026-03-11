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
</script>
</body>
</html>
