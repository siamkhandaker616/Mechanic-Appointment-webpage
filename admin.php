<?php
/* === SETUP === */
session_start();
require_once __DIR__ . '/functions.php';

$msg = '';
$msgType = '';
$conflictList = [];

/* === AJAX PASSWORD VERIFICATION === */
if (isset($_POST['verify_pw'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => ($_POST['admin_pw'] ?? '') === ADMIN_PW]);
    exit;
}

/* === GET ACTION HANDLERS === */

if (isset($_GET['remove_all_cancelled'])) handleRemoveAllCancelled();
if (isset($_GET['rebook']))              handleReBook();
if (isset($_GET['rebook_confirm']))      handleReBookConfirm();
if (isset($_GET['remove_all_completed'])) handleRemoveAllCompleted();
if (isset($_GET['remove']))             handleRemove();
if (isset($_GET['cancel']))             handleCancel();
if (isset($_GET['fire']))               handleFire();
if (isset($_GET['restore']))            handleRestore();
if (isset($_GET['remove_mechanic']))    handleRemoveMechanic();
if (isset($_GET['unblock']))            handleUnblock();
if (isset($_GET['remove_vacation']))    handleRemoveVacation();

/* === POST ACTION HANDLERS === */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_appointment'])) handleUpdateAppointment();
    if (isset($_POST['sim_toggle']) && !isset($_POST['toggle_sim'])) handleSimToggle();
    if (isset($_POST['toggle_sim']))        handleToggleSim();
    if (isset($_POST['add_mechanic']))      handleAddMechanic();
    if (isset($_POST['update_mechanic_info'])) handleUpdateMechanicInfo();
    if (isset($_POST['update_schedule']) && isset($_POST['mech_id'])) handleUpdateSchedule();
    if (isset($_POST['add_vacation']))      handleAddVacation();
    if (isset($_POST['override_slot']))     handleOverrideSlot();
}

/* === FLASH MESSAGES & DATA FETCHING === */

$msg = $_SESSION['flash_msg'] ?? $_GET['msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? ($_GET['msg'] ?? '' ? 'success' : '');
$conflictList = $_SESSION['flash_conflicts'] ?? [];
$pendingRebook = $_SESSION['pending_rebook'] ?? null;
unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['flash_conflicts'], $_SESSION['pending_rebook']);

advanceAppointmentStatuses();

$appointments = getAppointments();
$cancelledCount = 0;
$completedCount = 0;
foreach ($appointments as $appt) {
    if ($appt['status'] === STATUS_CANCELLED) $cancelledCount++;
    if ($appt['status'] === STATUS_COMPLETED) $completedCount++;
}
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
<!-- === HTML === -->
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
    <h1>Mayhem Mobility <img src="https://cdn.statically.io/gh/siamkhandaker616/Mechanic-Appointment-webpage/main/images/icons/tagline.png?v=22" alt="Mayhem Mobility Tagline" class="tagline"></h1>
    <p class="subtitle">Admin Panel</p>
    <div class="admin-nav">
        <a href="index.php" class="btn btn-sm btn-outline">Booking Page</a>
        <a href="admin.php" class="btn btn-sm btn-outline">Refresh</a>
    </div>
    <div class="settings-gear">
        <img src="images/doodles/gear.svg" alt="Settings" id="settings-btn">
        <div class="settings-dropdown hidden" id="settings-dropdown">
            <div class="settings-header">Disable —</div>
            <label><input type="checkbox" id="spotlight-toggle" class="custom-checkbox"> Spotlight of Shame</label>
            <label><input type="checkbox" id="doodles-toggle" class="custom-checkbox"> decorative doodles</label>
            <label><input type="checkbox" id="bg-toggle" class="custom-checkbox"> background</label>
            <label><input type="checkbox" id="animations-toggle" class="custom-checkbox"> animations</label>
        </div>
    </div>
</header>
<script>document.documentElement.style.setProperty('--header-h', document.querySelector('header').offsetHeight + 'px');</script>

<div class="container">

