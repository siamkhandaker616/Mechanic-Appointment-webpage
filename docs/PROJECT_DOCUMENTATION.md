# Mayhem Mobility — Project Documentation

## 1. Project Overview

`Mayhem Mobility` is a PHP/MySQL car workshop appointment system for CSE 391 Assignment 3. Customers can book 2-hour time slots with mechanics without creating an account. An admin panel provides full management: appointment editing, mechanic hiring/firing, schedule overrides, vacation tracking, and a simulated clock for testing.

The design uses a retro 1960s/70s pop art and comic strip aesthetic — Ben-Day dots, jagged speech bubbles, action bursts, onomatopoeia watermarks, comic panel rotation, and bold display fonts. The palette is ink-on-cream-paper with teal, navy, rust, pink, gold, and cyan accents.

The project is built with PHP 8+, MySQL (MariaDB), and vanilla JavaScript. It does not use a frontend or backend framework.

## 2. File Structure

```text
Assignment 3/
  index.php               Booking page (customer-facing)
  admin.php               Admin panel (management interface)
  availability.php        AJAX endpoint for slot availability
  config.example.php      Template — copy to config.php with your DB credentials
  functions.php           All business logic, DB queries, handler functions
  script.js               Client-side logic (booking + admin)
  datepicker.js           Custom-themed date picker widget
  style.css               Full 1502-line stylesheet
  README.md               Quick-start guide
  sql/
    schema.sql            Full database schema (8 tables)
    seed.sql              Seed data (mechanics, schedule, appointments)
  docs/
    PROJECT_DOCUMENTATION.md
  fonts/
    Action Man Bold.ttf
    Bangers.woff2
    LuckiestGuy-Regular.woff2
    PermanentMarker-Regular.woff2
    WalterTurncoat-Regular.woff2
  images/
    icons/
      *.png              Tagline, POW burst, etc.
    doodles/
      *.svg              Decorative SVGs + eye-open/eye-closed password icons
```

## 3. Pages

### `index.php` — Booking Page

The customer-facing booking form. No login required — customers are identified by phone number.

Processing flow:

1. On initial GET, the page renders an empty form plus a mechanic card grid and an empty slot grid.
2. On POST, `validateAppointmentInput()` checks required fields, date format, phone format, engine number format.
3. If valid, `findOrCreateClient()` looks up or creates a client by phone number. `findOrCreateCar()` does the same for the car by license number.
4. `isCarBookedOnDate()` prevents duplicate bookings for the same car on the same day.
5. `isSlotAvailable()` does a final concurrency check on the chosen slot.
6. On success, an inline confirmation panel replaces the form (`$success = true` branch).

Inline PHP data exports (inside `<script>` tags before `script.js`):

- `SLOT_LABELS` — time range labels for the 4 slots
- `SLOT_NAMES` — short names (Morning, Noon, Afternoon, Evening)
- `VACATION_DATA` — per-mechanic vacation periods
- `initialMechId`, `initialDate`, `initialSlot` — pre-selected values on form reload after POST

### `admin.php` — Admin Panel

The management interface with password-gated destructive actions. No session-based login — the password is checked per-action via an AJAX endpoint or inline POST verification.

Dispatcher pattern:

- Lines 9-14: AJAX `verify_pw` endpoint — returns JSON `{success: bool}`
- Lines 16-25: GET action handlers (`?cancel=N`, `?fire=N`, etc.) — all redirect via `flashAndRedirect()`
- Lines 27-39: POST action handlers (`update_date`, `update_mechanic`, etc.) — all redirect via `flashAndRedirect()`
- Lines 41-45: Flash message consumer — reads and clears session flash vars
- Line 47: `advanceAppointmentStatuses()` — auto-advances scheduled → in_progress → completed
- Lines 49-73: Data queries for appointments, mechanics, overrides, sim config

Template sections:

