<?php

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'mayhem_mobility');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

define('ADMIN_PW', 'your_admin_password');

define('SLOT_COUNT', 4);
define('DATE_REGEX', '/^\d{4}-\d{2}-\d{2}$/');
define('STATUS_SCHEDULED', 'scheduled');
define('STATUS_CANCELLED', 'cancelled');
define('STATUS_IN_PROGRESS', 'in_progress');
define('STATUS_COMPLETED', 'completed');

$DAY_NAMES_FULL = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAY_NAMES_ABBR = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

$SLOT_LABELS = [
    0 => '10:00 — 12:00',
    1 => '12:00 — 14:00',
    2 => '14:00 — 16:00',
    3 => '16:00 — 18:00',
];

$SLOT_NAMES = ['Morning', 'Noon', 'Afternoon', 'Evening'];

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
