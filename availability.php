<?php
require_once __DIR__ . '/functions.php';

$mechanicId = (int)($_GET['mechanic_id'] ?? 0);
$date = $_GET['date'] ?? '';

if (!$mechanicId || !$date || !preg_match(DATE_REGEX, $date)) {
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

$allMechs = getMechanics();
$allNames = [];
$allSlots = [];
foreach ($allMechs as $m) {
    $allNames[$m['id']] = $m['name'];
    $mechSlots = [];
    for ($i = 0; $i < SLOT_COUNT; $i++) {
        $mechSlots[] = [
            'index' => $i,
            'available' => isSlotAvailable((int)$m['id'], $date, $i),
        ];
    }
    $allSlots[(int)$m['id']] = $mechSlots;
}

$slotIndex = isset($_GET['slot_index']) ? (int)$_GET['slot_index'] : null;

$response = [
    'mechanic_id' => $mechanicId,
    'date' => $date,
    'slots' => $slots,
    'on_vacation' => isMechanicOnVacation($mechanicId, $date),
    'all_slots' => $allSlots,
    'all_names' => $allNames,
];

if ($slotIndex !== null && $slotIndex >= 0) {
    $mechanicName = $mechanic['name'] ?? '';
    $firstName = explode(' ', $mechanicName)[0];
    $nickname = $mechanic['nickname'] ?? '';
    $response['mechanic_first_name'] = $firstName;
    $response['mechanic_nickname'] = $nickname ?: $firstName;
    $response['adjacent_slot'] = getAdjacentSlotForMechanic($mechanicId, $date, $slotIndex);
    $nearby = getNearbyDatesForMechanic($mechanicId, $slotIndex, $date);
    $response['nearby_prev_date'] = $nearby['prev'];
    $response['nearby_next_date'] = $nearby['next'];
}

echo json_encode($response);
