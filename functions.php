<?php
require_once __DIR__ . '/config.php';

function handleVerifyPw(): void {
    if (!isset($_POST['verify_pw'])) return;
    header('Content-Type: application/json');
    $ok = ($_POST['admin_pw'] ?? '') === ADMIN_PW;
    if ($ok) $_SESSION['admin_verified'] = time();
    echo json_encode(['success' => $ok]);
    exit;
}

/* === FORMATTING HELPERS === */

function fmtDate(string $date): string {
    $ts = strtotime($date);
    return $ts ? date('j M Y', $ts) : $date;
}

function fmtNameTwoLines(string $name): string {
    $parts = explode(' ', $name, 2);
    if (count($parts) === 2) {
        return htmlspecialchars($parts[0]) . '<br>' . htmlspecialchars($parts[1]);
    }
    return htmlspecialchars($name);
}

function slotStartHour(int $slotIndex): int {
    return ($slotIndex + 5) * 2;
}
function slotEndHour(int $slotIndex): int {
    return slotStartHour($slotIndex) + 2;
}
function slotIndexFromHour(int $hour): int {
    return intdiv($hour, 2) - 5;
}

function normalizePhone(string $phone): string {
    return preg_replace('/[^\d]/', '', $phone);
}

/* === MECHANIC QUERIES === */

function getMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, quote, doodle, specialties, years_experience AS experience FROM mechanics WHERE is_active = 1 ORDER BY id")->fetchAll();
}

