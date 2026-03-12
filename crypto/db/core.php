<?php
// ── Core: PDO singleton + shared helpers ─────────────────────
require_once __DIR__ . '/../config.php';

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
