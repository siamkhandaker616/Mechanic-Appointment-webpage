# Mayhem Mobility — Developer Reference

## 1. File Inventory

| File | Lines | Role |
|------|-------|------|
| `index.php` | 393 | Booking page — customer form, PRG handling, password modal, FAQ, flash modal, spotlight overlay |
| `admin.php` | 717 | Admin panel — dispatch pattern, all management panels/modals, settings gear |
| `availability.php` | 146 | AJAX endpoint — slot availability + conflict suggestions |
| `functions.php` | 838 | Business logic — DB queries, CRUD, handlers, validation |
| `custom-select.js` | 303 | Custom-themed `<select>` replacement with key-tag trigger & dropdown |
| `spotlight.js` | 341 | Spotlight of Shame — validation mini-game with overlay/beam/burst animations |
| `script.js` | 1096 | Client logic — booking interactions, admin modals, gear init, utilities |
| `datepicker.js` | 288 | Custom date picker — singleton manager, calendar grid, placement control |
| `style.css` | 2139 | Full stylesheet — comic theme, spotlight, responsive |
| `config.php` | 36 | Constants — DB creds, slot config, status enums, admin password, timezone |
| `config.example.php` | 34 | Template — copy to config.php |
| `db-sync.php` | 39 | One-time migration — adds quote/theme columns to mechanics table |
| `sql/schema.sql` | 109 | 9 tables — full database schema |
| `sql/seed.sql` | ~30 | Seed data — 5 mechanics, schedules, sample appointments |

## 2. PHP — `functions.php` (838 lines)

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
| `getAllMechanics` | 422 | `(): array` | All mechanics (active + inactive) |
| `addMechanic` | 426 | `(string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote = null, string $theme = 'default'): int` | Insert mechanic + create 7-day all-false schedule |
| `updateMechanic` | 439 | `(int $id, string $name, ?string $nickname, ?string $specialties, int $years, ?string $quote = null, string $theme = 'default'): void` | Update mechanic details |
| `fireMechanic` | 444 | `(int $id): void` | Set `is_active = 0` |
| `restoreMechanic` | 456 | `(int $id): void` | Set `is_active = 1` |
| `removeMechanic` | 461 | `(int $id): void` | Hard delete from mechanics + related tables |

### Availability

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `isSlotAvailable` | 52 | `(int $mechanicId, string $date, int $slotIndex): bool` | Check schedule, overrides, appointments, vacations |
| `getMechanicSlotsAvailability` | 79 | `(int $mechanicId, string $date): array` | Array of 4 booleans for all slots |
| `getAdjacentSlotForMechanic` | 110 | `(int $mechanicId, string $date, int $slotIndex): ?int` | Nearest free slot (+1 or -1) |
| `getNearbyDatesForMechanic` | 118 | `(int $mechanicId, int $slotIndex, string $date): array` | ±30 days for same slot |

### Clients / Cars

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `findOrCreateClient` | 162 | `(string $name, string $phone, string $address): int` | Lookup by phone, update if returning |
| `findOrCreateCar` | 177 | `(int $clientId, string $licenseNo, string $engineNo, string $model): int` | Lookup by license, update if returning |
| `isCarBookedOnDate` | 156 | `(int $carId, string $date): bool` | Prevent double-booking |

### Appointments

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getAppointments` | 327 | `(?string $status = null): array` | All appointments with JOINs |
| `createAppointment` | 192 | `(int $clientId, int $carId, int $mechanicId, string $date, int $slotIndex): int` | Insert new appointment |
| `cancelAppointment` | 385 | `(int $appointmentId): bool` | Set cancelled + timestamp |
| `validateSlotAssignment` | 348 | `(int $mechanicId, string $date, int $slotIndex, ?int $excludeAppointmentId = null): array` | Check schedule/override/conflict, return error list |
| `validateAppointmentInput` | 393 | `(array $data): array` | Field-level validation. Conditional mechanic/slot messages |
| `advanceAppointmentStatuses` | 247 | `(): void` | Two-pass auto-advance (reversion + forward) |

### Schedule / Vacations

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getMechanicSchedule` | 471 | `(int $id): array` | Day-of-week slot map |
| `updateMechanicSchedule` | 485 | `(int $id, array $schedule): void` | Update day × slot grid |
| `getMechanicVacations` | 498 | `(int $mechanicId): array` | All vacations for a mechanic |
| `addMechanicVacation` | 504 | `(int $mechanicId, string $startDate, string $endDate, ?string $reason): void` | Insert vacation period |
| `removeMechanicVacation` | 509 | `(int $id): void` | Delete vacation by ID |
| `isMechanicOnVacation` | 514 | `(int $mechanicId, string $date): bool` | Check if mechanic is on vacation on a date |

