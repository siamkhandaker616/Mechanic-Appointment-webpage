<?php
require_once __DIR__ . '/functions.php';

$date = $_GET['date'] ?? '';

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date.']);
    exit;
}

$mechs = getMechanics();
$mechNames = [];
$mechSlots = [];
foreach ($mechs as $m) {
    $mechNames[$m['id']] = $m['name'];
    $slots = [];
    for ($i = 0; $i < SLOT_COUNT; $i++) {
        $slots[] = [
            'index' => $i,
            'available' => isSlotAvailable((int)$m['id'], $date, $i),
        ];
    }
    $mechSlots[(int)$m['id']] = $slots;
}

echo json_encode([
    'date' => $date,
    'names' => $mechNames,
    'slots' => $mechSlots,
]);
