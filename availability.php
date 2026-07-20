<?php
/* === SETUP & VALIDATION === */
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'quickbook') {
    $phone = normalizePhone($_GET['phone'] ?? '');
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
    if (!$nextAvail) {
        $nextAvail = findNextAvailableSlot(null, $dayAfter);
    }
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

if ($action === 'edit_lookup') {
    $phone = normalizePhone($_GET['phone'] ?? '');
    if (!$phone) {
        echo json_encode(['found' => false, 'message' => 'Phone number is required.']);
        exit;
    }
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.id, a.appointment_date, a.slot_index, a.status,
               c.id AS client_id, c.name AS client_name, c.phone, c.address,
               car.id AS car_id, car.license_no, car.engine_no, car.model,
               m.name AS mechanic_name
        FROM appointments a
        JOIN clients c ON c.id = a.client_id
        JOIN cars car ON car.id = a.car_id
        JOIN mechanics m ON m.id = a.mechanic_id
        WHERE REPLACE(REPLACE(c.phone, '-', ''), ' ', '') = ?
          AND a.status = '" . STATUS_SCHEDULED . "'
        ORDER BY a.appointment_date ASC, a.slot_index ASC
    ");
    $stmt->execute([$phone]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo json_encode(['found' => false, 'message' => 'No upcoming appointments found for that number.']);
        exit;
    }
    $appointments = [];
    foreach ($rows as $r) {
        $appointments[] = [
            'id' => (int)$r['id'],
            'appointment_date' => $r['appointment_date'],
            'slot_index' => (int)$r['slot_index'],
            'client' => [
                'id' => (int)$r['client_id'],
                'name' => $r['client_name'],
                'phone' => $r['phone'],
                'address' => $r['address'],
            ],
            'car' => [
                'id' => (int)$r['car_id'],
                'license_no' => $r['license_no'],
                'engine_no' => $r['engine_no'],
                'model' => $r['model'],
            ],
            'mechanic_name' => $r['mechanic_name'],
        ];
    }
    echo json_encode(['found' => true, 'appointments' => $appointments]);
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