### Simulation

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `getEffectiveTime` | 237 | `(): DateTime` | Returns simulated datetime or real DateTime |

### Redirect

| Function | Line | Signature | Purpose |
|----------|------|-----------|---------|
| `flashAndRedirect` | 522 | `(string $msg, string $type = 'success', string $url = 'admin.php'): never` | Set session flash vars, redirect, exit |

### Action Handlers (20)

All `handle*()` functions follow the pattern: read input, perform action, call `flashAndRedirect()`. All return `never`.

| Handler | Line | Trigger | Action |
|---------|------|---------|--------|
| `handleRemoveAllCancelled` | 555 | GET `?remove_all_cancelled` | DELETE all cancelled appointments |
| `handleRemoveAllCompleted` | 563 | GET `?remove_all_completed` | DELETE all completed appointments |
| `handleRemove` | 571 | GET `?remove=N` | DELETE cancelled appointment by ID |
| `handleCancel` | 582 | GET `?cancel=N` | SET status = cancelled |
| `handleReBook` | 594 | GET `?rebook=N` | Re-book a cancelled appointment (clone) |
| `handleReBookConfirm` | 629 | POST `rebook_confirm` | Confirm re-book with new date/slot |
| `handleFire` | 645 | GET `?fire=N` | SET is_active = 0 |
| `handleRestore` | 656 | GET `?restore=N` | SET is_active = 1 |
| `handleRemoveMechanic` | 665 | GET `?remove_mechanic=N` | Hard delete mechanic |
| `handleUnblock` | 675 | GET `?unblock=N` | DELETE override row |
| `handleRemoveVacation` | 683 | GET `?remove_vacation=N` | DELETE vacation row |
| `handleUpdateAppointment` | 695 | POST `update_appointment` | Update date/slot/mechanic |
| `handleEditBooking` | 743 | POST `edit_booking` | Edit booking client/car/date/slot/mechanic |
| `handleSimToggle` | 786 | POST `sim_toggle` | Toggle simulated time on/off |
| `handleToggleSim` | 798 | POST `toggle_sim` | Set simulated datetime |
| `handleAddMechanic` | 812 | POST `add_mechanic` | Insert new mechanic |
| `handleUpdateMechanicInfo` | 827 | POST `update_mechanic_info` | Edit mechanic details |
| `handleUpdateSchedule` | 848 | POST `update_schedule` | Update day × slot grid |
| `handleAddVacation` | 879 | POST `add_vacation` | Insert vacation period |
| `handleOverrideSlot` | 906 | POST `override_slot` | Block specific slots on a date |

## 3. JavaScript — `spotlight.js` (341 lines)

### Validation

| Function | Line | Purpose |
|----------|------|---------|
| `validateBookingForm()` | 15 | Check all fields against `data-validate` rules. Returns `{el, msg}[]`, `{dateMsg}`, or `[]` |
| `revalidateField(el)` | 204 | Re-check a single field. Returns error string or `null` |

### Spotlight lifecycle

| Function | Line | Purpose |
|----------|------|---------|
| `launchSpotlight(errors)` | 49 | Create overlay, beam, glow, burst. Lock all fields, focus first error. Add scroll + focusin listeners |
| `advanceSpotlight()` | 132 | Lock current field, unlock next, reposition elements, reset burst animation. Dismiss when done |
| `dismissSpotlight(callback)` | 175 | Remove all overlay elements, unlock fields, hide banner, detach listeners, cleanup after 400ms, invoke callback |

