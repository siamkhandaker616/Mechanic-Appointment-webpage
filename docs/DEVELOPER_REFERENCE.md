# Mayhem Mobility — Developer Reference

## 1. File Inventory

| File | Lines | Role |
|------|-------|------|
| `index.php` | 277 | Booking page — customer form, PRG handling, password modal, FAQ, flash modal, spotlight overlay |
| `admin.php` | 606 | Admin panel — dispatch pattern, all management panels/modals, settings gear |
| `availability.php` | ~100 | AJAX endpoint — slot availability + conflict suggestions |
| `functions.php` | 765 | Business logic — DB queries, CRUD, handlers, validation |
| `spotlight.js` | 330 | Spotlight of Shame — validation mini-game with overlay/beam/burst animations |
| `script.js` | 733 | Client logic — booking interactions, admin modals, gear init, utilities |
| `datepicker.js` | 320 | Custom date picker — singleton manager, calendar grid, placement control |
| `style.css` | 1931 | Full stylesheet — comic theme, spotlight, responsive |
| `config.php` | ~35 | Constants — DB creds, slot config, status enums, admin password, timezone |
| `config.example.php` | ~30 | Template — copy to config.php |
| `db-sync.php` | 36 | One-time migration — adds quote/theme columns to mechanics table |
| `sql/schema.sql` | 109 | 9 tables — full database schema |
| `sql/seed.sql` | ~30 | Seed data — 5 mechanics, schedules, sample appointments |

## 2. PHP — `functions.php` (765 lines)

### Helpers

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `fmtDate` | 6 | `(string $date): string` | ISO date → "j M Y" |
| `fmtNameTwoLines` | 11 | `(string $name): string` | Split at first space, insert `<br>` |
| `slotStartHour` | 19 | `(int $slotIndex): int` | Start hour for a slot index |
| `slotEndHour` | 22 | `(int $slotIndex): int` | End hour for a slot index |
| `slotIndexFromHour` | 25 | `(int $hour): int` | Slot index from a given hour |
| `getDB` | 28 | `(): PDO` | PDO singleton from config.php |

### Mechanics

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getMechanics` | 31 | `(): array` | All active mechanics |
| `getMechanicById` | 35 | `(int $id): ?array` | Single mechanic by ID |
| `getMechanicsForSelect` | 41 | `(): array` | Key-value pairs for `<select>` |
| `getAllMechanics` | 371 | `(): array` | All mechanics (active + inactive) |
| `addMechanic` | 375 | `(string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote, string $theme): int` | Insert mechanic + create 7-day all-false schedule |
| `updateMechanic` | 388 | `(int $id, string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote, string $theme): void` | Update mechanic details |
| `fireMechanic` | 393 | `(int $id): void` | Set `is_active = 0` |
| `restoreMechanic` | 398 | `(int $id): void` | Set `is_active = 1` |
| `removeMechanic` | 403 | `(int $id): void` | Hard delete from mechanics + related tables |

### Availability

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `isSlotAvailable` | 52 | `(int $mechanicId, string $date, int $slotIndex): bool` | Check schedule, overrides, appointments, vacations |
| `getMechanicSlotsAvailability` | 79 | `(int $mechanicId, string $date): array` | Array of 4 booleans for all slots |
| `getAdjacentSlotForMechanic` | 110 | `(int $mechanicId, string $date, int $slotIndex): ?int` | Nearest free slot (+1 or -1) |
| `getNearbyDatesForMechanic` | 116 | `(int $mechanicId, int $slotIndex, string $date): array` | ±30 days for same slot |

### Clients / Cars

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `findOrCreateClient` | 160 | `(string $name, string $phone, string $address): int` | Lookup by phone, update if returning |
| `findOrCreateCar` | 175 | `(int $clientId, string $licenseNo, string $engineNo, string $model): int` | Lookup by license, update if returning |
| `isCarBookedOnDate` | 154 | `(int $carId, string $date): bool` | Prevent double-booking |

### Appointments

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getAppointments` | 276 | `(?string $status): array` | All appointments with JOINs |
| `createAppointment` | 190 | `(int $clientId, int $carId, int $mechanicId, string $date, int $slotIndex): int` | Insert new appointment |
| `cancelAppointment` | 334 | `(int $appointmentId): bool` | Set cancelled + timestamp |
| `validateSlotAssignment` | 297 | `(int $mechanicId, string $date, int $slotIndex, ?int $excludeAppointmentId): array` | Check schedule/override/conflict, return error list |
| `validateAppointmentInput` | 342 | `(array $data): array` | Field-level validation. Conditional mechanic/slot messages |
| `advanceAppointmentStatuses` | 209 | `(): void` | Two-pass auto-advance (reversion + forward) |

