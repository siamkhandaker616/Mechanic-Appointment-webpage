<?php
/* === SETUP === */
session_start();
require_once __DIR__ . '/functions.php';

/* === AJAX PASSWORD VERIFICATION === */
if (isset($_POST['verify_pw'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => ($_POST['admin_pw'] ?? '') === ADMIN_PW]);
    exit;
}

/* === FLASH MESSAGES === */
$flashMsg = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
$savedPost = $_SESSION['booking_post'] ?? [];
unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['booking_post']);

$mechanics = getMechanics();
$mechSchedules = [];
$mechVacations = [];
foreach ($mechanics as $m) {
    $mechSchedules[$m['id']] = getMechanicSchedule((int)$m['id']);
    $mechVacations[$m['id']] = getMechanicVacations((int)$m['id']);
}
$confirmed = null;

/* === FORM HANDLING === */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_booking'])) handleEditBooking();
    $errors = validateAppointmentInput($_POST);

    if (empty($errors)) {
        $phone = preg_replace('/[^\d]/', '', $_POST['phone']);
        $clientId = findOrCreateClient(trim($_POST['name']), $phone, trim($_POST['address']));
        $carId = findOrCreateCar($clientId, strtoupper(trim($_POST['license_no'])), strtoupper(trim($_POST['engine_no'])), trim($_POST['car_model'] ?? ''));

        if (isCarBookedOnDate($carId, $_POST['date'])) {
            $errors[] = 'This car already has an appointment on this date.';
        } elseif (!isSlotAvailable((int)$_POST['mechanic_id'], $_POST['date'], (int)$_POST['slot_index'])) {
            $errors[] = 'Sorry, that slot was just taken. Pick another slot or mechanic above.';
        } else {
            createAppointment($clientId, $carId, (int)$_POST['mechanic_id'], $_POST['date'], (int)$_POST['slot_index']);
            $_SESSION['confirmed'] = [
                'date' => $_POST['date'],
                'slot_index' => (int)$_POST['slot_index'],
                'mechanic_id' => (int)$_POST['mechanic_id'],
            ];
            header('Location: index.php?confirmed=1');
            exit;
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash_msg'] = implode(' ', $errors);
        $_SESSION['flash_type'] = 'error';
        $_SESSION['booking_post'] = $_POST;
        header('Location: index.php');
        exit;
    }
}

if (isset($_GET['confirmed'])) {
    $confirmed = $_SESSION['confirmed'] ?? null;
    unset($_SESSION['confirmed']);
}

$selectedMechId = (int)($savedPost['mechanic_id'] ?? ($confirmed['mechanic_id'] ?? 0));
$selectedDate = $savedPost['date'] ?? ($confirmed['date'] ?? '');
$selectedSlot = $savedPost['slot_index'] ?? ($confirmed['slot_index'] ?? '');
?>
<!-- === HTML === -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Mayhem Mobility — Book Your Appointment</title>
<link rel="preload" href="fonts/Bangers.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/WalterTurncoat-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="style.css?v=<?= time() ?>">

</head>
<body>

<div class="omg omg-left">VROOM</div>
<div class="omg omg-right">KAPOW</div>
<div class="omg omg-bot">CLICK</div>

<header>
    <h1>Mayhem Mobility <img src="https://cdn.statically.io/gh/siamkhandaker616/Mechanic-Appointment-webpage/main/images/icons/tagline.png" alt="Mayhem Mobility Tagline" class="tagline"></h1>
    <p class="subtitle">Auto Repair &bull; Downtown &bull; Est. 1947</p>
</header>
<script>document.documentElement.style.setProperty('--header-h', document.querySelector('header').offsetHeight + 'px');</script>

<div class="container">

<?php if ($confirmed): ?>
<!-- === CONFIRMATION === -->
<div class="panel confirm-box">
    <img src="images/icons/pow.svg" alt="POW!" class="pow-burst">
    <h2>APPOINTMENT CONFIRMED!</h2>
    <div class="bubble">
        Your car is in good hands. We'll see you at
        <strong><?= htmlspecialchars(fmtDate($confirmed['date'])) ?></strong>,
        slot <strong><?= htmlspecialchars($SLOT_LABELS[(int)$confirmed['slot_index']] ?? '') ?></strong>
        with <strong><?= htmlspecialchars((getMechanicById((int)$confirmed['mechanic_id']) ?? [])['name'] ?? '') ?></strong>.
    </div>
    <a href="index.php" class="btn btn-pink">Book Another</a>
</div>
<?php else: ?>