function getMechanicById(int $id): ?array {
    $stmt = getDB()->prepare("SELECT id, name, nickname, bio, quote, doodle, specialties, years_experience AS experience FROM mechanics WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getMechanicsForSelect(): array {
    $mechs = getMechanics();
    $result = [];
    foreach ($mechs as $m) {
        $result[$m['id']] = $m['name'];
    }
    return $result;
}

/* === SLOT AVAILABILITY === */

function isSlotAvailable(int $mechanicId, string $date, int $slotIndex): bool {
    if ($slotIndex < 0 || $slotIndex >= SLOT_COUNT) return false;
    $db = getDB();

    $stmt = $db->prepare("SELECT 1 FROM mechanics WHERE id = ? AND is_active = 1");
    $stmt->execute([$mechanicId]);
    if (!$stmt->fetchColumn()) return false;

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

    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND slot_index = ? AND status != '" . STATUS_CANCELLED . "'");
    $stmt->execute([$mechanicId, $date, $slotIndex]);
    if ($stmt->fetchColumn() > 0) return false;

    if (isMechanicOnVacation($mechanicId, $date)) return false;

    return true;
}

function getMechanicSlotsAvailability(int $mechanicId, string $date): array {
    $db = getDB();
    $dow = (int)date('w', strtotime($date));

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
    $stmt->execute([$mechanicId, $dow]);
    $schedule = $stmt->fetch();
    if (!$schedule) return array_fill(0, SLOT_COUNT, false);

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
    $stmt->execute([$mechanicId, $date]);
    $override = $stmt->fetch();

    $stmt = $db->prepare("SELECT slot_index FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND status != ?");
    $stmt->execute([$mechanicId, $date, STATUS_CANCELLED]);
    $booked = [];
    foreach ($stmt->fetchAll() as $r) $booked[] = (int)$r['slot_index'];

    $onVacation = isMechanicOnVacation($mechanicId, $date);

    $result = [];
    for ($i = 0; $i < SLOT_COUNT; $i++) {
        $slotKey = 'slot_' . ($i + 1);
        $result[$i] = $schedule[$slotKey]
            && (!$override || $override[$slotKey])
            && !in_array($i, $booked)
            && !$onVacation;
    }
    return $result;
}

function getAdjacentSlotForMechanic(int $mechanicId, string $date, int $slotIndex): ?int {
    for ($offset = 1; $offset < SLOT_COUNT; $offset++) {
        if (isSlotAvailable($mechanicId, $date, $slotIndex + $offset)) return $slotIndex + $offset;
        if (isSlotAvailable($mechanicId, $date, $slotIndex - $offset)) return $slotIndex - $offset;
    }
    return null;
}

function getNearbyDatesForMechanic(int $mechanicId, int $slotIndex, string $date): array {
    $db = getDB();
    $result = ['prev' => null, 'next' => null];

    $stmt = $db->prepare("SELECT DISTINCT appointment_date FROM appointments WHERE mechanic_id = ? AND slot_index = ? AND status != '" . STATUS_CANCELLED . "' ORDER BY appointment_date");
    $stmt->execute([$mechanicId, $slotIndex]);
    $bookedDates = [];
    foreach ($stmt->fetchAll() as $row) {
        $bookedDates[] = $row['appointment_date'];
    }
    $bookedDates = array_flip($bookedDates);

    $oneMonthAfter = date('Y-m-d', strtotime($date . ' +30 days'));
    $preferredAdjacent = $slotIndex < SLOT_COUNT - 1 ? $slotIndex + 1 : $slotIndex - 1;

    $today = date('Y-m-d');
    for ($i = 1; $i <= 30; $i++) {
        $prev = date('Y-m-d', strtotime($date . " -$i days"));
        if ($prev <= $today) continue;
        $found = [];
        if (!isset($bookedDates[$prev]) && isSlotAvailable($mechanicId, $prev, $slotIndex)) {
            $found[] = $slotIndex;
        }
        if (isSlotAvailable($mechanicId, $prev, $preferredAdjacent)) {
            $found[] = $preferredAdjacent;
        }
        $found = array_values(array_unique($found));
        if (!empty($found)) {
            $result['prev'] = ['date' => $prev, 'slots' => $found];
            break;
        }
    }

    for ($i = 1; $i <= 30; $i++) {
        $next = date('Y-m-d', strtotime($date . " +$i days"));
        if ($next > $oneMonthAfter) break;
        $found = [];
        if (!isset($bookedDates[$next]) && isSlotAvailable($mechanicId, $next, $slotIndex)) {
            $found[] = $slotIndex;
        }
        if (isSlotAvailable($mechanicId, $next, $preferredAdjacent)) {
            $found[] = $preferredAdjacent;
        }
        $found = array_values(array_unique($found));
        if (!empty($found)) {
            $result['next'] = ['date' => $next, 'slots' => $found];
            break;
        }
    }

    return $result;
}

/* === BOOKING === */

function isCarBookedOnDate(int $carId, string $date): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM appointments WHERE car_id = ? AND appointment_date = ? AND status != '" . STATUS_CANCELLED . "'");
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

/* === QUICK BOOK HELPERS === */

function getLastAppointmentByPhone(string $phone): ?array {
    $db = getDB();
    $digits = normalizePhone($phone);
    $stmt = $db->prepare("
        SELECT a.id, a.client_id, a.car_id, a.mechanic_id, a.appointment_date, a.slot_index, a.status,
               c.name AS client_name, c.phone, c.address,
               car.license_no, car.engine_no, car.model
        FROM appointments a
        JOIN clients c ON c.id = a.client_id
        JOIN cars car ON car.id = a.car_id
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') = ?
        ORDER BY a.appointment_date DESC, a.id DESC
        LIMIT 1
    ");
    $stmt->execute([$digits]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function findNextAvailableSlot(?int $mechanicId = null, string $fromDate = ''): ?array {
    if (!$fromDate) {
        $fromDate = date('Y-m-d', strtotime('+1 day'));
    }

    $mechanicIds = [];
    if ($mechanicId !== null) {
        $mechanicIds[] = $mechanicId;
    } else {
        $mechs = getMechanics();
        $mechanicIds = array_column($mechs, 'id');
    }

    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d', strtotime($fromDate . " +{$i} days"));
        foreach ($mechanicIds as $mid) {
            for ($s = 0; $s < SLOT_COUNT; $s++) {
                if (isSlotAvailable($mid, $date, $s)) {
                    return ['date' => $date, 'slot' => $s, 'mechanic_id' => $mid];
                }
            }
        }
    }
    return null;
}

/* === TIME MANAGEMENT === */

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
    $currentSlot = slotIndexFromHour($currentHour);
    if ($currentSlot < 0) $currentSlot = -1;

    $db = getDB();
    $config = $db->query("SELECT use_simulated_time FROM sim_config WHERE id = 1")->fetch();
    $isSim = $config && $config['use_simulated_time'];

    // Revert completed: future → scheduled, today before slot end → in_progress
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_COMPLETED . "'");
    $toScheduled = [];
    $toInProgress = [];
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today) {
            $toScheduled[] = $a['id'];
        } elseif ($a['appointment_date'] === $today) {
            $slotEnd = slotEndHour((int)$a['slot_index']);
            if ($currentHour < $slotEnd) {
                $toInProgress[] = $a['id'];
            }
        }
    }
    if ($toScheduled) {
        $ph = implode(',', array_fill(0, count($toScheduled), '?'));
        $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "' WHERE id IN ($ph)")->execute($toScheduled);
    }
    if ($toInProgress) {
        $ph = implode(',', array_fill(0, count($toInProgress), '?'));
        $db->prepare("UPDATE appointments SET status = '" . STATUS_IN_PROGRESS . "' WHERE id IN ($ph)")->execute($toInProgress);
    }

    // Revert in_progress → scheduled if slot hasn't started
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_IN_PROGRESS . "'");
    $toRevert = [];
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today || ($a['appointment_date'] === $today && (int)$a['slot_index'] > $currentSlot)) {
            $toRevert[] = $a['id'];
        }
    }
    if ($toRevert) {
        $ph = implode(',', array_fill(0, count($toRevert), '?'));
        $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "' WHERE id IN ($ph)")->execute($toRevert);
    }

    // Forward: scheduled → in_progress — capture IDs before update
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index, mechanic_id FROM appointments WHERE status = '" . STATUS_SCHEDULED . "' AND (appointment_date < ? OR (appointment_date = ? AND slot_index <= ?))");
    $stmt->execute([$today, $today, $currentSlot]);
    $toInProgressRows = $stmt->fetchAll();
    $stmt = $db->prepare("UPDATE appointments SET status = '" . STATUS_IN_PROGRESS . "' WHERE status = '" . STATUS_SCHEDULED . "' AND (appointment_date < ? OR (appointment_date = ? AND slot_index <= ?))");
    $stmt->execute([$today, $today, $currentSlot]);
    if ($isSim && $toInProgressRows) {
        foreach ($toInProgressRows as $a) {
            $backup = json_encode(['date' => $a['appointment_date'], 'slot' => (int)$a['slot_index'], 'mech' => (int)$a['mechanic_id'], 'cancelled' => 'false']);
            $db->prepare("UPDATE appointments SET backup_data = ? WHERE id = ? AND backup_data IS NULL")->execute([$backup, $a['id']]);
        }
    }

    // Forward: in_progress → completed — capture IDs before update
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index, mechanic_id FROM appointments WHERE status = '" . STATUS_IN_PROGRESS . "' AND appointment_date <= ?");
    $stmt->execute([$today]);
    $allInProgress = $stmt->fetchAll();
    $toComplete = [];
    foreach ($allInProgress as $a) {
        $slotStart = slotStartHour((int)$a['slot_index']);
        if ($a['appointment_date'] < $today || ($a['appointment_date'] === $today && $currentHour >= $slotStart + 2)) {
            $toComplete[] = $a;
        }
    }
    if ($toComplete) {
        $ids = array_map(fn($x) => $x['id'], $toComplete);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE appointments SET status = '" . STATUS_COMPLETED . "' WHERE id IN ($ph)")->execute($ids);
    }
}