### Schedule / Vacations

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getMechanicSchedule` | 413 | `(int $id): array` | Day-of-week slot map |
| `updateMechanicSchedule` | 427 | `(int $id, array $schedule): void` | Update day × slot grid |
| `getMechanicVacations` | 440 | `(int $mechanicId): array` | All vacations for a mechanic |
| `addMechanicVacation` | 446 | `(int $mechanicId, string $startDate, string $endDate, ?string $reason): void` | Insert vacation period |
| `removeMechanicVacation` | 451 | `(int $id): void` | Delete vacation by ID |
| `isMechanicOnVacation` | 456 | `(int $mechanicId, string $date): bool` | Check if mechanic is on vacation on a date |

### Simulation

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getEffectiveTime` | 199 | `(): DateTime` | Returns simulated datetime or real DateTime |

### Redirect

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `flashAndRedirect` | 464 | `(string $msg, string $type): never` | Set session flash vars, redirect to admin.php, exit |

### Action Handlers (16)

All `handle*()` functions follow the pattern: read input, perform action, call `flashAndRedirect()`. All return `never`.

| Handler | Line | Trigger | Action |
|---------|------|---------|--------|
| `handleRemoveAllCancelled` | 473 | GET `?remove_all_cancelled` | DELETE all cancelled appointments |
| `handleRemove` | 480 | GET `?remove=N` | DELETE cancelled appointment by ID |
| `handleCancel` | 488 | GET `?cancel=N` | SET status = cancelled |
| `handleFire` | 497 | GET `?fire=N` | SET is_active = 0 |
| `handleRestore` | 506 | GET `?restore=N` | SET is_active = 1 |
| `handleRemoveMechanic` | 515 | GET `?remove_mechanic=N` | Hard delete mechanic |
| `handleUnblock` | 524 | GET `?unblock=N` | DELETE override row |
| `handleRemoveVacation` | 532 | GET `?remove_vacation=N` | DELETE vacation row |
| `handleUpdateAppointment` | 543 | POST `update_appointment` | Update date/slot/mechanic |
| `handleSimToggle` | 590 | POST `sim_toggle` | Toggle simulated time on/off |
| `handleToggleSim` | 598 | POST `toggle_sim` | Set simulated datetime |
| `handleAddMechanic` | 612 | POST `add_mechanic` | Insert new mechanic |
| `handleUpdateMechanicInfo` | 626 | POST `update_mechanic_info` | Edit mechanic details |
| `handleUpdateSchedule` | 646 | POST `update_schedule` | Update day × slot grid |
| `handleAddVacation` | 676 | POST `add_vacation` | Insert vacation period |
| `handleOverrideSlot` | 702 | POST `override_slot` | Block specific slots on a date |

## 3. JavaScript — `spotlight.js` (330 lines)

### Validation

| Function | Line | Purpose |
|----------|------|---------|
| `validateBookingForm()` | 15 | Check all fields against `data-validate` rules. Returns `{el, msg}[]`, `{dateMsg}`, or `[]` |
| `revalidateField(el)` | 175 | Re-check a single field. Returns error string or `null` |

### Spotlight lifecycle

| Function | Line | Purpose |
|----------|------|---------|
| `launchSpotlight(errors)` | 49 | Create overlay, beam, glow, burst. Lock all fields, focus first error. Add scroll + focusin listeners |
| `advanceSpotlight()` | 128 | Lock current field, unlock next, reposition elements, reset burst animation. Dismiss when done |
| `dismissSpotlight()` | 149 | Remove all overlay elements, unlock fields, hide banner, detach listeners, cleanup after 400ms |

