<?php
// ── Signal queries ────────────────────────────────────────────

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
