# Mayhem Mobility — Project Documentation

## 1. Project Overview

`Mayhem Mobility` is a PHP/MySQL car workshop appointment system for CSE 391 Assignment 3. Customers can book 2-hour time slots with mechanics without creating an account. An admin panel provides full management: appointment editing, mechanic hiring/firing, schedule overrides, vacation tracking, and a simulated clock for testing.

The design uses a retro 1960s/70s pop art and comic strip aesthetic — Ben-Day dots, jagged speech bubbles, action bursts, onomatopoeia watermarks, comic panel rotation, and bold display fonts. The palette is ink-on-cream-paper with teal, navy, rust, pink, gold, and cyan accents.

The project is built with PHP 8+, MySQL (MariaDB), and vanilla JavaScript. It does not use a frontend or backend framework.

## 2. Setup

### Local (XAMPP)

1. Place the project folder in your web server root.
2. Copy `config.example.php` to `config.php` and update DB credentials. Set `ADMIN_PW` and `SITE_URL`.
3. Import `sql/schema.sql` and `sql/seed.sql` into MySQL.
4. Start Apache and MySQL from XAMPP Control Panel.
5. Open `http://localhost/assignment3/index.php` in a browser.
6. Admin panel is at `admin.php`.

### Live (InfinityFree)

- Booking: `https://mayhem-mobility.page.gd`
- Admin: `https://mayhem-mobility.page.gd/admin.php`

## 3. File Structure

```text
Assignment 3/
  index.php               Booking page (customer-facing)
  admin.php               Admin panel (management interface)
  availability.php        AJAX endpoint for slot availability
  config.php              DB credentials, constants, slot labels
  functions.php           All business logic, DB queries, handler functions
  spotlight.js            Spotlight of Shame validation mini-game
  script.js               Client-side logic (booking + admin, ~1096 lines)
  datepicker.js            Custom-themed date picker widget (~288 lines)
  style.css               Full stylesheet (~2139 lines)
  README.md               Quick-start guide
  sql/
    schema.sql            Full database schema (9 tables)
    seed.sql              Seed data (mechanics, schedule, appointments)
  docs/
    PROJECT_DOCUMENTATION.md
    DEVELOPER_REFERENCE.md
    AI_DECLARATION.txt
  fonts/
    Action Man Bold.ttf
    Action Man Shaded Italic.ttf
    Bangers.woff2
    LuckiestGuy-Regular.woff2
    PermanentMarker-Regular.woff2
    WalterTurncoat-Regular.woff2
  images/
    icons/
      tagline.png         Header tagline
      pow.svg             Appointment confirmation burst
      bg.webp             Background texture
    doodles/
      *.svg               Decorative SVGs (gear, eye-open/closed, burst-vroom,
                          hero-helmet, hourglass, lightning, oil-can,
                          spark-plug, speech-bubble-1/2/3, stiletto, super-shield,
                          wonder-tiara, wrench, magnifying-glass,
                          magnifying-glass-hover)
    bursts/
      blank.svg, bzzt.svg, nada.svg, nope.svg, zilch.svg
```

## 4. Pages

### `index.php` — Booking Page

The customer-facing booking form. No login required — customers are identified by phone number.

Processing flow:

1. On initial GET, the page renders an empty form plus a mechanic card grid and an empty slot grid.
2. On POST, `validateAppointmentInput()` checks required fields, date format, phone format, engine number format.
3. If invalid, `$_SESSION['flash_msg']` / `$_SESSION['flash_type']` are set and the request redirects back to GET (PRG pattern). Form values preserved via `$_SESSION['booking_post']` → `$savedPost`.
4. If valid, `findOrCreateClient()` looks up or creates a client by phone number. `findOrCreateCar()` does the same for the car by license number.
5. `isCarBookedOnDate()` prevents duplicate bookings for the same car on the same day.
6. `isSlotAvailable()` does a final concurrency check on the chosen slot.
7. On success, an inline confirmation panel replaces the form (`$success = true` branch).

