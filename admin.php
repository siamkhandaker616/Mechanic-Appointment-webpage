<?php
session_start();
require_once __DIR__ . '/functions.php';

$msg = '';
$msgType = '';
$conflictList = [];

// --- GET action handlers (redirect after) ---

if (isset($_GET['remove_all_cancelled'])) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE status = '" . STATUS_CANCELLED . "'");
    $stmt->execute();
    flashAndRedirect($stmt->rowCount() . ' cancelled appointment(s) removed.');
}

if (isset($_GET['remove'])) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM appointments WHERE id = ? AND status = '" . STATUS_CANCELLED . "'");
    $stmt->execute([(int)$_GET['remove']]);
    $ok = $stmt->rowCount() > 0;
    flashAndRedirect($ok ? 'Appointment removed.' : 'Appointment not found or not cancellable.', $ok ? 'success' : 'error');
}

if (isset($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    if (cancelAppointment($id)) {
        flashAndRedirect('Appointment cancelled.');
    } else {
        flashAndRedirect('Could not cancel — appointment may already be in progress or completed.', 'error');
    }
}

if (isset($_GET['fire'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['fire']]);
    $m = $stmt->fetch();
    fireMechanic((int)$_GET['fire']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been fired!');
}

if (isset($_GET['restore'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['restore']]);
    $m = $stmt->fetch();
    restoreMechanic((int)$_GET['restore']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has rejoined!');
}

if (isset($_GET['remove_mechanic'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM mechanics WHERE id = ?");
    $stmt->execute([(int)$_GET['remove_mechanic']]);
    $m = $stmt->fetch();
    removeMechanic((int)$_GET['remove_mechanic']);
    flashAndRedirect(($m ? htmlspecialchars($m['name']) : 'Mechanic') . ' has been removed permanently.');
}

if (isset($_GET['unblock'])) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM mechanic_overrides WHERE id = ?");
    $stmt->execute([(int)$_GET['unblock']]);
    $ok = $stmt->rowCount() > 0;
    flashAndRedirect($ok ? 'Override removed.' : 'Override not found.', $ok ? 'success' : 'error');
}

if (isset($_GET['remove_vacation'])) {
    removeMechanicVacation((int)$_GET['remove_vacation']);
    flashAndRedirect('Vacation removed.');
}

// --- POST action handlers (redirect after) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_date']) && isset($_POST['appointment_id'])) {
        $id = (int)$_POST['appointment_id'];
        $newDate = $_POST['new_date'] ?? '';
        $newSlot = (int)($_POST['new_slot'] ?? 0);
        $result = updateAppointmentDate($id, $newDate, $newSlot);
        flashAndRedirect($result['message'], $result['success'] ? 'success' : 'error');
    }

    if (isset($_POST['update_mechanic']) && isset($_POST['appointment_id'])) {
        $id = (int)$_POST['appointment_id'];
        $newMech = (int)$_POST['new_mechanic'];
        $result = updateAppointmentMechanic($id, $newMech);
        flashAndRedirect($result['message'], $result['success'] ? 'success' : 'error');
    }

    if (isset($_POST['sim_toggle']) && !isset($_POST['toggle_sim'])) {
        $db = getDB();
        $useSim = (int)(isset($_POST['use_sim']));
        $stmt = $db->prepare("UPDATE sim_config SET use_simulated_time = ? WHERE id = 1");
        $stmt->execute([$useSim]);
    flashAndRedirect($useSim ? 'Simulated time activated.' : 'Real time restored.');
    }

    if (isset($_POST['toggle_sim'])) {
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

    if (isset($_POST['add_mechanic'])) {
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

    if (isset($_POST['update_mechanic_info'])) {
        $id = (int)$_POST['mech_id'];
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

    if (isset($_POST['update_schedule']) && isset($_POST['mech_id'])) {
        $mechId = (int)$_POST['mech_id'];
        $schedule = [];
        $dayNames = ['sun','mon','tue','wed','thu','fri','sat'];
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

    if (isset($_POST['add_vacation'])) {
        $mechId = (int)$_POST['vac_mech_id'];
        $start = $_POST['vac_start'] ?? '';
        $end = $_POST['vac_end'] ?? '';
        $reason = trim($_POST['vac_reason'] ?? '') ?: null;
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

    if (isset($_POST['override_slot'])) {
        $db = getDB();
        $mechId = (int)$_POST['override_mechanic'];
        $date = $_POST['override_date'];
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

}

// --- Read flash messages for display (only reached on GET) ---
$msg = $_SESSION['flash_msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? '';
$conflictList = $_SESSION['flash_conflicts'] ?? [];
unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['flash_conflicts']);

advanceAppointmentStatuses();

$appointments = getAppointments();
$mechanics = getMechanics();
$mechanicsForSelect = getMechanicsForSelect();
$allMechanics = getAllMechanics();
$scheduleData = [];
foreach ($allMechanics as $m) {
    $scheduleData[$m['id']] = getMechanicSchedule((int)$m['id']);
}
$vacationData = [];
foreach ($allMechanics as $m) {
    $vacationData[$m['id']] = getMechanicVacations((int)$m['id']);
}

$overrides = getDB()->query(
    "SELECT mo.id, mo.mechanic_id, mo.override_date, mo.slot_1, mo.slot_2, mo.slot_3, mo.slot_4, mo.reason, m.name AS mechanic_name
     FROM mechanic_overrides mo
     JOIN mechanics m ON m.id = mo.mechanic_id
     ORDER BY mo.override_date DESC, m.name ASC"
)->fetchAll();

$stmt = getDB()->query("SELECT use_simulated_time, simulated_datetime FROM sim_config WHERE id = 1");
$simConfig = $stmt->fetch();
$useSim = $simConfig && $simConfig['use_simulated_time'];
$simDt = $simConfig ? $simConfig['simulated_datetime'] : null;
$effectiveTime = getEffectiveTime();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Mayhem Mobility — Admin Panel</title>
<link rel="preload" href="fonts/Bangers.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/WalterTurncoat-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="omg omg-left">POW</div>
<div class="omg omg-right">ZOWIE</div>
<div class="omg omg-bot">BAM</div>

<header>
    <h1>Mayhem Mobility <img src="images/icons/tagline.png" alt="Mayhem Mobility Tagline" class="tagline"></h1>
    <p class="subtitle">Admin Panel</p>
    <div class="admin-nav">
        <a href="index.php" class="btn btn-sm btn-outline">Booking Page</a>
        <a href="admin.php" class="btn btn-sm btn-outline">Refresh</a>
    </div>
</header>

<div class="container">

<div class="panel">
    <div class="burst burst-right">TIME!</div>
    <h2>Simulated Time</h2>
    <div class="sim-bar <?= $useSim ? 'active' : '' ?>">
        <form method="post" style="display:contents">
        <span>
            <strong>Current Time:</strong>
            <?= htmlspecialchars($effectiveTime->format('d-m-Y H:i')) ?>
            <?php if ($useSim): ?>
            <em>(simulated)</em>
            <?php endif; ?>
        </span>
        <span class="sim-group">
            <input type="datetime-local" name="sim_datetime" data-placement="top" style="text-align:right" value="<?= $simDt ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($simDt))) : '' ?>">
            <button type="submit" name="toggle_sim" value="1" class="btn btn-sm">Set</button>
        </span>
        <span class="sim-group">
            <input type="checkbox" name="use_sim" value="1" id="use-sim" <?= $useSim ? 'checked' : '' ?> onchange="this.form.submit()">
            <input type="hidden" name="sim_toggle" value="1">
            <label for="use-sim">Use simulated time</label>
        </span>
        </form>
    </div>
</div>

<div class="panel">
    <div class="burst burst-right">LIST!</div>
    <h2>All Appointments</h2>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Client</th>
                <th>Phone</th>
                <th>Car</th>
                <th>Date</th>
                <th>Slot</th>
                <th>Mechanic</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($appointments)): ?>
            <tr><td colspan="8" style="text-align:center;font-style:italic;">No appointments yet.</td></tr>
            <?php else: ?>
            <?php foreach ($appointments as $a): ?>
            <tr>
                <td><strong><?= htmlspecialchars($a['client_name']) ?></strong></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars($a['phone']) ?></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars($a['license_no']) ?><br><small><?= htmlspecialchars($a['model']) ?></small></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars(fmtDate($a['appointment_date'])) ?></td>
                <td><?= htmlspecialchars([0 => 'Morning', 1 => 'Noon', 2 => 'Afternoon', 3 => 'Evening'][(int)$a['slot_index']] ?? '') ?></td>
                <td><?= htmlspecialchars($a['mechanic_name']) ?></td>
                <td style="white-space:nowrap;"><span class="status-badge status-<?= htmlspecialchars($a['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $a['status'])) ?></span></td>
                <td style="white-space:nowrap;">
                    <?php if ($a['status'] === STATUS_SCHEDULED): ?>
                    <button class="btn btn-sm btn-outline" onclick="toggleEdit(<?= $a['id'] ?>)">Edit</button>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showCancelModal(<?= $a['id'] ?>)">Cancel</button>
                    <?php elseif ($a['status'] === STATUS_CANCELLED): ?>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showRemoveModal(<?= $a['id'] ?>)">Remove</button>
                    <?php else: ?>
                    <span style="font-size:0.8rem;color:#888;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="edit-row" id="edit-<?= $a['id'] ?>">
                <td colspan="8">
                    <div class="edit-inner">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                            <input type="date" name="new_date" value="<?= $a['appointment_date'] ?>" min="<?= date('Y-m-d') ?>">
                            <select name="new_slot">
                                <?php foreach ($SLOT_LABELS as $si => $sl): ?>
                                <option value="<?= $si ?>" <?= $si === (int)$a['slot_index'] ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_date" class="btn btn-sm">Change Date</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                            <select name="new_mechanic" data-current="<?= (int)$a['mechanic_id'] ?>" onchange="toggleMechSwapBtn(this)">
                                <?php foreach ($mechanicsForSelect as $mid => $mname): ?>
                                <option value="<?= $mid ?>" <?= $mid === (int)$a['mechanic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($mname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_mechanic" class="btn btn-sm">Change Mechanic</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div style="margin-top:16px;text-align:right;">
        <a href="?remove_all_cancelled" class="btn btn-sm btn-rust">Remove All Cancelled</a>
    </div>
    </div>
</div>

<div class="panel">
    <div class="burst burst-right">LOCK!</div>
    <h2>Schedule Override</h2>
    <p style="margin-bottom:12px;font-size:0.9rem;">Block specific slots for a mechanic on a given date (days off, early leave).</p>
    <form method="post" style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div>
                <label>Mechanic</label>
                <select name="override_mechanic" required>
                    <option value="">— Select —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date</label>
                <input type="date" name="override_date" required min="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label style="font-size:0.9rem;">Blocked Slots</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php for ($i = 0; $i < SLOT_COUNT; $i++): ?>
                    <label style="font-size:0.75rem;border:2px solid var(--ink);padding:6px;cursor:pointer;">
                        <input type="checkbox" name="slots[]" value="<?= $i ?>"> <?= htmlspecialchars($SLOT_LABELS[$i] ?? ($i + 1)) ?>
                    </label>
                    <?php endfor; ?>
                </div>
                <label style="margin-top:6px;">Reason (optional)</label>
                <input type="text" name="reason" placeholder="e.g. sick day" style="width:100%;max-width:526px;">
            </div>
        </div>
        <div style="display:flex;gap:12px;justify-content:space-between;">
            <button type="submit" name="override_slot" class="btn btn-sm btn-rust">Save Override</button>
            <?php if (!empty($overrides)): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleOverrides()" id="overrides-toggle">Show All Blocks</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (!empty($overrides)): ?>
<div class="panel" id="overrides-panel" style="display: none;">
    <div class="burst burst-right" style="background:var(--rust);">HELD!</div>
    <h2>Active Overrides</h2>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Mechanic</th>
                <th>Date</th>
                <th>Blocked Slots</th>
                <th>Reason</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($overrides as $o):
                $blocked = [];
                for ($i = 0; $i < SLOT_COUNT; $i++) {
                    $key = 'slot_' . ($i + 1);
                    if (!$o[$key]) {
                        $blocked[] = htmlspecialchars($SLOT_LABELS[$i] ?? "Slot " . ($i + 1));
                    }
                }
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($o['mechanic_name']) ?></strong></td>
                <td><?= htmlspecialchars(fmtDate($o['override_date'])) ?></td>
                <td style="font-size:0.85rem;"><?= $blocked ? implode('<br>', $blocked) : '<em>none</em>' ?></td>
                <td><?= htmlspecialchars($o['reason'] ?? '—') ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showUnblockModal(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['mechanic_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars(fmtDate($o['override_date'])) ?>')">Unblock</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="burst burst-right">HIRE!</div>
    <h2>Mechanics</h2>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Nickname</th>
                <th>Specialties</th>
                <th>Exp</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allMechanics as $m): ?>
            <?php $onLeave = $m['is_active'] && isMechanicOnVacation((int)$m['id'], date('Y-m-d')); ?>
            <tr>
                <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                <td><?= htmlspecialchars($m['nickname'] ?? '—') ?></td>
                <td><?= htmlspecialchars($m['specialties'] ?? '—') ?></td>
                <td><?= (int)$m['experience'] ?></td>
                <td>
                    <?php if ($onLeave): ?>
                    <span class="status-badge" style="background:var(--gold);color:var(--ink);white-space:nowrap;">On Leave</span>
                    <?php else: ?>
                    <span class="status-badge <?= $m['is_active'] ? 'status-scheduled' : 'status-cancelled' ?>"><?= $m['is_active'] ? 'Active' : 'Inactive' ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($m['is_active']): ?>
                    <button class="btn btn-sm btn-outline" onclick="openMechModal(this)" data-mid="<?= $m['id'] ?>" data-mname="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>" data-mnick="<?= htmlspecialchars($m['nickname'] ?? '', ENT_QUOTES) ?>" data-mquote="<?= htmlspecialchars($m['quote'] ?? '', ENT_QUOTES) ?>" data-mspec="<?= htmlspecialchars($m['specialties'] ?? '', ENT_QUOTES) ?>" data-experience="<?= (int)$m['experience'] ?>">Edit</button>
                    <button class="btn btn-sm btn-outline" onclick="openScheduleModal(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')">Schedule</button>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showFireModal(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')">Fire</button>
                    <?php else: ?>
                    <a href="?restore=<?= $m['id'] ?>" class="btn btn-sm btn-outline">Restore</a>
                    <a href="?remove_mechanic=<?= $m['id'] ?>" class="btn btn-sm btn-rust" onclick="return confirm('Permanently delete <?= htmlspecialchars($m['name'], ENT_QUOTES) ?> and all their data?')">Remove</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <details style="margin-top:16px;border:2px solid var(--ink);padding:12px;background:var(--cyan-light);box-shadow:3px 3px 0 var(--ink);">
        <summary style="font-family:var(--font-sub);text-transform:uppercase;cursor:pointer;font-size:0.9rem;">Register New Mechanic</summary>
        <form method="post" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
            <div>
                <label>Name</label>
                <input type="text" name="mech_name" required>
            </div>
            <div>
                <label>Nickname</label>
                <input type="text" name="mech_nickname">
            </div>
            <div>
                <label>Catchphrase</label>
                <input type="text" name="mech_quote" placeholder="e.g. I'll fix it fast!">
            </div>
            <div>
                <label>Specialties</label>
                <input type="text" name="mech_specialties" placeholder="e.g. Engine, Transmission">
            </div>
            <div>
                <label>Experience</label>
                <input type="number" name="mech_years" value="0" style="width:80px;">
            </div>
            <button type="submit" name="add_mechanic" class="btn btn-sm">Hire</button>
        </form>
    </details>
</div>

</div>

<div class="modal-overlay hidden" id="mech-modal" onclick="closeMechModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:650px;">
        <div class="burst burst-right">EDIT!</div>
        <h2>Edit Mechanic</h2>
        <div style="display:flex;gap:12px;align-items:stretch;">
            <div style="flex:1;">
                <form method="post" id="mech-modal-form">
                    <input type="hidden" name="mech_id" id="modal-mech-id">
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label>Name</label>
                            <input type="text" name="mech_name" id="modal-mech-name" readonly style="width:100%;background:var(--paper);cursor:not-allowed;">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Nickname</label>
                            <input type="text" name="mech_nickname" id="modal-mech-nickname" style="width:100%;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Catchphrase</label>
                        <input type="text" name="mech_quote" id="modal-mech-quote" placeholder="e.g. I'll fix it fast!">
                    </div>
                    <div class="form-group">
                        <label>Specialties</label>
                        <input type="text" name="mech_specialties" id="modal-mech-specialties" placeholder="e.g. Engine, Transmission">
                    </div>
                    <div class="form-group">
                        <label>Experience</label>
                        <input type="number" name="mech_years" id="modal-mech-exp" style="width:100px;background:var(--paper);cursor:not-allowed;" readonly>
                    </div>
                    <div style="display:flex;gap:12px;margin-top:8px;">
                        <button type="submit" name="update_mechanic_info" class="btn btn-sm">Save</button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('mech-modal').classList.add('hidden')">Cancel</button>
                    </div>
                </form>
            </div>
            <div style="border-left:2px dashed var(--ink);align-self:stretch;"></div>
            <div style="flex:1;">
                <h3 style="font-family:var(--font-sub);font-size:0.9rem;text-transform:uppercase;margin-bottom:8px;">Vacations</h3>
                <div id="vacation-list" style="margin-bottom:10px;"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                    <div>
                        <label style="font-size:0.75rem;">Start</label>
                        <input type="date" id="vac-start" style="width:auto;font-size:0.8rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">End</label>
                        <input type="date" id="vac-end" data-placement="top" style="width:auto;font-size:0.8rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;">Reason (Optional)</label>
                        <input type="text" id="vac-reason" placeholder="Outside commitments" style="width: 200px;font-size:0.8rem;">
                    </div>
                    <button type="button" class="btn btn-sm btn-pink" onclick="addVacation()" style="font-size:0.8rem;padding:6px 14px;">Send On Vacation</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="schedule-modal" onclick="closeScheduleModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:560px;">
        <div class="burst burst-right">WEEK!</div>
        <h2><span id="schedule-mech-name">Schedule</span></h2>
        <p style="margin-bottom:12px;font-size:0.85rem;">Toggle which slots this mechanic works each day.</p>
        <form method="post" id="schedule-form">
            <input type="hidden" name="mech_id" id="schedule-mech-id">
            <table style="font-size:0.8rem;">
                <thead>
                    <tr>
                        <th style="min-width:60px;">Day</th>
                        <?php for ($si = 0; $si < SLOT_COUNT; $si++): ?>
                        <th style="text-align:center;padding:6px;"><?= htmlspecialchars($SLOT_LABELS[$si]) ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($d = 0; $d <= 6; $d++): ?>
                    <tr>
                        <td><strong><?= $GLOBALS['DAY_NAMES_ABBR'][$d] ?></strong></td>
                        <?php for ($si = 0; $si < SLOT_COUNT; $si++): ?>
                        <td style="text-align:center;">
                            <input type="checkbox" name="dow_<?= $d ?>[]" value="<?= $si ?>" class="sched-cb" data-dow="<?= $d ?>" data-slot="<?= $si ?>">
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <div style="display:flex;gap:12px;margin-top:16px;justify-content:flex-end;">
                <button type="submit" name="update_schedule" class="btn btn-sm">Save Schedule</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('schedule-modal').classList.add('hidden')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($conflictList)): ?>
<div class="modal-overlay" id="conflict-modal" onclick="closeConflictModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:480px;">
        <button type="button" class="modal-close" onclick="document.getElementById('conflict-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-right" style="background:var(--pink);">BLOCKED!</div>
        <h2>Cancel These First</h2>
        <p>The following appointments occupy slots you tried to override:</p>
        <ul style="margin:16px 0;padding-left:20px;">
            <?php foreach ($conflictList as $name): ?>
            <li style="margin-bottom:6px;"><?= $name ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="font-size:0.85rem;color:var(--pink);">Cancel them from the table below, then try again.</p>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('conflict-modal').classList.add('hidden')">Got it</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="cancel-modal" onclick="closeCancelModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('cancel-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" style="margin-bottom:12px;">WHOA!</div>
        <h2 style="margin-top:30px; margin-left: 5px;">Cancel Appointment ?</h2>
        <p style="margin:16px 0;">This can't be undone. Are you sure?</p>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="#" id="cancel-confirm-link" class="btn btn-sm btn-rust">Yes, Cancel</a>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('cancel-modal').classList.add('hidden')">Nevermind</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="fire-modal" onclick="closeFireModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('fire-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" style="margin-bottom:12px;">FIRED!</div>
        <h2 style="margin-top:30px; margin-left: 5px;" id="fire-modal-title">Fire Mechanic?</h2>
        <p style="margin:16px 0;" id="fire-modal-msg">They'll be retired and won't appear for new bookings.</p>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="#" id="fire-confirm-link" class="btn btn-sm btn-rust">Yes, Fire</a>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('fire-modal').classList.add('hidden')">Nevermind</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="remove-modal" onclick="closeRemoveModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('remove-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" style="margin-bottom:12px;">GONE!</div>
        <h2 style="margin-top:30px; margin-left: 5px;">Remove Appointment?</h2>
        <p style="margin:16px 0; ">This permanently deletes the record. Are you sure?</p>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="#" id="remove-confirm-link" class="btn btn-sm btn-rust">Yes, Remove</a>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('remove-modal').classList.add('hidden')">Nevermind</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="unblock-modal" onclick="closeUnblockModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('unblock-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" style="margin-bottom:12px;">FREE!</div>
        <h2 style="margin-top:30px; margin-left: 5px;">Remove Override?</h2>
        <p style="margin:16px 0;" id="unblock-msg">This will unblock the slots for this date.</p>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="#" id="unblock-confirm-link" class="btn btn-sm btn-rust">Yes, Unblock</a>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('unblock-modal').classList.add('hidden')">Nevermind</button>
        </div>
    </div>
</div>

<?php if ($msg): ?>
<div class="modal-overlay" id="msg-modal" onclick="closeMsgModal(event)">
    <div class="modal-box msg-box msg-<?= $msgType ?>">
        <button type="button" class="modal-close" onclick="document.getElementById('msg-modal').classList.add('hidden')">&times;</button>
        <div class="msg-content"><?= htmlspecialchars($msg) ?></div>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-pink btn-outline" onclick="document.getElementById('msg-modal').classList.add('hidden')">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var SCHEDULE_DATA = <?= json_encode($scheduleData) ?>;
var VACATION_DATA = <?= json_encode($vacationData) ?>;

function toggleEdit(id) {
    var row = document.getElementById('edit-' + id);
    row.classList.toggle('show');
}

function toggleOverrides() {
    var panel = document.getElementById('overrides-panel');
    var btn = document.getElementById('overrides-toggle');
    var open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    btn.textContent = open ? 'Show All Blocks' : 'Hide All Blocks';
}

function openMechModal(btn) {
    document.getElementById('modal-mech-id').value = btn.dataset.mid;
    document.getElementById('modal-mech-name').value = btn.dataset.mname;
    document.getElementById('modal-mech-nickname').value = btn.dataset.mnick;
    document.getElementById('modal-mech-quote').value = btn.dataset.mquote;
    document.getElementById('modal-mech-specialties').value = btn.dataset.mspec;
    document.getElementById('modal-mech-exp').value = btn.dataset.experience;
    renderVacations(parseInt(btn.dataset.mid));
    document.getElementById('mech-modal').classList.remove('hidden');
}

function closeMechModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('mech-modal').classList.add('hidden');
    }
}

function openScheduleModal(id, name) {
    document.getElementById('schedule-mech-id').value = id;
    document.getElementById('schedule-mech-name').textContent = 'Schedule — ' + name;

    var cbs = document.querySelectorAll('#schedule-form .sched-cb');
    cbs.forEach(function(cb) {
        cb.checked = false;
    });

    var sched = SCHEDULE_DATA[id] || {};
    cbs.forEach(function(cb) {
        var dow = parseInt(cb.dataset.dow);
        var slot = parseInt(cb.dataset.slot);
        if (sched[dow] && sched[dow][slot]) {
            cb.checked = true;
        }
    });

    document.getElementById('schedule-modal').classList.remove('hidden');
}

function toggleMechSwapBtn(sel) {
    var btn = sel.closest('form').querySelector('[name="update_mechanic"]');
    if (parseInt(sel.value) === parseInt(sel.dataset.current)) {
        btn.disabled = true;
        btn.classList.add('disabled');
    } else {
        btn.disabled = false;
        btn.classList.remove('disabled');
    }
}

function closeScheduleModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('schedule-modal').classList.add('hidden');
    }
}

function showCancelModal(id) {
    document.getElementById('cancel-confirm-link').href = '?cancel=' + id;
    document.getElementById('cancel-modal').classList.remove('hidden');
}
function closeCancelModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('cancel-modal').classList.add('hidden');
    }
}
function showFireModal(id, name) {
    document.getElementById('fire-confirm-link').href = '?fire=' + id;
    document.getElementById('fire-modal-title').textContent = 'Fire ' + name + '?';
    document.getElementById('fire-modal').classList.remove('hidden');
}
function closeFireModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('fire-modal').classList.add('hidden');
    }
}
function showRemoveModal(id) {
    document.getElementById('remove-confirm-link').href = '?remove=' + id;
    document.getElementById('remove-modal').classList.remove('hidden');
}
function closeRemoveModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('remove-modal').classList.add('hidden');
    }
}
function showUnblockModal(id, name, date) {
    document.getElementById('unblock-confirm-link').href = '?unblock=' + id;
    document.getElementById('unblock-msg').textContent = 'Unblock ' + name + ' on ' + date + '?';
    document.getElementById('unblock-modal').classList.remove('hidden');
}
function closeUnblockModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('unblock-modal').classList.add('hidden');
    }
}

