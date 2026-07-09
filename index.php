<?php
require_once __DIR__ . '/functions.php';

$mechanics = getMechanics();
$mechSchedules = [];
foreach ($mechanics as $m) {
    $mechSchedules[$m['id']] = getMechanicSchedule((int)$m['id']);
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
            $suggestions = suggestAlternatives((int)$_POST['mechanic_id'], $_POST['date'], (int)$_POST['slot_index']);
            $errors[] = 'slot_taken';
        } else {
            createAppointment($clientId, $carId, (int)$_POST['mechanic_id'], $_POST['date'], (int)$_POST['slot_index']);
            $success = true;
        }
    }
}

$selectedMechId = (int)($_POST['mechanic_id'] ?? 0);
$selectedDate = $_POST['date'] ?? '';
$selectedSlot = $_POST['slot_index'] ?? '';
$suggestions = [];
if (!empty($errors) && in_array('slot_taken', $errors) && $selectedMechId && $selectedDate && $selectedSlot !== '') {
    $suggestions = suggestAlternatives($selectedMechId, $selectedDate, (int)$selectedSlot);
    $errors = array_filter($errors, fn($e) => $e !== 'slot_taken');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="images/icons/favicon.ico">
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
        with <strong><?= htmlspecialchars(getMechanicById((int)$_POST['mechanic_id'])['name'] ?? '') ?></strong>.
    </div>
    <a href="index.php" class="btn btn-pink">Book Another</a>
</div>
<?php else: ?>

<div class="panel">
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
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label for="license_no">Car License Number</label>
                    <input type="text" id="license_no" name="license_no" value="<?= htmlspecialchars($_POST['license_no'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="engine_no">Car Engine Number</label>
                    <input type="text" id="engine_no" name="engine_no" value="<?= htmlspecialchars($_POST['engine_no'] ?? '') ?>" required>
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
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" min="<?= date('Y-m-d') ?>" required onchange="fetchAvailability()">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Select a Mechanic</label>
            <div id="mechanic-list">
                <?php $dayAbbr = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
                <?php foreach ($mechanics as $m): ?>
                <?php $sched = $mechSchedules[$m['id']] ?? []; ?>
                <?php $onVacation = $selectedDate && isMechanicOnVacation((int)$m['id'], $selectedDate); ?>
                <div class="mechanic-card <?= $selectedMechId === (int)$m['id'] ? 'selected' : '' ?>" onclick="selectMechanic(<?= $m['id'] ?>)">
                    <input type="radio" name="mechanic_id" value="<?= $m['id'] ?>" <?= $selectedMechId === (int)$m['id'] ? 'checked' : '' ?> style="display:none;">
                    <h3><?= htmlspecialchars($m['name']) ?></h3>
                    <?php if ($m['nickname']): ?><span class="nickname">"<?= htmlspecialchars($m['nickname']) ?>"</span><?php endif; ?>
                    <?php if ($onVacation): ?><span class="status-badge status-cancelled" style="margin-left:6px;font-size:0.65rem;">ON VACATION</span><?php endif; ?>
                    <div class="specialties"><?= htmlspecialchars($m['specialties']) ?> &bull; <?= $m['years_experience'] ?> yrs</div>
                    <div class="work-days">
                        <?php for ($d = 0; $d <= 6; $d++): ?>
                        <span class="work-day <?= isset($sched[$d]) ? 'on' : 'off' ?>"><?= $dayAbbr[$d] ?></span>
                        <?php endfor; ?>
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
        <summary>What do the slot times mean?</summary>
        <p>Slot 1: 08:00–10:00 &bull; Slot 2: 10:00–12:00 &bull; Slot 3: 12:00–14:00 &bull; Slot 4: 14:00–16:00</p>
    </details>
</div>
</div>
<?php endif; ?>

<script>
var SLOT_LABELS = <?= json_encode($SLOT_LABELS) ?>;
var MECHANIC_NAMES = <?= json_encode(getMechanicsForSelect()) ?>;
var initialMechId = <?= $selectedMechId ?: '0' ?>;
var initialDate = <?= json_encode($selectedDate) ?>;
var initialSlot = <?= json_encode($selectedSlot !== '' ? (int)$selectedSlot : 'null') ?>;
var lastSlots = null;
var allMechData = null;

function selectMechanic(id) {
    document.querySelectorAll('.mechanic-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('input[name="mechanic_id"]').forEach(r => {
        if (parseInt(r.value) === id) {
            r.checked = true;
            r.closest('.mechanic-card').classList.add('selected');
        }
    });
    fetchAvailability();
}

function hideTooltip() {
    var t = document.getElementById('slot-tooltip');
    if (t) t.classList.add('hidden');
}