Also contains an AJAX `verify_pw` endpoint for the password-gated "Admin Panel" link, a password modal, a flash message modal for PRG errors, a Help & Info FAQ section, the spotlight overlay element, and the shame banner.

### `admin.php` — Admin Panel

The management interface with password-gated destructive actions. No session-based login — the password is checked per-action via an AJAX endpoint or inline POST verification.

Template sections:

- **Simulated time panel** — toggle, datetime picker, Set button
- **All Appointments table** — per-row Edit (inline date/slot/mechanic swap), Cancel, Remove
- **Active Overrides table** — lists blocked date/slot combinations with Unblock
- **Schedule Override form** — per-mechanic, per-date slot blocking with conflict detection
- **All Mechanics table** — Edit, Schedule, Fire/Restore, Remove
- **Register New Mechanic form** — collapsible `<details>` section
- **Edit Mechanic modal** — name, nickname, quote, specialties, exp; name/exp password-locked
- **Schedule modal** — day × slot checkbox grid
- **Vacation list** — inline within Edit Mechanic modal, with Add/Remove
- **Conflict modal** — shown when override would clash with existing appointments
- **Password modal** — generic password gate for destructive actions
- **Flash message modal** — shown after redirect on success/failure
- **Settings gear** — "Disable Spotlight of Shame" toggle (localStorage, affects booking page)

### `availability.php` — AJAX Slot Endpoint

Called by the booking page to fetch slot availability for a mechanic on a given date. Returns which slots are free, taken, or blocked. When a taken slot is clicked, returns alternative suggestions (adjacent slots, nearby dates, other mechanics free at that time).

## 5. Database Structure

9 tables:

- **`mechanics`** — id, name, nickname, quote, theme, specialties, years_experience, is_active, created_at
- **`mechanic_schedule`** — id, mechanic_id, day_of_week, slot_1..slot_4 (unique on mechanic+day)
- **`mechanic_overrides`** — id, mechanic_id, override_date, slot_1..slot_4, reason (unique on mechanic+date)
- **`mechanic_vacations`** — id, mechanic_id, start_date, end_date, reason
- **`clients`** — id, name, phone (unique), address, created_at
- **`cars`** — id, client_id, license_no (unique), engine_no, model, created_at
- **`appointments`** — id, client_id, car_id, mechanic_id, date, slot_index, status, cancelled_at, admin_notes, timestamps (partial unique on car+date for non-cancelled rows)
- **`reviews`** — id, appointment_id (unique), rating 1-5, comment, created_at
- **`sim_config`** — id (singleton), use_simulated_time, simulated_datetime

## 6. Features

### 6.1 Booking Flow

**What it does:** A customer selects a mechanic from the card grid, picks a date, sees available time slots highlighted in green, clicks one, fills in their details (name, phone, address, car info), and books. If validation fails, the form is repopulated with their entries and a flash message explains the error.

**How it works (implementer notes):** Form submission is validated client-side by `spotlight.js` (`validateBookingForm()`) and server-side by `functions.php` (`validateAppointmentInput()`). Server errors use the PRG pattern: `$_SESSION['flash_msg']` + `$_SESSION['booking_post']` are set, the page redirects back to GET, and the form is repopulated from `$savedPost`. The form uses `novalidate` — HTML5 validation is replaced by the custom Spotlight of Shame system. Slots are fetched via `availability.php` which checks `isSlotAvailable()` against the mechanic's schedule, overrides, existing appointments, and vacations.


### 6.2 Slot Suggestions & Conflict Handling

**What it does:** If a customer clicks a grey (taken) slot, a tooltip appears with suggestions: the same slot on nearby dates (±30 days), the same date with an adjacent free slot, or the same slot with a different mechanic who is free at that time. Clicking a suggestion auto-fills the form with the new mechanic/date/slot. If a "different mechanic" suggestion is used, the page scrolls to that mechanic's card.

**How it works (implementer notes):** `showTooltip()` calls `availability.php?mechanic_id=X&date=Y&slot_index=Z`. The endpoint runs `getAdjacentSlotForMechanic()` (+1/-1 slot), `getNearbyDatesForMechanic()` (±30 days), and queries all other mechanics' availability for that slot. Results are rendered as clickable chips that call `fillSuggestion(mechId, date, slotIndex, scrollToCard)` — when `scrollToCard` is true, `card.scrollIntoView({ behavior:'smooth', block:'center' })` is called.

