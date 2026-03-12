<?php
// ══════════════════════════════════════════════════════════════
//  JSON REST API — returns JSON for all GET/POST actions
//  Future React frontend will consume these endpoints.
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jsonOk(mixed $data): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_PRETTY_PRINT);
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── GET ENDPOINTS ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {

        case 'portfolio':
            $portfolio    = getPortfolio();
            $latestPrices = getLatestPrices();
            $totalUsd     = 0;
            $holdings     = [];
            foreach ($portfolio as $row) {
                $price   = $latestPrices[$row['coin']] ?? null;
                $val     = $price ? $row['amount'] * $price : null;
                $totalUsd += $val ?? 0;
                $holdings[] = [
                    'coin'       => $row['coin'],
                    'name'       => $SUPPORTED_COINS[$row['coin']] ?? $row['coin'],
                    'amount'     => (float)$row['amount'],
                    'target_pct' => $row['target_pct'] !== null ? (float)$row['target_pct'] : null,
                    'price_usd'  => $price,
                    'value_usd'  => $val ? round($val, 2) : null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }
            // Second pass for allocation %
            foreach ($holdings as &$h) {
                $h['alloc_pct'] = ($h['value_usd'] && $totalUsd > 0) ? round(($h['value_usd'] / $totalUsd) * 100, 2) : null;
                $h['drift_pct'] = ($h['alloc_pct'] !== null && $h['target_pct'] !== null) ? round($h['alloc_pct'] - $h['target_pct'], 2) : null;
            }
            unset($h);
            jsonOk(['holdings' => $holdings, 'total_usd' => round($totalUsd, 2)]);

        case 'signals':
            $strategy = $_GET['strategy'] ?? 'all';
            $limit    = min((int)($_GET['limit'] ?? 100), 500);
            $signals  = getSignals($strategy, $limit);
            // Decode JSON details field
            foreach ($signals as &$s) {
                $s['details'] = json_decode($s['details'] ?? '{}', true) ?? [];
            }
            unset($s);
            jsonOk($signals);

        case 'alerts':
            $alerts      = getAlerts();
            $unseenCount = getUnseenAlertCount();
            foreach ($alerts as &$a) {
                $a['details'] = json_decode($a['details'] ?? '{}', true) ?? [];
            }
            unset($a);
            jsonOk(['alerts' => $alerts, 'unseen_count' => $unseenCount]);

        case 'trades':
            $status       = $_GET['status'] ?? 'all';
            $trades       = getTrades($status);
            $latestPrices = getLatestPrices();
            $trades       = computeTradePnl($trades, $latestPrices);

            $openTrades   = array_values(array_filter($trades, fn($t) => $t['status'] === 'open'));
            $closedTrades = array_values(array_filter($trades, fn($t) => $t['status'] === 'closed'));
            $totalPnl     = round(array_sum(array_column($trades, 'pnl_usd')), 2);

            // P&L by strategy
            $pnlByStrategy = [];
            foreach ($trades as $t) {
                $strat = $t['strategy'] ?? 'unknown';
                $pnlByStrategy[$strat] = round(($pnlByStrategy[$strat] ?? 0) + $t['pnl_usd'], 2);
            }

            jsonOk([
                'trades'          => $trades,
                'open_count'      => count($openTrades),
                'closed_count'    => count($closedTrades),
                'total_pnl'       => $totalPnl,
                'pnl_by_strategy' => $pnlByStrategy,
            ]);

        case 'prices':
            jsonOk(getLatestPrices());

        case 'summary':
            jsonOk(getDashboardSummary());

        case 'log':
            $lines   = min((int)($_GET['lines'] ?? 200), 1000);
            $content = getLogContent($lines);
            jsonOk(['content' => $content, 'lines' => $lines]);

        case 'performance':
            jsonOk(getStrategyPerformance());

        case 'paper_portfolio':
            $latestPrices = getLatestPrices();
            jsonOk(getPaperPortfolioSummary($latestPrices));

        case 'paper_portfolio_history':
            $days = min((int)($_GET['days'] ?? 30), 365);
            jsonOk(getPaperPortfolioHistory($days));

        case 'paper_portfolio_performance':
            jsonOk(getPaperPortfolioPerformance());

        default:
            jsonErr('Unknown action: ' . $action, 404);
    }
}

