<?php
require_once __DIR__ . '/config.php';

function getMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, specialties, years_experience FROM mechanics WHERE is_active = 1 ORDER BY id")->fetchAll();
}

function getMechanicById(int $id): ?array {
    $stmt = getDB()->prepare("SELECT id, name, nickname, bio, specialties, years_experience FROM mechanics WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getMechanicsForSelect(): array {
    $mechs = getMechanics();
    $result = [];
    foreach ($mechs as $m) {
        $result[$m['id']] = $m['name'] . ($m['nickname'] ? " (\"" . $m['nickname'] . "\")" : "");
    }
    return $result;
}

function isSlotAvailable(int $mechanicId, string $date, int $slotIndex): bool {
    $db = getDB();
    $dow = (int)date('w', strtotime($date));

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
    $stmt->execute([$mechanicId, $dow]);
    $schedule = $stmt->fetch();
    if (!$schedule) return false;

    $slotKey = 'slot_' . ($slotIndex + 1);
    if (!$schedule[$slotKey]) return false;

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
    $stmt->execute([$mechanicId, $date]);
    $override = $stmt->fetch();
    if ($override && !$override[$slotKey]) return false;

    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND slot_index = ? AND status != 'cancelled'");
    $stmt->execute([$mechanicId, $date, $slotIndex]);
    if ($stmt->fetchColumn() > 0) return false;

    if (isMechanicOnVacation($mechanicId, $date)) return false;

    return true;
}

function getAvailableSlotsForMechanic(int $mechanicId, string $date): array {
    $available = [];
    for ($i = 0; $i < SLOT_COUNT; $i++) {
        if (isSlotAvailable($mechanicId, $date, $i)) {
            $available[] = $i;
        }
    }
    return $available;
}

function getAllMechanicsAvailability(string $date): array {
    $mechs = getMechanics();
    $result = [];
    foreach ($mechs as $m) {
        $slots = getAvailableSlotsForMechanic($m['id'], $date);
        $result[] = [
            'mechanic' => $m,
            'available_slots' => $slots,
            'available_count' => count($slots),
        ];
    }
    return $result;
}

function isCarBookedOnDate(int $carId, string $date): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM appointments WHERE car_id = ? AND appointment_date = ? AND status != 'cancelled'");
    $stmt->execute([$carId, $date]);
    return $stmt->fetchColumn() > 0;
}

function findOrCreateClient(string $name, string $phone, string $address): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM clients WHERE phone = ?");
    $stmt->execute([$phone]);
    $client = $stmt->fetch();
    if ($client) {
        $stmt = $db->prepare("UPDATE clients SET name = ?, address = ? WHERE id = ?");
        $stmt->execute([$name, $address, $client['id']]);
        return (int)$client['id'];
    }
    $stmt = $db->prepare("INSERT INTO clients (name, phone, address) VALUES (?, ?, ?)");
    $stmt->execute([$name, $phone, $address]);
    return (int)$db->lastInsertId();
}

function findOrCreateCar(int $clientId, string $licenseNo, string $engineNo, string $model): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM cars WHERE license_no = ?");
    $stmt->execute([$licenseNo]);
    $car = $stmt->fetch();
    if ($car) {
        $stmt = $db->prepare("UPDATE cars SET engine_no = ?, model = ?, client_id = ? WHERE id = ?");
        $stmt->execute([$engineNo, $model, $clientId, $car['id']]);
        return (int)$car['id'];
    }
    $stmt = $db->prepare("INSERT INTO cars (client_id, license_no, engine_no, model) VALUES (?, ?, ?, ?)");
    $stmt->execute([$clientId, $licenseNo, $engineNo, $model]);
    return (int)$db->lastInsertId();
}

function createAppointment(int $clientId, int $carId, int $mechanicId, string $date, int $slotIndex): int {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO appointments (client_id, car_id, mechanic_id, appointment_date, slot_index) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$clientId, $carId, $mechanicId, $date, $slotIndex]);
    return (int)$db->lastInsertId();
}

