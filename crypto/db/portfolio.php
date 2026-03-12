<?php
// ── Portfolio queries ─────────────────────────────────────────

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
