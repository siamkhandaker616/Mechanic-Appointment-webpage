<?php
require_once __DIR__ . '/config.php';

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

function getMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, quote, theme, specialties, years_experience AS experience FROM mechanics WHERE is_active = 1 ORDER BY id")->fetchAll();
}

function getMechanicById(int $id): ?array {
    $stmt = getDB()->prepare("SELECT id, name, nickname, bio, specialties, years_experience AS experience FROM mechanics WHERE id = ?");
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
    if ($slotIndex < 0 || $slotIndex >= SLOT_COUNT) return false;
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

    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND slot_index = ? AND status != '" . STATUS_CANCELLED . "'");
    $stmt->execute([$mechanicId, $date, $slotIndex]);
    if ($stmt->fetchColumn() > 0) return false;

    if (isMechanicOnVacation($mechanicId, $date)) return false;

    return true;
}

function getAdjacentSlotForMechanic(int $mechanicId, string $date, int $slotIndex): ?int {
    if (isSlotAvailable($mechanicId, $date, $slotIndex + 1)) return $slotIndex + 1;
    if (isSlotAvailable($mechanicId, $date, $slotIndex - 1)) return $slotIndex - 1;
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

    $oneMonthBefore = date('Y-m-d', strtotime($date . ' -30 days'));
    $oneMonthAfter = date('Y-m-d', strtotime($date . ' +30 days'));

    $today = date('Y-m-d');
    for ($i = 1; $i <= 30; $i++) {
        $prev = date('Y-m-d', strtotime($date . " -$i days"));
        if ($prev < $oneMonthBefore) break;
        if ($prev <= $today) continue;
        if (!isset($bookedDates[$prev]) && isSlotAvailable($mechanicId, $prev, $slotIndex)) {
            $result['prev'] = $prev;
            break;
        }
    }

    for ($i = 1; $i <= 30; $i++) {
        $next = date('Y-m-d', strtotime($date . " +$i days"));
        if ($next > $oneMonthAfter) break;
        if (!isset($bookedDates[$next]) && isSlotAvailable($mechanicId, $next, $slotIndex)) {
            $result['next'] = $next;
            break;
        }
    }

    return $result;
}

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

    // Revert over-advanced: completed → in_progress if slot hasn't ended
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_COMPLETED . "'");
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today) {
            $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "' WHERE id = ? AND status = '" . STATUS_COMPLETED . "'")->execute([$a['id']]);
        } elseif ($a['appointment_date'] === $today) {
            $slotEnd = slotEndHour((int)$a['slot_index']);
            if ($currentHour < $slotEnd) {
                $db->prepare("UPDATE appointments SET status = '" . STATUS_IN_PROGRESS . "' WHERE id = ? AND status = '" . STATUS_COMPLETED . "'")->execute([$a['id']]);
            }
        }
    }

    // Revert over-advanced: in_progress → scheduled if slot hasn't started
    $stmt = $db->query("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_IN_PROGRESS . "'");
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] > $today || ($a['appointment_date'] === $today && (int)$a['slot_index'] > $currentSlot)) {
            $db->prepare("UPDATE appointments SET status = '" . STATUS_SCHEDULED . "' WHERE id = ? AND status = '" . STATUS_IN_PROGRESS . "'")->execute([$a['id']]);
        }
    }

    // Forward: scheduled → in_progress
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_SCHEDULED . "' AND appointment_date <= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $a) {
        if ($a['appointment_date'] < $today || ($a['appointment_date'] === $today && (int)$a['slot_index'] <= $currentSlot)) {
            $db->prepare("UPDATE appointments SET status = '" . STATUS_IN_PROGRESS . "' WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'")->execute([$a['id']]);
        }
    }

    // Forward: in_progress → completed
    $stmt = $db->prepare("SELECT id, appointment_date, slot_index FROM appointments WHERE status = '" . STATUS_IN_PROGRESS . "' AND appointment_date <= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $a) {
        $slotStart = slotStartHour((int)$a['slot_index']);
        if ($a['appointment_date'] < $today || ($a['appointment_date'] === $today && $currentHour >= $slotStart + 2)) {
            $db->prepare("UPDATE appointments SET status = '" . STATUS_COMPLETED . "' WHERE id = ? AND status = '" . STATUS_IN_PROGRESS . "'")->execute([$a['id']]);
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

function updateAppointmentDate(int $appointmentId, string $newDate, int $newSlot): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT car_id, mechanic_id FROM appointments WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not found or no longer scheduled.'];

    $validation = validateSlotAssignment((int)$appt['mechanic_id'], $newDate, $newSlot);
    if (!$validation['success']) return $validation;

    $stmt = $db->prepare("UPDATE appointments SET appointment_date = ?, slot_index = ? WHERE id = ?");
    $stmt->execute([$newDate, $newSlot, $appointmentId]);
    return ['success' => true, 'message' => 'Appointment date updated.'];
}

function updateAppointmentMechanic(int $appointmentId, int $newMechanicId): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT appointment_date, slot_index, mechanic_id FROM appointments WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not found or no longer scheduled.'];

    $validation = validateSlotAssignment($newMechanicId, $appt['appointment_date'], (int)$appt['slot_index'], $appointmentId);
    if (!$validation['success']) return $validation;

    $stmt = $db->prepare("UPDATE appointments SET mechanic_id = ? WHERE id = ?");
    $stmt->execute([$newMechanicId, $appointmentId]);
    return ['success' => true, 'message' => 'Appointment mechanic updated.'];
}

