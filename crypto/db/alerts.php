<?php
// ── Alert queries ─────────────────────────────────────────────

function getUnseenAlertCount(): int {
    try {
        return (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status='new'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getAlerts(int $limit = 50, ?string $strategyOnly = null, array $excludeStrategies = []): array {
    try {
        $where  = [];
        $params = [];
        if ($strategyOnly !== null) {
            $where[]  = "strategy = ?";
            $params[] = $strategyOnly;
        }
        if (!empty($excludeStrategies)) {
            $ph      = implode(',', array_fill(0, count($excludeStrategies), '?'));
            $where[] = "strategy NOT IN ($ph)";
            $params  = array_merge($params, $excludeStrategies);
        }
        $sql     = "SELECT * FROM alerts";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql    .= " ORDER BY timestamp DESC LIMIT ?";
        $params[] = $limit;
        $stmt    = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