- **Simulated time panel** — toggle, datetime picker, Set button (disabled when sim off)
- **All Appointments table** — paginated list with per-row Edit (inline date/mechanic swap), Cancel, Remove actions; striped rows via PHP counter class
- **Active Overrides table** — lists blocked date/slot combinations with Unblock button
- **Schedule Override form** — per-mechanic, per-date slot blocking with conflict detection
- **All Mechanics table** — Name, specialties, exp, status (Active/On Leave/Inactive), with Edit, Schedule, Fire/Restore, Remove actions; striped rows via PHP counter class
- **Register New Mechanic form** — collapsible `<details>` section
- **Edit Mechanic modal** — name, nickname, quote, specialties, exp; name/exp locked behind password
- **Schedule modal** — day × slot checkbox grid
- **Conflict modal** — shown when override would clobber existing appointments
- **Password modal** — generic password gate for destructive actions

All modal dialogs follow a consistent pattern: overlay backdrop, decorated box with burst label, action description, confirm button, dismiss options (close X, Cancel button, overlay click).

### `availability.php` — AJAX Slot Endpoint

Called by `fetch('availability.php?mechanic_id=X&date=Y&slot_index=Z')` from `script.js`.

Response structure:

- `slots` — array of `{index, label, available}` for the selected mechanic/date
- `on_vacation` — boolean
- `all_slots` — `{ mechanic_id: [{index, available}, ...] }` for all mechanics
- `all_names` — `{ mechanic_id: name }` for tooltip labels
- When `slot_index` is provided: `mechanic_first_name`, `mechanic_nickname`, `adjacent_slot`, `nearby_prev_date`, `nearby_next_date`

Used by both the booking page (rendering slot chips) and the tooltip suggestion system (clicking a taken slot shows alternatives).

## 4. Database Structure

8 tables:

### `mechanics`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | Auto-increment |
| name | VARCHAR(100) | |
| nickname | VARCHAR(50) | Nullable |
| bio | TEXT | |
| quote | VARCHAR(255) | Shown on booking page hover |
| theme | VARCHAR(20) | per-mechanic visual theme |
| specialties | TEXT | Comma-separated |
| years_experience | INT | |
| is_active | BOOLEAN | Soft delete for firing |
| created_at | DATETIME | |

### `mechanic_schedule`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| mechanic_id | INT FK | mechanics.id |
| day_of_week | TINYINT | 0=Sun … 6=Sat |
| slot_1..slot_4 | BOOLEAN | Default TRUE |

Unique on `(mechanic_id, day_of_week)`.

### `mechanic_overrides`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| mechanic_id | INT FK | |
| override_date | DATE | |
| slot_1..slot_4 | BOOLEAN | 0 = blocked |
| reason | VARCHAR(255) | |

Unique on `(mechanic_id, override_date)`. Inserted with `ON DUPLICATE KEY UPDATE` so re-overriding updates in place.

### `mechanic_vacations`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| mechanic_id | INT FK | |
| start_date | DATE | |
| end_date | DATE | |
| reason | VARCHAR(255) | |

### `clients`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| name | VARCHAR(100) | |
| phone | VARCHAR(20) | Unique — used for lookups |
| address | TEXT | |
| created_at | DATETIME | |

### `cars`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| client_id | INT FK | |
| license_no | VARCHAR(50) | Unique |
| engine_no | VARCHAR(50) | |
| model | VARCHAR(100) | |
| created_at | DATETIME | |

### `appointments`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| client_id | INT FK | |
| car_id | INT FK | |
| mechanic_id | INT FK | |
| appointment_date | DATE | |
| slot_index | TINYINT | 0=10:00, 1=12:00, 2=14:00, 3=16:00 |
| status | ENUM | scheduled / in_progress / completed / cancelled |
| cancelled_at | DATETIME | Nullable |
| admin_notes | TEXT | |
| created_at / updated_at | DATETIME | |

Unique on `(car_id, appointment_date)` — one booking per car per day.