### Positioning

| Function | Line | Purpose |
|----------|------|---------|
| `positionSpotlight(error)` | 258 | Set curtain sizes, beam clip-path, glow position, burst position via getBoundingClientRect |
| `repositionOnScroll()` | 114 | Debounced (120ms). Add `.scroll-hide` to burst during scroll, remove after scroll stops |

### Focus handling

| Function | Line | Purpose |
|----------|------|---------|
| `handleSpotlightFocus(e)` | 187 | Attach blur + keydown listeners to current field. Revalidate on blur/Enter. Randomise phone bursts, advance on valid, show burst on invalid. Prevent leak via `_lastBlurHandler`/`_lastKeydownHandler` |

### Inline error fallback

| Function | Line | Purpose |
|----------|------|---------|
| `window.showInlineErrors(errs)` | 306 | Append `.field-error` spans under each invalid field |
| `window.hideInlineErrors()` | 315 | Remove all `.field-error` spans |

### Key globals

| Variable | Purpose |
|----------|---------|
| `window.SPOTLIGHT_DISABLED` | Boolean from `localStorage.getItem('spotlight_disabled')` |
| `FIELD_PRIORITY` | `['name','license_no','phone','engine_no','address']` — validation order |
| `PHONE_BURST_KEYS` | `['zilch','nada','bzzt','nope']` — blank excluded from phone re-randomisation |
| `BURST_KEYS` | `['blank','zilch','nada','bzzt','nope']` — defined inline in index.php |
| `_spotlightErrors` | Current error array |
| `_spotlightIndex` | Current error index |
| `_fieldBurstMap` | Per-field burst key assignment |
| `_lastBlurHandler` / `_lastKeydownHandler` | Active listener references for cleanup |

## 4. JavaScript — `script.js` (733 lines)

### Global utilities

| Function | Line | Purpose |
|----------|------|---------|
| `htmlspecialchars(s)` | 3 | DOM-based string escape for HTML |
| `fmtDate(isoStr)` | 9 | ISO → "D Mon YYYY" |
| `repositionPastDateMsg()` | 24 | Position date-error bubble below submit button |
| `showPastDateMsg(msg)` | 36 | Show jagged date-error bubble |
| `hidePastDateMsg()` | 53 | Hide date-error bubble |
| `initNumStepper(input)` | 260 | Up/down button stepper for number inputs |
| `updateStepperBg(input)` | 366 | Stepper bg colour based on readOnly |

### Booking page

| Function | Line | Purpose |
|----------|------|---------|
| `isOnVacation(mechId, date)` | 614 | Check VACATION_DATA overlap |
| `updateVacationBadges(date)` | 616 | Toggle "ON VACATION" badges on mechanic cards |
| `updateQuotePosition(card)` | 569 | Position quote tooltip relative to card |
| `selectMechanic(id)` | 577 | Select mechanic card, fetch availability |
| `fetchAvailability()` | 587 | Call availability.php, render slot chips |
| `selectSlot(el, index)` | 602 | Highlight slot chip, set hidden input |
| `showTooltip(el)` | 607 | Fetch suggestions for taken slot, render chips |
| `hideTooltip()` | 609 | Hide suggestion tooltip |
| `fillSuggestion(mechId, date, slotIndex, scrollToCard)` | 611 | Fill form from suggestion, scroll if `scrollToCard` true |
| Submit handler | 692 | Validate via `validateBookingForm()`, branch on `window.SPOTLIGHT_DISABLED` |

### Admin page