<!-- === SIMULATED TIME === -->
<div class="panel">
    <div class="burst burst-right">TIME!</div>
    <h2>Simulated Time</h2>
    <div class="sim-bar <?= $useSim ? 'active' : '' ?>">
        <form method="post" style="display:contents">
        <span>
            <strong>Current Time:</strong>
            <?= htmlspecialchars($effectiveTime->format('j M Y • H:i')) ?>
            <?php if ($useSim): ?>
            <em>(simulated)</em>
            <?php endif; ?>
        </span>
        <span class="sim-group">
            <input type="datetime-local" name="sim_datetime" data-placement="top" style="text-align:right" value="<?= $useSim && $simDt ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($simDt))) : '' ?>">
            <button type="submit" name="toggle_sim" value="1" class="btn btn-sm" <?= $useSim ? '' : 'disabled' ?>>Set</button>
        </span>
        <span class="sim-group">
            <input type="checkbox" name="use_sim" value="1" id="use-sim" class="custom-checkbox" <?= $useSim ? 'checked' : '' ?> onchange="this.form.submit()">
            <input type="hidden" name="sim_toggle" value="1">
            <label for="use-sim"><pre style= "font-family:'Walter Turncoat';"> Use simulated time</pre></label>
        </span>
        </form>
    </div>
</div>

