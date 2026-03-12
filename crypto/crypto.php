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
<link rel="stylesheet" href="assets/style.css">
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
const APP_DATA = { ppFundedAmount: <?= isset($ppCfg) ? (int)($ppCfg['funded_amount'] ?? 1000) : 1000 ?> };
</script>
<script src="assets/app.js"></script>
</body>
</html>