| Function | Line | Purpose |
|----------|------|---------|
| `requirePw(url)` | 380 | Set pending action, open password modal |
| `requirePwForForm(form)` | 395 | Open modal, submit form on success |
| `requirePwForField(fieldId)` | 420 | Open modal, unlock field on success |
| `openPwModal()` | 390 | Show password modal, focus input |
| `closePwModal()` | 446 | Hide password modal |
| `confirmPw()` | 406 | XHR verify_pw, execute pending action on success |
| `togglePwVisibility()` | 442 | Show/hide password text |
| `toggleEdit(id)` | 513 | Show/hide inline edit row |
| `toggleOverrides()` | 515 | Show/hide Active Overrides panel |
| `openMechModal(btn)` | 488 | Fill Edit Mechanic modal from data attributes |
| `openMechModalById(id, name, nickname, quote, specialties, exp)` | 481 | Programmatic modal open for new-hire |
| `closeMechModal(event)` | 495 | Close with new-hire redirect |
| `openScheduleModal(id, name)` | 501 | Open schedule grid modal |
| `toggleUpdateApptBtn(el)` | 525 | Enable/disable Update button on change |
| `validateOverrideForm()` | 538 | Client-side override validation |
| `clearOverrideError()` | 550 | Hide override error |
| `renderVacations(id)` | 564 | Populate vacation list in Edit modal |
| `addVacation()` | 598 | Submit vacation via hidden form |

### Settings gear init (DOMContentLoaded)

| Element | Behaviour |
|---------|-----------|
| `#settings-btn` click | Toggle `.hidden` on `#settings-dropdown` |
| Document click outside | Close dropdown |
| `#spotlight-toggle` change | Write to localStorage, `location.reload()` |
| `window.SPOTLIGHT_DISABLED` fallback | Read from localStorage if spotlight.js not loaded |

### Modal helpers

`showCancelModal`, `closeCancelModal`, `showFireModal`, `closeFireModal`, `showRemoveModal`, `closeRemoveModal`, `showUnblockModal`, `closeUnblockModal`, `closeConflictModal`, `closeMsgModal`

## 5. JavaScript — `datepicker.js` (320 lines)

| Component | Purpose |
|-----------|---------|
| `DPM` singleton | Manager with scroll/resize/click listeners, registered per-picker |
| Calendar grid | Month navigation, day selection, today highlight |
| Month tooltip | Hover shows month/year |
| Confirm mode | `data-confirm` attribute on input |
| Placement | `data-placement`: auto / top |
| Lazy creation | Popup created on first focus |
| Viewport edge avoidance | Repositions if near edge |

## 6. Config — `config.php`

| Constant | Value | Purpose |
|----------|-------|---------|
| `DB_HOST` | localhost | MySQL host |
| `DB_PORT` | 3306 | MySQL port |
| `DB_NAME` | mayhem_mobility | Database name |
| `DB_USER` | root | MySQL user |
| `DB_PASS` | | MySQL password |
| `SLOT_COUNT` | 4 | Number of daily slots |
| `DATE_REGEX` | `/^\d{4}-\d{2}-\d{2}$/` | ISO date pattern |
| `STATUS_SCHEDULED` | scheduled | Appointment status |
| `STATUS_CANCELLED` | cancelled | Appointment status |
| `STATUS_IN_PROGRESS` | in_progress | Appointment status |
| `STATUS_COMPLETED` | completed | Appointment status |
| `SITE_URL` | http://localhost/assignment3 | Base URL for redirects |
| `ADMIN_PW` | meow meow | Admin password gate |

| Global | Value | Purpose |
|--------|-------|---------|
| `$SLOT_LABELS` | `['10:00–12:00', '12:00–14:00', '14:00–16:00', '16:00–18:00']` | Slot time ranges |
| `$SLOT_NAMES` | `['Morning', 'Noon', 'Afternoon', 'Evening']` | Short slot names |
| `timezone` | `'Asia/Dhaka'` | Default timezone |

## 7. Database Schema

### `mechanics`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| name | VARCHAR(100) | |
| nickname | VARCHAR(50) | Nullable |
| quote | VARCHAR(255) | |
| theme | VARCHAR(20) | Default 'default' |
| specialties | TEXT | |
| years_experience | INT | Default 0 |
| is_active | BOOLEAN | Default TRUE |
| created_at | DATETIME | |

### `mechanic_schedule`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| mechanic_id | INT FK | mechanics.id |
| day_of_week | TINYINT | 0=Sun…6=Sat |
| slot_1–slot_4 | BOOLEAN | Default TRUE |
| UNIQUE | (mechanic_id, day_of_week) | |