function renderVacations(id) {
    var list = document.getElementById('vacation-list');
    list.innerHTML = '';
    var vacs = VACATION_DATA[id] || [];
    if (vacs.length === 0) {
        list.innerHTML = '<p style="font-size:0.85rem;opacity:0.7;">No vacations scheduled.</p>';
    } else {
        var html = '';
        vacs.forEach(function(v) {
            var label = v.start_date + ' to ' + v.end_date;
            if (v.reason) label += ' (' + v.reason + ')';
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;padding:4px 8px;background:var(--cyan-light);border:2px solid var(--ink);font-size:0.8rem;">';
            html += '<span style="flex:1;">' + label + '</span>';
            html += '<a href="?remove_vacation=' + v.id + '" class="btn btn-sm btn-rust" style="font-size:0.65rem;padding:2px 8px;">End</a>';
            html += '</div>';
        });
        list.innerHTML = html;
    }
}

function addVacation() {
    var id = document.getElementById('modal-mech-id').value;
    var start = document.getElementById('vac-start').value;
    var end = document.getElementById('vac-end').value;
    if (!id || !start || !end) return;
    if (start > end) { alert('Start date must be before end date.'); return; }
    var reason = document.getElementById('vac-reason').value;
    var f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    f.innerHTML = '<input name="add_vacation" value="1">'
        + '<input name="vac_mech_id" value="' + id + '">'
        + '<input name="vac_start" value="' + start + '">'
        + '<input name="vac_end" value="' + end + '">'
        + '<input name="vac_reason" value="' + reason.replace(/"/g,'&quot;') + '">';
    document.body.appendChild(f);
    f.submit();
}

function closeConflictModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('conflict-modal').classList.add('hidden');
    }
}

function closeMsgModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('msg-modal').classList.add('hidden');
    }
}
</script>
<script src="datepicker.js"></script>
</body>
</html>
