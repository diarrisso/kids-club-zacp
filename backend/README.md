# Masinga Booking — Backend

Multi-tenant SaaS for medical appointment booking.
**Phase 1 = foundation** (tenancy + auth + practice management CRUD). First tenant: *Kids Club by zacp*.

## Stack

- Laravel 11 · Inertia 2 · Vue 3 (`<script setup lang="ts">`) · Tailwind 3 · shadcn-vue
- PostgreSQL 16 — **schema-per-tenant** via `stancl/tenancy` v3
- Laravel Fortify (tenant-aware auth) · Pest 3 (TDD)

## Quick start

1. Install: `composer install && npm install`
2. Env: `cp .env.example .env && php artisan key:generate`
3. Configure PostgreSQL in `.env` (`DB_DATABASE=masinga_booking`)
4. Hosts entries (`/etc/hosts`):
   ```
   127.0.0.1 central.masinga-booking.test
   127.0.0.1 kidsclub.masinga-booking.test
   127.0.0.1 masinga-booking.test
   ```
5. Migrate + seed: `php artisan migrate --seed`
6. Run: `composer dev` (serve + queue + vite) — or `php artisan serve` + `npm run dev`
7. Login: `http://kidsclub.masinga-booking.test:8000/login` → `michael@kidsclub.de` / `changeme`

## Architecture

- **Central** (`public` schema): `tenants`, `domains`, `users`, `plans`. Served on `central_domains`.
- **Per-tenant** (`tenant_<id>` schema): `practitioners`, `services`, `practitioner_service`,
  `availabilities`, `availability_exceptions`. Served on `<tenant>.masinga-booking.test`.

### Tenancy switching (hybrid — see `app/Tenancy/` + `app/Listeners/`)

| Context | Mechanism | Why |
|---|---|---|
| Request-time | `SearchPathBootstrapper` — `SET search_path` (no reconnect) | transaction-safe (RefreshDatabase tests don't deadlock) |
| Tenant migrations | `SwitchSearchPathForMigration` / `ResetSearchPathAfterMigration` — config reconnect | the migrator introspects the *config* search_path; safe because migrations never run in a test transaction |

Central models (`User`, `Plan`, `Tenant`, `Domain`) are pinned to the central connection
(`CentralConnection`) so auth resolves `public.users` even in tenant context.

## German URLs

Localized paths: `/behandler` (practitioners), `/leistungen` (services),
`/sprechzeiten` (availabilities), `/abwesenheiten` (absences). Route names stay English
(`tenant.practitioners.*`).

## Testing

Two suites that **must run as separate processes** (committed tenant schemas can't coexist
with a RefreshDatabase transaction in one process):

```bash
composer test          # runs Unit+central, then tenant, as separate processes
# or individually:
php artisan test --testsuite=Unit,central   # transaction-based
php artisan test --testsuite=tenant         # real committed tenant schemas
```

- `tests/TestCase` + RefreshDatabase → central/auth tests
- `tests/TenantTestCase` → tenant-schema tests (real schema per test, dropped on teardown)
- `CrossTenantIsolationTest` is the DSGVO linchpin — must always pass.

## Booking API (Phase 2)

Public, anonymous JSON API consumed by the embeddable widget. Tenant by path:

- `GET  /api/v1/widget/{slug}/services`
- `GET  /api/v1/widget/{slug}/services/{service}/practitioners`
- `GET  /api/v1/widget/{slug}/slots?practitioner_id&service_id&from&to`
- `POST /api/v1/widget/{slug}/appointments`            (consent + honeypot, pessimistic lock)
- `GET  /api/v1/widget/{slug}/appointments/{token}`
- `POST /api/v1/widget/{slug}/appointments/{token}/cancel`

Slots are duration-aligned, auto-confirmed, 2h lead / 60d horizon. Rate-limited
(20 reads, 5 bookings per minute per IP). Slot computation lives in
`App\Services\Tenant\AvailabilityCalculator`.
