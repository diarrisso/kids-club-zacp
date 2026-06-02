# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Kids Club by zacp** — a single-tenant appointment booking app for one pediatric
dental practice. The Laravel/Inertia app lives in **`backend/`** (run all commands
from there). A public, embeddable Vue widget handles anonymous parent bookings; the
authenticated CRUD lets practice staff manage practitioners, services, opening hours,
and absences. No patient data flows through WordPress.

Three deliverables, one repo:
- `backend/` — Laravel 13 · Inertia 2 · Vue 3 (`<script setup lang="ts">`) · Tailwind 3 · shadcn-vue · Fortify auth · Pest 4 · **PostgreSQL**.
- `backend/resources/js/widget/` — standalone IIFE widget, built separately, embedded on the practice's public site.
- `wordpress-plugin/masinga-booking/` — thin WP wrapper exposing the widget via `[masinga_booking]` shortcode / Gutenberg block.

`docs/superpowers/` holds the design specs and implementation plans (read these for the "why" behind phases). `01-discovery/` is the original client questionnaire.

## ⚠️ "Tenant" naming is vestigial — this is NOT multi-tenant

This started as a multi-tenant SaaS (`stancl/tenancy`, schema-per-tenant) and was
**refactored to single-tenant** (commit `9eea4c3`), then **upgraded Laravel 11 → 13**
(commit `f65e381`). The tenancy infrastructure is gone — single database, single
`search_path`, no tenant resolution.

But the `Tenant` *names* were deliberately left in place (a cosmetic rename was
deferred as out-of-scope). So you will see, and should **not** be misled by:
- `App\Models\Tenant\*` (Practitioner, Service, Appointment, …) — ordinary models on the default connection.
- `App\Http\Controllers\Tenant\*`, `App\Services\Tenant\*`, `Database\Factories\Tenant\*`.
- `tests/Feature/TenantSchema/*` — ordinary `RefreshDatabase` feature tests, no schema switching.
- `TenantLayout.vue`, route names `tenant.practitioners.*`, etc.

There is exactly one database, one practice, and any authenticated user is staff
(no roles). The practice identity comes from `config('app.name')` / `APP_NAME`, and
cabinet alert emails go to `PRACTICE_NOTIFICATION_EMAIL` (see `App\Support\CabinetNotifier`).

⚠️ **`backend/README.md` is stale** — it still describes the multi-tenant architecture
(schema-per-tenant, two test suites, `*.masinga-booking.test` hosts, Laravel 11). Trust
this file and the code over that README.

## Commands (run from `backend/`)

```bash
composer dev          # serve + queue:listen + pail (logs) + vite, all at once
php artisan serve     # app only
npm run dev           # vite only

composer test         # config:clear then artisan test — the canonical full suite
php artisan test --filter=WidgetBooking          # single test file/case by name
php artisan test tests/Feature/TenantSchema/WidgetBookingTest.php   # single file by path

vendor/bin/pint       # PHP code style (Laravel Pint)

npm run build         # main app assets
npm run build:widget  # embeddable widget -> public/widget/masinga-widget.js
npm run test:widget   # widget unit tests (Vitest + @vue/test-utils)
```

There is **no `type-check` script** despite `tsconfig.json` being present; `.ts` is
compiled by Vite/esbuild without type checking. Widget logic is unit-tested via Vitest
(`tests/widget/`).

### Local setup
PostgreSQL is the real target (test env hardcodes `DB_CONNECTION=pgsql`,
`DB_DATABASE=masinga_booking_test` in `phpunit.xml`). `.env.example` defaults to
sqlite for convenience but the booking engine and tests assume Postgres semantics.
Seed with `php artisan migrate --seed` (runs `KidsClubSeeder`).

## Architecture

### Two front doors
1. **Authenticated staff CRUD** (`routes/web.php`, Inertia pages under `resources/js/Pages/Tenant/`):
   German URLs, English route names. `/behandler`=practitioners, `/leistungen`=services,
   `/sprechzeiten`=availabilities, `/abwesenheiten`=absences. Auth via Fortify
   (`FortifyServiceProvider` wires login view to Inertia `Auth/Login`; supports 2FA + passkeys).
2. **Public widget JSON API** (`routes/api.php`, prefix `/api/v1/widget`, controllers
   under `App\Http\Controllers\Widget\`): anonymous, no auth, IP rate-limited. Read
   endpoints (services/practitioners/slots) throttled `widget-read` (20/min); booking +
   cancel throttled `widget-book` (5/min). Limiters defined in `AppServiceProvider::boot`.

Plus a server-rendered **public cancellation page** (`/storno/{token}`,
`routes/web.php` → `Public\CancellationPageController`) — the link target in
appointment emails, needing session+CSRF for its POST form (throttle `storno`, 10/min).

### Booking engine — the core domain
`App\Services\Tenant\AvailabilityCalculator` computes free slots and is the heart of
the app. Key invariants (constants at top of the class):
- Slots are duration-aligned to the chosen service, `Europe/Berlin` clinic timezone.
- **2h minimum lead** (`LEAD_MINUTES=120`), **60-day horizon** (`HORIZON_DAYS`).
- A slot is dropped if it overlaps an `AvailabilityException` (absence) or any
  `pending`/`confirmed` appointment.
- Availabilities are weekly (`day_of_week` ISO) with optional `valid_from`/`valid_to`.

Booking writes use a **pessimistic lock** to prevent double-booking under concurrency,
plus a consent timestamp + honeypot for spam. Appointments are auto-confirmed
(`Appointment` default status `confirmed`), keyed by UUID, addressed publicly via an
opaque `cancellation_token`.

`Appointment::$fillable` deliberately **excludes** `notes_internal` and
`reminder_sent_at` — those are staff/system-only and must never be mass-assignable
from the public API. Respect this when adding fields.

### Notifications (Phase 4)
- Mailables in `App\Mail\*`: confirmation, reminder, cancelled. Blade in `resources/views/emails/`.
- `php artisan appointments:send-reminders` (`SendAppointmentReminders`) runs **hourly**
  (`routes/console.php`) scanning a half-open `[24h, 25h)` window so each appointment is
  reminded exactly once. Guarded by `reminder_sent_at`.
- Cabinet cancellation alerts via `CabinetNotifier::notifyCancelled` — queued, wrapped
  in `rescue()` so a queue/Redis failure never fails the user-facing cancellation.

### The widget build (important gotchas)
`vite.widget.config.js` builds a standalone **IIFE** into `public/widget/` (inside
Laravel's `public/`, so `publicDir: false` prevents duplicating public assets). It
defines `process.env.NODE_ENV` because Vue reads it at runtime and the IIFE has no
bundler-injected env. Widget source uses the `@widget` alias; the main app uses `@` →
`resources/js`. The widget mounts in Shadow DOM and is tenant-agnostic (no `{tenant}`
URL segment). Multi-step flow lives in `resources/js/widget/steps/` driven by `useWizard.ts`.

## Conventions
- **German URLs, English route names** — never hardcode paths; always `route('name')`.
- Validation lives in `App\Http\Requests\*` form requests (Tenant\* for staff, Widget\* for public).
- Follow the user's global workflow in `~/.claude/CLAUDE.md` (feature branch per task,
  Chrome visual check, tests, code review before merge/deploy, CodeRabbit autofix).
  When touching specs/plans in `docs/superpowers/`, they auto-open in Marked per that workflow.
