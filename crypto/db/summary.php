<?php
// ── Dashboard summary + log ───────────────────────────────────

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

function getLogContent(int $lines = 200): ?string {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) return null;
    $content = tailFile($LOG_FILE, $lines);
    return $content !== false ? $content : null;
}