function cancelAppointment(int $appointmentId): bool {
    $stmt = getDB()->prepare("UPDATE appointments SET status = '" . STATUS_CANCELLED . "', cancelled_at = NOW() WHERE id = ? AND status = '" . STATUS_SCHEDULED . "'");
    $stmt->execute([$appointmentId]);
    return $stmt->rowCount() > 0;
}

function validateAppointmentInput(array $data): array {
    $errors = [];

    if (empty(trim($data['name'] ?? ''))) $errors[] = 'Name is required.';
    if (empty(trim($data['phone'] ?? ''))) $errors[] = 'Phone number is required.';
    elseif (!preg_match('/^[\d\s\-\+\(\)]+$/', $data['phone'])) $errors[] = 'Phone must contain only digits, spaces, +, -, and parentheses.';

    if (empty(trim($data['license_no'] ?? ''))) $errors[] = 'Car license number is required.';
    if (empty(trim($data['engine_no'] ?? ''))) $errors[] = 'Car engine number is required.';
    elseif (!preg_match('/^[a-zA-Z0-9]+$/', $data['engine_no'])) $errors[] = 'Engine number must be alphanumeric.';

    if (empty($data['date'] ?? '')) $errors[] = 'Appointment date is required.';
    elseif (!preg_match(DATE_REGEX, $data['date'])) $errors[] = 'Invalid date format.';
    elseif ($data['date'] < date('Y-m-d')) $errors[] = 'Appointment date cannot be in the past.';

    if (!isset($data['mechanic_id']) || !$data['mechanic_id']) $errors[] = 'Please select a mechanic.';
    if (!isset($data['slot_index']) || $data['slot_index'] === '') $errors[] = 'Please select a time slot.';

    if (empty(trim($data['address'] ?? ''))) $errors[] = 'Address is required.';

    return $errors;
}

function getAllMechanics(): array {
    return getDB()->query("SELECT id, name, nickname, bio, quote, theme, specialties, years_experience AS experience, is_active FROM mechanics ORDER BY is_active DESC, name ASC")->fetchAll();
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
    $stmt = getDB()->prepare("UPDATE mechanics SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);
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

function flashAndRedirect(string $msg, string $type = 'success'): never {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: admin.php');
    exit;
}

// --- Action handlers (dispatched from admin.php) ---

function handleRemoveAllCancelled(): never {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE status = '" . STATUS_CANCELLED . "'");
    $stmt->execute();
    flashAndRedirect($stmt->rowCount() . ' cancelled appointment(s) removed.');
}

function handleRemove(): never {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE id = ? AND status = '" . STATUS_CANCELLED . "'");
    $stmt->execute([(int)$_GET['remove']]);
    $ok = $stmt->rowCount() > 0;
    flashAndRedirect($ok ? 'Appointment removed.' : 'Appointment not found or not cancellable.', $ok ? 'success' : 'error');
}

function handleCancel(): never {
    $id = (int)$_GET['cancel'];
    if (cancelAppointment($id)) {
        flashAndRedirect('Appointment cancelled.');
    } else {
        flashAndRedirect('Could not cancel — appointment may already be in progress or completed.', 'error');
    }
}

function handleFire(): never {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['fire']]);
    $m = $stmt->fetch();
    fireMechanic((int)$_GET['fire']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been fired!');
}

function handleRestore(): never {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['restore']]);
    $m = $stmt->fetch();
    restoreMechanic((int)$_GET['restore']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has rejoined!');
}

