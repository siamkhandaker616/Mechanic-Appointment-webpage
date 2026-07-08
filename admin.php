<?php
session_start();
require_once __DIR__ . '/functions.php';

$msg = '';
$msgType = '';
$conflictList = [];

// --- GET action handlers (redirect after) ---

if (isset($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    if (cancelAppointment($id)) {
        $_SESSION['flash_msg'] = 'Appointment cancelled.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg'] = 'Could not cancel — appointment may already be in progress or completed.';
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: admin.php');
    exit;
}

if (isset($_GET['fire'])) {
    fireMechanic((int)$_GET['fire']);
    $_SESSION['flash_msg'] = 'Mechanic deactivated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: admin.php');
    exit;
}

if (isset($_GET['restore'])) {
    restoreMechanic((int)$_GET['restore']);
    $_SESSION['flash_msg'] = 'Mechanic restored.';
    $_SESSION['flash_type'] = 'success';
    header('Location: admin.php');
    exit;
}

// --- POST action handlers (redirect after) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_date']) && isset($_POST['appointment_id'])) {
        $id = (int)$_POST['appointment_id'];
        $newDate = $_POST['new_date'] ?? '';
        $newSlot = (int)($_POST['new_slot'] ?? 0);
        if (updateAppointmentDate($id, $newDate, $newSlot)) {
            $_SESSION['flash_msg'] = 'Appointment date updated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg'] = 'Could not update date — slot may no longer be available.';
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_mechanic']) && isset($_POST['appointment_id'])) {
        $id = (int)$_POST['appointment_id'];
        $newMech = (int)$_POST['new_mechanic'];
        if (updateAppointmentMechanic($id, $newMech)) {
            $_SESSION['flash_msg'] = 'Appointment mechanic updated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg'] = 'Could not update mechanic — they may be fully booked.';
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['sim_toggle']) && !isset($_POST['toggle_sim'])) {
        $db = getDB();
        $useSim = (int)(isset($_POST['use_sim']));
        $stmt = $db->prepare("UPDATE sim_config SET use_simulated_time = ? WHERE id = 1");
        $stmt->execute([$useSim]);
        $_SESSION['flash_msg'] = $useSim ? 'Simulated time activated.' : 'Real time restored.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
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
        $_SESSION['flash_msg'] = 'Simulated time updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_mechanic'])) {
        $name = trim($_POST['mech_name'] ?? '');
        $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
        $specialties = trim($_POST['mech_specialties'] ?? '');
        $years = (int)($_POST['mech_years'] ?? 0);
        if ($name) {
            addMechanic($name, $nickname, $specialties, $years);
            $_SESSION['flash_msg'] = 'Mechanic added.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_mechanic_info'])) {
        $id = (int)$_POST['mech_id'];
        $name = trim($_POST['mech_name'] ?? '');
        $nickname = trim($_POST['mech_nickname'] ?? '') ?: null;
        $specialties = trim($_POST['mech_specialties'] ?? '');
        $years = (int)($_POST['mech_years'] ?? 0);
        if ($name && $id) {
            updateMechanic($id, $name, $nickname, $specialties, $years);
            $_SESSION['flash_msg'] = 'Mechanic updated.';
            $_SESSION['flash_type'] = 'success';
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
        $_SESSION['flash_msg'] = 'Schedule updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['override_slot'])) {
        $db = getDB();
        $mechId = (int)$_POST['override_mechanic'];
        $date = $_POST['override_date'];
        $slots = $_POST['slots'] ?? [];
        $reason = trim($_POST['reason'] ?? '');

        $stmt = $db->prepare("SELECT a.id, a.slot_index, c.name AS client_name FROM appointments a JOIN clients c ON c.id = a.client_id WHERE a.mechanic_id = ? AND a.appointment_date = ? AND a.status NOT IN ('cancelled','completed')");
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
                $slotFlags['slot_' . ($i + 1)] = in_array($i, $slots) ? 1 : 0;
            }
            $stmt = $db->prepare("INSERT INTO mechanic_overrides (mechanic_id, override_date, slot_1, slot_2, slot_3, slot_4, reason)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE slot_1=VALUES(slot_1), slot_2=VALUES(slot_2), slot_3=VALUES(slot_3), slot_4=VALUES(slot_4), reason=VALUES(reason)");
            $stmt->execute([$mechId, $date, $slotFlags['slot_1'], $slotFlags['slot_2'], $slotFlags['slot_3'], $slotFlags['slot_4'], $reason]);
            $_SESSION['flash_msg'] = 'Schedule override saved.';
            $_SESSION['flash_type'] = 'success';
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
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="omg omg-left">POW</div>
<div class="omg omg-right">ZOWIE</div>
<div class="omg omg-bot">BAM</div>

<header>
    <img src="images/icons/tagline.png" alt="Mayhem Mobility Tagline" class="tagline">
    <h1>Mayhem Mobility</h1>
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
        <span>
            <strong>Current Time:</strong>
            <?= htmlspecialchars($effectiveTime->format('Y-m-d H:i')) ?>
            <?php if ($useSim): ?>
            <em>(simulated)</em>
            <?php endif; ?>
        </span>
        <form method="post" class="inline-form">
            <input type="checkbox" name="use_sim" value="1" id="use-sim" <?= $useSim ? 'checked' : '' ?> onchange="this.form.submit()">
            <input type="hidden" name="sim_toggle" value="1">
            <label for="use-sim">Use simulated time</label>
            <input type="datetime-local" name="sim_datetime" value="<?= $simDt ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($simDt))) : '' ?>">
            <button type="submit" name="toggle_sim" value="1" class="btn btn-sm">Set</button>
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
                <td><?= htmlspecialchars($a['phone']) ?></td>
                <td><?= htmlspecialchars($a['license_no']) ?><br><small><?= htmlspecialchars($a['model']) ?></small></td>
                <td><?= htmlspecialchars($a['appointment_date']) ?></td>
                <td><?= htmlspecialchars($SLOT_LABELS[(int)$a['slot_index']] ?? '') ?></td>
                <td><?= htmlspecialchars($a['mechanic_name']) ?></td>
                <td><span class="status-badge status-<?= htmlspecialchars($a['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $a['status'])) ?></span></td>
                <td>
                    <?php if ($a['status'] === 'scheduled'): ?>
                    <button class="btn btn-sm btn-outline" onclick="toggleEdit(<?= $a['id'] ?>)">Edit</button>
                    <a href="?cancel=<?= $a['id'] ?>" class="btn btn-sm btn-rust" onclick="return confirm('Cancel this appointment?')">Cancel</a>
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
                            <select name="new_mechanic">
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
    </div>
</div>

<div class="panel">
    <div class="burst burst-right">LOCK!</div>
    <h2>Schedule Override</h2>
    <p style="margin-bottom:12px;font-size:0.9rem;">Block specific slots for a mechanic on a given date (days off, early leave).</p>
    <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
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
            <label>Blocked Slots</label>
            <div style="display:flex;gap:6px;">
                <?php for ($i = 0; $i < SLOT_COUNT; $i++): ?>
                <label style="font-size:0.75rem;border:2px solid var(--ink);padding:6px;cursor:pointer;">
                    <input type="checkbox" name="slots[]" value="<?= $i ?>"> <?= $i + 1 ?>
                </label>
                <?php endfor; ?>
            </div>
        </div>
        <div>
            <label>Reason (optional)</label>
            <input type="text" name="reason" placeholder="e.g. sick day">
        </div>
        <button type="submit" name="override_slot" class="btn btn-sm btn-rust">Save Override</button>
    </form>
</div>

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
                <th>Years</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allMechanics as $m): ?>
            <tr>
                <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                <td><?= htmlspecialchars($m['nickname'] ?? '—') ?></td>
                <td><?= htmlspecialchars($m['specialties'] ?? '—') ?></td>
                <td><?= (int)$m['years_experience'] ?></td>
                <td><span class="status-badge <?= $m['is_active'] ? 'status-scheduled' : 'status-cancelled' ?>"><?= $m['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-sm btn-outline" onclick="openMechModal(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($m['nickname'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($m['specialties'] ?? '', ENT_QUOTES) ?>', <?= (int)$m['years_experience'] ?>)">Edit</button>
                    <button class="btn btn-sm btn-outline" onclick="openScheduleModal(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')">Schedule</button>
                    <?php if ($m['is_active']): ?>
                    <a href="?fire=<?= $m['id'] ?>" class="btn btn-sm btn-rust" onclick="return confirm('Fire <?= htmlspecialchars($m['name']) ?>?')">Fire</a>
                    <?php else: ?>
                    <a href="?restore=<?= $m['id'] ?>" class="btn btn-sm btn-outline">Restore</a>
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
                <label>Specialties</label>
                <input type="text" name="mech_specialties" placeholder="e.g. Engine, Transmission">
            </div>
            <div>
                <label>Years Exp.</label>
                <input type="number" name="mech_years" value="0" style="width:80px;">
            </div>
            <button type="submit" name="add_mechanic" class="btn btn-sm">Hire</button>
        </form>
    </details>
