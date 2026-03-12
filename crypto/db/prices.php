<?php
// ── Price queries ─────────────────────────────────────────────

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