function suggestAlternatives(int $mechanicId, string $date, int $slotIndex): array {
    $suggestions = [];

    $sameDaySlots = getAvailableSlotsForMechanic($mechanicId, $date);
    foreach ($sameDaySlots as $slot) {
        if ($slot !== $slotIndex) {
            $suggestions[] = [
                'mechanic_id' => $mechanicId,
                'slot_index' => $slot,
                'type' => 'same_mechanic',
            ];
        }
    }

    $stmt = getDB()->prepare("SELECT specialties FROM mechanics WHERE id = ?");
    $stmt->execute([$mechanicId]);
    $targetMech = $stmt->fetch();
    $targetKeywords = $targetMech ? array_map('trim', explode(',', $targetMech['specialties'])) : [];

    $mechs = getMechanics();
    foreach ($mechs as $m) {
        if ((int)$m['id'] === $mechanicId) continue;
        if (isSlotAvailable((int)$m['id'], $date, $slotIndex)) {
            $keywords = array_map('trim', explode(',', $m['specialties']));
            $matchCount = count(array_intersect($keywords, $targetKeywords));
            $suggestions[] = [
                'mechanic_id' => (int)$m['id'],
                'slot_index' => $slotIndex,
                'type' => $matchCount > 0 ? 'similar_mechanic' : 'other_mechanic',
                'match_score' => $matchCount,
            ];
        }
    }

    usort($suggestions, function ($a, $b) {
        $aScore = $a['type'] === 'same_mechanic' ? 100 : ($a['match_score'] ?? 0);
        $bScore = $b['type'] === 'same_mechanic' ? 100 : ($b['match_score'] ?? 0);
        return $bScore <=> $aScore;
    });

    return $suggestions;
}

function getEffectiveTime(): DateTime {
    $stmt = getDB()->prepare("SELECT use_simulated_time, simulated_datetime FROM sim_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch();
    if ($config && $config['use_simulated_time'] && $config['simulated_datetime']) {
        return new DateTime($config['simulated_datetime']);
    }
    return new DateTime();
}

function advanceAppointmentStatuses(): void {
    $now = getEffectiveTime();
    $today = $now->format('Y-m-d');
    $currentHour = (int)$now->format('G');
    $currentSlot = intdiv($currentHour, 2) - 4;
    if ($currentSlot < 0) $currentSlot = -1;

    $db = getDB();

    // Revert over-advanced: completed → in_progress if slot hasn't ended
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = 'completed'");
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today) {
            $db->prepare("UPDATE appointments SET status = 'scheduled' WHERE id = ? AND status = 'completed'")->execute([$a['id']]);
        } elseif ($a['appointment_date'] === $today) {
            $slotEnd = ((int)$a['slot_index'] + 4) * 2 + 2;
            if ($currentHour < $slotEnd) {
                $db->prepare("UPDATE appointments SET status = 'in_progress' WHERE id = ? AND status = 'completed'")->execute([$a['id']]);
            }
        }
    }

    // Revert over-advanced: in_progress → scheduled if slot hasn't started
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = 'in_progress'");
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today || ($a['appointment_date'] === $today && (int)$a['slot_index'] > $currentSlot)) {
            $db->prepare("UPDATE appointments SET status = 'scheduled' WHERE id = ? AND status = 'in_progress'")->execute([$a['id']]);
        }
    }

    // Forward: scheduled → in_progress
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index FROM appointments WHERE status = 'scheduled' AND appointment_date <= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] < $today || ($a['appointment_date'] === $today && (int)$a['slot_index'] <= $currentSlot)) {
            $db->prepare("UPDATE appointments SET status = 'in_progress' WHERE id = ? AND status = 'scheduled'")->execute([$a['id']]);
        }
    }

    // Forward: in_progress → completed
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index FROM appointments WHERE status = 'in_progress' AND appointment_date <= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $a) {
        $slotEndHour = ((int)$a['slot_index'] + 4) * 2;
        if ($a['appointment_date'] < $today || ($a['appointment_date'] === $today && $currentHour >= $slotEndHour + 2)) {
            $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ? AND status = 'in_progress'")->execute([$a['id']]);
        }
    }
}

