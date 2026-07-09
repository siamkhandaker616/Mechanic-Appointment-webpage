<?php
require_once __DIR__ . '/functions.php';

$mechanicId = (int)($_GET['mechanic_id'] ?? 0);
$date = $_GET['date'] ?? '';

if (!$mechanicId || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters.']);
    exit;
}

$mechanic = getMechanicById($mechanicId);
if (!$mechanic) {
    http_response_code(404);
    echo json_encode(['error' => 'Mechanic not found.']);
    exit;
}

global $SLOT_LABELS;
$slots = [];
for ($i = 0; $i < SLOT_COUNT; $i++) {
    $slots[] = [
        'index' => $i,
        'label' => $SLOT_LABELS[$i] ?? "Slot " . ($i + 1),
        'available' => isSlotAvailable($mechanicId, $date, $i),
    ];
}

echo json_encode([
    'mechanic_id' => $mechanicId,
    'date' => $date,
    'slots' => $slots,
    'on_vacation' => isMechanicOnVacation($mechanicId, $date),
]);
