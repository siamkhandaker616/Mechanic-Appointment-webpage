<?php
require_once __DIR__ . '/functions.php';

$mechanics = getMechanics();
$mechSchedules = [];
$mechVacations = [];
foreach ($mechanics as $m) {
    $mechSchedules[$m['id']] = getMechanicSchedule((int)$m['id']);
    $mechVacations[$m['id']] = getMechanicVacations((int)$m['id']);
}
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $success = true;
        }
    }
}

$selectedMechId = (int)($_POST['mechanic_id'] ?? 0);
$selectedDate = $_POST['date'] ?? '';
$selectedSlot = $_POST['slot_index'] ?? '';
?>
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
    <h1>Mayhem Mobility <img src="images/icons/tagline.png" alt="Mayhem Mobility Tagline" class="tagline"></h1>
    <p class="subtitle">Auto Repair &bull; Downtown &bull; Est. 1947</p>
</header>

<div class="container">

<?php if ($success): ?>
<div class="panel confirm-box">
    <img src="images/icons/pow.png" alt="POW!" class="pow-burst">
    <h2>APPOINTMENT CONFIRMED!</h2>
    <div class="bubble">
        Your car is in good hands. We'll see you at
        <strong><?= htmlspecialchars(fmtDate($_POST['date'])) ?></strong>,
        slot <strong><?= htmlspecialchars($SLOT_LABELS[(int)$_POST['slot_index']] ?? '') ?></strong>
        with <strong><?= htmlspecialchars((getMechanicById((int)$_POST['mechanic_id']) ?? [])['name'] ?? '') ?></strong>.
    </div>
    <a href="index.php" class="btn btn-pink">Book Another</a>
</div>
<?php else: ?>

<div class="panel booking-panel">
    <div class="burst burst-right">BOOK!</div>
    <h2>Book a Time</h2>
    <p style="margin-bottom:16px;">Tell us about yourself and your car, then pick your mechanic and slot.</p>

    <?php if (!empty($errors)): ?>
    <div class="flash-msg error">
        <ul>
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" id="booking-form">
        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label for="name">Your Name</label>
                    <input type="text" id="name" name="name" data-validate="required" data-err-required="Name is required." value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" data-validate="required|phone" data-err-required="Phone is required." data-err-phone="Digits only." value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label for="license_no">Car License Number</label>
                    <input type="text" id="license_no" name="license_no" data-validate="required" data-err-required="License number is required." value="<?= htmlspecialchars($_POST['license_no'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="engine_no">Car Engine Number</label>
                    <input type="text" id="engine_no" name="engine_no" data-validate="required|alphanumeric" data-err-required="Engine number is required." data-err-alphanumeric="Alphanumeric only." value="<?= htmlspecialchars($_POST['engine_no'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="car_model">Car Model <span style="font-weight:normal;">(optional)</span></label>
                    <input type="text" id="car_model" name="car_model" value="<?= htmlspecialchars($_POST['car_model'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col" style="max-width:320px;">
                <div class="form-group">
                    <label for="date">Appointment Date</label>
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" required onchange="fetchAvailability()">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Select a Mechanic</label>
            <div id="mechanic-list">
                <?php foreach ($mechanics as $m): ?>
                <?php $sched = $mechSchedules[$m['id']] ?? []; ?>
                <div class="mechanic-card <?= $selectedMechId === (int)$m['id'] ? 'selected' : '' ?>" data-quote="<?= htmlspecialchars($m['quote'] ?? '', ENT_QUOTES) ?>" onclick="selectMechanic(<?= $m['id'] ?>)">
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
            <div id="slot-container" class="slot-grid">
                <p style="font-style:italic;color:#888;">Select a date and mechanic to see slots.</p>
            </div>
            <input type="hidden" name="slot_index" id="slot_index" value="">
        </div>

        <button type="submit" class="btn btn-pink">Book Appointment</button>
    </form>
</div>

<div class="panel help-section">
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

<script>
var SLOT_LABELS = <?= json_encode($SLOT_LABELS) ?>;
var SLOT_NAMES = <?= json_encode($SLOT_NAMES) ?>;
var VACATION_DATA = <?= json_encode($mechVacations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var TODAY = '<?= date('Y-m-d') ?>';
var initialMechId = <?= $selectedMechId ?: '0' ?>;
var initialDate = <?= json_encode($selectedDate) ?>;
var initialSlot = <?= $selectedSlot !== '' ? json_encode((int)$selectedSlot) : 'null' ?>;
</script>
<script src="script.js"></script>
<script src="datepicker.js"></script>
</body>
</html>
