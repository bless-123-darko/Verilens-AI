<?php

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/../verilens.sqlite';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        initSchema($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }

    return $pdo;
}

function initSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scan_history (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            image_path      TEXT NOT NULL,
            source_type     TEXT NOT NULL CHECK(source_type IN ('upload','url')),
            verdict         TEXT NOT NULL,
            confidence      INTEGER NOT NULL,
            risk_level      TEXT NOT NULL,
            detected_objects TEXT NOT NULL DEFAULT '[]',
            reasons         TEXT NOT NULL DEFAULT '[]',
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function getTotalScans(): int {
    $pdo = getDB();
    return (int) $pdo->query('SELECT COUNT(*) FROM scan_history')->fetchColumn();
}