<!-- === ALL APPOINTMENTS === -->
<div class="panel">
    <img class="doodle doodle-speech-appt" id="speech-bubble" src="images/doodles/speech-bubble-1.svg" alt="">
    <div class="burst burst-right">GIGS!</div>
    <span style="display:inline-flex;align-items:center;">
        <h2 style="margin:0;">All Appointments</h2>
        <img src="images/doodles/magnifying-glass.svg"
             id="search-toggle"
             alt="Search filters"
             onmouseover="this.src='images/doodles/magnifying-glass-hover.svg'"
             onmouseout="this.src='images/doodles/magnifying-glass.svg'"
             onclick="openSearchModal()">
    </span>
    <div class="ov-scroll-x">
    <table id="appt-table">
        <thead>
            <tr>
                <th>Client</th>
                <th>Phone</th>
                <th>Car</th>
                <th>Date</th>
                <th>Slot</th>
                <th>Mechanic</th>
                <th class="th-center">Status</th>
                <th class="th-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($appointments)): ?>
            <tr><td colspan="8" style="text-align:center;font-style:italic;">No appointments yet.</td></tr>
            <?php else: ?>
            <?php $aRowNum = 0; ?>
            <?php foreach ($appointments as $a): $aRowNum++; ?>
            <tr class="<?= $aRowNum % 2 === 0 ? 'stripe-even' : '' ?>"
                data-name="<?= htmlspecialchars(strtolower($a['client_name'])) ?>"
                data-phone="<?= htmlspecialchars($a['phone']) ?>"
                data-car="<?= htmlspecialchars(strtolower($a['license_no'] . ' ' . ($a['model'] ?? ''))) ?>"
                data-status="<?= $a['status'] ?>"
                data-mechanic="<?= htmlspecialchars(strtolower($a['mechanic_name'])) ?>"
                data-date="<?= $a['appointment_date'] ?>">
                <td><strong><?= fmtNameTwoLines($a['client_name']) ?></strong></td>
                <td class="td-nowrap"><?= htmlspecialchars($a['phone']) ?></td>
                <td class="td-car"><?= htmlspecialchars($a['license_no']) ?><br><small><?= htmlspecialchars($a['model']) ?></small></td>
                <td class="td-nowrap"><?= htmlspecialchars(fmtDate($a['appointment_date'])) ?></td>
                <td><?= htmlspecialchars($SLOT_NAMES[(int)$a['slot_index']] ?? '') ?></td>
                <td><strong><?= fmtNameTwoLines($a['mechanic_name']) ?></strong></td>
                <td class="td-nowrap"><span class="status-badge status-<?= htmlspecialchars($a['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $a['status'])) ?></span></td>
                <td class="td-nowrap">
                    <?php if ($a['status'] === STATUS_SCHEDULED): ?>
                    <button class="btn btn-sm btn-outline" onclick="toggleEdit(<?= $a['id'] ?>, this)">Edit</button>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showCancelModal(<?= $a['id'] ?>)">Cancel</button>
                    <?php elseif ($a['status'] === STATUS_CANCELLED): ?>
                    <button type="button" class="btn btn-sm btn-jade" onclick="requirePw('?rebook=<?= $a['id'] ?>', false)">Rebook</button>
                    <button type="button" class="btn btn-sm btn-rust" onclick="showRemoveModal(<?= $a['id'] ?>)">Remove</button>
                    <?php else: ?>
                    <span style="display:block;text-align:center;font-size:0.8rem;color:#888;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="edit-row" id="edit-<?= $a['id'] ?>">
                <td colspan="8">
                    <div class="edit-inner">
                        <form method="post" class="inline-form" onsubmit="return requirePwForForm(this)">
                            <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                            <input type="date" name="new_date" value="<?= htmlspecialchars($a['appointment_date']) ?>" min="<?= date('Y-m-d') ?>" data-original-date="<?= htmlspecialchars($a['appointment_date']) ?>" onchange="toggleUpdateApptBtn(this)">
                             <select class="custom-select" name="new_slot" data-original-slot="<?= (int)$a['slot_index'] ?>" onchange="toggleUpdateApptBtn(this)">
                                <?php foreach ($SLOT_LABELS as $si => $sl): ?>
                                <option value="<?= $si ?>" <?= $si === (int)$a['slot_index'] ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                                <?php endforeach; ?>
                            </select>
                             <select class="custom-select" name="new_mechanic" data-original-mechanic="<?= (int)$a['mechanic_id'] ?>" onchange="toggleUpdateApptBtn(this)">
                                <?php foreach ($mechanicsForSelect as $mid => $mname): ?>
                                <option value="<?= $mid ?>" <?= $mid === (int)$a['mechanic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($mname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_appointment" class="btn btn-sm disabled" disabled>Update Appointment</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($cancelledCount > 0 || $completedCount > 0): ?>
    <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end;">
        <?php if ($cancelledCount > 0): ?>
        <a href="#" class="btn btn-sm btn-rust" onclick="requirePw('?remove_all_cancelled');return false;">Clear Cancelled</a>
        <?php endif; ?>
        <?php if ($completedCount > 0): ?>
        <a href="#" class="btn btn-sm btn-jade" onclick="requirePw('?remove_all_completed');return false;">Archive Completed</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- === SCHEDULE OVERRIDE === -->
<div class="panel">
    <div class="burst burst-right">LOCK!</div>
    <h2>Schedule Override</h2>
    <p style="margin-bottom:12px;font-size:0.9rem;">Block specific slots for a mechanic on a given date (days off, early leave).</p>
    <form method="post" class="col-form" onsubmit="return validateOverrideForm()">
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div>
                <label>Mechanic</label>
                <select class="custom-select" name="override_mechanic" onchange="clearOverrideError()">
                    <option value="">— Select —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date</label>
                <input type="date" name="override_date" onchange="clearOverrideError()">
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
                <input type="text" name="reason" placeholder="sick day" style="width:100%;max-width:526px;">
            </div>
        </div>
        <div style="display:flex;gap:12px;justify-content:space-between;position:relative;">
            <div id="override-error" class="field-error" style="display:none;position:absolute;bottom:100%;left:0;margin-bottom:4px;white-space:nowrap;"></div>
            <button type="submit" name="override_slot" class="btn btn-rust">Save Override</button>
            <?php if (!empty($overrides)): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleOverrides()" id="overrides-toggle">Show All Blocks</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (!empty($overrides)): ?>
<!-- === ACTIVE OVERRIDES === -->
<div class="panel" id="overrides-panel" style="display: none;">
    <div class="burst burst-right" style="background:var(--rust);">HELD!</div>
    <h2>Active Overrides</h2>
    <div class="ov-scroll-x">
    <table>
        <thead>
            <tr>
                <th>Mechanic</th>
                <th>Date</th>
                <th>Blocked Slots</th>
                <th class="th-center">Reason</th>
                <th class="th-center">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php $oRowNum = 0; ?>
            <?php foreach ($overrides as $o): $oRowNum++;
                $blocked = [];
                for ($i = 0; $i < SLOT_COUNT; $i++) {
                    $key = 'slot_' . ($i + 1);
                    if (!$o[$key]) {
                        $blocked[] = htmlspecialchars($SLOT_LABELS[$i] ?? "Slot " . ($i + 1));
                    }
                }
            ?>
            <tr class="<?= $oRowNum % 2 === 0 ? 'stripe-even' : '' ?>">
                <td><strong><?= fmtNameTwoLines($o['mechanic_name']) ?></strong></td>
                <td><?= htmlspecialchars(fmtDate($o['override_date'])) ?></td>
                <td style="font-size:0.85rem;"><?= $blocked ? implode('<br>', $blocked) : '<em>none</em>' ?></td>
                <td<?= $o['reason'] ? '' : ' style="text-align:center"' ?>><?= htmlspecialchars($o['reason'] ?: '—') ?></td>
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

<!-- === MECHANICS === -->
<div class="panel">
    <img class="doodle doodle-wrench-admin" src="images/doodles/wrench.svg" alt="">
    <div class="burst burst-right">HIRE!</div>
    <h2>Mechanics</h2>
    <div class="ov-scroll-x">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Nickname</th>
                <th>Specialties</th>
                <th>Exp</th>
                <th class="th-center">Status</th>
                <th class="th-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $mRowNum = 0; ?>
            <?php $_cancelCountStmt = getDB()->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND status = 'scheduled'"); ?>
            <?php foreach ($allMechanics as $m): $mRowNum++; ?>
            <?php $onLeave = $m['is_active'] && isMechanicOnVacation((int)$m['id'], date('Y-m-d')); ?>
            <tr class="<?= $mRowNum % 2 === 0 ? 'stripe-even' : '' ?>">
                <td><strong><?= fmtNameTwoLines($m['name']) ?></strong></td>
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
                <td class="td-nowrap">
                    <?php if ($m['is_active']): ?>
                    <button class="btn btn-sm btn-outline" onclick="openMechModal(this)" data-mid="<?= $m['id'] ?>" data-mname="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>" data-mnick="<?= htmlspecialchars($m['nickname'] ?? '', ENT_QUOTES) ?>" data-mquote="<?= htmlspecialchars($m['quote'] ?? '', ENT_QUOTES) ?>" data-mspec="<?= htmlspecialchars($m['specialties'] ?? '', ENT_QUOTES) ?>" data-experience="<?= (int)$m['experience'] ?>">Edit</button>
                    <?php $_cancelCountStmt->execute([$m['id']]); $_cc = (int)$_cancelCountStmt->fetchColumn(); ?>
                    <button type="button" class="btn btn-sm btn-rust" data-bookings="<?= $_cc ?>" onclick="showFireModal(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', this.dataset.bookings)">Fire</button>
                    <?php else: ?>
                    <a href="?restore=<?= $m['id'] ?>" class="btn btn-sm btn-jade">Rehire</a>
                    <a href="#" class="btn btn-sm btn-rust" onclick="requirePw('?remove_mechanic=<?= $m['id'] ?>');return false;">Remove</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <details class="recruitment-details">
        <summary>Register New Mechanic</summary>
        <form method="post" style="margin-top:24px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;" onsubmit="return validateRecruitForm()" novalidate>
            <div>
                <label>Name</label>
                <input type="text" name="mech_name" placeholder="John Doe">
            </div>
            <div>
                <label>Nickname</label>
                <input type="text" name="mech_nickname" placeholder="Sparky">
            </div>
            <div>
                <label>Specialties</label>
                <input type="text" name="mech_specialties" placeholder="Engine, Transmission">
            </div>
            <div>
                <label>Experience</label>
                <input type="number" name="mech_years" value="0" style="width:80px;" data-stepper="edit">
            </div>
            <button type="submit" name="add_mechanic" class="btn btn-sm btn-recruit" style="margin-left:auto;">Recruit!</button>
        </form>
    </details>
</div>

</div>

<!-- === MODALS === -->

<div class="modal-overlay hidden" id="mech-modal" onclick="closeMechModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:650px;">
        <div class="burst burst-right">EDIT!</div>
        <h2>Edit Mechanic</h2>
        <div style="display:flex;gap:12px;align-items:stretch;">
            <div class="flex-1">
                <form method="post" id="mech-modal-form" onsubmit="return checkSimGuard()">
                    <input type="hidden" name="mech_id" id="modal-mech-id">
                    <input type="hidden" name="_new_hire_name" id="new-hire-name" value="<?= htmlspecialchars($_GET['hire_name'] ?? '', ENT_QUOTES) ?>">
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" class="flex-1">
                            <label>Name</label>
                            <input type="text" name="mech_name" id="modal-mech-name" readonly style="width:100%;background:var(--paper);cursor:pointer;" onclick="requirePwForField('modal-mech-name')">
                        </div>
                        <div class="form-group" class="flex-1">
                            <label>Nickname</label>
                            <input type="text" name="mech_nickname" id="modal-mech-nickname" style="width:100%;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Catchphrase</label>
                        <input type="text" name="mech_quote" id="modal-mech-quote" placeholder="I'll fix it fast!">
                    </div>
                    <div class="form-group">
                        <label>Specialties</label>
                        <input type="text" name="mech_specialties" id="modal-mech-specialties" placeholder="Engine, Transmission">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                        <label>Experience</label>
                        <input type="number" name="mech_years" id="modal-mech-exp" style="width:65px;background:var(--paper);cursor:pointer;" readonly onclick="requirePwForField('modal-mech-exp')" data-stepper="edit">
                        <button type="button" class="btn btn-sm" style="margin-left:auto;" onclick="openScheduleModal(document.getElementById('modal-mech-id').value, document.getElementById('modal-mech-name').value)">Schedule</button>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;">
                        <button type="submit" name="update_mechanic_info" class="btn btn-sm">Save</button>
                        <button type="button" class="btn btn-sm btn-rust" onclick="closeMechModal(event)">Cancel</button>
                    </div>
                </form>
            </div>
            <div style="border-left:2px dashed var(--teal);align-self:stretch;"></div>
            <div class="flex-1">
                <h3 style="font-family:var(--font-sub);font-size:0.9rem;text-transform:uppercase;margin-bottom:8px;">Vacations</h3>
                <div id="vacation-list" style="margin-bottom:10px;"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                    <div>
                        <label class="vac-label">Start</label>
                        <input type="date" id="vac-start" data-placement="top" style="width:auto;font-size:0.8rem;">
                    </div>
                    <div>
                        <label class="vac-label">End</label>
                        <input type="date" id="vac-end" data-placement="top" style="width:auto;font-size:0.8rem;">
                    </div>
                    <div>
                        <label class="vac-label">Reason (Optional)</label>
                        <textarea id="vac-reason" placeholder="Outside commitments" rows="1" style="width:200px;font-size:0.8rem;resize:none;"></textarea>
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
        <form method="post" id="schedule-form" onsubmit="return checkSimGuard()">
            <input type="hidden" name="mech_id" id="schedule-mech-id">
            <input type="hidden" name="mech_name" id="sched-mech-name">
            <input type="hidden" name="mech_nickname" id="sched-mech-nickname">
            <input type="hidden" name="mech_quote" id="sched-mech-quote">
            <input type="hidden" name="mech_specialties" id="sched-mech-specialties">
            <input type="hidden" name="mech_years" id="sched-mech-years">
            <input type="hidden" name="_new_hire_name" value="<?= htmlspecialchars($_GET['hire_name'] ?? '', ENT_QUOTES) ?>">
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
                <button type="button" class="btn btn-sm btn-rust" onclick="document.getElementById('schedule-modal').classList.add('hidden')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($conflictList)): ?>
<div class="modal-overlay" id="conflict-modal" onclick="closeConflictModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:480px;">
        <button type="button" class="modal-close" onclick="document.getElementById('conflict-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-right" style="background:var(--pink);font-size:0.6rem;">BLOCKED!</div>
        <h2>Cancel These First</h2>
        <p>The following appointments occupy slots you tried to override:</p>
        <ul style="margin:16px 0;padding-left:20px;">
            <?php foreach ($conflictList as $name): ?>
            <li style="margin-bottom:6px;"><?= $name ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="font-size:0.85rem;color:var(--pink);">Cancel them from the table below, then try again.</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('conflict-modal').classList.add('hidden')">Got it</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="cancel-modal" onclick="closeCancelModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('cancel-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">WHOA!</div>
        <h2 class="modal-h2">Cancel Appointment ?</h2>
        <p class="modal-body-p">This can't be undone. Are you sure?</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-rust" onclick="requirePw(_pendingAction, false)">Yes, Cancel</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('cancel-modal').classList.add('hidden')">Forget it</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="fire-modal" onclick="closeFireModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('fire-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">FIRED!</div>
        <h2 class="modal-h2" id="fire-modal-title">Fire Mechanic?</h2>
        <p class="modal-body-p" id="fire-modal-msg">They'll be retired and won't appear for new bookings.</p>
        <p id="fire-modal-cancel-count" style="margin:0 0 16px;font-weight:bold;color:var(--dark);display:none;"></p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-rust" onclick="requirePw(_pendingAction, false)">Yes, Fire</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('fire-modal').classList.add('hidden')">Forget it</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="rebook-mech-modal" onclick="if(event.target===event.currentTarget)this.classList.add('hidden')">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('rebook-mech-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">NOPE!</div>
        <h2 style="margin-top:30px;margin-bottom:50px;" id="rebook-mech-heading"></h2>
        <div style="display:flex;gap:16px;align-items:center;">
            <div class="flex-1">
                <p style="margin:0 0 6px;font-size: 0.85rem">Pick a new mechanic for this job:</p>
                <select id="rebook-mech-select" class="custom-select fire-swap">
                    <?php foreach ($mechanicsForSelect as $mid => $mname): ?>
                    <option value="<?= $mid ?>"><?= htmlspecialchars($mname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('rebook-mech-modal').classList.add('hidden')">Forget it</button>
                <button type="button" class="btn btn-sm btn-jade" id="rebook-confirm-btn">Reassign</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="sim-block-modal" onclick="closeSimBlockModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="closeSimBlockModal()">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">NOPE!</div>
        <h2 class="modal-h2">Sim Mode Active</h2>
        <p class="modal-body-p">Sim mode is active — you're playing with fake time, not making real decisions. Exit sim mode first.</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink btn-outline" onclick="closeSimBlockModal()">OK</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="remove-modal" onclick="closeRemoveModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('remove-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">GONE!</div>
        <h2 class="modal-h2">Remove Appointment?</h2>
        <p class="modal-body-p">This permanently deletes the record. Are you sure?</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-rust" onclick="requirePw(_pendingAction)">Yes, Remove</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('remove-modal').classList.add('hidden')">Forget it</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="unblock-modal" onclick="closeUnblockModal(event)">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('unblock-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">FREE!</div>
        <h2 class="modal-h2">Remove Override?</h2>
        <p class="modal-body-p" id="unblock-msg">This will unblock the slots for this date.</p>
        <div class="modal-btn-row">
            <a href="#" id="unblock-confirm-link" class="btn btn-sm btn-rust">Yes, Unblock</a>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('unblock-modal').classList.add('hidden')">Forget it</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="vac-limit-modal" onclick="if(event.target===event.currentTarget)this.classList.add('hidden')">
    <div class="modal-box msg-box msg-error">
        <button type="button" class="modal-close" onclick="document.getElementById('vac-limit-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" style="background:var(--pink);">NICE</br>TRY!</div>
        <h2 style="margin-top:30px;">Maximum Vacations Reached</h2>
        <p class="modal-body-p">A mechanic can only have <strong>3 active vacations</strong> at a time. Let them actually work once in a while!</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('vac-limit-modal').classList.add('hidden')">Fine!</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="pw-modal" onclick="closePwModal(event)">
    <div class="modal-box" style="max-width:380px;" onclick="event.stopPropagation()">
        <div class="burst burst-right" style="font-size:0.6rem;">LOCKED!</div>
        <h2>Enter Admin Password</h2>
        <p style="margin:8px 0 16px;font-size:0.85rem;">This action requires admin authorization.</p>
        <div style="position:relative;">
            <input type="password" id="admin-pw-input" placeholder="Password" style="width:100%;font-size:1rem;padding-right:44px;">
            <button type="button" id="pw-toggle" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;line-height:1;" onclick="togglePwVisibility()">
                <img src="images/doodles/eye-closed.svg" alt="Show password" style="display:block;">
            </button>
        </div>
        <p id="pw-error" style="color:var(--rust);font-size:0.8rem;margin-top:6px;display:none;">Incorrect password.</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink" onclick="confirmPw()">Confirm</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="closePwModal()">Cancel</button>
        </div>
    </div>
</div>

<?php if ($msg): ?>
<div class="modal-overlay" id="msg-modal" onclick="closeMsgModal(event)">
    <div class="modal-box msg-box msg-<?= htmlspecialchars($msgType) ?>">
        <button type="button" class="modal-close" onclick="document.getElementById('msg-modal').classList.add('hidden')">&times;</button>
        <div class="msg-content"><?= htmlspecialchars($msg) ?></div>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink btn-outline" onclick="document.getElementById('msg-modal').classList.add('hidden')">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="search-modal" onclick="closeSearchModal(event)">
    <div class="modal-box" style="max-width:580px;" onclick="event.stopPropagation()">
        <button type="button" class="modal-close" onclick="closeSearchModal()">&times;</button>
        <div class="burst burst-right">FIND!</div>
        <h2>Filter Appointments</h2>
        <div class="row" style="align-items:flex-start;">
            <div class="col col-form">
                <input type="text" id="filter-name" placeholder="Name" oninput="filterAppTable()" style="width:100%;background:var(--paper);">
                <input type="text" id="filter-phone" placeholder="Phone" oninput="filterAppTable()" style="width:100%;background:var(--paper);">
                <input type="text" id="filter-car" placeholder="Car" oninput="filterAppTable()" style="width:100%;background:var(--paper);">
            </div>
            <div class="col col-form">
                <select id="filter-status" class="custom-select" onchange="filterAppTable()">
                    <option value="">All Status</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <div style="height:36px;"></div>
                <select id="filter-mechanic" class="custom-select" onchange="filterAppTable()">
                    <option value="">All Mechanics</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= htmlspecialchars($m['name']) ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="margin-top:12px;">
            <label style="font-family:var(--font-hand);font-size:0.85rem;">Date range:</label>
            <div style="display:flex;gap:8px;margin-top:4px;">
                <input type="date" id="filter-date-from" onchange="filterAppTable()" class="flex-1">
                <input type="date" id="filter-date-to" onchange="filterAppTable()" class="flex-1">
            </div>
        </div>
        <div style="display:flex;gap:12px;margin-top:20px;justify-content:space-between;">
            <button type="button" class="btn btn-sm btn-pink" onclick="clearFilters()">Clear</button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-sm btn-rust" onclick="closeSearchModal()">Cancel</button>
                <button type="button" class="btn btn-sm btn-jade" onclick="closeSearchModal()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- === INLINE SCRIPT === -->
<script src="custom-select.js"></script>
<script>
<?php if (isset($_GET['new_mechanic'])): 
    $__nmId = (int)$_GET['new_mechanic'];
    $__nm = null;
    foreach ($allMechanics as $__m) { if ((int)$__m['id'] === $__nmId) { $__nm = $__m; break; } }
    if ($__nm):
?>
window._newHireName = <?= json_encode($_GET['hire_name'] ?? '') ?>;
window.addEventListener('DOMContentLoaded', function() {
    openMechModalById(
        <?= $__nm['id'] ?>,
        <?= json_encode($__nm['name']) ?>,
        <?= json_encode($__nm['nickname'] ?? '') ?>,
        <?= json_encode($__nm['quote'] ?? '') ?>,
        <?= json_encode($__nm['specialties'] ?? '') ?>,
        <?= (int)$__nm['experience'] ?>
    );
});
<?php endif; endif; ?>
<?php if (isset($_GET['rebook_pick_mechanic']) && $pendingRebook): ?>
window.addEventListener('DOMContentLoaded', function() {
    document.getElementById('rebook-mech-heading').textContent = <?= json_encode($pendingRebook['old_first_name']) ?> + " doesn't work here anymore";
    document.getElementById('rebook-mech-modal').classList.remove('hidden');
    document.getElementById('rebook-confirm-btn').addEventListener('click', function() {
        var mech = document.getElementById('rebook-mech-select').value;
        window.location.href = '?rebook_confirm=<?= $pendingRebook['id'] ?>&new_mech=' + mech;
    });
});
<?php endif; ?>
var TODAY = '<?= getEffectiveTime()->format('Y-m-d') ?>';
var EFFECTIVE_DATE = TODAY;
var SCHEDULE_DATA = <?= json_encode($scheduleData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var VACATION_DATA = <?= json_encode($vacationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var SIM_MODE_ACTIVE = <?= json_encode($useSim) ?>;
</script>
<script src="script.js"></script>
<script src="datepicker.js"></script>
</body>
</html>