### Positioning

| Function | Line | Purpose |
|----------|------|---------|
| `positionSpotlight(error)` | 289 | Set curtain sizes, beam clip-path, glow position, burst position via getBoundingClientRect |
| `repositionOnScroll()` | 118 | Debounced (120ms). Add `.scroll-hide` to burst during scroll, remove after scroll stops |

### Focus handling

| Function | Line | Purpose |
|----------|------|---------|
| `handleSpotlightFocus(e)` | 216 | Attach blur + keydown listeners to current field. Revalidate on blur/Enter. Randomise phone bursts, advance on valid, show burst on invalid. Prevent leak via `_lastBlurHandler`/`_lastKeydownHandler` |

### Inline error fallback

| Function | Line | Purpose |
|----------|------|---------|
| `window.showInlineErrors(errs)` | — | Append `.field-error` spans under each invalid field |
| `window.hideInlineErrors()` | — | Remove all `.field-error` spans |

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

## 4. JavaScript — `script.js` (1096 lines)

### Global utilities

| Function | Line | Purpose |
|----------|------|---------|
| `debounce(fn, ms)` | 8 | Debounce helper for rapid events |
| `htmlspecialchars(s)` | 17 | DOM-based string escape for HTML |
| `fmtDate(isoStr)` | 23 | ISO → "D Mon YYYY" |
| `repositionPastDateMsg()` | 29 | Position date-error bubble below submit button |
| `showPastDateMsg(msg)` | 41 | Show jagged date-error bubble |
| `hidePastDateMsg()` | 58 | Hide date-error bubble |
| `initNumStepper(input)` | 374 | Up/down button stepper for number inputs |
| `updateStepperBg(input)` | 480 | Stepper bg colour based on readOnly |

### Booking page

| Function | Line | Purpose |
|----------|------|---------|
| `isOnVacation(mechId, date)` | 65 | Check VACATION_DATA overlap |
| `updateVacationBadges(date)` | 73 | Toggle "ON VACATION" badges on mechanic cards |
| `updateQuotePosition(card)` | 89 | Position quote tooltip relative to card |
| `selectMechanic(id)` | 111 | Select mechanic card, fetch availability |
| `hideTooltip()` | 137 | Hide suggestion tooltip |
| `showTooltip(el)` | 142 | Fetch suggestions for taken slot, render chips |
| `fetchAvailability()` | 204 | Call availability.php, render slot chips |
| `selectSlot(el, index)` | 242 | Highlight slot chip, set hidden input |
| `fillSuggestion(mechId, date, slotIndex, scrollToCard)` | 248 | Fill form from suggestion, scroll if `scrollToCard` true |
| Submit handler | — | Validate via `validateBookingForm()`, branch on `window.SPOTLIGHT_DISABLED` |

### Admin page — Search & Filter

| Function | Line | Purpose |
|----------|------|---------|
| `filterAppTable()` | 270 | Filter appointment table rows by search input + status |
| `openSearchModal()` | 294 | Show search info modal |
| `closeSearchModal(event)` | 298 | Hide search info modal |
| `clearFilters()` | 304 | Reset search + status filter, show all rows |

### Admin page — Unsaved changes guard

| Function | Line | Purpose |
|----------|------|---------|
| `confirmUnsaved(callback)` | 342 | Prompt before navigating with unsaved changes |
| `confirmUnsavedGo()` | 357 | Confirm and proceed |
| `cancelUnsaved()` | 362 | Cancel navigation, restore scroll |

### Admin page — Password gate

