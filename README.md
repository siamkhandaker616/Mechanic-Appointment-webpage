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

**Live (hosted on InfinityFree):**
- Booking page: https://mayhem-mobility.page.gd
- Admin panel: https://mayhem-mobility.page.gd/admin.php

**Local (XAMPP):**
Copy `config.example.php` to `config.php` and fill in your DB credentials. `config.php` is gitignored — credentials stay local.

## Database

8 tables: `mechanics`, `mechanic_schedule`, `mechanic_overrides`, `clients`, `cars`, `appointments`, `reviews`, `sim_config`.

## Design

Retro 60s/70s pop art inspired — Ben-Day dots, jagged speech bubbles, action bursts, onomatopoeia watermarks, comic panel rotation.

- `--ink`: #1a1a2e
- `--paper`: #e0cc5a
- `--cream`: #e2d055
- `--teal`: #2a6b6b
- `--teal-light`: #3a9b9b
- `--teal-dark`: #1a4a4a
- `--rust`: #a0453b
- `--navy`: #16213e
- `--pink`: #d63384
- `--gold`: #f5c518
- `--burst`: #e524e2

Font stack: Bangers / Action Man Bold / Walter Turncoat / Luckiest Guy / Permanent Marker.

## File Structure

```
├── index.php               Booking page
├── admin.php               Admin panel
├── availability.php        AJAX slot availability endpoint
├── config.php              DB connection + constants
├── functions.php           All business logic
├── script.js               Client-side validation
├── datepicker.js           Custom themed date picker
├── style.css               Full stylesheet
├── sql/
│   ├── schema.sql
│   └── seed.sql
├── fonts/                  Self-hosted woff2/ttf
├── docs/                   Documentation and AI Declaration
├── images
│   ├── icons               PNG Icons
│   └── doodles             SVGs

```