/* === APPOINTMENT QUERIES === */

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
    $sql .= " ORDER BY a.appointment_date ASC, a.slot_index ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAppointmentById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.id, a.mechanic_id, a.appointment_date, a.slot_index, a.status, a.created_at,
               c.name AS client_name, c.phone,
               car.license_no, car.engine_no, car.model,
               m.name AS mechanic_name, m.nickname AS mechanic_nickname
        FROM appointments a
        JOIN clients c ON c.id = a.client_id
        JOIN cars car ON car.id = a.car_id
        JOIN mechanics m ON m.id = a.mechanic_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function validateSlotAssignment(int $mechanicId, string $date, int $slotIndex, ?int $excludeAppointmentId = null): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([$mechanicId]);
    $m = $stmt->fetch();
    $name = $m ? $m['name'] : "Mechanic #{$mechanicId}";

    $dow = (int)date('w', strtotime($date));
    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
    $stmt->execute([$mechanicId, $dow]);
    $schedule = $stmt->fetch();
    if (!$schedule) return ['success' => false, 'message' => "{$name} does not work on {$GLOBALS['DAY_NAMES_FULL'][$dow]}."];

    $slotKey = 'slot_' . ($slotIndex + 1);
    if (!$schedule[$slotKey]) return ['success' => false, 'message' => "{$name} is not scheduled for that time slot."];

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_overrides WHERE mechanic_id = ? AND override_date = ?");
    $stmt->execute([$mechanicId, $date]);
    $override = $stmt->fetch();
    if ($override && !$override[$slotKey]) return ['success' => false, 'message' => "{$name} has a schedule override blocking that slot."];

    $sql = "SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND slot_index = ? AND status != '" . STATUS_CANCELLED . "'";
    $params = [$mechanicId, $date, $slotIndex];
    if ($excludeAppointmentId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeAppointmentId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn() > 0) return ['success' => false, 'message' => "{$name} already has an appointment at that date and time."];

    return ['success' => true];
}

/* === APPOINTMENT MUTATIONS === */

function cancelAppointment(int $appointmentId): bool {
    $stmt = getDB()->prepare("UPDATE appointments SET status = '" . STATUS_CANCELLED . "', cancelled_at = NOW() WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$appointmentId]);
    return $stmt->rowCount() > 0;
}

/* === INPUT VALIDATION === */

function validateAppointmentInput(array $data): array {
    $errors = [];

    if (empty(trim($data['name'] ?? ''))) $errors[] = 'Name is required.';
    if (empty(trim($data['phone'] ?? ''))) $errors[] = 'Phone number is required.';
    elseif (!preg_match('/^[\d\s\-\+\(\)]+$/', $data['phone'])) $errors[] = 'Phone must contain only digits, spaces, +, -, and parentheses.';

    if (empty(trim($data['license_no'] ?? ''))) $errors[] = 'Car license number is required.';
    if (empty(trim($data['engine_no'] ?? ''))) $errors[] = 'Car engine number is required.';
    elseif (!preg_match('/^[a-zA-Z0-9\-]+$/', $data['engine_no'])) $errors[] = 'Engine number must be alphanumeric.';

    if (empty($data['date'] ?? '')) $errors[] = 'Appointment date is required.';
    elseif (!preg_match(DATE_REGEX, $data['date'])) $errors[] = 'Invalid date format.';
    elseif ($data['date'] < date('Y-m-d')) $errors[] = 'Appointment date cannot be in the past.';

    if (!isset($data['mechanic_id']) || !$data['mechanic_id']) $errors[] = 'Please select a mechanic first.';
    else {
        if (!getMechanicById((int)$data['mechanic_id'])) $errors[] = 'Selected mechanic does not exist.';
        if (!isset($data['slot_index']) || $data['slot_index'] === '') $errors[] = 'Please select a suitable slot first.';
        elseif ((int)$data['slot_index'] < 0 || (int)$data['slot_index'] >= SLOT_COUNT) $errors[] = 'Invalid time slot.';
    }

    if (empty(trim($data['address'] ?? ''))) $errors[] = 'Address is required.';

    return $errors;
}

/* === MECHANIC MANAGEMENT === */

function getAllMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, quote, theme, doodle, specialties, years_experience AS experience, is_active FROM mechanics ORDER BY is_active DESC, name ASC")->fetchAll();
}