### 6.3 Admin — Appointment Management

**What it does:** The All Appointments table lists every booking with client, car, date, slot, mechanic, and status. Each scheduled appointment has Edit and Cancel buttons. Edit opens an inline row with dropdowns for date, slot, and mechanic — any change enables the Update button. Cancel sets the status to cancelled. Cancelled appointments can be hard-deleted with Remove. A "Remove All Cancelled" bulk action is available at the bottom.

**How it works (implementer notes):** `handleUpdateAppointment()` handles date/slot/mechanic changes in a single POST handler (`?update_appointment`). It validates via `validateSlotAssignment()` which checks schedule, overrides, and appointment conflicts. Cancel/remove actions are GET handlers guarded by the password gate. The inline edit row visibility toggles via `toggleEdit()`, and the Update button enable/disable is managed by `toggleUpdateApptBtn()` which compares current values to `data-original-*` attributes.

### 6.4 Admin — Mechanic Management

**What it does:** The All Mechanics table shows every mechanic with name, nickname, specialties, years of experience, and status (Active / On Leave / Inactive). Active mechanics can be Edited (name, nickname, quote, specialties, exp — name and exp are password-locked), have their weekly Schedule configured (day × slot checkboxes), or be Fired (soft-delete, becomes Inactive). Inactive mechanics can be Restored (rehired) or Removed (hard-delete). New mechanics are registered via a collapsible form. After hiring, the Edit modal auto-opens so the admin can fill in details and set the schedule. Vacations are managed inline within the Edit modal — pick start/end dates and optionally a reason.

**How it works (implementer notes):** `addMechanic()` inserts the mechanic and creates a 7-day schedule with all slots false (the admin must configure availability via the Schedule modal). `fireMechanic()` sets `is_active = 0`. `removeMechanic()` hard-deletes from all related tables. The new-hire auto-open mechanic modal is driven by a JS block in `admin.php` that checks `$_GET['new_mechanic']` and calls `openMechModalById()`. A hidden `_new_hire_name` input carries the new hire's name through forms (update info, schedule, vacation) so the server redirects with a hired message. Vacations are stored in `mechanic_vacations` and checked via `isMechanicOnVacation()`. A client-side guard in `addVacation()` blocks sending a mechanic on vacation if they have no enabled schedule slots — the admin must set availability via the Schedule button first.

### 6.5 Admin — Schedule Overrides

**What it does:** The admin can block specific time slots for a mechanic on a specific date (e.g., sick day, early leave). The form selects a mechanic, date, which slots to block, and an optional reason. If any existing non-cancelled appointments occupy those slots, a conflict modal lists the affected clients and the save is blocked. Active overrides are shown in a table with an Unblock button.

**How it works (implementer notes):** `handleOverrideSlot()` POST handler uses `ON DUPLICATE KEY UPDATE` so re-overriding the same mechanic+date updates in place. Conflict detection queries appointments with matching mechanic/date/slot and status != cancelled/completed. Client-side validation via `validateOverrideForm()` ensures mechanic and date are filled.

### 6.6 Admin — Simulated Clock

**What it does:** The Simulated Time panel lets the admin toggle a fake clock. When enabled, the admin sets a specific datetime and the system behaves as if that is the current time. Appointments auto-advance (scheduled → in_progress → completed) based on the simulated time. This enables time-travel testing of status transitions. The panel highlights when active.

**How it works (implementer notes):** `getEffectiveTime()` checks `sim_config.use_simulated_time`. If true, returns `sim_config.simulated_datetime`; otherwise returns `new DateTime()`. `advanceAppointmentStatuses()` runs a two-pass system: a reversion pass (resets incorrectly advanced appointments), then a forward pass (advances based on slot start/end times vs effective time). Runs once per admin page load after POST handling.

### 6.7 Password Gate