<!-- === BOOKING FORM === -->
<div class="panel booking-panel">
    <div class="burst burst-right">BOOK!</div>
    <h2>Book a Time</h2>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <p style="margin:0;">Tell us about yourself and your car, then pick your mechanic and slot.</p>
        <button type="button" class="btn btn-sm btn-teal" onclick="openQuickBook()" style="flex-shrink:0;">Quick Book</button>
    </div>

    <form method="post" id="booking-form" novalidate>
        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label for="name">Your Name</label>
                    <input type="text" id="name" name="name" placeholder="e.g. John Smith" data-validate="required" data-err-required="Name is required." value="<?= htmlspecialchars($savedPost['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="e.g. 555-0199" data-validate="required|phone" data-err-required="Phone is required." data-err-phone="Valid phone #s only." value="<?= htmlspecialchars($savedPost['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" placeholder="e.g. 123 Main Street, Metropolis" data-validate="required" data-err-required="Address is required."><?= htmlspecialchars($savedPost['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label for="license_no">Car License Number</label>
                    <input type="text" id="license_no" name="license_no" placeholder="e.g. ABC-1234" data-validate="required" data-err-required="License number is required." value="<?= htmlspecialchars($savedPost['license_no'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="engine_no">Car Engine Number</label>
                    <input type="text" id="engine_no" name="engine_no" placeholder="e.g. 8NR-TS2021" data-validate="required|alphanumeric" data-err-required="Engine number is required." data-err-alphanumeric="Alphanumeric only." value="<?= htmlspecialchars($savedPost['engine_no'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="car_model">Car Model <span style="font-weight:normal;">(optional)</span></label>
                    <input type="text" id="car_model" name="car_model" placeholder="e.g. Ford Mustang" value="<?= htmlspecialchars($savedPost['car_model'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col" style="max-width:320px;">
                <div class="form-group">
                    <label for="date">Appointment Date</label>
                    <input type="date" id="date" name="date" placeholder="Pick a date" data-validate="required" data-err-required="Appointment date is required." value="<?= htmlspecialchars($selectedDate) ?>" onchange="fetchAvailability()">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Select a Mechanic</label>
            <div id="mechanic-list">
                <?php foreach ($mechanics as $m): ?>
                <?php $sched = $mechSchedules[$m['id']] ?? []; ?>
                <div class="mechanic-card <?= $selectedMechId === (int)$m['id'] ? 'selected' : '' ?>" data-quote="<?= htmlspecialchars($m['quote'] ?? '', ENT_QUOTES) ?>" data-doodle="<?= htmlspecialchars($m['doodle'] ?? '', ENT_QUOTES) ?>" onclick="selectMechanic(<?= $m['id'] ?>)">
                    <input type="radio" name="mechanic_id" value="<?= $m['id'] ?>" <?= $selectedMechId === (int)$m['id'] ? 'checked' : '' ?> style="display:none;">
                    <h3><?= htmlspecialchars($m['name']) ?></h3>
                    <?php if ($m['nickname']): ?><span class="nickname">"<?= htmlspecialchars($m['nickname']) ?>"</span><?php endif; ?>
                    <div class="specialties"><?= htmlspecialchars($m['specialties']) ?></div>
                    <div class="work-days">
                        <?php for ($d = 0; $d <= 6; $d++): ?>
                        <span class="work-day <?= isset($sched[$d]) ? 'on' : 'off' ?>"><?= $GLOBALS['DAY_NAMES_ABBR'][$d] ?></span>
                        <?php endfor; ?>
                    </div>
                    <div class="exp-badge">
                        <span class="exp-label">Experience:</span>
                        <span class="exp-value"><?= (int)$m['experience'] > 0 ? (int)$m['experience'] . ' yr' . ((int)$m['experience'] !== 1 ? 's' : '') : '< 1 yr' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label>Available Slots</label>
            <div style="display:flex;gap:16px;align-items:flex-start;">
                <div id="slot-container" class="slot-grid" style="flex:1;">
                    <p style="font-style:italic;color:#888;">Select a date and mechanic to see slots.</p>
                </div>
                <button type="button" class="btn btn-sm btn-pink" onclick="openEditBooking()" style="margin-top:4px;">Edit Booking</button>
            </div>
            <input type="hidden" name="slot_index" id="slot_index" value="">
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <button type="submit" class="btn btn-pink">Book Appointment</button>
            <a href="#" class="btn btn-sm btn-outline" onclick="requirePwNewTab('admin.php');return false;">Admin Panel</a>
        </div>
    </form>
</div>