### `reviews`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| appointment_id | INT FK | Unique |
| rating | TINYINT | 1–5 |
| comment | TEXT | |
| created_at | DATETIME | |

### `sim_config`

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | CHECK (id=1) — singleton row |
| use_simulated_time | BOOLEAN | |
| simulated_datetime | DATETIME | |

Pre-seeded with one row on schema creation.

## 5. JavaScript / AJAX Architecture

### `script.js` (415 lines)

All client-side logic for both pages. Page-specific code is guarded by `DOMContentLoaded` checks for element existence (`#booking-form` for booking page, `#pw-modal` for admin page).

#### Booking page functions

- **`htmlspecialchars(s)`** — Custom escape for `<`, `>`, `&`, `"`, `'` using DOM text node + innerHTML + `.replace()`.
- **`isOnVacation(mechId, date)`** — Checks `VACATION_DATA` for date overlap.
- **`updateVacationBadges(date)`** — Adds/removes "ON VACATION" badges on mechanic cards dynamically when date changes.
- **`updateQuotePosition(card)`** — Positions the quote tooltip relative to a hovered mechanic card.
- **`selectMechanic(id)`** — Marks a mechanic card as selected, fetches availability.
- **`fetchAvailability()`** — Calls `availability.php`, renders `.slot-chip` divs. Green chips are clickable (selectable), gray chips show a tooltip on click.
- **`selectSlot(el, index)`** — Highlights the chosen slot chip, stores index in hidden input.
- **`showTooltip(el)`** — Called when clicking a taken slot. Fetches alternative suggestions from `availability.php` (adjacent slots, nearby dates, other mechanics free at that time). Renders clickable suggestion chips that call `fillSuggestion()`.
- **`hideTooltip()`** — Hides the suggestion tooltip.
- **`fillSuggestion(mechId, date, slotIndex)`** — Fills the form with the suggested mechanic/date/slot, re-fetches availability, auto-selects the slot.
- **`formatSuggestDate(dateStr)`** — Reverses ISO date for UK-style display in tooltip chips.

#### Admin page functions

- **Password gate**: `requirePw(actionUrl)`, `requirePwForForm(form)`, `requirePwForField(fieldId)`, `openPwModal()`, `closePwModal()`, `confirmPw()`, `togglePwVisibility()` — Generic password modal system used by all destructive actions. The modal sends `verify_pw=1` via XHR; on success, executes the pending action (redirect, form submit, or field unlock).
- **`toggleEdit(id)`** — Shows/hides the inline edit form row for an appointment.
- **`toggleOverrides()`** — Shows/hides the Active Overrides panel.
- **`openMechModal(btn)`** — Fills and opens the Edit Mechanic modal from data attributes.
- **`openScheduleModal(id, name)`** — Opens the schedule checkbox grid modal.
- **`toggleMechSwapBtn(sel)`** — Enables/disables the Change Mechanic button based on selection change.
- **`toggleDateChangeBtn(el)`** — Enables/disables the Change Date button based on date/slot change.
- **`renderVacations(id)`** — Renders a mechanic's vacation list inside the Edit modal.
- **`addVacation()`** — Dynamically creates and submits a hidden form to add a vacation period.
- Modal show/close functions: `showCancelModal`, `showFireModal`, `showRemoveModal`, `showUnblockModal` and their `close*Modal` counterparts.

### `datepicker.js` (311 lines)

A custom date picker that replaces native `<input type="date">` and `<input type="datetime-local">` UI when the `data-datepicker` attribute is present.

Features:

- Singleton manager (`DPM`) with scroll/resize/click listeners, registered per-picker
- Calendar grid with month navigation (prev/next arrows)
- Day selection with today highlighting
- Month/year hover tooltip
- Confirm button mode (data-confirm attrubute)
- Placement control (data-placement: auto / top)
- Lazy popup creation on first focus
- Positions relative to input, avoids viewport edges

## 6. Backend Architecture

