<?php
require_once __DIR__ . '/functions.php';

$db = getDB();

$existing = $db->query("SELECT COUNT(*) FROM appointments WHERE client_id IN (SELECT id FROM clients WHERE name LIKE 'Test%')")->fetchColumn();
if ($existing > 0) {
    echo "Test appointments already exist. Delete test clients/cars/appointments to rerun.\n";
    exit;
}

// Create test clients and cars
$testData = [
    ['name' => 'Test Bruce',  'phone' => '000-111-0001', 'license' => 'T-BAT-01', 'engine' => 'E-BAT-01', 'model' => 'Batmobile'],
    ['name' => 'Test Selina', 'phone' => '000-111-0002', 'license' => 'T-CAT-01', 'engine' => 'E-CAT-01', 'model' => 'Coupe'],
    ['name' => 'Test Barry',  'phone' => '000-111-0003', 'license' => 'T-FLS-01', 'engine' => 'E-FLS-01', 'model' => 'Sedan'],
    ['name' => 'Test Arthur', 'phone' => '000-111-0004', 'license' => 'T-AQU-01', 'engine' => 'E-AQU-01', 'model' => 'SUV'],
    ['name' => 'Test Hal',    'phone' => '000-111-0005', 'license' => 'T-LAN-01', 'engine' => 'E-LAN-01', 'model' => 'Sports Car'],
];

$clientCarIds = [];
foreach ($testData as $t) {
    $stmt = $db->prepare("INSERT INTO clients (name, phone, address) VALUES (?, ?, 'Test Address')");
    $stmt->execute([$t['name'], $t['phone']]);
    $cid = $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO cars (client_id, license_no, engine_no, model) VALUES (?, ?, ?, ?)");
    $stmt->execute([$cid, $t['license'], $t['engine'], $t['model']]);
    $clientCarIds[] = ['client' => $cid, 'car' => $db->lastInsertId()];
}

// Try multiple future dates to find one where all 5 mechs have at least 2 open slots
$mechs = $db->query("SELECT id, name FROM mechanics WHERE is_active = 1")->fetchAll();
$slotLabels = ['08:00—10:00', '10:00—12:00', '12:00—14:00', '14:00—16:00'];

for ($daysFromNow = 3; $daysFromNow <= 14; $daysFromNow++) {
    $date = date('Y-m-d', strtotime("+$daysFromNow days"));
    $dow = (int)date('w', strtotime($date));
    $booked = 0;
    $usedPairs = [];

    foreach ($mechs as $mech) {
        $schedule = getMechanicSchedule((int)$mech['id']);
        $daySlots = $schedule[$dow] ?? [false, false, false, false];

        // Check overrides for this date
        $overrideStmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
        $overrideStmt->execute([$mech['id'], $date]);
        $override = $overrideStmt->fetch();
        if ($override) {
            for ($s = 0; $s < 4; $s++) {
                if (!$override["slot_" . ($s + 1)]) {
                    $daySlots[$s] = false;
                }
            }
        }

        $openSlots = [];
        foreach ($daySlots as $s => $open) {
            if ($open) $openSlots[] = $s;
        }

        if (count($openSlots) < 2) continue;

        // Book 2 non-conflicting slots for this mechanic
        $slotsToBook = array_slice($openSlots, 0, 2);
        foreach ($slotsToBook as $slotIdx) {
            $cc = $clientCarIds[$booked % count($clientCarIds)];
            $stmt = $db->prepare("INSERT INTO appointments (client_id, car_id, mechanic_id, appointment_date, slot_index, status) VALUES (?, ?, ?, ?, ?, 'scheduled')");
            $stmt->execute([$cc['client'], $cc['car'], $mech['id'], $date, $slotIdx]);
            echo "  {$mech['name']} slot {$slotLabels[$slotIdx]} on {$date} → {$testData[$booked % count($testData)]['name']}\n";
            $booked++;
            $usedPairs[] = [$mech['id'], $slotIdx];
        }
    }

    if ($booked > 0) {
        echo "\nBooked {$booked} appointments on {$date} (day {$dow}). Try overriding in admin!\n";
        exit;
    }
}

echo "Could not find a suitable date within 14 days.\n";