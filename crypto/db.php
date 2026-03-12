<?php
// ══════════════════════════════════════════════════════════════
//  DATABASE LAYER — thin aggregator (backward-compatible)
//  All query functions live in db/ domain files.
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/db/core.php';
require_once __DIR__ . '/db/portfolio.php';
require_once __DIR__ . '/db/prices.php';
require_once __DIR__ . '/db/signals.php';
require_once __DIR__ . '/db/alerts.php';
require_once __DIR__ . '/db/trades.php';
require_once __DIR__ . '/db/summary.php';
require_once __DIR__ . '/db/paper.php';