**What it does:** Destructive or sensitive actions require an admin password. A modal pops up asking for the password, which is verified via AJAX. On success, the action executes. On failure, an error is shown inside the modal and the user can retry. The modal supports Escape to close and Enter to confirm.

**Actions that require password:** Cancel appointment, Remove cancelled appointment, Remove all cancelled, Fire mechanic, Remove mechanic, Edit appointment, Unblock override, Rebook appointment, Archive Completed, Unlock name/exp in Edit Mechanic modal.

**Actions that do NOT require password:** Restore mechanic, Remove vacation, Hire mechanic, Sim toggle, Schedule update, Add vacation.

**How it works (implementer notes):**

Three entry points:
- `requirePw(actionUrl, newTab?)` — for link-style actions. Sets `_pendingAction` (and optionally `_pendingNewTab`) and opens the modal. Used by Cancel, Fire, Remove, Unblock, Rebook, Archive Completed.
- `requirePwForField(fieldId)` — for unlocking read-only fields (name, experience). Sets `_pendingField` and opens the modal. On success, the field becomes writable.
- `requirePwForForm(form)` — for form submissions (override slot). Sets `_pendingForm` and opens the modal. On success, a hidden `admin_pw` input is appended and the form is submitted.

`confirmPw()` sends `verify_pw=1&admin_pw=X` via XHR to `admin.php`. Server compares against `ADMIN_PW` constant and returns `{success: bool}`. On success, the pending action is followed. On failure, an error is shown and the user can retry. The confirm button is disabled during the request and always restored via `onloadend` (handles success, network error, or abort).

A global `keydown` listener closes the password modal on Escape and triggers confirmation on Enter.

### 6.8 Status Auto-Advance

Appointments automatically move through statuses based on the effective time (real or simulated):

- `scheduled → in_progress`: when current time passes the slot start time
- `in_progress → completed`: when current time passes the slot end time

A reversion pass runs first to reset any appointments that were incorrectly advanced (e.g., after moving the sim clock backwards). Runs once per admin page load.

### 6.9 Spotlight of Shame

**What it does:** When the customer submits the form with missing or invalid fields, the page darkens — four curtains close in around the first error, a yellow spotlight beam shines down from the top of the viewport, a comic-style burst graphic (one of five hand-drawn SVGs: blank, zilch, nada, bzzt, nope) pops up next to the field, and a menacing "YOU ARE LOCKED IN" banner slides in at the top. All other fields become read-only. The customer must fix each field one by one — the spotlight advances to the next error only after the current field is corrected. On valid entry (blur or Enter), the burst fades and the spotlight moves on. The banner reads "the spotlight will guide you to each field — fix it, then move on."

If the admin disables the feature via the settings gear (admin page header), form errors instead show inline red text beneath each field and the page scrolls to top on submit. The spotlight toggle is stored in `localStorage` and persists across sessions.

**How it works (implementer notes):** Managed entirely by `spotlight.js`. `validateBookingForm()` checks form fields against `data-validate`/`data-err-*` attributes. `launchSpotlight()` creates overlay curtains (positioned via `getBoundingClientRect`), a beam (CSS `clip-path: polygon()` cone), a glow (gold border rim), and positions an error burst SVG. `advanceSpotlight()` moves to the next error, re-randomising phone-field bursts from `PHONE_BURST_KEYS` while keeping other fields on their fixed shuffled key. `repositionOnScroll()` hides bursts during scroll (120ms debounce). Listener cleanup uses `_lastBlurHandler`/`_lastKeydownHandler` to prevent leaks. A 600ms cooldown timer prevents the auto-focus burst from immediately fading out.

## 7. Theme & Customization

The entire interface is hand-crafted as a retro 1960s/70s pop art and comic strip experience. Every pixel — from the font loading strategy to the Ben-Day dot backgrounds, from the hand-drawn doodle SVGs to the rotation angle of each panel — was deliberately chosen to sell the illusion that you are not using a web application, but flipping through a comic book.

### 7.1 Art Direction