function addMechanic(string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote = null, string $theme = 'default'): int {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO mechanics (name, nickname, quote, theme, specialties, years_experience, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->execute([$name, $nickname, $quote, $theme, $specialties, $years]);
    $id = (int)$db->lastInsertId();

    $insert = $db->prepare("INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4) VALUES (?, ?, 0, 0, 0, 0)");
    for ($dow = 0; $dow <= 6; $dow++) {
        $insert->execute([$id, $dow]);
    }
    return $id;
}

function updateMechanic(int $id, string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote = null, string $theme = 'default'): void {
    $stmt = getDB()->prepare("UPDATE mechanics SET name = ?, nickname = ?, quote = ?, theme = ?, specialties = ?, years_experience = ? WHERE id = ?");
    $stmt->execute([$name, $nickname, $quote, $theme, $specialties, $years, $id]);
}

function fireMechanic(int $id): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM appointments WHERE mechanic_id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $a) {
        saveBackupIfSim((int)$a['id']);
    }
    $db->prepare("UPDATE appointments SET status = '" . STATUS_CANCELLED . "', cancelled_at = NOW() WHERE mechanic_id = ? AND status = '" . STATUS_SCHEDULED . "'")->execute([$id]);
    $stmt = $db->prepare("UPDATE mechanics SET is_active = 0, fired = ? WHERE id = ?");
    $stmt->execute([isSimMode() ? 1 : 0, $id]);
}

function restoreMechanic(int $id): void {
    $stmt = getDB()->prepare("UPDATE mechanics SET is_active = 1 WHERE id = ?");
    $stmt->execute([$id]);
}

function removeMechanic(int $id): void {
    $db = getDB();
    $db->prepare("DELETE FROM mechanic_schedule WHERE mechanic_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM mechanic_vacations WHERE mechanic_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM mechanic_overrides WHERE mechanic_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM mechanics WHERE id = ?")->execute([$id]);
}

/* === SCHEDULE MANAGEMENT === */

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

/* === VACATION MANAGEMENT === */

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

/* === FLASH MESSAGING === */

function flashAndRedirect(string $msg, string $type = 'success', string $url = 'admin.php'): never {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url);
    exit;
}

function ajaxFlash(string $msg, string $type = 'success', string $url = 'admin.php'): never {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        echo json_encode(['ok' => $type === 'success', 'msg' => $msg]);
        exit;
    }
    flashAndRedirect($msg, $type, $url);
}

/* === SIM GUARDS & BACKUP === */

function isSimMode(): bool {
    $config = getDB()->query("SELECT use_simulated_time FROM sim_config WHERE id = 1")->fetch();
    return $config && $config['use_simulated_time'];
}

function guardAgainstSim(): void {
    if (isSimMode()) {
        ajaxFlash('Sim mode is active — you\'re playing with fake time, not making real decisions. Exit sim mode first.', 'error');
    }
}

function saveBackupIfSim(int $appointmentId): void {
    if (!isSimMode()) return;
    $db = getDB();
    $stmt = $db->prepare("SELECT appointment_date, slot_index, mechanic_id, status FROM appointments WHERE id = ? AND backup_data IS NULL");
    $stmt->execute([$appointmentId]);
    $orig = $stmt->fetch();
    if (!$orig) return;
    $backup = json_encode(['date' => $orig['appointment_date'], 'slot' => (int)$orig['slot_index'], 'mech' => (int)$orig['mechanic_id'], 'cancelled' => $orig['status'] === STATUS_CANCELLED ? 'true' : 'false']);
    $db->prepare("UPDATE appointments SET backup_data = ? WHERE id = ?")->execute([$backup, $appointmentId]);
}

/* === ACTION HANDLERS === */

function handleRemoveAllCancelled(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    guardAgainstSim();
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE status = '" . STATUS_CANCELLED . "'");
    $stmt->execute();
    ajaxFlash($stmt->rowCount() . ' cancelled appointment(s) removed.');
}

function handleRemoveAllCompleted(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    guardAgainstSim();
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE status = '" . STATUS_COMPLETED . "'");
    $stmt->execute();
    ajaxFlash($stmt->rowCount() . ' completed appointment(s) removed.');
}

function handleRemove(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    guardAgainstSim();
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE id = ? AND status = '" . STATUS_CANCELLED . "'");
    $stmt->execute([(int)$_GET['remove']]);
    $ok = $stmt->rowCount() > 0;
    ajaxFlash($ok ? 'Appointment removed.' : 'Appointment not found or not cancellable.', $ok ? 'success' : 'error');
}

function handleCancel(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    $id = (int)$_GET['cancel'];
    saveBackupIfSim($id);
    if (cancelAppointment($id)) {
        ajaxFlash('Appointment cancelled.');
    } else {
        ajaxFlash('Could not cancel — appointment may already be in progress or completed.', 'error');
    }
}

