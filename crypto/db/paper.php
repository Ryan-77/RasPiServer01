<?php
// ── Paper portfolio queries ───────────────────────────────────

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