### `mechanic_overrides`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| mechanic_id | INT FK | |
| override_date | DATE | |
| slot_1–slot_4 | BOOLEAN | 0=blocked |
| reason | VARCHAR(255) | |
| UNIQUE | (mechanic_id, override_date) | ON DUPLICATE KEY UPDATE |

### `mechanic_vacations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| mechanic_id | INT FK | |
| start_date | DATE | |
| end_date | DATE | |
| reason | VARCHAR(255) | |

### `clients`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| name | VARCHAR(100) | |
| phone | VARCHAR(20) | UNIQUE |
| address | TEXT | |
| created_at | DATETIME | |

### `cars`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| client_id | INT FK | |
| license_no | VARCHAR(50) | UNIQUE |
| engine_no | VARCHAR(50) | |
| model | VARCHAR(100) | |
| created_at | DATETIME | |

### `appointments`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| client_id | INT FK | |
| car_id | INT FK | |
| mechanic_id | INT FK | |
| appointment_date | DATE | |
| slot_index | TINYINT | 0=10:00, 1=12:00, 2=14:00, 3=16:00 |
| status | ENUM | scheduled/in_progress/completed/cancelled |
| cancelled_at | DATETIME | Nullable |
| admin_notes | TEXT | |
| created_at | DATETIME | |
| updated_at | DATETIME | ON UPDATE CURRENT_TIMESTAMP |
| UNIQUE | (car_id, appointment_date) | |

### `reviews`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| appointment_id | INT FK | UNIQUE |
| rating | TINYINT | 1-5 |
| comment | TEXT | |
| created_at | DATETIME | |

### `sim_config`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | CHECK(id=1) |
| use_simulated_time | BOOLEAN | DEFAULT FALSE |
| simulated_datetime | DATETIME | |

Pre-seeded with one row.

## 8. CSS Component Reference

### Layout
| Selector | Purpose |
|----------|---------|
| `.container` | Max-width 980px centred wrapper |
| `.row` / `.col` | Flexbox row with 20px gap, responsive columns |

### Panels
| Selector | Purpose |
|----------|---------|
| `.panel` | Comic-themed card with rotation, shadow, decorative stripe/block |
| `.panel::before` | Teal dashed top stripe |
| `.panel::after` | Pink "■" bottom-right |
| `.booking-panel` | Removes top border from booking panel |

### Typography
- `--font-display`: Bangers (headlines)
- `--font-sub`: Luckiest Guy (subheadings)
- `--font-marker`: Permanent Marker (accents)
- `--font-action`: Action Man Bold (bursts)
- `--font-hand`: Walter Turncoat (body)

### Colour Variables
| Variable | Hex | Use |
|----------|-----|-----|
| `--ink` | #1a1a2e | Text, borders |
| `--ink-alt` | #78a1f5 | Text outline |
| `--paper` | #e0cc5a | Body bg |
| `--cream` | #ecd94d | Panel bg |
| `--teal` | #2a6b6b | Accent |
| `--teal-light` | #3a9b9b | Checkbox checked |
| `--teal-dark` | #1a4a4a | Subtitle |
| `--rust` | #a0453b | Danger, shame banner |
| `--pink` | #d63384 | Accent, bursts |
| `--gold` | #f5c518 | Highlights, banner text |
| `--navy` | #16213e | Table headers |
| `--cyan` | #caeded | Bubble bg |

### Spotlight
| Selector | Purpose |
|----------|---------|
| `.overlay-curtain` | 4 fixed curtains (top/bottom/left/right) with noise + halftone |
| `.spotlight-beam` | CSS cone via clip-path, yellow gradient |
| `.spotlight-glow` | Gold border + shadow rim at field position |
| `.error-burst` | SVG burst, animated via error-pop / fade-out |
| `.error-burst.scroll-hide` | Hidden during scroll (opacity:0, animation:none) |
| `.burst-blank` / `.burst-zilch` / `.burst-nada` / `.burst-bzzt` / `.burst-nope` | Which SVG to show |
| `.shame-banner` | Fixed top bar, rust bg + gold text, shame-slide animation |
| `.field-error` | Red italic inline error text |