function handleRemoveMechanic(): never {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['remove_mechanic']]);
    $m = $stmt->fetch();
    removeMechanic((int)$_GET['remove_mechanic']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been removed permanently.');
}

function handleUnblock(): never {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM mechanic_overrides WHERE id = ?");
    $stmt->execute([(int)$_GET['unblock']]);
    $ok = $stmt->rowCount() > 0;
    flashAndRedirect($ok ? 'Override removed.' : 'Override not found.', $ok ? 'success' : 'error');
}

function handleRemoveVacation(): never {
    removeMechanicVacation((int)$_GET['remove_vacation']);
    flashAndRedirect('Vacation removed.');
}

function handleUpdateDate(): never {
    if (($_POST['admin_pw'] ?? '') !== ADMIN_PW) {
        flashAndRedirect('Invalid password.', 'error');
    }
    $id = (int)($_POST['appointment_id'] ?? 0);
    $newDate = $_POST['new_date'] ?? '';
    $newSlot = (int)($_POST['new_slot'] ?? 0);
    if (!preg_match(DATE_REGEX, $newDate)) flashAndRedirect('Invalid date format.', 'error');
    $result = updateAppointmentDate($id, $newDate, $newSlot);
    flashAndRedirect($result['message'], $result['success'] ? 'success' : 'error');
}

function handleUpdateMechanic(): never {
    if (($_POST['admin_pw'] ?? '') !== ADMIN_PW) {
        flashAndRedirect('Invalid password.', 'error');
    }
    $id = (int)($_POST['appointment_id'] ?? 0);
    $newMech = (int)($_POST['new_mechanic'] ?? 0);
    $result = updateAppointmentMechanic($id, $newMech);
    flashAndRedirect($result['message'], $result['success'] ? 'success' : 'error');
}

function handleSimToggle(): never {
    $db = getDB();
    $useSim = (int)(isset($_POST['use_sim']));
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
    $name = trim($_POST['mech_name'] ?? '');
    $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
    $quote = trim($_POST['mech_quote'] ?? '') ?: null;
    $specialties = trim($_POST['mech_specialties'] ?? '');
    $years = (int)($_POST['mech_years'] ?? 0);
    if ($name) {
        addMechanic($name, $nickname, $specialties, $years, $quote);
        flashAndRedirect(htmlspecialchars($name) . ' has been hired!');
    }
    header('Location: admin.php');
    exit;
}

function handleUpdateMechanicInfo(): never {
    $id = (int)($_POST['mech_id'] ?? 0);
    $name = trim($_POST['mech_name'] ?? '');
    $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
    $quote = trim($_POST['mech_quote'] ?? '') ?: null;
    $specialties = trim($_POST['mech_specialties'] ?? '');
    $years = (int)($_POST['mech_years'] ?? 0);
    if ($name && $id) {
        updateMechanic($id, $name, $nickname, $specialties, $years, $quote);
        flashAndRedirect('Mechanic updated.');
    }
    header('Location: admin.php');
    exit;
}

function handleUpdateSchedule(): never {
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
    updateMechanicSchedule($mechId, $schedule);
    flashAndRedirect('Schedule updated.');
}

function handleAddVacation(): never {
    $mechId = (int)($_POST['vac_mech_id'] ?? 0);
    $start = $_POST['vac_start'] ?? '';
    $end = $_POST['vac_end'] ?? '';
    $reason = trim($_POST['vac_reason'] ?? '') ?: null;
    if (!preg_match(DATE_REGEX, $start) || !preg_match(DATE_REGEX, $end)) {
        flashAndRedirect('Invalid date format.', 'error');
    }
    if ($start < date('Y-m-d')) {
        flashAndRedirect('Vacation cannot start in the past.', 'error');
    }
    if ($mechId && $start && $end && $start <= $end) {
        addMechanicVacation($mechId, $start, $end, $reason);
        $stmt = getDB()->prepare("SELECT name FROM mechanics WHERE id = ?");
        $stmt->execute([$mechId]);
        $m = $stmt->fetch();
        flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' is on vacation ' . $start . ' to ' . $end . '.');
    } else {
        flashAndRedirect('Invalid vacation dates.', 'error');
    }
}

function handleOverrideSlot(): never {
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
        $_SESSION['flash_conflicts'] = array_map(fn($c) => htmlspecialchars($c['client_name']) . ' (slot ' . ((int)$c['slot_index'] + 1) . ')', $conflicts);
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