| Function | Line | Purpose |
|----------|------|---------|
| `checkSimGuard()` | 495 | Block actions if sim mode is off |
| `closeSimBlockModal(event)` | 502 | Close sim-block modal |
| `requirePw(actionUrl, guardSim)` | 507 | Set pending action, open password modal |
| `requirePwNewTab(actionUrl)` | 511 | Open password guard in new tab |
| `requirePwForForm(form)` | 514 | Open modal, submit form on success |
| `requirePwForField(fieldId)` | 517 | Open modal, unlock field on success |
| `openPwModal()` | 522 | Show password modal, focus input |
| `togglePwVisibility()` | 530 | Show/hide password text |
| `confirmPw()` | 541 | XHR verify_pw, execute pending action on success; always re-enables button via try/catch + onloadend |
| `closePwModal()` | 596 | Hide password modal |

### Admin page — Management panels

| Function | Line | Purpose |
|----------|------|---------|
| `toggleEdit(id, btn)` | 603 | Show/hide inline edit row |
| `toggleOverrides()` | 608 | Show/hide Active Overrides panel |
| `openMechModal(btn)` | 617 | Fill Edit Mechanic modal from data attributes |
| `openMechModalById(id, name, nickname, quote, specialties, experience)` | 637 | Programmatic modal open for new-hire |
| `closeMechModal(event)` | 657 | Close with new-hire redirect |
| `openScheduleModal(id, name)` | 673 | Open schedule grid modal |
| `toggleUpdateApptBtn(el)` | 688 | Enable/disable Update button on change |
| `closeScheduleModal(event)` | 700 | Close schedule grid modal |
| `validateOverrideForm()` | 701 | Client-side override validation |
| `clearOverrideError()` | 713 | Hide override error |
| `validateRecruitForm()` | 717 | Client-side recruit form validation |
| `showBurstOver(el)` | 730 | Animate burst decoration over element |
| `renderVacations(id, mechName)` | 760 | Populate vacation list in Edit modal |
| `addVacation()` | 779 | Submit vacation via hidden form; guard checks all days×slots for any enabled slot |

### Admin page — Modal helpers (inlines)

| Function | Purpose |
|----------|---------|
| `showCancelModal(id)` | Show cancel confirmation |
| `closeCancelModal(event)` | Hide cancel confirmation |
| `showFireModal(id, name, count)` | Show fire confirmation with cancel count |
| `closeFireModal(event)` | Hide fire confirmation |
| `showRemoveModal(id)` | Show remove confirmation |
| `closeRemoveModal(event)` | Hide remove confirmation |
| `showUnblockModal(id, name, date)` | Show unblock confirmation |
| `closeUnblockModal(event)` | Hide unblock confirmation |
| `closeConflictModal(event)` | Hide conflict modal |
| `closeMsgModal(event)` | Hide flash message modal |
| `closeQbFailModal(event)` | Hide quick-book failure modal |

### Admin page — Quick Book

| Function | Line | Purpose |
|----------|------|---------|
| `openQuickBook()` | 846 | Open Quick Book panel |
| `lookupQuickBook()` | 853 | Search appointments by phone or license |

### Admin page — Edit Booking

| Function | Line | Purpose |
|----------|------|---------|
| `openEditBooking()` | 913 | Open Edit Booking panel |
| `lookupEditBooking()` | 920 | Search appointment by ID for editing |
| `openEditModal(appt)` | 964 | Populate and show edit appointment modal |

### Settings gear init (DOMContentLoaded)

| Element | Behaviour |
|---------|-----------|
| `#settings-btn` click | Toggle `.hidden` on `#settings-dropdown` |
| Document click outside | Close dropdown |
| `#spotlight-toggle` change | Write to localStorage, `location.reload()` |
| `window.SPOTLIGHT_DISABLED` fallback | Read from localStorage if spotlight.js not loaded |

## 5. JavaScript — `custom-select.js` (351 lines)

IIFE that replaces `<select class="custom-select">` with a custom-themed dropdown using fold/unfold panel animations.