function showTooltip(el) {
    var slotIndex = parseInt(el.dataset.slot);
    var mechIdEl = document.querySelector('input[name="mechanic_id"]:checked');
    var currentMechId = parseInt(mechIdEl.value);

    var t = document.getElementById('slot-tooltip');
    if (!t) {
        t = document.createElement('div');
        t.id = 'slot-tooltip';
        t.className = 'slot-tooltip';
        document.body.appendChild(t);
    }

    var html = '<div class="tt-title">That slot\'s taken!</div>';
    html += '<div class="tt-sub">Here\'s what\'s still open:</div>';
    html += '<div class="tt-chips">';

    if (lastSlots) {
        lastSlots.slots.forEach(function(s) {
            if (s.available) {
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + (document.getElementById('date').value) + '\', ' + s.index + ')"><span class="chip-label">' + SLOT_LABELS[s.index] + '</span></button>';
            }
        });
    }

    if (allMechData) {
        var others = [];
        Object.keys(allMechData.slots).forEach(function(mid) {
            var id = parseInt(mid);
            if (id === currentMechId) return;
            var slots = allMechData.slots[mid];
            for (var i = 0; i < slots.length; i++) {
                if (slots[i].available && slots[i].index === slotIndex) {
                    others.push(id);
                    break;
                }
            }
        });
        if (others.length > 0) {
            others.forEach(function(mid) {
                var name = allMechData.names[mid] || ('Mechanic #' + mid);
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + mid + ', \'' + (document.getElementById('date').value) + '\', ' + slotIndex + ')"><strong>' + name + '</strong><span class="chip-label">' + SLOT_LABELS[slotIndex] + '</span></button>';
            });
        }
    }

    html += '</div>';
    t.innerHTML = html;
    t._target = el;
    t.classList.remove('hidden');

    var rect = el.getBoundingClientRect();
    var top = rect.top - t.offsetHeight - 8;
    var left = rect.left + rect.width - 60;
    left = Math.min(left, window.innerWidth - t.offsetWidth - 10);
    t.style.top = Math.max(4, top) + 'px';
    t.style.left = Math.max(4, left) + 'px';
}

function fetchAvailability() {
    var mechIdEl = document.querySelector('input[name="mechanic_id"]:checked');
    var dateEl = document.getElementById('date');
    var container = document.getElementById('slot-container');

    hideTooltip();

    if (!mechIdEl || !dateEl.value) {
        container.innerHTML = '<p style="font-style:italic;color:#888;">Select a date and mechanic to see slots.</p>';
        return;
    }

    var mechParams = new URLSearchParams({ mechanic_id: mechIdEl.value, date: dateEl.value });
    var allParams = new URLSearchParams({ date: dateEl.value });

    Promise.all([
        fetch('availability.php?' + mechParams).then(function(r) { return r.json(); }),
        fetch('all-availability.php?' + allParams).then(function(r) { return r.json(); })
    ]).then(function(results) {
        var data = results[0];
        var allData = results[1];

        if (data.error) {
            container.innerHTML = '<p style="color:var(--rust);font-weight:bold;">' + data.error + '</p>';
            return;
        }

        lastSlots = data;
        allMechData = allData;

        var html = '';
        data.slots.forEach(function(slot) {
            var taken = slot.available ? '' : 'taken';
            html += '<div class="slot-chip ' + taken + '" data-slot="' + slot.index + '">' + SLOT_LABELS[slot.index] + '</div>';
        });
        container.innerHTML = html;

        container.querySelectorAll('.slot-chip').forEach(function(chip) {
            if (chip.classList.contains('taken')) {
                chip.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var t = document.getElementById('slot-tooltip');
                    if (t && !t.classList.contains('hidden') && t._target === chip) {
                        hideTooltip();
                    } else {
                        showTooltip(chip);
                    }
                });
            } else {
                chip.addEventListener('click', function() {
                    selectSlot(this, parseInt(this.dataset.slot));
                });
            }
        });
    }).catch(function() {
        container.innerHTML = '<p style="color:var(--rust);font-weight:bold;">Could not load slots.</p>';
    });
}

function selectSlot(el, index) {
    document.querySelectorAll('.slot-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('slot_index').value = index;
}

function fillSuggestion(mechId, date, slotIndex) {
    hideTooltip();
    selectMechanic(mechId);
    document.getElementById('date').value = date;
    setTimeout(function() {
        fetchAvailability();
        setTimeout(function() {
            var chips = document.querySelectorAll('.slot-chip');
            chips.forEach(function(c) {
                if (parseInt(c.dataset.slot) === slotIndex && !c.classList.contains('taken')) {
                    selectSlot(c, slotIndex);
                }
            });
        }, 300);
    }, 100);
}

document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        var t = document.getElementById('slot-tooltip');
        if (t && !t.classList.contains('hidden') && !t.contains(e.target) && e.target !== t._target && !e.target.closest('.slot-chip.taken')) {
            hideTooltip();
        }
    });
    if (initialMechId && initialDate) {
        selectMechanic(initialMechId);
        if (initialSlot !== null) {
            setTimeout(function() {
                document.querySelectorAll('.slot-chip').forEach(function(c) {
                    if (parseInt(c.dataset.slot) === initialSlot && !c.classList.contains('taken')) {
                        selectSlot(c, initialSlot);
                    }
                });
            }, 400);
        }
    }
});
</script>
<script src="datepicker.js"></script>
</body>
</html>