The guiding principle: **ink on newsprint**. The cream base simulates aged comic paper; the near-black ink stands in for printer's ink. Teal, rust, gold, and pink provide the four-colour process feel. The slightly uneven panel rotations mimic a page where panels were physically cut and pasted. The jagged speech bubbles, the burst graphics, the onomatopoeia watermarks — every element references a specific comic book convention.

All decorative graphics are original vector SVG artwork, drawn by hand in Inkscape and Illustrator, then optimised with SVGO. No stock assets, no clip art, no AI-generated graphics.

### 7.2 Colour Palette

| Variable | Hex | Role |
|----------|-----|------|
| `--ink` | `#1a1a2e` | Text, primary borders — a warm near-black that avoids harshness |
| `--paper` | `#e0cc5a` | Page background — aged cream, not sterile white |
| `--cream` | `#ecd94d` | Panel/card background — warmer than newsprint |
| `--teal` | `#2a6b6b` | Primary accent — complementary to cream, recalls ink+water |
| `--teal-light` | `#3a9b9b` | Checkbox checked state, hover states |
| `--teal-dark` | `#1a4a4a` | Subtitle text, depth accent |
| `--rust` | `#a0453b` | Danger, cancellation, shame — warm red-brown |
| `--pink` | `#d63384` | Pop energy burst, decorative accents |
| `--gold` | `#f5c518` | Highlights, banner text, star accents |
| `--navy` | `#16213e` | Table headers, deep accent |
| `--cyan` | `#caeded` | Speech bubble fill — the only light cool colour |
| `--shadow-md` | `rgba(26,26,46,0.4)` | Box shadows — matches ink colour |

Pairs are deliberately mismatched: teal buttons with gold text, rust banners with gold text, pink accents against cream backgrounds. The result is deliberately loud — a pop art comic should not be subtle.

### 7.3 Font System

Six self-hosted font files — five actively used, one (Action Man Shaded Italic) a leftover from an earlier iteration:

| Font | Use | Fallback Chain |
|------|-----|----------------|
| **Bangers** (WOFF2) | Headlines, panel titles, all-caps emphasis | `cursive, fantasy, Impact` |
| **Luckiest Guy** (WOFF2) | Subheadings, nav items | `cursive, serif` |
| **Permanent Marker** (WOFF2) | Accents, quote text | `cursive, "Comic Sans MS"` |
| **Walter Turncoat** (WOFF2) | Body text, form labels, table content | `cursive, sans-serif` |
| **Action Man Bold** (TTF) | Burst graphics, onomatopoeia | `Impact, sans-serif` |
| ~~Action Man Shaded Italic (TTF)~~ | (orphan — file exists but not referenced in any stylesheet) | — |

The self-hosting was deliberate: Google Fonts would break the offline XAMPP experience, introduce latency, and leak user data. Fonts were downloaded, converted to WOFF2 for compression, and served from `/fonts/`. The fallback chains ensure each font is replaced by a visually similar system font in the same generic category.

Between the five active font faces, only **one** (`<h1>` headline) uses a standard serif keyword — everything else maps to `cursive` or `fantasy`, pushing the page further into hand-drawn territory even when the custom font fails to load.

### 7.4 Ben-Day Dots

The background is built from layered CSS `radial-gradient()` circles — a Ben-Day dot pattern that mimics the four-colour halftone printing process used in comic books of the 1960s.

```css
/* Simplified — actual uses three overlapping gradients */
background-image:
  radial-gradient(circle, var(--ink) 1px, transparent 1px),
  radial-gradient(circle, transparent 0.5px, var(--paper) 0.5px);
```

The effect is applied to the `<body>` background, creating the texture of cheap newsprint. The dot density and colour shift subtly between page sections (cream dots over the body, darker dots on the settings dropdown). In the spotlight overlay, the curtains layer a noise texture (`data:image/svg+xml` base64 encoded SVG) with a halftone dot overlay to simulate the feel of a comic panel that has been physically darkened.

### 7.5 Panel System

Every section on both pages is wrapped in a `.panel` — a box that reads like a comic strip panel:

- **Rotation**: Even panels rotate `-0.6deg`, odd panels `0.4deg` — alternating to create a hand-placed, slightly chaotic layout. The rotation is subtle enough not to disorient but noticeable enough to suppress the "perfectly aligned web app" feel.
- **Dashed stripe**: `::before` creates a teal dashed line across the top, like a comic panel border.
- **Corner square**: `::after` places a filled pink `■` character in the bottom-right corner — referencing the solid colour blocks that appear in comic panel corners.
- **Shadow**: `2px 2px 0 var(--ink)` — not a blur shadow but a hard offset, like a misaligned printing plate.
- **Booking panel exception**: The main booking panel has `border-top: none` — the dashed stripe sits on top of the form rather than repeating the border.

Key panels decorated this way: booking panel, mechanic cards, each admin section (appointments, mechanics, overrides, schedule), modals, the flash message box.

### 7.6 Hand-Drawn SVGs

All SVG image assets — bursts, doodles, and icons — are original vector artwork drawn by hand, not AI-generated or auto-traced. Every curve was placed point by point.

#### Spotlight Error Bursts (`images/bursts/`)

Five SVGs created for the spotlight validation mini-game:

| File | Design Detail |
|------|---------------|
| `blank.svg` | An empty burst shape — the point is the shape itself, not the text. A stylised jagged starburst with rounded tips and alternating long/spike points. Used as the base animation container for all error bursts. |
| `zilch.svg` | The word "ZILCH" hand-lettered in bold sans-serif, slanted right. Each letter is a separate path to allow independent positioning within the burst star. The "Z" is oversized, the "H" is undersized — deliberate pop art lettering irregularity. |
| `nada.svg` | "NADA" in bold italic, with a sharp upward slant. The "D" has an exaggerated bowl, the "A"s are asymmetrical. Letter spacing is tight — the word fills the star like a label bursting at the seams. |
| `bzzt.svg` | The most complex of the set. "BZZT" with electrical discharge zig-zags radiating from the letters. Three lightning-bolt paths (one per Z) created as separate strokes, each with 6–8 jagged segments. The "B" is drawn as two disconnected lobes to suggest the electric shock interfering with the letterform itself. |
| `nope.svg` | "NOPE" with two exclamation marks stacked diagonally. The "O" is drawn as an irregular ellipse — not a perfect circle — to maintain the hand-drawn feel. The "P" has an exaggerated descender that curves into the star point. |

Each SVG was then hand-optimised: path data minified (decimal precision reduced, redundant commands merged), viewBox and responsive sizing set, `<title>` and `<desc>` added for accessibility. The total filesize across all five bursts is under 12 KB.

#### Confirmation Burst (`images/icons/pow.svg`)

The "POW!" graphic shown on the booking confirmation panel. Traced as clean SVG paths, optimised through SVGO, and inlined into the page. It represents the payoff — where the error bursts are negative feedback, POW! is the celebratory burst that tells the customer they succeeded.

### 7.7 CSS Burst Labels & Animation System

**Panel / Modal bursts** (`.burst` class):

A separate system from the hand-drawn SVGs — these are pure CSS star shapes created via `clip-path: polygon()` (24-point star) with text content:

- 20+ labels used across the site: BOOK!, PHONE!, TIME!, GIGS!, LOCK!, HELD!, HIRE!, EDIT!, WEEK!, BLOCKED!, WHOA!, FIRED!, NOPE!, GONE!, FREE!, NICE TRY!, LOCKED!, FIND!, PICK!, FIX!, HEY!, DONE!
- Each set in Action Man Bold (`var(--font-action)`), rotated 2–5°, positioned absolutely to overlap the panel/modal border (the comic book convention of a sound effect bleeding out of the panel)
- Default colour is `var(--ink)`; some instances override inline (`background:var(--pink)` for NOPE! on some modals, `background:var(--gold)` with `color:var(--ink)` for HEY! and DONE!)

**Spotlight burst animation:**

The hand-drawn burst SVGs (section 7.6) are animated via CSS keyframes:

- `error-pop`: scale 0 → 1.1 (overshoot) → 1.0 (settle) — a pop-and-squeeze effect over 250ms
- `fade-out`: opacity 1 → 0 over 400ms, triggered when switching fields or dismissing
- `scroll-hide` class: pauses all animation (opacity 0, animation-play-state paused) while the user scrolls, preventing the burst from trailing across the viewport

Phone fields (`.field-phone`) re-randomise their burst word on every invalid input, cycling between `zilch`, `nada`, `bzzt`, and `nope`. All other fields keep their assigned burst for the entire session — the shuffled key is fixed on first error.

### 7.8 Decorative Doodle Icons

Eighteen icon SVGs in `images/doodles/` serve as themed UI embellishments across both pages (all hand-drawn — see §7.6):

`gear.svg` (gear icon, rotates on hover), `eye-open.svg` / `eye-closed.svg` (password visibility toggle), `burst-vroom.svg` (admin section header VROOM burst), `hero-helmet.svg` (mechanic card badge), `hourglass.svg` (simulated time panel), `lightning.svg` (flash message accent), `oil-can.svg` (car form section icon), `spark-plug.svg` (engine/slot decoration), `speech-bubble-1.svg` / `speech-bubble-2.svg` / `speech-bubble-3.svg` (quote tooltip indicators), `stiletto.svg` (danger/destructive marker), `super-shield.svg` (confirmation/protection icon), `wonder-tiara.svg` (hero/reward accent), `wrench.svg` (mechanic icon), `magnifying-glass.svg` / `magnifying-glass-hover.svg` (search toggle states).

These are secondary to the hand-drawn bursts but follow the same ethos: bespoke vector artwork, no stock assets, fully accessible.

### 7.8 Onomatopoeia Watermarks

Each page has a `.omg` elements positioned at fixed viewport locations, layered behind content like a watermark bleed from another page:

- **Booking page**: "VROOM" (top), "KAPOW" (centre), "CLICK" (bottom)
- **Admin page**: "POW" (top-right), "ZOWIE" (mid-left), "BAM" (bottom)

Styled in Action Man Bold, large font size (4.5rem+), rotated 5–15°, opacity 6–10%, colour matched to section background. These are the equivalent of sound effects that have "bled through" from a previous panel — a printing technique where ink from the other side of the thin newsprint is faintly visible.

### 7.9 Settings Gear & Dropdown

The gear icon (`images/doodles/gear.svg`) in the admin page header rotates on hover (`transform: rotate(90deg)`, `transition: transform 0.5s`). The dropdown panel beneath it uses:

- `var(--cream)` background with a dot pattern overlay
- `var(--border)` (`3px solid var(--ink)`) plus `var(--shadow-md)`
- A "⚙ SETTINGS" header in `--font-display` (Bangers) with a pink dashed underline
- The spotlight toggle `input[type="checkbox"]` is replaced by a `.custom-checkbox`

### 7.10 Custom Checkbox

The `.custom-checkbox` class replaces native checkbox appearance with a themed alternative:

- `appearance: none` — removes native browser styling
- `20×20px`, matching the standard checkbox size
- `var(--paper)` background, `var(--border)` (`3px solid var(--ink)`)
- Hover state: `var(--teal-light)` background
- When checked: `var(--teal)` dark teal fill with a gold lightning bolt SVG (via `background-image`, no `::after` text)
- Shared by the spotlight toggle, doodles toggle, background toggle, animations toggle, and the sim time toggle on admin.php — any future toggle will use the same class

### 7.11 Speech Bubbles & Tooltips

**Standard bubbles** (`.bubble`): Cyan fill (`var(--cyan)`), `var(--border)` (`3px solid var(--ink)`), triangular tail (`::before`/`::after`). Used for mechanic quotes on hover — the tail aligns to the left side of the card.

**Jagged bubbles** (`.jagged-bubble`): Cyan fill, ink border, but the top edge is jagged (CSS `clip-path` with teeth). Used for the date error message.

**Quote tooltips** (`.quote-tooltip`): Appear below mechanic cards on hover. Gold background (`var(--gold)`), ink border, ink shadow. Positioned via `updateQuotePosition()` which watches card position in the scrolling layout.