</div>

</div>

<div class="modal-overlay hidden" id="mech-modal" onclick="closeMechModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="burst burst-right">EDIT!</div>
        <h2>Edit Mechanic</h2>
        <form method="post" id="mech-modal-form">
            <input type="hidden" name="mech_id" id="modal-mech-id">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="mech_name" id="modal-mech-name" readonly style="background:var(--paper);cursor:not-allowed;">
            </div>
            <div class="form-group">
                <label>Nickname</label>
                <input type="text" name="mech_nickname" id="modal-mech-nickname">
            </div>
            <div class="form-group">
                <label>Specialties</label>
                <input type="text" name="mech_specialties" id="modal-mech-specialties" placeholder="e.g. Engine, Transmission">
            </div>
            <div class="form-group">
                <label>Years Experience</label>
                <input type="number" name="mech_years" id="modal-mech-years" style="width:100px;background:var(--paper);cursor:not-allowed;" readonly>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px;">
                <button type="submit" name="update_mechanic_info" class="btn btn-sm">Save</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('mech-modal').classList.add('hidden')">Cancel</button>
            </div>
        </form>
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
                    <?php $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
                    <?php for ($d = 0; $d <= 6; $d++): ?>
                    <tr>
                        <td><strong><?= $dayNames[$d] ?></strong></td>
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

function toggleEdit(id) {
    var row = document.getElementById('edit-' + id);
    row.classList.toggle('show');
}

function openMechModal(id, name, nickname, specialties, years) {
    document.getElementById('modal-mech-id').value = id;
    document.getElementById('modal-mech-name').value = name;
    document.getElementById('modal-mech-nickname').value = nickname;
    document.getElementById('modal-mech-specialties').value = specialties;
    document.getElementById('modal-mech-years').value = years;
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

function closeScheduleModal(event) {
    if (event.target === event.currentTarget) {
        document.getElementById('schedule-modal').classList.add('hidden');
    }
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
