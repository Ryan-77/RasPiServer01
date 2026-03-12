<?php
// ══════════════════════════════════════════════════════════════
//  DATABASE LAYER — PDO singleton + query helper functions
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';

function db(): PDO {
    global $DB_FILE;
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO("sqlite:$DB_FILE");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode=WAL");
    }
    return $pdo;
}

// ── Helpers ──────────────────────────────────────────────────

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tailFile(string $file, int $n): string|false {
    $f = fopen($file, 'rb');
    if (!$f) return false;
    fseek($f, 0, SEEK_END);
    $pos = ftell($f); $data = ''; $found = 0;
    while ($pos > 0 && $found <= $n) {
        $read = min(4096, $pos); $pos -= $read; fseek($f, $pos);
        $data = fread($f, $read) . $data; $found = substr_count($data, "\n");
    }
    fclose($f);
    return implode("\n", array_slice(explode("\n", $data), -$n));
}

// ── Portfolio ────────────────────────────────────────────────

function getPortfolio(): array {
    try {
        return db()->query("SELECT * FROM portfolio ORDER BY coin")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function upsertPortfolio(string $coin, float $amount, ?float $targetPct): bool {
    $stmt = db()->prepare(
        "INSERT INTO portfolio (coin, amount, target_pct, updated_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(coin) DO UPDATE SET amount=excluded.amount,
         target_pct=excluded.target_pct, updated_at=excluded.updated_at"
    );
    return $stmt->execute([$coin, $amount, $targetPct, date('c')]);
}

function deletePortfolioCoin(string $coin): bool {
    return db()->prepare("DELETE FROM portfolio WHERE coin = ?")->execute([$coin]);
}

// ── Prices ───────────────────────────────────────────────────

function getLatestPrices(): array {
    $prices = [];
    try {
        $rows = db()->query("
            SELECT coin, price_usd FROM price_history
            WHERE (coin, timestamp) IN (
                SELECT coin, MAX(timestamp) FROM price_history GROUP BY coin
            )
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $prices[$r['coin']] = (float)$r['price_usd'];
    } catch (Exception $e) {
        // Fallback: per-coin query for older SQLite versions
        $portfolio = getPortfolio();
        foreach ($portfolio as $row) {
            try {
                $r = db()->prepare("SELECT price_usd FROM price_history WHERE coin=? ORDER BY timestamp DESC LIMIT 1");
                $r->execute([$row['coin']]);
                $p = $r->fetchColumn();
                if ($p !== false) $prices[$row['coin']] = (float)$p;
            } catch (Exception $e2) {}
        }
    }
    return $prices;
}

function getLatestPriceForCoin(string $coin): ?float {
    $stmt = db()->prepare("SELECT price_usd FROM price_history WHERE coin=? ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute([$coin]);
    $p = $stmt->fetchColumn();
    return $p !== false ? (float)$p : null;
}

// ── Signals ──────────────────────────────────────────────────

function getSignals(?string $strategy = null, int $limit = 100): array {
    try {
        if ($strategy && $strategy !== 'all') {
            $stmt = db()->prepare("SELECT * FROM analysis_signals WHERE strategy = ? ORDER BY timestamp DESC LIMIT ?");
            $stmt->execute([$strategy, $limit]);
        } else {
            $stmt = db()->prepare("SELECT * FROM analysis_signals ORDER BY timestamp DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Alerts ───────────────────────────────────────────────────

function getUnseenAlertCount(): int {
    try {
        return (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status='new'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getAlerts(int $limit = 50): array {
    try {
        return db()->query("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function dismissAlert(int $id): bool {
    return db()->prepare("UPDATE alerts SET status='seen' WHERE id=?")->execute([$id]);
}

function dismissAllAlerts(): int {
    return db()->exec("UPDATE alerts SET status='seen' WHERE status='new'");
}

// ── Paper Trades ─────────────────────────────────────────────

function getTrades(?string $statusFilter = null): array {
    try {
        $rows = db()->query("
            SELECT pt.*, a.strategy, a.signal as alert_signal
            FROM paper_trades pt
            LEFT JOIN alerts a ON pt.alert_id = a.id
            ORDER BY pt.timestamp DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if ($statusFilter && $statusFilter !== 'all') {
            $rows = array_values(array_filter($rows, fn($t) => $t['status'] === $statusFilter));
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

function computeTradePnl(array $trades, array $latestPrices): array {
    foreach ($trades as &$t) {
        if ($t['status'] === 'closed' && $t['exit_price'] !== null) {
            $pnl = $t['action'] === 'buy'
                ? ((float)$t['exit_price'] - (float)$t['entry_price']) * (float)$t['amount_coin']
                : ((float)$t['entry_price'] - (float)$t['exit_price']) * (float)$t['amount_coin'];
            $t['current_price'] = (float)$t['exit_price'];
        } else {
            $cur = $latestPrices[$t['coin']] ?? (float)$t['entry_price'];
            $t['current_price'] = $cur;
            $pnl = $t['action'] === 'buy'
                ? ($cur - (float)$t['entry_price']) * (float)$t['amount_coin']
                : ((float)$t['entry_price'] - $cur) * (float)$t['amount_coin'];
        }
        $t['pnl_usd'] = round($pnl, 2);
        $t['pnl_pct'] = (float)$t['amount_usd'] > 0 ? round(($pnl / (float)$t['amount_usd']) * 100, 2) : 0;
    }
    unset($t);
    return $trades;
}

function closeTrade(int $id, string $coin): bool {
    $exitPrice = getLatestPriceForCoin($coin);
    if ($exitPrice === null) return false;
    return db()->prepare("UPDATE paper_trades SET status='closed', exit_price=?, closed_at=? WHERE id=? AND status='open'")
        ->execute([$exitPrice, date('c'), $id]);
}

function closeAllTrades(): int {
    $open = db()->query("SELECT id, coin FROM paper_trades WHERE status='open'")->fetchAll(PDO::FETCH_ASSOC);
    $now = date('c');
    $count = 0;
    foreach ($open as $t) {
        $price = getLatestPriceForCoin($t['coin']);
        if ($price !== null) {
            db()->prepare("UPDATE paper_trades SET status='closed', exit_price=?, closed_at=? WHERE id=?")
                ->execute([$price, $now, $t['id']]);
            $count++;
        }
    }
    return $count;
}

function resetTrades(): void {
    db()->exec("DELETE FROM paper_trades");
}

// ── Strategy Performance ─────────────────────────────────────

function getStrategyPerformance(): array {
    try {
        $rows = db()->query("
            SELECT
                COALESCE(a.strategy, 'unknown') as strategy,
                COUNT(*)                        as total_trades,
                SUM(CASE WHEN pt.status='closed' AND pt.exit_price IS NOT NULL THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN pt.status='open' THEN 1 ELSE 0 END) as open_count
            FROM paper_trades pt
            LEFT JOIN alerts a ON pt.alert_id = a.id
            GROUP BY a.strategy
        ")->fetchAll(PDO::FETCH_ASSOC);

        $latestPrices = getLatestPrices();
        $allTrades = getTrades();
        $allTrades = computeTradePnl($allTrades, $latestPrices);

        $result = [];
        foreach ($rows as $r) {
            $strat = $r['strategy'];
            $stratTrades = array_filter($allTrades, fn($t) => ($t['strategy'] ?? 'unknown') === $strat);
            $closedTrades = array_filter($stratTrades, fn($t) => $t['status'] === 'closed');

            $wins = array_filter($closedTrades, fn($t) => $t['pnl_usd'] > 0);
            $losses = array_filter($closedTrades, fn($t) => $t['pnl_usd'] < 0);
            $totalPnl = array_sum(array_column(array_values($stratTrades), 'pnl_usd'));
            $pnlValues = array_column(array_values($closedTrades), 'pnl_usd');

            $result[] = [
                'strategy'     => $strat,
                'total_trades' => (int)$r['total_trades'],
                'open_count'   => (int)$r['open_count'],
                'closed_count' => (int)$r['closed_count'],
                'wins'         => count($wins),
                'losses'       => count($losses),
                'win_rate'     => count($closedTrades) > 0 ? round(count($wins) / count($closedTrades) * 100, 1) : 0,
                'total_pnl'    => round($totalPnl, 2),
                'avg_pnl'      => count($closedTrades) > 0 ? round($totalPnl / count($closedTrades), 2) : 0,
                'best_trade'   => !empty($pnlValues) ? round(max($pnlValues), 2) : 0,
                'worst_trade'  => !empty($pnlValues) ? round(min($pnlValues), 2) : 0,
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

// ── Summary ──────────────────────────────────────────────────

function getDashboardSummary(): array {
    $portfolio    = getPortfolio();
    $latestPrices = getLatestPrices();
    $totalUsd     = array_sum(array_map(fn($r) => $r['amount'] * ($latestPrices[$r['coin']] ?? 0), $portfolio));
    $unseenCount  = getUnseenAlertCount();

    $allTrades  = getTrades();
    $allTrades  = computeTradePnl($allTrades, $latestPrices);
    $openCount  = count(array_filter($allTrades, fn($t) => $t['status'] === 'open'));
    $closedCount = count(array_filter($allTrades, fn($t) => $t['status'] === 'closed'));
    $totalPnl   = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);

    $lastAnalysis = null;
    try {
        $ts = db()->query("SELECT timestamp FROM analysis_signals ORDER BY timestamp DESC LIMIT 1")->fetchColumn();
        if ($ts) $lastAnalysis = $ts;
    } catch (Exception $e) {}

    return [
        'portfolio_value' => round($totalUsd, 2),
        'coin_count'      => count($portfolio),
        'unseen_alerts'   => $unseenCount,
        'open_trades'     => $openCount,
        'closed_trades'   => $closedCount,
        'total_pnl'       => $totalPnl,
        'last_analysis'   => $lastAnalysis,
        'has_targets'     => (bool)array_filter($portfolio, fn($r) => $r['target_pct'] !== null),
    ];
}

// ── Log ──────────────────────────────────────────────────────

function getLogContent(int $lines = 200): ?string {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) return null;
    $content = tailFile($LOG_FILE, $lines);
    return $content !== false ? $content : null;
}

// ── Paper Portfolio ──────────────────────────────────────────

function getPaperPortfolioConfig(): ?array {
    try {
        $row = db()->query("SELECT * FROM paper_portfolio_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function getPaperPortfolioHoldings(): array {
    try {
        return db()->query("SELECT * FROM paper_portfolio_holdings WHERE amount > 0 ORDER BY current_value DESC")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getPaperPortfolioAllocations(): array {
    try {
        return db()->query("SELECT * FROM paper_portfolio_allocations ORDER BY recommended_pct DESC")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getPaperPortfolioSummary(array $latestPrices): array {
    $config     = getPaperPortfolioConfig();
    $holdings   = getPaperPortfolioHoldings();
    $allocs     = getPaperPortfolioAllocations();

    if (!$config) {
        return ['funded' => false];
    }

    // Refresh holding values from live prices
    $holdingsOut = [];
    $holdingsTotal = 0;
    foreach ($holdings as $h) {
        $price = $latestPrices[$h['coin']] ?? 0;
        $val = $price > 0 ? $h['amount'] * $price : (float)$h['current_value'];
        $cost = (float)$h['total_cost'];
        $upnl = $val - $cost;
        $holdingsTotal += $val;
        $holdingsOut[] = [
            'coin'            => $h['coin'],
            'amount'          => (float)$h['amount'],
            'avg_entry_price' => (float)$h['avg_entry_price'],
            'current_price'   => $price,
            'current_value'   => round($val, 2),
            'total_cost'      => round($cost, 2),
            'unrealized_pnl'  => round($upnl, 2),
            'unrealized_pct'  => $cost > 0 ? round(($upnl / $cost) * 100, 2) : 0,
        ];
    }

    $totalVal = (float)$config['cash_balance'] + $holdingsTotal;

    // Compute actual allocation % for each holding
    foreach ($holdingsOut as &$h) {
        $h['actual_pct'] = $totalVal > 0 ? round(($h['current_value'] / $totalVal) * 100, 2) : 0;
    }
    unset($h);

    // Latest history row for analytics
    $latestHistory = null;
    try {
        $latestHistory = db()->query("SELECT * FROM paper_portfolio_history ORDER BY recorded_at DESC LIMIT 1")
                             ->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $marginUsed = min(0, (float)$config['cash_balance']);
    $marginAvail = ((float)$config['margin_limit'] * (float)$config['funded_amount']) + (float)$config['cash_balance'];

    return [
        'funded' => true,
        'config' => [
            'funded_amount'   => (float)$config['funded_amount'],
            'cash_balance'    => (float)$config['cash_balance'],
            'total_value'     => round($totalVal, 2),
            'high_water_mark' => (float)$config['high_water_mark'],
            'max_drawdown'    => (float)$config['max_drawdown'],
            'status'          => $config['status'],
            'created_at'      => $config['created_at'],
            'updated_at'      => $config['updated_at'],
        ],
        'settings' => [
            'cash_reserve_pct' => (float)$config['cash_reserve_pct'],
            'stop_loss_pct'    => (float)$config['stop_loss_pct'],
            'take_profit_pct'  => (float)$config['take_profit_pct'],
            'margin_limit'     => (float)$config['margin_limit'],
        ],
        'holdings'    => $holdingsOut,
        'allocations' => $allocs,
        'analytics' => [
            'cumulative_return' => $latestHistory ? (float)$latestHistory['cumulative_return_pct'] : 0,
            'daily_return'      => $latestHistory ? (float)$latestHistory['period_return_pct'] : 0,
            'sharpe_ratio'      => $latestHistory ? (float)$latestHistory['sharpe_ratio'] : 0,
            'btc_return'        => $latestHistory ? (float)$latestHistory['btc_return_pct'] : 0,
            'equal_weight_return' => $latestHistory ? (float)$latestHistory['equal_weight_return_pct'] : 0,
        ],
        'risk' => [
            'margin_used'      => round(abs($marginUsed), 2),
            'margin_available'  => round(max(0, $marginAvail), 2),
            'is_on_margin'     => $marginUsed < 0,
        ],
    ];
}

function fundPaperPortfolio(float $amount): bool {
    $ts = date('c');
    try {
        db()->exec("DELETE FROM paper_portfolio_config");
        db()->exec("DELETE FROM paper_portfolio_holdings");
        db()->exec("DELETE FROM paper_portfolio_allocations");
        $stmt = db()->prepare(
            "INSERT INTO paper_portfolio_config
             (id, funded_amount, cash_balance, total_value, high_water_mark,
              max_drawdown, cash_reserve_pct, stop_loss_pct, take_profit_pct,
              margin_limit, status, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, 0.0, 5.0, 10.0, 25.0, 0.30, 'active', ?, ?)"
        );
        return $stmt->execute([$amount, $amount, $amount, $amount, $ts, $ts]);
    } catch (Exception $e) {
        return false;
    }
}

function resetPaperPortfolio(): bool {
    try {
        db()->exec("DELETE FROM paper_portfolio_config");
        db()->exec("DELETE FROM paper_portfolio_holdings");
        db()->exec("DELETE FROM paper_portfolio_allocations");
        db()->exec("DELETE FROM paper_portfolio_history");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function updatePaperSettings(array $settings): bool {
    $allowed = ['stop_loss_pct', 'take_profit_pct', 'cash_reserve_pct', 'margin_limit'];
    $sets = [];
    $vals = [];
    foreach ($allowed as $key) {
        if (isset($settings[$key])) {
            $sets[] = "$key = ?";
            $vals[] = (float)$settings[$key];
        }
    }
    if (empty($sets)) return false;
    $sets[] = "updated_at = ?";
    $vals[] = date('c');
    $vals[] = 1; // WHERE id=1
    try {
        $sql = "UPDATE paper_portfolio_config SET " . implode(', ', $sets) . " WHERE id=?";
        return db()->prepare($sql)->execute($vals);
    } catch (Exception $e) {
        return false;
    }
}

function togglePaperPortfolio(string $status): bool {
    if (!in_array($status, ['active', 'paused'])) return false;
    try {
        return db()->prepare("UPDATE paper_portfolio_config SET status=?, updated_at=? WHERE id=1")
                    ->execute([$status, date('c')]);
    } catch (Exception $e) {
        return false;
    }
}

function getPaperPortfolioHistory(int $hours = 720): array {
    try {
        $stmt = db()->prepare(
            "SELECT * FROM paper_portfolio_history WHERE recorded_at >= datetime('now', ?) ORDER BY recorded_at ASC"
        );
        $stmt->execute(["-{$hours} hours"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getPaperTradingHistory(int $hours = 720): array {
    try {
        $stmt = db()->prepare(
            "SELECT * FROM paper_trading_history WHERE recorded_at >= datetime('now', ?) ORDER BY recorded_at ASC"
        );
        $stmt->execute(["-{$hours} hours"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getPaperPortfolioPerformance(): array {
    $config = getPaperPortfolioConfig();
    if (!$config) return [];

    try {
        $created = $config['created_at'] ?? '2000-01-01';
        $stmt = db()->prepare("
            SELECT
                COALESCE(a.strategy, 'unknown') as strategy,
                COUNT(*) as total_trades,
                SUM(CASE WHEN pt.status='closed' THEN 1 ELSE 0 END) as closed_trades,
                SUM(CASE WHEN pt.status='open' THEN 1 ELSE 0 END) as open_trades,
                SUM(CASE WHEN pt.status='closed' AND pt.exit_price IS NOT NULL
                    AND CASE WHEN pt.action='buy'
                        THEN (pt.exit_price - pt.entry_price) * pt.amount_coin
                        ELSE (pt.entry_price - pt.exit_price) * pt.amount_coin END > 0
                    THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN pt.status='closed' AND pt.exit_price IS NOT NULL
                    THEN CASE WHEN pt.action='buy'
                        THEN (pt.exit_price - pt.entry_price) * pt.amount_coin
                        ELSE (pt.entry_price - pt.exit_price) * pt.amount_coin END
                    ELSE 0 END) as total_pnl
            FROM paper_trades pt
            LEFT JOIN alerts a ON pt.alert_id = a.id
            WHERE pt.timestamp >= ?
            GROUP BY a.strategy
        ");
        $stmt->execute([$created]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $closed = (int)$r['closed_trades'];
            $wins = (int)$r['wins'];
            $result[] = [
                'strategy'      => $r['strategy'],
                'total_trades'  => (int)$r['total_trades'],
                'closed_trades' => $closed,
                'open_trades'   => (int)$r['open_trades'],
                'wins'          => $wins,
                'losses'        => $closed - $wins,
                'win_rate'      => $closed > 0 ? round(($wins / $closed) * 100, 1) : 0,
                'total_pnl'     => round((float)$r['total_pnl'], 2),
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}
