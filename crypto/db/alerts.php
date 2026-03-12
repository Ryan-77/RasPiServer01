<?php
// ── Alert queries ─────────────────────────────────────────────

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
