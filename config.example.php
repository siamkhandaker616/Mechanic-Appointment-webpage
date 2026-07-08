<?php

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'mystery_motors');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

define('SLOT_COUNT', 4);
define('MAX_SLOTS_PER_MECHANIC', 4);

define('SITE_URL', 'https://your-domain.com');

$SLOT_LABELS = [
    0 => '08:00 — 10:00',
    1 => '10:00 — 12:00',
    2 => '12:00 — 14:00',
    3 => '14:00 — 16:00',
];

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
