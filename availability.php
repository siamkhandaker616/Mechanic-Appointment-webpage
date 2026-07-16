<?php
/* === SETUP & VALIDATION === */
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'quickbook') {
    $phone = preg_replace('/[^\d]/', '', $_GET['phone'] ?? '');
    if (!$phone) {
        echo json_encode(['found' => false, 'message' => 'Phone number is required.']);
        exit;
    }
    $last = getLastAppointmentByPhone($phone);
    if (!$last) {
        echo json_encode(['found' => false, 'message' => 'That number ain\'t in our grease-stained ledger, pal. First time? Fill out the form.']);
        exit;
    }
    $dayAfter = date('Y-m-d', strtotime($last['appointment_date'] . ' +1 day'));
    $nextAvail = findNextAvailableSlot((int)$last['mechanic_id'], $dayAfter);
    echo json_encode([
        'found' => true,
        'client' => [
            'name' => $last['client_name'],
            'phone' => $last['phone'],
            'address' => $last['address'],
        ],
        'car' => [
            'license_no' => $last['license_no'],
            'engine_no' => $last['engine_no'],
            'model' => $last['model'],
        ],
        'last_mechanic_id' => (int)$last['mechanic_id'],
        'next_available' => $nextAvail,
    ]);
    exit;
}

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
$slotAvailability = getMechanicSlotsAvailability($mechanicId, $date);
$slots = [];
for ($i = 0; $i < SLOT_COUNT; $i++) {
    $slots[] = [
        'index' => $i,
        'label' => $SLOT_LABELS[$i] ?? "Slot " . ($i + 1),
        'available' => $slotAvailability[$i],
    ];
}

$allMechs = getMechanics();
$allNames = [];
$allSlots = [];
foreach ($allMechs as $m) {
    $allNames[$m['id']] = $m['name'];
    $mechSlots = [];
    $mechAvail = getMechanicSlotsAvailability((int)$m['id'], $date);
    for ($i = 0; $i < SLOT_COUNT; $i++) {
        $mechSlots[] = [
            'index' => $i,
            'available' => $mechAvail[$i],
        ];
    }
    $allSlots[(int)$m['id']] = $mechSlots;
}

$slotIndex = isset($_GET['slot_index']) ? (int)$_GET['slot_index'] : null;
if ($slotIndex !== null && ($slotIndex < 0 || $slotIndex >= SLOT_COUNT)) $slotIndex = null;

/* === BUILD RESPONSE === */

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