function getAppointments(?string $status = null): array {
    $db = getDB();
    $sql = "SELECT a.id, a.mechanic_id, a.appointment_date, a.slot_index, a.status, a.created_at,
                   c.name AS client_name, c.phone, c.address,
                   car.license_no, car.engine_no, car.model,
                   m.name AS mechanic_name, m.nickname AS mechanic_nickname
            FROM appointments a
            JOIN clients c ON c.id = a.client_id
            JOIN cars car ON car.id = a.car_id
            JOIN mechanics m ON m.id = a.mechanic_id";
    $params = [];
    if ($status) {
        $sql .= " WHERE a.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY a.appointment_date DESC, a.slot_index ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function updateAppointmentDate(int $appointmentId, string $newDate, int $newSlot): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT car_id, mechanic_id FROM appointments WHERE id = ? AND status = 'scheduled'");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not found or no longer scheduled.'];

    if (!isSlotAvailable((int)$appt['mechanic_id'], $newDate, $newSlot)) {
        $newMechId = (int)$appt['mechanic_id'];
        $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
        $stmt->execute([$newMechId]);
        $m = $stmt->fetch();
        $mechName = $m ? $m['name'] : "Mechanic #{$newMechId}";

        $dow = (int)date('w', strtotime($newDate));
        $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
        $stmt->execute([$newMechId, $dow]);
        $schedule = $stmt->fetch();

        if (!$schedule) return ['success' => false, 'message' => "{$mechName} does not work on {$dayNames[$dow]}."];

        $slotKey = 'slot_' . ($newSlot + 1);
        if (!$schedule[$slotKey]) return ['success' => false, 'message' => "{$mechName} is not scheduled for that time slot."];

        $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
        $stmt->execute([$newMechId, $newDate]);
        $override = $stmt->fetch();
        if ($override && !$override[$slotKey]) return ['success' => false, 'message' => "{$mechName} has a schedule override blocking that slot."];

        return ['success' => false, 'message' => "{$mechName} already has an appointment at that date and time."];
    }

    $stmt = $db->prepare("UPDATE appointments SET appointment_date = ?, slot_index = ? WHERE id = ?");
    $stmt->execute([$newDate, $newSlot, $appointmentId]);
    return ['success' => true, 'message' => 'Appointment date updated.'];
}

function updateAppointmentMechanic(int $appointmentId, int $newMechanicId): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([$newMechanicId]);
    $mech = $stmt->fetch();
    $name = $mech ? $mech['name'] : "Mechanic #{$newMechanicId}";

    $stmt = $db->prepare("SELECT appointment_date, slot_index, mechanic_id FROM appointments WHERE id = ? AND status = 'scheduled'");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not found or no longer scheduled.'];

    if ((int)$appt['mechanic_id'] === $newMechanicId) return ['success' => false, 'message' => 'That is already their mechanic.'];

    $dow = (int)date('w', strtotime($appt['appointment_date']));
    $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
    $stmt->execute([$newMechanicId, $dow]);
    $schedule = $stmt->fetch();
    if (!$schedule) return ['success' => false, 'message' => "{$name} does not work on {$dayNames[$dow]}."];

    $slotKey = 'slot_' . ((int)$appt['slot_index'] + 1);
    if (!$schedule[$slotKey]) return ['success' => false, 'message' => "{$name} is not scheduled for that time slot."];

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
    $stmt->execute([$newMechanicId, $appt['appointment_date']]);
    $override = $stmt->fetch();
    if ($override && !$override[$slotKey]) return ['success' => false, 'message' => "{$name} has a schedule override blocking that slot on this date."];

    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND slot_index = ? AND status != 'cancelled' AND id != ?");
    $stmt->execute([$newMechanicId, $appt['appointment_date'], $appt['slot_index'], $appointmentId]);
    if ($stmt->fetchColumn() > 0) return ['success' => false, 'message' => "{$name} already has an appointment at that date and time."];

    $stmt = $db->prepare("UPDATE appointments SET mechanic_id = ? WHERE id = ?");
    $stmt->execute([$newMechanicId, $appointmentId]);
    return ['success' => true, 'message' => 'Appointment mechanic updated.'];
}

function cancelAppointment(int $appointmentId): bool {
    $stmt = getDB()->prepare("UPDATE appointments SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND status = 'scheduled'");
    $stmt->execute([$appointmentId]);
    return $stmt->rowCount() > 0;
}