**Slot tooltips** (`.slot-tooltip`): Appear when a taken slot is clicked. Gold background, ink border, `filter: drop-shadow(...)` for a jagged shadow effect. Contains suggestion chips for alternative slots/dates/mechanics.

### 7.12 Buttons & Tables

**Buttons** (`.btn`): `var(--teal)` background, `var(--gold)` text, `var(--border)` (`3px solid var(--ink)`), `var(--shadow-lg)` (`5px 5px 0 var(--ink)`). Hover lifts to `8px 8px 0 var(--ink)` and lightens background to `var(--teal-light)`. Active pushes shadow to `var(--shadow-sm)` (`3px 3px 0`). All buttons have a trailing `►` via `::after` that shifts right on hover. Variants: `--pink` (pop/confirm), `--rust` (danger/destroy), `--outline` (inverted, transparent bg), `--sm` (compact), `--recruit` (green hire, flashing), `--jade` (green confirm).

**Tables**: Comic-themed throughout — `var(--cyan)` rows with `#c6dddd` stripe (`tr.stripe-even`), `var(--navy)` headers with `var(--gold)` text, `var(--border)` (`3px solid var(--ink)`). Status badges (`.status-badge`) use colour-coded pills: `status-scheduled` (teal/gold), `status-in_progress` (pink/gold), `status-completed` (gold/ink, rotated -1deg with marker font), `status-cancelled` (rust/gold).

### 7.13 Inline Error Text

When the Spotlight of Shame is disabled, validation errors appear as `.field-error` spans beneath each invalid field. Styled in `var(--font-hand)` (Walter Turncoat) to look hand-written, italic, `var(--rust)` colour — a subtle red scribble in the margin, like a teacher's correction.

### 7.14 Custom Datepicker

The date picker (`datepicker.js`, 320 lines) is a fully custom widget — no `<input type="date">`. It renders a calendar grid themed to match the site:

- Cream/paper backgrounds, teal headers, ink borders
- Month navigation with Bangers-font arrows
- Hover tooltip shows the full month name + year
- Today highlighted in teal
- Placement adapts to viewport edges (auto-flip to top)
- Lazy construction — the popup DOM is created on first focus
- Confirm mode via `data-confirm` attribute for certain fields

### 7.15 Theme Statistics

A rough count of the customization effort:

- **~2139 lines** of CSS across a single stylesheet
- **6 font faces**, self-hosted from `.woff2`/`.ttf` files
- **18 hand-drawn SVG doodles** in `images/doodles/`
- **5 hand-drawn SVG error bursts** in `images/bursts/`
- **1 hand-drawn SVG confirmation burst** in `images/icons/`
- **20+ burst labels** applied across panels and modals (BOOK!, PHONE!, TIME!, GIGS!, LOCK!, HELD!, HIRE!, EDIT!, WEEK!, BLOCKED!, WHOA!, FIRED!, NOPE!, GONE!, FREE!, NICE TRY!, LOCKED!, FIND!, PICK!, FIX!, HEY!, DONE!)
- **6 onomatopoeia watermarks** (3 per page, VROOM/KAPOW/CLICK + POW/ZOWIE/BAM)
- **36 CSS custom properties** in the `:root` block for the colour palette, fonts, and effects
- **16 CSS animation keyframe sets** (pour-oil, wobble-mech, wobble-mech-flip, comicPop, popIn, burst-pop, recflic, vacay-pop, float, whamFlash, pow-pop, shakyPop, shame-slide, error-pop, error-fade, shake)
- **Ben-Day dots** via layered `radial-gradient()` on `<body>`, overlay curtains, and dropdown
- **No CSS framework** — every selector is hand-written for this specific design

## 8. Notes

- Copy `config.example.php` to `config.php` before running. Default admin password is `'meow meow'`.
- No email or SMS notifications are sent. Customers must remember their appointment time.
- All SQL queries use prepared statements. Only hardcoded constants are concatenated directly.
- JSON exports (`VACATION_DATA`, `SCHEDULE_DATA`) use `JSON_HEX_*` flags to prevent XSS.
