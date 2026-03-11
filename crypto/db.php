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