function validateAppointmentInput(array $data): array {
    $errors = [];

    if (empty(trim($data['name'] ?? ''))) $errors[] = 'Name is required.';
    if (empty(trim($data['phone'] ?? ''))) $errors[] = 'Phone number is required.';
    elseif (!preg_match('/^[\d\s\-\+\(\)]+$/', $data['phone'])) $errors[] = 'Phone must contain only digits.';

    if (empty(trim($data['license_no'] ?? ''))) $errors[] = 'Car license number is required.';
    if (empty(trim($data['engine_no'] ?? ''))) $errors[] = 'Car engine number is required.';
    elseif (!preg_match('/^[a-zA-Z0-9]+$/', $data['engine_no'])) $errors[] = 'Engine number must be alphanumeric.';

    if (empty($data['date'] ?? '')) $errors[] = 'Appointment date is required.';
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) $errors[] = 'Invalid date format.';
    elseif ($data['date'] < date('Y-m-d')) $errors[] = 'Appointment date cannot be in the past.';

    if (!isset($data['mechanic_id']) || !$data['mechanic_id']) $errors[] = 'Please select a mechanic.';
    if (!isset($data['slot_index']) || $data['slot_index'] === '') $errors[] = 'Please select a time slot.';

    if (empty(trim($data['address'] ?? ''))) $errors[] = 'Address is required.';

    return $errors;
}

function getAllMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, specialties, years_experience, is_active FROM mechanics ORDER BY is_active DESC, name ASC")->fetchAll();
}

function addMechanic(string $name, ?string $nickname, ?string $specialties, int $years): int {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO mechanics (name, nickname, specialties, years_experience, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$name, $nickname, $specialties, $years]);
    $id = (int)$db->lastInsertId();

    $insert = $db->prepare("INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4) VALUES (?, ?, 1, 1, 1, 1)");
    for ($dow = 0; $dow <= 6; $dow++) {
        $insert->execute([$id, $dow]);
    }
    return $id;
}

function updateMechanic(int $id, string $name, ?string $nickname, ?string $specialties, int $years): void {
    $stmt = getDB()->prepare("UPDATE mechanics SET name = ?, nickname = ?, specialties = ?, years_experience = ? WHERE id = ?");
    $stmt->execute([$name, $nickname, $specialties, $years, $id]);
}

function fireMechanic(int $id): void {
    $stmt = getDB()->prepare("UPDATE mechanics SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);
}

function restoreMechanic(int $id): void {
    $stmt = getDB()->prepare("UPDATE mechanics SET is_active = 1 WHERE id = ?");
    $stmt->execute([$id]);
}

function getMechanicSchedule(int $id): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT day_of_week, slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ?");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
    $schedule = [];
    foreach ($rows as $r) {
        $schedule[(int)$r['day_of_week']] = [
            (bool)$r['slot_1'], (bool)$r['slot_2'], (bool)$r['slot_3'], (bool)$r['slot_4'],
        ];
    }
    return $schedule;
}

function updateMechanicSchedule(int $id, array $schedule): void {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM mechanic_schedule WHERE mechanic_id = ?");
    $stmt->execute([$id]);

    $insert = $db->prepare("INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($schedule as $dow => $slots) {
        $insert->execute([$id, $dow, $slots[0] ? 1 : 0, $slots[1] ? 1 : 0, $slots[2] ? 1 : 0, $slots[3] ? 1 : 0]);
    }
}

function getMechanicVacations(int $mechanicId): array {
    $stmt = getDB()->prepare("SELECT id, start_date, end_date, reason FROM mechanic_vacations WHERE mechanic_id = ? ORDER BY start_date ASC");
    $stmt->execute([$mechanicId]);
    return $stmt->fetchAll();
}

function addMechanicVacation(int $mechanicId, string $startDate, string $endDate, ?string $reason): void {
    $stmt = getDB()->prepare("INSERT INTO mechanic_vacations (mechanic_id, start_date, end_date, reason) VALUES (?, ?, ?, ?)");
    $stmt->execute([$mechanicId, $startDate, $endDate, $reason]);
}

function removeMechanicVacation(int $id): void {
    $stmt = getDB()->prepare("DELETE FROM mechanic_vacations WHERE id = ?");
    $stmt->execute([$id]);
}

function isMechanicOnVacation(int $mechanicId, string $date): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM mechanic_vacations WHERE mechanic_id = ? AND start_date <= ? AND end_date >= ?");
    $stmt->execute([$mechanicId, $date, $date]);
    return $stmt->fetchColumn() > 0;
}
