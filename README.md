# Mayhem Mobility

Car workshop appointment system with a retro comic strip aesthetic. Built for CSE 391 Assignment 3.

## Tech

PHP 8+, MySQL (MariaDB), vanilla JS. Served via XAMPP.

## Features

- 4×2hr slots per mechanic per day (08:00–16:00)
- 3 appointment statuses: scheduled → in_progress → completed (+ cancelled)
- No-login user identification by phone number
- Duplicate booking prevention (per car, per day)
- Auto-suggestion on slot conflicts (same mechanic other time / similar mechanic same time)
- Admin panel with simulated clock toggle, schedule overrides, date/mechanic edits
- Status auto-advances based on effective time

## Setup

Copy `config.example.php` to `config.php` and fill in your DB credentials. `config.php` is gitignored — credentials stay local.

## Database

8 tables: `mechanics`, `mechanic_schedule`, `mechanic_overrides`, `clients`, `cars`, `appointments`, `reviews`, `sim_config`.

## Design

Retro 60s/70s pop art inspired — Ben-Day dots, jagged speech bubbles, action bursts, onomatopoeia watermarks, comic panel rotation.

- `--paper`: #e0cc5a (yellowed page)
- `--cream`: #e8d86a (panel surface)
- `--ink`: #1a1a2e
- `--pink`: #d63384
- `--teal`: #2a6b6b
- `--gold`: #f5c518
- `--burst`: #8b5cf6

Font stack: Bangers / Action Man Bold / Walter Turncoat / Luckiest Guy / Permanent Marker.

## File Structure

```
├── index.php           Booking page
├── admin.php           Admin panel
├── config.php          DB connection + constants
├── functions.php       All business logic
├── availability.php    AJAX slot availability endpoint
├── style.css           Full stylesheet
├── script.js           Client-side validation
├── datepicker.js       Custom themed date picker
├── sql/
│   ├── schema.sql
│   └── seed.sql
├── images/doodles/     SVG placeholders (WIP)
└── docs/planning.md    Spec reference
```
