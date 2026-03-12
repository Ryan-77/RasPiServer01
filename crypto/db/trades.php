<?php
// ── Paper trade queries ───────────────────────────────────────

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