// ── POST ENDPOINTS ───────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept both form-encoded and JSON body
    $input = $_POST;
    if (empty($input) || !isset($input['action'])) {
        $json = json_decode(file_get_contents('php://input'), true);
        if ($json) $input = array_merge($input, $json);
    }
    $action = $input['action'] ?? '';

    switch ($action) {

        case 'portfolio_upsert':
            $coin   = strtolower(trim($input['coin'] ?? ''));
            $amount = (float)($input['amount'] ?? 0);
            $tgt    = isset($input['target_pct']) && $input['target_pct'] !== '' ? (float)$input['target_pct'] : null;
            if (!array_key_exists($coin, $SUPPORTED_COINS) || $amount <= 0) {
                jsonErr('Invalid coin or amount.');
            }
            upsertPortfolio($coin, $amount, $tgt);
            jsonOk(['coin' => $coin, 'msg' => strtoupper($coin) . ' updated.']);

        case 'portfolio_delete':
            $coin = strtolower(trim($input['coin'] ?? ''));
            if (!$coin) jsonErr('Missing coin.');
            deletePortfolioCoin($coin);
            jsonOk(['coin' => $coin, 'msg' => strtoupper($coin) . ' removed.']);

        case 'run_analysis':
            global $LOG_FILE;
            shell_exec('python3 /var/www/html/crypto/crypto.py >> ' . escapeshellarg($LOG_FILE) . ' 2>&1 &');
            jsonOk(['msg' => 'Analysis triggered — results will appear shortly.']);

        case 'dismiss_alert':
            $id = (int)($input['alert_id'] ?? 0);
            if ($id <= 0) jsonErr('Invalid alert_id.');
            dismissAlert($id);
            jsonOk(['msg' => 'Alert dismissed.']);

        case 'dismiss_all_alerts':
            $count = dismissAllAlerts();
            jsonOk(['msg' => "Dismissed all alerts.", 'count' => $count]);

        case 'close_trade':
            $id   = (int)($input['trade_id'] ?? 0);
            $coin = strtolower(trim($input['coin'] ?? ''));
            if ($id <= 0 || !$coin) jsonErr('Missing trade_id or coin.');
            if (!closeTrade($id, $coin)) jsonErr('Could not close trade — no price data.');
            jsonOk(['msg' => 'Trade closed.']);

        case 'close_all_trades':
            $count = closeAllTrades();
            jsonOk(['msg' => "Closed $count trade(s).", 'count' => $count]);

        case 'reset_trades':
            resetTrades();
            jsonOk(['msg' => 'Paper trading history reset.']);

        case 'fund_paper_portfolio':
            $amount = (float)($input['amount'] ?? 1000);
            if ($amount < 100 || $amount > 1000000) jsonErr('Amount must be between $100 and $1,000,000.');
            fundPaperPortfolio($amount);
            jsonOk(['msg' => "Paper portfolio funded with \${$amount}.", 'amount' => $amount]);

        case 'reset_paper_portfolio':
            resetPaperPortfolio();
            jsonOk(['msg' => 'Paper portfolio reset.']);

        case 'update_paper_settings':
            $settings = [];
            foreach (['stop_loss_pct', 'take_profit_pct', 'cash_reserve_pct', 'margin_limit'] as $k) {
                if (isset($input[$k])) $settings[$k] = (float)$input[$k];
            }
            if (empty($settings)) jsonErr('No valid settings provided.');
            updatePaperSettings($settings);
            jsonOk(['msg' => 'Paper portfolio settings updated.', 'updated' => array_keys($settings)]);

        case 'toggle_paper_portfolio':
            $status = $input['status'] ?? '';
            if (!in_array($status, ['active', 'paused'])) jsonErr('Status must be active or paused.');
            togglePaperPortfolio($status);
            jsonOk(['msg' => "Paper portfolio {$status}.", 'status' => $status]);

        default:
            jsonErr('Unknown action: ' . $action, 404);
    }
}

jsonErr('Method not allowed.', 405);