function handleReBook(): never {
    $id = (int)$_GET['rebook'];
    $db = getDB();
    $stmt = $db->prepare("SELECT a.appointment_date, a.slot_index, a.mechanic_id, m.is_active FROM appointments a JOIN mechanics m ON m.id = a.mechanic_id WHERE a.id = ? AND a.status = '" . STATUS_CANCELLED . "'");
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) {
        ajaxFlash('Appointment not found or not cancelled.', 'error');
    }
    $effectiveTime = getEffectiveTime();
    $todayStr = $effectiveTime->format('Y-m-d');
    if ($appt['appointment_date'] < $todayStr) {
        ajaxFlash('Can\'t rebook a ghost — that date\'s already in the rearview.', 'error');
    }
    if ($appt['appointment_date'] === $todayStr) {
        $currentHour = (int)$effectiveTime->format('G');
        if ($currentHour >= slotStartHour((int)$appt['slot_index'])) {
            ajaxFlash('Too slow — that time slot already drove off without you.', 'error');
        }
    }
    if (!$appt['is_active']) {
        $mstmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
        $mstmt->execute([(int)$appt['mechanic_id']]);
        $mname = $mstmt->fetchColumn() ?: 'Unknown';
        $firstName = explode(' ', $mname)[0];
        $_SESSION['pending_rebook'] = ['id' => $id, 'old_first_name' => $firstName, 'reason' => 'fired', 'date' => $appt['appointment_date'], 'slot' => (int)$appt['slot_index']];
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            echo json_encode(['ok' => false, 'redirect' => 'admin.php?rebook_pick_mechanic=1']);
            exit;
        }
        header('Location: admin.php?rebook_pick_mechanic=1');
        exit;
    }
    $validation = validateSlotAssignment((int)$appt['mechanic_id'], $appt['appointment_date'], (int)$appt['slot_index'], $id);
    if (!$validation['success']) {
        $mstmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
        $mstmt->execute([(int)$appt['mechanic_id']]);
        $mname = $mstmt->fetchColumn() ?: 'Unknown';
        $firstName = explode(' ', $mname)[0];
        $_SESSION['pending_rebook'] = ['id' => $id, 'old_first_name' => $firstName, 'reason' => 'busy', 'date' => $appt['appointment_date'], 'slot' => (int)$appt['slot_index']];
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            echo json_encode(['ok' => false, 'redirect' => 'admin.php?rebook_pick_mechanic=1']);
            exit;
        }
        header('Location: admin.php?rebook_pick_mechanic=1');
        exit;
    }
    saveBackupIfSim($id);
    $stmt = $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "', cancelled_at = NULL WHERE id = ?");
    $stmt->execute([$id]);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        echo json_encode(['ok' => true, 'msg' => 'Appointment rebooked successfully.']);
        exit;
    }
    flashAndRedirect('Appointment rebooked successfully.');
}

function handleReBookConfirm(): never {
    $id = (int)$_GET['rebook_confirm'];
    $newMech = (int)$_GET['new_mech'];
    $db = getDB();
    $stmt = $db->prepare("SELECT appointment_date, slot_index FROM appointments WHERE id = ? AND status = '" . STATUS_CANCELLED . "'");
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) flashAndRedirect('Appointment not found.', 'error');
    $stmt = $db->prepare("SELECT 1 FROM mechanics WHERE id = ? AND is_active = 1");
    $stmt->execute([$newMech]);
    if (!$stmt->fetch()) flashAndRedirect('Invalid mechanic.', 'error');
    $validation = validateSlotAssignment($newMech, $appt['appointment_date'], (int)$appt['slot_index'], $id);
    if (!$validation['success']) flashAndRedirect($validation['message'], 'error');
    saveBackupIfSim($id);
    $stmt = $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "', mechanic_id = ?, cancelled_at = NULL WHERE id = ?");
    $stmt->execute([$newMech, $id]);
    flashAndRedirect('Appointment rebooked with new mechanic.');
}