### `functions.php` (665 lines)

Core business logic split into:

#### Helper functions
- `fmtDate()` — ISO date → "j M Y" format
- `fmtNameTwoLines()` — Splits name at first space, inserts `<br>`
- `slotStartHour()` / `slotEndHour()` / `slotIndexFromHour()` — Slot time math
- `getDB()` — PDO singleton from `config.php`

#### Availability system
- `isSlotAvailable()` — Checks schedule, overrides, existing appointments, vacations. Returns `false` if the slot is taken, blocked, or mechanic is on vacation.
- `getAdjacentSlotForMechanic()` — Returns the nearest free slot (+1 or -1)
- `getNearbyDatesForMechanic()` — Searches ±14 days for the same slot with the same mechanic

#### Appointment CRUD
- `createAppointment()`, `cancelAppointment()`, `removeAppointment()` (DELETE for cancelled)
- `updateAppointmentDate()` — Validates via `validateSlotAssignment()` before updating
- `updateAppointmentMechanic()` — Same validation pattern
- `validateSlotAssignment()` — Central validator: checks schedule, override, and appointment conflicts
- `validateAppointmentInput()` — Field-level validation (required fields, formats)
- `advanceAppointmentStatuses()` — Two-pass auto-advance: first reverts incorrectly forward-advanced appointments, then advances `scheduled → in_progress` (time-based) and `in_progress → completed` (time-based)

#### Mechanic management
- `getMechanics()`, `getMechanicById()`, `getAllMechanics()`, `getMechanicsForSelect()`
- `addMechanic()` — Inserts mechanic + auto-creates 7-day full schedule
- `updateMechanic()`, `fireMechanic()`, `restoreMechanic()`, `removeMechanic()` (hard delete)

#### Schedule system
- `getMechanicSchedule()`, `updateMechanicSchedule()`
- `getMechanicVacations()`, `addMechanicVacation()`, `removeMechanicVacation()`
- `isMechanicOnVacation()`

#### Client/Car management
- `findOrCreateClient()` — Lookup by phone, update name/address if returning
- `findOrCreateCar()` — Lookup by license, update engine/model if returning
- `isCarBookedOnDate()` — Prevents double-booking

#### Simulation
- `getEffectiveTime()` — Returns simulated datetime if enabled, else real time

#### Flash + redirect
- `flashAndRedirect()` — Sets session flash vars, redirects to admin.php, exits

#### 17 Action handlers (`handle*()` functions)

All follow the pattern: read input, perform action, call `flashAndRedirect()`.

| Handler | Trigger | Action |
|---------|---------|--------|
| `handleRemoveAllCancelled()` | GET `?remove_all_cancelled` | Deletes all cancelled appointments |
| `handleRemove()` | GET `?remove=N` | Deletes a specific cancelled appointment |
| `handleCancel()` | GET `?cancel=N` | Marks appointment as cancelled |
| `handleFire()` | GET `?fire=N` | Sets `is_active = 0` on mechanic |
| `handleRestore()` | GET `?restore=N` | Sets `is_active = 1` on mechanic |
| `handleRemoveMechanic()` | GET `?remove_mechanic=N` | Hard-deletes a mechanic |
| `handleUnblock()` | GET `?unblock=N` | Deletes an override row |
| `handleRemoveVacation()` | GET `?remove_vacation=N` | Deletes a vacation row |
| `handleUpdateDate()` | POST `update_date` | Changes appointment date/slot (password-gated) |
| `handleUpdateMechanic()` | POST `update_mechanic` | Swaps appointment mechanic (password-gated) |
| `handleSimToggle()` | POST `sim_toggle` | Toggles simulated time on/off |
| `handleToggleSim()` | POST `toggle_sim` | Sets simulated datetime |
| `handleAddMechanic()` | POST `add_mechanic` | Creates new mechanic |
| `handleUpdateMechanicInfo()` | POST `update_mechanic_info` | Edits mechanic details |
| `handleUpdateSchedule()` | POST `update_schedule` | Updates day × slot grid |
| `handleAddVacation()` | POST `add_vacation` | Adds vacation period |
| `handleOverrideSlot()` | POST `override_slot` | Blocks specific slots on a date |