### Pop Art Decorations
| Selector | Purpose |
|----------|---------|
| `.burst` | Star shape clip-path, decorative label |
| `.burst-right` / `.burst-left` | Position variants |
| `.omg` | Fixed onomatopoeia watermark |
| `.custom-checkbox` | Styled checkbox (appearance:none, teal+gold on checked) |
| `.settings-gear` / `#settings-btn` | Gear icon, rotates on hover |
| `.settings-dropdown` | Cream bg + dot pattern, "⚙ SETTINGS" header |

### Tables
| Selector | Purpose |
|----------|---------|
| `table` / `th` / `td` | Comic-themed table |
| `tr.stripe-even td` | Alternating row bg |
| `.status-badge` | Appointment status pill |

### Buttons
| Selector | Purpose |
|----------|---------|
| `.btn` | Base button style (ink bg, gold text, shadow) |
| `.btn-sm` | Smaller variant |
| `.btn-outline` | Inverted style |
| `.btn-pink` / `.btn-rust` | Colour variants |
| `.btn-recruit` | Green hire button |

### Modals
| Selector | Purpose |
|----------|---------|
| `.modal-overlay` | Fixed backdrop |
| `.modal-box` | Themed box with burst and content |
| `.msg-box` | Flash message variant |
| `.msg-content` | Message text |
| `.modal-close` | × close button |
| `.admin-nav` | Header nav row (Booking Page / Refresh) |

### Bubbles & Tooltips
| Selector | Purpose |
|----------|---------|
| `.bubble` | Speech bubble with triangular tail (cyan) |
| `.jagged-bubble` | Jagged-tail bubble variant |
| `.slot-tooltip` | Slot suggestion popup (gold bg, ink border) |
| `.quote-tooltip` | Mechanic quote bubble on card hover |

## 9. API Endpoints

### `availability.php`
| Parameter | Type | Required | Purpose |
|-----------|------|----------|---------|
| `mechanic_id` | int | Yes | Which mechanic |
| `date` | string (Y-m-d) | Yes | Which date |
| `slot_index` | int | No | For suggestion queries |

Response fields: `slots`, `on_vacation`, `all_slots`, `all_names`, `mechanic_first_name`, `mechanic_nickname`, `adjacent_slot`, `nearby_prev_date`, `nearby_next_date`

### `verify_pw` (POST, both `index.php` and `admin.php`)
| Field | Type | Purpose |
|-------|------|---------|
| `verify_pw` | 1 | Flag to trigger verification |
| `admin_pw` | string | The password to check |

Response: `{success: true|false}`

### GET Handlers (`admin.php`)
`?cancel=N`, `?fire=N`, `?restore=N`, `?remove=N`, `?remove_mechanic=N`, `?remove_all_cancelled`, `?unblock=N`, `?remove_vacation=N`

### POST Handlers (`admin.php`)
`update_appointment`, `sim_toggle`, `toggle_sim`, `add_mechanic`, `update_mechanic_info`, `update_schedule`, `add_vacation`, `override_slot`

## 10. Admin Dispatch Map

```
admin.php entry
├── AJAX verify_pw (line 11)
├── GET action handlers (line 17)
│   ├── remove_all_cancelled → handleRemoveAllCancelled()
│   ├── remove=N → handleRemove()
│   ├── cancel=N → handleCancel()
│   ├── fire=N → handleFire()
│   ├── restore=N → handleRestore()
│   ├── remove_mechanic=N → handleRemoveMechanic()
│   ├── unblock=N → handleUnblock()
│   └── remove_vacation=N → handleRemoveVacation()
├── POST action handlers (line 28)
│   ├── update_appointment → handleUpdateAppointment()
│   ├── sim_toggle → handleSimToggle()
│   ├── toggle_sim → handleToggleSim()
│   ├── add_mechanic → handleAddMechanic()
│   ├── update_mechanic_info → handleUpdateMechanicInfo()
│   ├── update_schedule → handleUpdateSchedule()
│   ├── add_vacation → handleAddVacation()
│   └── override_slot → handleOverrideSlot()
├── Flash message consumer (line 41)
├── advanceAppointmentStatuses() (line 48)
└── Data queries → render template (line 50)
```