| Function | Line | Purpose |
|----------|------|---------|
| `createCustomSelect(selectEl)` | 4 | Wraps native `<select>` in `.custom-select-wrap` with trigger + fold-panel dropdown; applies `.theme-cyan` for override_mechanic, `.fire-swap` for fire-swap selects |
| `buildOptions()` | 57 | Creates fold-panel per `<option>`, each with `.fold-paper` containing option label and optional key-tag letter (first char parenthesised) |
| `selectOption(idx)` | 114 | Updates native `selectedIndex`, dispatches `change` event |
| `syncFromNative()` | 126 | Syncs visual state from native select (for external changes) |
| `measurePanelHeights()` | 136 | Returns array of each panel's `.fold-paper` height for animation |
| `openDropdown()` | 156 | Opens with staggered fold-out animation (paper rotates 92deg→0, clip-path reveals downwards) |
| `abortOpen()` | 228 | Cancels in-progress open animation and resets to closed state |
| `closeDropdown()` | 249 | Closes with staggered reverse animation (papers fold up, clip-path shrinks) |
| `toggleDropdown()` | 302 | Opens if closed, closes if open |

### Keyboard navigation

| Key | Action |
|-----|--------|
| Enter / Space | Toggle open/close |
| Escape | Close, refocus trigger |
| ArrowDown | Open if closed, else next option |
| ArrowUp | Open if closed, else previous option |

### Click-outside close

Global click listener closes any open dropdown when clicking outside its `.custom-select-wrap` (lines 341–350).

## 6. JavaScript — `datepicker.js` (288 lines)

| Component | Purpose |
|-----------|---------|
| `DPM` singleton | Manager with scroll/resize/click listeners, registered per-picker |
| Calendar grid | Month navigation, day selection, today highlight |
| Month tooltip | Hover shows month/year |
| Confirm mode | `data-confirm` attribute on input |
| Placement | `data-placement`: auto / top |
| Lazy creation | Popup created on first focus |
| Viewport edge avoidance | Repositions if near edge |

## 7. Config — `config.php`

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

## 8. Database Schema

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

## 9. CSS Component Reference

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
| `--teal-light` | #3a9b9b | Checkbox hover, accent |
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
| `.custom-checkbox` | Styled checkbox (20×20px, appearance:none, `var(--border)` outline, SVG lightning bolt via background-image, hover → `--teal-light`, checked → `--teal`) |
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

## 10. API Endpoints

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
`?cancel=N`, `?fire=N`, `?restore=N`, `?remove=N`, `?remove_mechanic=N`, `?remove_all_cancelled`, `?remove_all_completed`, `?rebook=N`, `?unblock=N`, `?remove_vacation=N`

### POST Handlers (`admin.php`)
`update_appointment`, `edit_booking`, `rebook_confirm`, `sim_toggle`, `toggle_sim`, `add_mechanic`, `update_mechanic_info`, `update_schedule`, `add_vacation`, `override_slot`

## 11. Admin Dispatch Map

```
admin.php entry
├── AJAX verify_pw (line 11)
├── GET action handlers (line 19)
│   ├── remove_all_cancelled → handleRemoveAllCancelled()
│   ├── rebook → handleReBook()
│   ├── remove_all_completed → handleRemoveAllCompleted()
│   ├── remove=N → handleRemove()
│   ├── cancel=N → handleCancel()
│   ├── fire=N → handleFire()
│   ├── restore=N → handleRestore()
│   ├── remove_mechanic=N → handleRemoveMechanic()
│   ├── unblock=N → handleUnblock()
│   └── remove_vacation=N → handleRemoveVacation()
├── POST action handlers (line 35)
│   ├── update_appointment → handleUpdateAppointment()
│   ├── edit_booking → handleEditBooking()
│   ├── rebook_confirm → handleReBookConfirm()
│   ├── sim_toggle → handleSimToggle()
│   ├── toggle_sim → handleToggleSim()
│   ├── add_mechanic → handleAddMechanic()
│   ├── update_mechanic_info → handleUpdateMechanicInfo()
│   ├── update_schedule → handleUpdateSchedule()
│   ├── add_vacation → handleAddVacation()
│   └── override_slot → handleOverrideSlot()
├── Flash message consumer (line 48)
├── advanceAppointmentStatuses() (line 55)
└── Data queries → render template (line 56)
```