<div class="panel help-section">
    <img class="doodle doodle-oil-help" src="images/doodles/oil-can.svg" alt="">
    <h2>Help &amp; Info</h2>
    <details>
        <summary>How do I book?</summary>
        <p>Fill in your info and your car's details, pick a date, choose one of our mechanics, and select an available time slot. Hit "Book Appointment" and you're all set.</p>
    </details>
    <details>
        <summary>How many cars can I book per day?</summary>
        <p>Each car can have one appointment per day. If you own multiple cars, you can book each one separately.</p>
    </details>
    <details>
        <summary>What if my preferred slot is taken?</summary>
        <p>The system will suggest the nearest available options — either a different time with the same mechanic, or a similarly skilled mechanic at your chosen time.</p>
    </details>
    <details>
        <summary>Do I need an account?</summary>
        <p>No. Just provide your phone number — it's how we identify returning customers.</p>
    </details>
    <details>
        <summary>How do I check a mechanic's work schedule?</summary>
        <p>Each mechanic card shows the days they work with colored dots below their name. A green dot (<span style="display:inline-block;width:12px;height:12px;background:var(--teal);vertical-align:middle;margin:0 2px;border-radius:2px;"></span>) means they're available that day &bull; a gray dot (<span style="display:inline-block;width:12px;height:12px;background:#e8e8e8;border:2px solid #bbb;vertical-align:middle;margin:0 2px;border-radius:2px;"></span>) means they're off. If a mechanic is on vacation, an <strong>ON VACATION</strong> badge will also appear.</p>
    </details>
</div>
</div>
<?php endif; ?>