function handleFire(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) flashAndRedirect('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['fire']]);
    $m = $stmt->fetch();
    fireMechanic((int)$_GET['fire']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been fired!');
}

function handleRestore(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    $db = getDB();
    $stmt = $db->prepare("SELECT name, nickname, quote, specialties, years_experience AS experience FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['restore']]);
    $m = $stmt->fetch();
    restoreMechanic((int)$_GET['restore']);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        echo json_encode(['ok' => true, 'msg' => ($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has rejoined!', 'mechanic' => $m ? ['name' => $m['name'], 'nickname' => $m['nickname'] ?? '', 'quote' => $m['quote'] ?? '', 'specialties' => $m['specialties'] ?? '', 'experience' => (int)$m['experience'], 'bookings' => 0] : null]);
        exit;
    }
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has rejoined!');
}

function handleRemoveMechanic(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    guardAgainstSim();
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['remove_mechanic']]);
    $m = $stmt->fetch();
    removeMechanic((int)$_GET['remove_mechanic']);
    ajaxFlash(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been removed permanently.');
}

function handleUnblock(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM mechanic_overrides WHERE id = ?");
    $stmt->execute([(int)$_GET['unblock']]);
    $ok = $stmt->rowCount() > 0;
    ajaxFlash($ok ? 'Override removed.' : 'Override not found.', $ok ? 'success' : 'error');
}

function handleRemoveVacation(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) ajaxFlash('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    guardAgainstSim();
    $vacId = (int)$_GET['remove_vacation'];
    $stmt = getDB()->prepare("SELECT mechanic_id, start_date, end_date FROM mechanic_vacations WHERE id = ?");
    $stmt->execute([$vacId]);
    $vac = $stmt->fetch();
    removeMechanicVacation($vacId);
    $name = trim($_GET['mech_name'] ?? '');
    $firstName = $name ? explode(' ', $name)[0] : $name;
    if ($firstName && $vac) {
        $today = getEffectiveTime()->format('Y-m-d');
        if ($vac['start_date'] <= $today && $vac['end_date'] >= $today) {
            ajaxFlash(htmlspecialchars($firstName) . ' has been called back in early from vacation.');
        } else {
            ajaxFlash(htmlspecialchars($firstName) . '\'s vacation has been cancelled.');
        }
    } else {
        ajaxFlash('Vacation removed.');
    }
}

function handleUpdateAppointment(): never {
    if (($_SESSION['admin_verified'] ?? 0) < time() - 60) flashAndRedirect('Session expired. Re-authenticate.', 'error');
    unset($_SESSION['admin_verified']);
    $id = (int)($_POST['appointment_id'] ?? 0);
    saveBackupIfSim($id);
    $newDate = $_POST['new_date'] ?? '';
    $newSlot = (int)($_POST['new_slot'] ?? 0);
    $newMech = (int)($_POST['new_mechanic'] ?? 0);

    $db = getDB();
    $stmt = $db->prepare("SELECT appointment_date, slot_index, mechanic_id FROM appointments WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) flashAndRedirect('Appointment not found or no longer scheduled.', 'error');

    $dateChanged = $newDate && $newDate !== $appt['appointment_date'];
    $slotChanged = $newSlot !== (int)$appt['slot_index'];
    $mechChanged = $newMech && $newMech !== (int)$appt['mechanic_id'];

    if (!$dateChanged && !$slotChanged && !$mechChanged) {
        flashAndRedirect('No changes detected.', 'error');
    }

    if ($dateChanged && !preg_match(DATE_REGEX, $newDate)) {
        flashAndRedirect('Invalid date format.', 'error');
    }

    $finalMech = $mechChanged ? $newMech : (int)$appt['mechanic_id'];
    $finalDate = $dateChanged ? $newDate : $appt['appointment_date'];
    $finalSlot = $slotChanged ? $newSlot : (int)$appt['slot_index'];

    $validation = validateSlotAssignment($finalMech, $finalDate, $finalSlot, $id);
    if (!$validation['success']) flashAndRedirect($validation['message'], 'error');

    $db->beginTransaction();
    try {
        if ($dateChanged || $slotChanged) {
            $stmt = $db->prepare("UPDATE appointments SET appointment_date = ?, slot_index = ? WHERE id = ?");
            $stmt->execute([$finalDate, $finalSlot, $id]);
        }

        if ($mechChanged) {
            $stmt = $db->prepare("UPDATE appointments SET mechanic_id = ? WHERE id = ?");
            $stmt->execute([$finalMech, $id]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        flashAndRedirect('Update failed — try again.', 'error');
    }

    flashAndRedirect('Appointment updated.');
}

function handleEditBooking(): never {
    $id = (int)($_POST['appointment_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $licenseNo = strtoupper(trim($_POST['license_no'] ?? ''));
    $engineNo = strtoupper(trim($_POST['engine_no'] ?? ''));
    $model = trim($_POST['car_model'] ?? '');

    if (!$id || !$name || !$address || !$licenseNo || !$engineNo) {
        flashAndRedirect('All fields except car model are required.', 'error');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT a.client_id, a.car_id FROM appointments a WHERE a.id = ? AND a.status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) flashAndRedirect('Appointment not found.', 'error');

    $stmt = $db->prepare("UPDATE clients SET name = ?, address = ? WHERE id = ?");
    $stmt->execute([$name, $address, $appt['client_id']]);

    $stmt = $db->prepare("UPDATE cars SET license_no = ?, engine_no = ?, model = ? WHERE id = ?");
    $stmt->execute([$licenseNo, $engineNo, $model, $appt['car_id']]);

    flashAndRedirect('Booking updated!', 'success', 'index.php');
}

function restoreBackupData(): void {
    $db = getDB();
    // Restore appointments with backup
    $stmt = $db->query("SELECT id, backup_data FROM appointments WHERE backup_data IS NOT NULL");
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode($row['backup_data'], true);
        if ($data && isset($data['date'], $data['slot'], $data['mech'])) {
            $newStatus = $data['cancelled'] === 'true' ? STATUS_CANCELLED : STATUS_SCHEDULED;
            $db->prepare("UPDATE appointments SET appointment_date = ?, slot_index = ?, mechanic_id = ?, status = ?, cancelled_at = NULL, backup_data = NULL WHERE id = ?")
               ->execute([$data['date'], $data['slot'], $data['mech'], $newStatus, $row['id']]);
        }
    }
    // Restore mechanics that were fired during sim
    $db->exec("UPDATE mechanics SET is_active = 1, fired = FALSE WHERE fired = TRUE");
}

function handleSimToggle(): never {
    $db = getDB();
    $wasSim = (bool)$db->query("SELECT use_simulated_time FROM sim_config WHERE id = 1")->fetchColumn();
    $useSim = (int)(isset($_POST['use_sim']));
    if ($wasSim && !$useSim) {
        restoreBackupData();
    }
    $stmt = $db->prepare("UPDATE sim_config SET use_simulated_time = ? WHERE id = 1");
    $stmt->execute([$useSim]);
    flashAndRedirect($useSim ? 'Simulated time activated.' : 'Real time restored.');
}

function handleToggleSim(): never {
    $db = getDB();
    $simDt = $_POST['sim_datetime'] ?? null;
    if ($simDt) {
        $ts = strtotime($simDt);
        if ($ts) {
            $simDt = date('Y-m-d H:i:s', $ts);
        }
        $stmt = $db->prepare("UPDATE sim_config SET simulated_datetime = ? WHERE id = 1");
        $stmt->execute([$simDt]);
    }
    flashAndRedirect('Simulated time updated.');
}

function handleAddMechanic(): never {
    guardAgainstSim();
    $name = trim($_POST['mech_name'] ?? '');
    $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
    $specialties = trim($_POST['mech_specialties'] ?? '');
    $years = (int)($_POST['mech_years'] ?? 0);
    if ($name) {
        $id = addMechanic($name, $nickname, $specialties, $years);
        header('Location: admin.php?new_mechanic=' . $id . '&hire_name=' . urlencode($name));
        exit;
    }
    header('Location: admin.php');
    exit;
}

function handleUpdateMechanicInfo(): never {
    guardAgainstSim();
    $id = (int)($_POST['mech_id'] ?? 0);
    $name = trim($_POST['mech_name'] ?? '');
    $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
    $quote = trim($_POST['mech_quote'] ?? '') ?: null;
    $specialties = trim($_POST['mech_specialties'] ?? '');
    $years = (int)($_POST['mech_years'] ?? 0);
    $newHireName = trim($_POST['_new_hire_name'] ?? '');
    if ($name && $id) {
        updateMechanic($id, $name, $nickname, $specialties, $years, $quote);
        if ($newHireName) {
            flashAndRedirect($newHireName . ' has been hired!');
        }
        $firstName = explode(' ', $name)[0];
        flashAndRedirect(htmlspecialchars($firstName) . "'s info updated.");
    }
    header('Location: admin.php');
    exit;
}

function handleUpdateSchedule(): never {
    guardAgainstSim();
    $mechId = (int)($_POST['mech_id'] ?? 0);
    $schedule = [];
    for ($d = 0; $d <= 6; $d++) {
        $key = 'dow_' . $d;
        $slots = isset($_POST[$key]) ? array_map('intval', (array)$_POST[$key]) : [];
        $slotFlags = [];
        for ($s = 0; $s < SLOT_COUNT; $s++) {
            $slotFlags[] = in_array($s, $slots);
        }
        if (in_array(true, $slotFlags)) {
            $schedule[$d] = $slotFlags;
        }
    }
    $newHireName = trim($_POST['_new_hire_name'] ?? '');
    $mechName = trim($_POST['mech_name'] ?? '');
    $mechNickname = trim($_POST['mech_nickname'] ?? '') ?: null;
    $mechQuote = trim($_POST['mech_quote'] ?? '') ?: null;
    $mechSpecialties = trim($_POST['mech_specialties'] ?? '');
    $mechYears = (int)($_POST['mech_years'] ?? 0);
    if ($mechId && $mechName) {
        updateMechanic($mechId, $mechName, $mechNickname, $mechSpecialties, $mechYears, $mechQuote);
    }
    updateMechanicSchedule($mechId, $schedule);
    if ($newHireName) {
        flashAndRedirect($newHireName . ' has been hired!');
    }
    $savedBoth = !empty($_POST['_saved_both']);
    flashAndRedirect(htmlspecialchars($mechName) . ($savedBoth ? "'s info and schedule have been updated." : "'s schedule has been updated."));
}

function handleAddVacation(): never {
    guardAgainstSim();
    $mechId = (int)($_POST['vac_mech_id'] ?? 0);
    $start = $_POST['vac_start'] ?? '';
    $end = $_POST['vac_end'] ?? '';
    $reason = trim($_POST['vac_reason'] ?? '') ?: null;
    if (!preg_match(DATE_REGEX, $start) || !preg_match(DATE_REGEX, $end)) {
        ajaxFlash('Invalid date format.', 'error');
    }
    if ($start < getEffectiveTime()->format('Y-m-d')) {
        ajaxFlash('Vacation cannot start in the past.', 'error');
    }
    $newHireName = trim($_POST['_new_hire_name'] ?? '');
    if ($mechId && $start && $end && $start <= $end) {
        $stmt = getDB()->prepare("SELECT id FROM mechanic_vacations WHERE mechanic_id = ? AND start_date <= ? AND end_date >= ?");
        $stmt->execute([$mechId, $end, $start]);
        if ($stmt->fetchColumn() > 0) {
            $sn = getDB()->prepare("SELECT name FROM mechanics WHERE id = ?");
            $sn->execute([$mechId]);
            $nm = $sn->fetchColumn();
            ajaxFlash(($nm ? htmlspecialchars($nm) . ' is already on vacation during those dates. Try again!' : 'Overlapping vacation found.'), 'error');
        }
        $apptStmt = getDB()->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date BETWEEN ? AND ? AND status IN ('" . STATUS_SCHEDULED . "', '" . STATUS_IN_PROGRESS . "')");
        $apptStmt->execute([$mechId, $start, $end]);
        $apptCount = (int)$apptStmt->fetchColumn();
        if ($apptCount > 0) {
            $sn2 = getDB()->prepare("SELECT name FROM mechanics WHERE id = ?");
            $sn2->execute([$mechId]);
            $nm2 = $sn2->fetchColumn();
            ajaxFlash(($nm2 ? htmlspecialchars($nm2) . ' has ' . $apptCount . ' upcoming appointment(s) during those dates — reschedule them first!' : $apptCount . ' appointment(s) conflict with these dates.'), 'error');
        }
        addMechanicVacation($mechId, $start, $end, $reason);
        $newVacId = (int)getDB()->lastInsertId();
        if ($newHireName) {
            ajaxFlash($newHireName . ' has been hired!');
        }
        $stmt = getDB()->prepare("SELECT name FROM mechanics WHERE id = ?");
        $stmt->execute([$mechId]);
        $m = $stmt->fetch();
        $msg = ($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' is on vacation ' . fmtDate($start) . ' to ' . fmtDate($end) . '.';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            echo json_encode(['ok' => true, 'msg' => $msg, 'vacation' => ['id' => $newVacId, 'start_date' => $start, 'end_date' => $end, 'reason' => $reason]]);
            exit;
        }
        flashAndRedirect($msg);
    } else {
        ajaxFlash('Invalid vacation dates.', 'error');
    }
}

function handleOverrideSlot(): never {
    guardAgainstSim();
    $db = getDB();
    $mechId = (int)($_POST['override_mechanic'] ?? 0);
    $date = $_POST['override_date'] ?? '';
    if (!preg_match(DATE_REGEX, $date)) flashAndRedirect('Invalid date format.', 'error');
    $slots = $_POST['slots'] ?? [];
    $reason = trim($_POST['reason'] ?? '');

    $dow = (int)date('w', strtotime($date));
    $dayNames = $GLOBALS['DAY_NAMES_FULL'];

    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([$mechId]);
    $mech = $stmt->fetch();
    $mechName = $mech ? $mech['name'] : "Mechanic #{$mechId}";

    $stmt = $db->prepare("SELECT slot_1, slot_2, slot_3, slot_4 FROM mechanic_schedule WHERE mechanic_id = ? AND day_of_week = ?");
    $stmt->execute([$mechId, $dow]);
    $schedule = $stmt->fetch();

    if (!$schedule) {
        flashAndRedirect("{$mechName} does not work on {$dayNames[$dow]} — no override needed.", 'error');
    }

    if (isMechanicOnVacation($mechId, $date)) {
        flashAndRedirect("{$mechName} is on vacation on " . date('j F', strtotime($date)) . " — no override needed.", 'error');
    }

    $invalidSlots = [];
    foreach ($slots as $s) {
        $slotKey = 'slot_' . ((int)$s + 1);
        if (!$schedule[$slotKey]) {
            $invalidSlots[] = (int)$s + 1;
        }
    }
    if (!empty($invalidSlots)) {
        flashAndRedirect("{$mechName} is not scheduled for slot(s) " . implode(', ', $invalidSlots) . " on {$dayNames[$dow]} — cannot block them.", 'error');
    }

    $stmt = $db->prepare("SELECT a.id, a.slot_index, c.name AS client_name FROM appointments a JOIN clients c ON c.id = a.client_id WHERE a.mechanic_id = ? AND a.appointment_date = ? AND a.status NOT IN ('" . STATUS_CANCELLED . "','" . STATUS_COMPLETED . "')");
    $stmt->execute([$mechId, $date]);
    $conflicts = [];
    foreach ($stmt->fetchAll() as $a) {
        if (in_array((int)$a['slot_index'], $slots)) {
            $conflicts[] = $a;
        }
    }

    if (!empty($conflicts)) {
            $_SESSION['flash_conflicts'] = array_map(fn($c) => htmlspecialchars($c['client_name']) . ' (' . ($GLOBALS['SLOT_NAMES'][(int)$c['slot_index']] ?? 'Slot ' . ((int)$c['slot_index'] + 1)) . ')', $conflicts);
    } else {
        $slotFlags = [];
        for ($i = 0; $i < SLOT_COUNT; $i++) {
            $slotFlags['slot_' . ($i + 1)] = in_array($i, $slots) ? 0 : 1;
        }
        $stmt = $db->prepare("INSERT INTO mechanic_overrides (mechanic_id, override_date, slot_1, slot_2, slot_3, slot_4, reason)
                              VALUES (?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE slot_1=VALUES(slot_1), slot_2=VALUES(slot_2), slot_3=VALUES(slot_3), slot_4=VALUES(slot_4), reason=VALUES(reason)");
        $stmt->execute([$mechId, $date, $slotFlags['slot_1'], $slotFlags['slot_2'], $slotFlags['slot_3'], $slotFlags['slot_4'], $reason]);
        flashAndRedirect('Schedule override saved.');
    }
    header('Location: admin.php');
    exit;
}