All handlers return `never` — they always terminate via `flashAndRedirect()` (which calls `header()` + `exit`).

## 7. Visual Design Concept

The aesthetic is retro comic book / pop art:

- **Panel layouts**: `.panel` elements have slight rotation offsets (0.4°–0.6°) for a hand-placed comic panel feel. Even/odd panels alternate rotation direction.
- **Color palette**: `--ink` (#1a1a2e) on `--paper` (#e0cc5a) with accent colors: teal, rust, navy, pink, gold, cyan, burst (magenta), pop (blue).
- **Fonts**: Five self-hosted display fonts: Bangers (headlines), Action Man Bold (bursts), Walter Turncoat (body), Luckiest Guy (subheadings), Permanent Marker (accents).
- **Bursts**: Triangular star shapes with `clip-path: polygon()` in four directional variants (left, right, top-left, top-right). Used on modals ("WHOA!", "FIRED!", "GONE!", "FREE!", "BOOK!", "LIST!", "TIME!", "HELD!", "BLOCKED!", "BAM", "POW", "ZOWIE", "VROOM", "KAPOW", "CLICK").
- **Onomatopoeia watermarks**: Three fixed-position repeating background-style texts ("VROOM", "KAPOW", "CLICK" on booking page; "POW", "ZOWIE", "BAM" on admin).
- **Ben-Day dots**: `.dot-bg` utility applies `background-image: radial-gradient(circle, ...)` for a retro printing effect.
- **Shadows**: Heavy box-shadows with `--shadow-offset: 6px` for a 3D pop-art feel.
- **Tables**: Themed with `--navy` headers (`--gold` text), `--cyan` row backgrounds, alternating `#bcd4d4` stripe on even rows (via `tr.stripe-even td`).
- **Buttons**: Styled with comic colors, bold font, heavy shadows, hover transforms.
- **Scrollbars**: Custom thin `--ink` scrollbars matching the theme.

## 8. Password Gate System

Destructive actions are protected by an admin password (`ADMIN_PW` constant in `config.php`, default `'meow meow'`).

### Gated actions

The following require password confirmation:

- Cancel appointment
- Remove cancelled appointment
- Remove all cancelled appointments
- Fire mechanic
- Remove mechanic
- Edit appointment date
- Edit appointment mechanic
- Unlock name field in Edit Mechanic modal
- Unlock experience field in Edit Mechanic modal

### Non-gated actions

These do NOT require password:

- Restore mechanic
- Unblock override
- Remove vacation
- Hire mechanic
- Sim toggle
- Schedule update
- Add vacation
- Override slot

### Password modal flow

1. User clicks a gated action → `requirePw()` sets `_pendingAction`, opens modal
2. User enters password, clicks Confirm → `confirmPw()` sends `verify_pw=1` via XHR to `admin.php`
3. Server compares `$_POST['admin_pw']` against `ADMIN_PW` constant, returns `{success: bool}`
4. On success: executes the pending action (redirect, form submit, or field unlock)
5. On failure: shows error message inside modal, user can retry

The password input includes a show/hide toggle using `eye-open.svg`/`eye-closed.svg` icons.

## 9. Simulation System

A simulated clock that overrides the real system time for testing.

- **Toggle**: Checkbox in the Simulated Time panel activates/deactivates sim mode. The panel highlights when active.
- **Set button**: A datetime-local input lets the admin pick a specific simulated time. The Set button is disabled when sim mode is off.
- **Display**: Current effective time shown in the panel with a `(simulated)` label when active.
- **Behavior**: `getEffectiveTime()` checks `sim_config.use_simulated_time` — if true, returns `sim_config.simulated_datetime`, otherwise returns `new DateTime()`.
- **Status auto-advance**: `advanceAppointmentStatuses()` uses the effective time to determine when appointments move from `scheduled → in_progress → completed`, enabling time-travel testing.

## 10. Schedule Override System

Allows blocking specific time slots for a mechanic on a given date (e.g., days off, early leave).

- **Override form**: Dropdown for mechanic, date picker, 4 checkboxes for slots (labelled with time ranges), optional reason text.
- **Validation**: Rejects if mechanic doesn't work that day (`isSlotAvailable()` check). Rejects if slot is not in mechanic's base schedule.
- **Conflict detection**: Before saving, checks if any existing non-cancelled, non-completed appointments occupy the selected slots. If conflicts exist, a modal lists them with client names and the save is blocked.
- **Unblock**: Each override in the Active Overrides table has an Unblock button that deletes the row.
- **Privacy**: Override reasons are shown inside the admin panel only.

## 11. Status Auto-Advance

Appointment statuses auto-advance based on the effective time (real or simulated).

### Forward pass

- `scheduled → in_progress`: If current time is past the appointment start time (slot start), the status advances.
- `in_progress → completed`: If current time is past the appointment end time (slot end), the status advances.

### Reversion pass

Before the forward pass, a reversion pass resets any appointments that were incorrectly advanced (e.g., if simulated time was set to a date before the appointment). This prevents false states.

The auto-advance runs once per admin page load, after POST handling and before rendering.

## 12. Images and Assets

### Fonts
- `fonts/Action Man Bold.ttf` — Burst/action labels
- `fonts/Bangers.woff2` — Headlines (preloaded)
- `fonts/LuckiestGuy-Regular.woff2` — Subheadings
- `fonts/PermanentMarker-Regular.woff2` — Accent text
- `fonts/WalterTurncoat-Regular.woff2` — Body text (preloaded)

### Icons (PNG)
- `images/icons/tagline.png` — Header tagline image
- `images/icons/pow.png` — Appointment confirmation burst

### Doodles (SVG)
- `images/doodles/eye-open.svg` — Password show icon
- `images/doodles/eye-closed.svg` — Password hide icon

## 13. Notes

- Copy `config.example.php` to `config.php` and fill in your database credentials before running locally.
- The contact form from the homepage assignment is not present in this project — bookings are handled by the app itself.
- No email or SMS notifications are sent. Customers must remember their appointment time.
- The `json_encode()` outputs for `VACATION_DATA` and `SCHEDULE_DATA` use `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` flags to prevent XSS via stored vacation reasons.
- All SQL queries use prepared statements for user input. Only hardcoded constants (status values, table names) are concatenated directly.

## 14. How to Run

### Local (XAMPP)

1. Place the project folder in your web server root.
2. Copy `config.example.php` to `config.php` and update DB credentials.
3. Import `sql/schema.sql` and `sql/seed.sql` into MySQL.
4. Start Apache and MySQL from XAMPP Control Panel.
5. Open the booking page in a browser (e.g., `http://localhost/assignment3/index.php`).
6. Admin panel is at `admin.php` in the same directory.

### Live (InfinityFree)

- Booking page: `https://mayhem-mobility.page.gd`
- Admin panel: `https://mayhem-mobility.page.gd/admin.php`

## 15. Summary

This project is a functional car workshop appointment system with a retro comic book aesthetic for CSE 391 Assignment 3. It implements phone-based no-login booking, 4 daily time slots, mechanic management (hire/fire/edit/schedule), admin appointment editing, schedule overrides with conflict detection, vacation tracking, a simulated clock for time-travel testing, and automatic status progression. The codebase demonstrates PHP 8+ PDO/MySQL, vanilla JavaScript AJAX, PRG (Post-Redirect-Get) pattern with session flash messages, prepared statement SQL, CSS custom properties, responsive design, comic pop art styling with custom fonts and decorative bursts, and a password-gated admin system.