<!-- === INLINE SCRIPT === -->
<script>
var SLOT_LABELS = <?= json_encode($SLOT_LABELS) ?>;
var SLOT_NAMES = <?= json_encode($SLOT_NAMES) ?>;
var VACATION_DATA = <?= json_encode($mechVacations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var TODAY = '<?= date('Y-m-d') ?>';
var initialMechId = <?= $selectedMechId ?: '0' ?>;
var initialDate = <?= json_encode($selectedDate) ?>;
var initialSlot = <?= $selectedSlot !== '' ? json_encode((int)$selectedSlot) : 'null' ?>;
var BURST_KEYS = ['blank','zilch','nada','bzzt','nope'];
</script>
<script src="spotlight.js"></script>
<script src="script.js"></script>
<script src="datepicker.js"></script>

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
<div class="modal-overlay hidden" id="qb-phone-modal" onclick="if (event.target===event.currentTarget) this.classList.add('hidden')">
    <div class="modal-box" style="max-width:380px;" onclick="event.stopPropagation()">
        <div class="burst burst-right">PHONE!</div>
        <h2>Quick Book</h2>
        <p style="margin:8px 0 16px;">Enter your phone number to pull up your last booking.</p>
        <input type="tel" id="qb-phone-input" placeholder="e.g. 09123456789" style="width:100%;font-size:1rem;">
        <p id="qb-phone-error" style="color:var(--rust);font-size:0.8rem;margin-top:6px;display:none;"></p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink" onclick="lookupQuickBook()">Look Up</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('qb-phone-modal').classList.add('hidden')">Cancel</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="qb-fail-modal" onclick="closeQbFailModal(event)">
    <div class="modal-box msg-box msg-error" onclick="event.stopPropagation()">
        <button type="button" class="modal-close" onclick="document.getElementById('qb-fail-modal').classList.add('hidden')">&times;</button>
        <div class="burst burst-left" class="modal-burst-below">NOPE!</div>
        <h2 class="modal-h2">Not Found</h2>
        <p class="modal-body-p" id="qb-fail-msg">That number ain't in our grease-stained ledger, pal. First time? Fill out the form.</p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink btn-outline" onclick="document.getElementById('qb-fail-modal').classList.add('hidden')">OK</button>
        </div>
    </div>
</div>

<!-- === EDIT BOOKING MODALS === -->
<div class="modal-overlay hidden" id="eb-phone-modal" onclick="if (event.target===event.currentTarget) this.classList.add('hidden')">
    <div class="modal-box" style="max-width:380px;" onclick="event.stopPropagation()">
        <div class="burst burst-right">EDIT!</div>
        <h2>Edit Booking</h2>
        <p style="margin:8px 0 16px;">Enter your phone number to find your booking.</p>
        <input type="tel" id="eb-phone-input" placeholder="e.g. 09123456789" style="width:100%;font-size:1rem;">
        <p id="eb-phone-error" style="color:var(--rust);font-size:0.8rem;margin-top:6px;display:none;"></p>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink" onclick="lookupEditBooking()">Look Up</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('eb-phone-modal').classList.add('hidden')">Cancel</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="eb-select-modal" onclick="if (event.target===event.currentTarget) this.classList.add('hidden')">
    <div class="modal-box" style="max-width:420px;" onclick="event.stopPropagation()">
        <div class="burst burst-right">PICK!</div>
        <h2>Select Booking to Edit</h2>
        <p style="margin:8px 0 16px;">You have multiple bookings. Pick one to edit.</p>
        <div id="eb-select-list" style="display:flex;flex-direction:column;gap:8px;"></div>
        <div class="modal-btn-row" style="margin-top:12px;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('eb-select-modal').classList.add('hidden')">Cancel</button>
        </div>
    </div>
</div>

<div class="modal-overlay hidden" id="eb-edit-modal" onclick="if (event.target===event.currentTarget) this.classList.add('hidden')">
    <div class="modal-box" style="max-width:620px;" onclick="event.stopPropagation()">
        <div class="burst burst-right">FIX!</div>
        <h2>Edit Your Booking</h2>
        <form id="eb-form" method="post" action="">
            <input type="hidden" name="edit_booking" value="1">
            <input type="hidden" name="appointment_id" id="eb-appt-id">
            <div style="display:flex;gap:12px;align-items:stretch;">
                <div class="flex-1">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" id="eb-name" name="name" placeholder="e.g. John Smith">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea id="eb-address" name="address" placeholder="e.g. 123 Main Street"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" id="eb-display-phone" readonly style="background:var(--paper);cursor:default;">
                    </div>
                </div>
                <div style="border-left:2px dashed var(--ink);align-self:stretch;"></div>
                <div class="flex-1">
                    <div class="form-group">
                        <label>Car License Number</label>
                        <input type="text" id="eb-license" name="license_no" placeholder="e.g. ABC-1234">
                    </div>
                    <div class="form-group">
                        <label>Car Engine Number</label>
                        <input type="text" id="eb-engine" name="engine_no" placeholder="e.g. 8NR-TS2021">
                    </div>
                    <div class="form-group">
                        <label>Car Model <span style="font-weight:normal;">(optional)</span></label>
                        <input type="text" id="eb-model" name="car_model" placeholder="e.g. Ford Mustang">
                    </div>
                    <div style="margin-top:8px;padding:6px 8px;background:var(--cream);border-radius:6px;font-size:0.85rem;">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <span><strong>Date:</strong> <span id="eb-display-date"></span></span>
                            <span><strong>Slot:</strong> <span id="eb-display-slot"></span></span>
                            <span><strong>Mechanic:</strong> <span id="eb-display-mechanic"></span></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-btn-row" style="margin-top:16px;">
                <button type="submit" class="btn btn-sm btn-pink">Save Changes</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('eb-edit-modal').classList.add('hidden')">Cancel</button>
            </div>
        </form>
    </div>
</div>

    <div id="spotlight-overlay"></div>
    <div id="shame-banner" class="shame-banner hidden" onclick="this.classList.add('hidden')">
        <strong>YOU ARE LOCKED IN.</strong><br>
        <span id="banner-sub">The spotlight will guide you to each field — fix it, then move on.</span><br>
        <small>(or refresh like a coward)</small>
    </div>

<div class="modal-overlay hidden" id="thank-you-modal" onclick="document.getElementById('thank-you-modal').classList.add('hidden')">
    <div class="modal-box" style="background:var(--pink);border-color:var(--gold);" onclick="event.stopPropagation()">
        <div class="burst burst-right" style="background:var(--gold);color:var(--ink);">DONE!</div>
        <p style="text-align:center;font-weight:bold;font-size:1.5rem;color:var(--gold);text-shadow:2px 2px 0 var(--ink);margin-top:40px;">Thank you!!!</p>
        <p style="text-align:center;font-family:var(--font-hand);font-size:1.2rem;color:var(--gold);text-shadow:1px 1px 0 var(--ink);">You completed the form!</p>
        <button id="spotlight-done-btn" class="btn btn-sm btn-teal btn-outline" style="margin-top:16px;display:block;margin-left:auto;width:fit-content;">Take a Bow</button>
    </div>
</div>

<?php if ($flashMsg): ?>
<div class="modal-overlay" id="msg-modal" onclick="closeMsgModal(event)">
    <div class="modal-box msg-box msg-<?= htmlspecialchars($flashType) ?>">
        <button type="button" class="modal-close" onclick="document.getElementById('msg-modal').classList.add('hidden')">&times;</button>
        <div class="msg-content"><?= htmlspecialchars($flashMsg) ?></div>
        <div class="modal-btn-row">
            <button type="button" class="btn btn-sm btn-pink btn-outline" onclick="document.getElementById('msg-modal').classList.add('hidden')">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
