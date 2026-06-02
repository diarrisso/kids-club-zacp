# Phase 5 — Calendrier dashboard · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **⚠️ Révisé 2026-06-02 — single-tenant / Laravel 13.** Ce plan a été écrit avant le retrait de la multi-tenancy (`9eea4c3`) et la montée Laravel 13 (`f65e381`). Il est ici adapté à la réalité actuelle : **une seule base PostgreSQL**, routes dans `routes/web.php`, **suite de tests unique `RefreshDatabase`** (`tests/Feature/`, dossier `TenantSchema/` conservé par convention), URLs de test **relatives**, `User::factory()->create()` pour l'auth, `config('app.name')` pour le nom du cabinet, lien `/storno` **sans segment tenant**. Pas de `stancl/tenancy`, pas de `TenantTestCase`, pas de `tenancy()->initialize()`, pas de `--testsuite=tenant`. Les namespaces `App\…\Tenant\…` restent (vestigiaux — cf. `CLAUDE.md`).

**Goal:** Give the cabinet a FullCalendar-based admin console (behind `auth`) to view, create (phone bookings), drag&drop-reschedule, and cancel appointments — color-coded by practitioner.

**Architecture:** Inertia page `Tenant/Appointments/Calendar.vue` mounting FullCalendar Vue 3 (MIT plugins). The calendar reads a JSON events feed and performs mutations through JSON endpoints (axios, auto-XSRF) so drag&drop can `revert()` cleanly on a conflict. A thin `AppointmentController` delegates create/reschedule to an `AppointmentScheduler` service that takes the practitioner-row lock and enforces the ONLY hard rule — no overlap on the same practitioner (cabinet "override": open-hours/grid/lead are NOT enforced). The Appointment→event mapping lives in a pure, Vitest-tested TS helper.

**Tech Stack:** Laravel 13 (native `->change()`, no doctrine/dbal), **single PostgreSQL database** (no tenancy), Inertia 2 + Vue 3 `<script setup lang="ts">`, FullCalendar v6 (`@fullcalendar/vue3` + daygrid/timegrid/interaction), axios (global, auto-XSRF), Pest 4, Vitest.

---

## Key facts the engineer must know (verified against the codebase)

- **One test suite** via `composer test` (`@php artisan config:clear` then `@php artisan test`). All tests run under `RefreshDatabase` on the single PostgreSQL DB — Pest's `tests/Pest.php` applies `RefreshDatabase` to everything `->in('Feature')`. Backend tests for this phase go in `backend/tests/Feature/TenantSchema/` (the folder name is vestigial; tests are ordinary feature tests). Run a subset with `php artisan test --filter=<Name>`.
- **Routes are plain** (`routes/web.php`): authenticated staff routes live in the existing `Route::middleware('auth')->group(function () { ... })` block (alongside `behandler`/`leistungen`/`sprechzeiten`/`abwesenheiten`). In tests, hit them with **relative** URLs and `$this->actingAs(User::factory()->create())` — e.g. `$this->actingAs($user)->getJson('/termine/events?...')`. Guests are redirected (`auth` middleware). Pattern reference: `tests/Feature/TenantSchema/PractitionerTest.php` (uses `User::factory()->create()` + `/behandler`).
- **`Appointment`** (`app/Models/Tenant/Appointment.php`): UUID PK; `$fillable` includes practitioner_id, service_id, starts_at, ends_at, status, patient_*, parent_first_name/last_name/email/phone, parent_consent_at, notes_parent, cancellation_token. **`notes_internal` and `reminder_sent_at` are deliberately NOT fillable** — set them by direct assignment only. Casts: starts_at/ends_at/parent_consent_at = datetime, patient_birthdate = date. Default `status` attribute = `confirmed`. `service()`/`practitioner()` belongsTo.
- **`Practitioner`**: `fullName()` → "Dr. Anna Berg" (`trim("{title} {first_name} {last_name}")`); `color` (hex string, default `#0a6cb3`); `is_active`; `scopeActive`. **`Service`**: `name`, `duration_minutes`, `is_active`.
- **`AvailabilityCalculator::CLINIC_TIMEZONE`** = `'Europe/Berlin'` (`app/Services/Tenant/AvailabilityCalculator.php`). Reuse this constant for all date parse/serialize.
- **Phase 2 booking lock pattern** (mirror it — see `app/Http/Controllers/Widget/AppointmentController.php`): `Practitioner::query()->whereKey($id)->lockForUpdate()->first();` then an overlap `exists()` check, inside `DB::transaction`. Lock a ROW, never an aggregate (PostgreSQL).
- **Phase 4 confirmation wiring** (mirror the widget controller exactly): post-commit, `$cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);` then `rescue(fn () => Mail::to($email)->queue(new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)))`. **No `tenant()` calls** — `AppointmentConfirmationMail(Appointment, string $cabinetName, string $cancelUrl)`; `storno.show` takes only `{token}`.
- **Frontend**: Inertia resolves `resources/js/Pages/**/*.vue`; alias `@` → `resources/js`; `window.axios` is global and sends `X-XSRF-TOKEN` automatically for same-origin web routes (CSRF satisfied). `TenantLayout` nav is a plain `{ href, label }` array in `resources/js/Layouts/TenantLayout.vue` — add a `Termine` entry. Existing page convention: `defineOptions({ layout: TenantLayout })`, `<script setup lang="ts">`, German UI, Tailwind utilities. For the mapper unit test use the widget Vitest config: `npm run test:widget`.
- **Commits**: English, branch is already `feature/phase-5-dashboard-calendar`. Always `git add` explicit paths, never `git add -A`. Do NOT stage the root `CLAUDE.md` (untracked, separate concern) in feature commits.

---

## File Structure

**Create:**
- `backend/database/migrations/2026_06_01_000017_make_parent_email_consent_nullable.php`
- `backend/app/Services/Tenant/AppointmentScheduler.php` — create/reschedule with row lock + overlap check.
- `backend/app/Http/Controllers/Tenant/AppointmentController.php` — index, events, store, update, destroy + a private `toDto()`.
- `backend/app/Http/Requests/Tenant/StoreManualAppointmentRequest.php`
- `backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php`
- `backend/resources/js/lib/calendar.ts` — `toCalendarEvent(dto)` pure mapper.
- `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue`
- `backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`
- Tests: `backend/tests/Feature/TenantSchema/{AppointmentParentNullableTest,CalendarEventsFeedTest,AppointmentSchedulerTest,ManualBookingTest,RescheduleTest,CancelAppointmentTest,CalendarAuthTest}.php` and `backend/tests/widget/calendar.test.ts` (Vitest).

**Modify:**
- `backend/routes/web.php` — +5 routes in the existing `auth` group.
- `backend/resources/js/Layouts/TenantLayout.vue` — nav link.
- `backend/package.json` — FullCalendar deps.

---

# LOT A — Lecture (calendrier + feed + filtre)

## Task 1: Migration — `parent_email` / `parent_consent_at` nullable

**Files:**
- Create: `backend/database/migrations/2026_06_01_000017_make_parent_email_consent_nullable.php`
- Test: `backend/tests/Feature/TenantSchema/AppointmentParentNullableTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual (phone) bookings may have no email and no explicit consent
        // record. Laravel 13 changes columns natively (no doctrine/dbal).
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('parent_email')->nullable()->change();
            $table->timestamp('parent_consent_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('parent_email')->nullable(false)->change();
            $table->timestamp('parent_consent_at')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/TenantSchema/AppointmentParentNullableTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('allows an appointment with no parent_email and no consent (manual booking)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'parent_email' => null, 'parent_consent_at' => null,
    ]);

    expect($a->fresh())
        ->parent_email->toBeNull()
        ->parent_consent_at->toBeNull();
});
```

- [ ] **Step 3: Run the test**

Run: `cd backend && php artisan test --filter=AppointmentParentNullable`
Expected: PASS (RefreshDatabase runs the new migration; the columns are now nullable).

> If the `AppointmentFactory` always sets `parent_email`/`parent_consent_at`, the explicit `null` override in the test still proves nullability. If the DB rejects null before the migration, the test fails — confirming the migration is required.

- [ ] **Step 4: Commit**

```bash
cd backend && git add database/migrations/2026_06_01_000017_make_parent_email_consent_nullable.php tests/Feature/TenantSchema/AppointmentParentNullableTest.php
git commit -m "feat: make appointment parent_email and parent_consent_at nullable"
```

---

## Task 2: Install FullCalendar dependencies

**Files:**
- Modify: `backend/package.json` (+ lockfile)

- [ ] **Step 1: Install the MIT plugins + Vue 3 adapter**

Run:
```bash
cd backend && npm install @fullcalendar/core@^6 @fullcalendar/vue3@^6 @fullcalendar/daygrid@^6 @fullcalendar/timegrid@^6 @fullcalendar/interaction@^6
```
Expected: the five packages added to `dependencies` in `package.json`.

- [ ] **Step 2: Verify the build still compiles**

Run: `cd backend && npm run build`
Expected: build succeeds (no errors). This confirms the new deps resolve before we import them.

- [ ] **Step 3: Commit**

```bash
cd backend && git add package.json package-lock.json
git commit -m "build: add FullCalendar (vue3 + daygrid/timegrid/interaction) MIT plugins"
```

---

## Task 3: Events feed + index page + read routes

**Files:**
- Create: `backend/app/Http/Controllers/Tenant/AppointmentController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/CalendarEventsFeedTest.php`

- [ ] **Step 1: Add the read routes**

In `backend/routes/web.php`, add the import at the top with the other `App\Http\Controllers\Tenant\...` imports:

```php
use App\Http\Controllers\Tenant\AppointmentController;
```

Inside the existing `Route::middleware('auth')->group(function () { ... })` block (alongside the `behandler`/`leistungen` resources), add:

```php
    Route::get('/termine', [AppointmentController::class, 'index'])->name('tenant.appointments.index');
    Route::get('/termine/events', [AppointmentController::class, 'events'])->name('tenant.appointments.events');
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/TenantSchema/CalendarEventsFeedTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

function feedUrl(CarbonImmutable $start, CarbonImmutable $end, array $practitionerIds = []): string
{
    $q = ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()];
    foreach ($practitionerIds as $i => $id) {
        $q["practitioner_ids[$i]"] = $id;
    }
    return '/termine/events?'.http_build_query($q);
}

it('returns confirmed appointments within the range', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'title' => 'Dr.', 'color' => '#3b82f6']);
    $s = Service::factory()->create(['name' => 'Prophylaxe', 'duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');

    $inRange = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'status' => 'confirmed', 'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
    ]);
    // out of range
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addDays(30), 'ends_at' => $start->addDays(30)->addMinutes(30), 'status' => 'confirmed',
    ]);
    // cancelled in range -> excluded
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30), 'status' => 'cancelled',
    ]);

    $this->actingAs($user)
        ->getJson(feedUrl($start->startOfWeek(), $start->endOfWeek()))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $inRange->id])
        ->assertJsonFragment(['name' => 'Prophylaxe']);
});

it('filters the feed by practitioner', function () {
    $user = User::factory()->create();
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');

    Appointment::factory()->create(['practitioner_id' => $p1->id, 'service_id' => $s->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed']);
    Appointment::factory()->create(['practitioner_id' => $p2->id, 'service_id' => $s->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed']);

    $this->actingAs($user)
        ->getJson(feedUrl($start->startOfWeek(), $start->endOfWeek(), [$p1->id]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $p1->id, 'name' => $p1->fullName()]);
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `cd backend && php artisan test --filter=CalendarEventsFeed`
Expected: FAIL — controller class not found.

- [ ] **Step 4: Implement the controller (index + events + toDto)**

Create `backend/app/Http/Controllers/Tenant/AppointmentController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    private const TZ = AvailabilityCalculator::CLINIC_TIMEZONE;

    public function index(): Response
    {
        return Inertia::render('Tenant/Appointments/Calendar', [
            'practitioners' => Practitioner::query()->orderBy('last_name')->get()
                ->map(fn (Practitioner $p) => ['id' => $p->id, 'name' => $p->fullName(), 'color' => $p->color])
                ->all(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name, 'duration_minutes' => $s->duration_minutes])
                ->all(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $start = CarbonImmutable::parse($request->query('start'), self::TZ);
        $end = CarbonImmutable::parse($request->query('end'), self::TZ);
        $practitionerIds = array_filter((array) $request->query('practitioner_ids', []));

        $appointments = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($practitionerIds, fn ($q) => $q->whereIn('practitioner_id', $practitionerIds))
            ->with(['service', 'practitioner'])
            ->get()
            ->map(fn (Appointment $a) => $this->toDto($a))
            ->all();

        return response()->json($appointments);
    }

    /** Lightweight appointment shape consumed by the TS calendar mapper. */
    private function toDto(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'starts_at' => $a->starts_at->setTimezone(self::TZ)->toIso8601String(),
            'ends_at' => $a->ends_at->setTimezone(self::TZ)->toIso8601String(),
            'status' => $a->status,
            'patient_first_name' => $a->patient_first_name,
            'patient_last_name' => $a->patient_last_name,
            'patient_birthdate' => $a->patient_birthdate?->toDateString(),
            'parent_first_name' => $a->parent_first_name,
            'parent_last_name' => $a->parent_last_name,
            'parent_email' => $a->parent_email,
            'parent_phone' => $a->parent_phone,
            'notes_internal' => $a->notes_internal,
            'practitioner' => ['id' => $a->practitioner->id, 'name' => $a->practitioner->fullName(), 'color' => $a->practitioner->color],
            'service' => ['id' => $a->service->id, 'name' => $a->service->name, 'duration_minutes' => $a->service->duration_minutes],
        ];
    }
}
```

- [ ] **Step 5: Run the test**

Run: `cd backend && php artisan test --filter=CalendarEventsFeed`
Expected: PASS (both cases).

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Http/Controllers/Tenant/AppointmentController.php routes/web.php tests/Feature/TenantSchema/CalendarEventsFeedTest.php
git commit -m "feat: appointment calendar index page + JSON events feed"
```

---

## Task 4: `lib/calendar.ts` — Appointment→event mapper (Vitest)

**Files:**
- Create: `backend/resources/js/lib/calendar.ts`
- Test: `backend/tests/widget/calendar.test.ts`

> Vitest is already configured for `tests/widget/*.test.ts` (the widget suite). This mapper test lives there to reuse that config. Run with `npm run test:widget`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/calendar.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'

const dto: AppointmentDto = {
    id: 'uuid-1',
    starts_at: '2026-06-01T09:00:00+02:00',
    ends_at: '2026-06-01T09:30:00+02:00',
    status: 'confirmed',
    patient_first_name: 'Lina', patient_last_name: 'Müller', patient_birthdate: '2019-04-12',
    parent_first_name: 'Anna', parent_last_name: 'Müller', parent_email: 'anna@example.de', parent_phone: '+49 170 0',
    notes_internal: null,
    practitioner: { id: 2, name: 'Dr. Anna Berg', color: '#3b82f6' },
    service: { id: 5, name: 'Prophylaxe', duration_minutes: 30 },
}

describe('toCalendarEvent', () => {
    it('maps a DTO to a FullCalendar event with title, color and props', () => {
        const e = toCalendarEvent(dto)
        expect(e.id).toBe('uuid-1')
        expect(e.title).toBe('Lina M. — Prophylaxe')
        expect(e.start).toBe('2026-06-01T09:00:00+02:00')
        expect(e.end).toBe('2026-06-01T09:30:00+02:00')
        expect(e.backgroundColor).toBe('#3b82f6')
        expect(e.borderColor).toBe('#3b82f6')
        expect(e.extendedProps).toEqual(dto)
    })
})
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && npm run test:widget`
Expected: FAIL — cannot resolve `@/lib/calendar`.

> Note: `@` must resolve to `resources/js` in the widget Vitest config. If the test cannot resolve `@/lib/calendar`, confirm the alias in `vitest.config.ts`; add it if missing (`resolve.alias['@'] = path.resolve(__dirname, 'resources/js')`) and commit that config tweak with this task.

- [ ] **Step 3: Implement the mapper**

Create `backend/resources/js/lib/calendar.ts`:

```ts
export interface PractitionerRef { id: number; name: string; color: string }
export interface ServiceRef { id: number; name: string; duration_minutes: number }

export interface AppointmentDto {
    id: string
    starts_at: string
    ends_at: string
    status: string
    patient_first_name: string
    patient_last_name: string
    patient_birthdate: string | null
    parent_first_name: string
    parent_last_name: string
    parent_email: string | null
    parent_phone: string | null
    notes_internal: string | null
    practitioner: PractitionerRef
    service: ServiceRef
}

export interface CalendarEvent {
    id: string
    title: string
    start: string
    end: string
    backgroundColor: string
    borderColor: string
    extendedProps: AppointmentDto
}

/** Pure mapping from an appointment DTO to a FullCalendar event input. */
export function toCalendarEvent(a: AppointmentDto): CalendarEvent {
    const lastInitial = a.patient_last_name ? `${a.patient_last_name[0]}.` : ''
    return {
        id: a.id,
        title: `${a.patient_first_name} ${lastInitial} — ${a.service.name}`.replace(/\s+—/, ' —'),
        start: a.starts_at,
        end: a.ends_at,
        backgroundColor: a.practitioner.color,
        borderColor: a.practitioner.color,
        extendedProps: a,
    }
}
```

- [ ] **Step 4: Run the test**

Run: `cd backend && npm run test:widget`
Expected: PASS (title is exactly `Lina M. — Prophylaxe`).

- [ ] **Step 5: Commit**

```bash
cd backend && git add resources/js/lib/calendar.ts tests/widget/calendar.test.ts
git commit -m "feat: add toCalendarEvent appointment->FullCalendar mapper"
```

---

## Task 5: `Calendar.vue` page (read-only) + nav link

**Files:**
- Create: `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue`

> No automated test (FullCalendar rendering is verified in Chrome at the end). This task wires the read-only calendar; create/edit/drag come in Lot B.

- [ ] **Step 1: Add the nav link**

In `backend/resources/js/Layouts/TenantLayout.vue`, add to the `nav` array (after the Dashboard entry):

```js
    { href: '/termine', label: '🗓️ Termine' },
```

- [ ] **Step 2: Create the calendar page (read-only)**

Create `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue`:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import deLocale from '@fullcalendar/core/locales/de'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'

defineOptions({ layout: TenantLayout })

const props = defineProps<{
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
}>()

const calendarRef = ref()
const activePractitioners = ref<number[]>(props.practitioners.map((p) => p.id))

const togglePractitioner = (id: number) => {
    const i = activePractitioners.value.indexOf(id)
    if (i === -1) activePractitioners.value.push(id)
    else activePractitioners.value.splice(i, 1)
    calendarRef.value?.getApi().refetchEvents()
}

const fetchEvents = async (info: { startStr: string; endStr: string }, success: (e: any[]) => void, failure: (e: any) => void) => {
    try {
        const { data } = await window.axios.get('/termine/events', {
            params: { start: info.startStr, end: info.endStr, practitioner_ids: activePractitioners.value },
        })
        success((data as AppointmentDto[]).map(toCalendarEvent))
    } catch (e) {
        failure(e)
    }
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    locale: deLocale,
    timeZone: 'Europe/Berlin',
    firstDay: 1,
    nowIndicator: true,
    slotMinTime: '07:00:00',
    slotMaxTime: '20:00:00',
    allDaySlot: false,
    height: 'auto',
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
    events: fetchEvents,
}))
</script>

<template>
    <Head title="Termine" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Termine</h1>

        <div class="flex flex-wrap gap-4 mb-4">
            <label v-for="p in props.practitioners" :key="p.id" class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="activePractitioners.includes(p.id)" @change="togglePractitioner(p.id)" />
                <span class="inline-block w-3 h-3 rounded-full" :style="{ background: p.color }"></span>
                {{ p.name }}
            </label>
        </div>

        <div class="bg-white rounded shadow p-4">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build to verify it compiles**

Run: `cd backend && npm run build`
Expected: build succeeds (Calendar.vue + FullCalendar imports resolve).

- [ ] **Step 4: Commit**

```bash
cd backend && git add resources/js/Pages/Tenant/Appointments/Calendar.vue resources/js/Layouts/TenantLayout.vue
git commit -m "feat: read-only appointment calendar page with practitioner filter"
```

---

> **⏸ CHECKPOINT — fin du Lot A.** Lancer `composer test` + `npm run test:widget` (tout vert), vérifier `/termine` en Chrome (semaine rendue, filtre praticien), puis revue avant d'attaquer le Lot B.

---

# LOT B — Écriture (créer / déplacer / annuler)

## Task 6: `AppointmentScheduler` — create/reschedule with overlap lock

**Files:**
- Create: `backend/app/Services/Tenant/AppointmentScheduler.php`
- Test: `backend/tests/Feature/TenantSchema/AppointmentSchedulerTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/AppointmentSchedulerTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AppointmentScheduler;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

function baseData(Practitioner $p, Service $s, CarbonImmutable $start): array
{
    return [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller', 'parent_phone' => '+49 170 0',
    ];
}

it('creates a manual appointment outside opening hours (cabinet override)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    // 20:00 — outside any normal opening hours; override must allow it.
    $start = CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin');

    $a = app(AppointmentScheduler::class)->create(baseData($p, $s, $start));

    expect($a->status)->toBe('confirmed')
        ->and($a->cancellation_token)->not->toBeNull();
});

it('rejects an overlapping appointment for the same practitioner (409)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    app(AppointmentScheduler::class)->create(baseData($p, $s, $start->addMinutes(15)));
})->throws(HttpException::class);

it('reschedule moves the slot and excludes the appointment itself from the overlap check', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    // Move to the same slot (self-overlap must be allowed) then to a new slot.
    $same = app(AppointmentScheduler::class)->reschedule($a, ['starts_at' => $start, 'ends_at' => $start->addMinutes(30)]);
    expect($same->starts_at->equalTo($start))->toBeTrue();

    $moved = app(AppointmentScheduler::class)->reschedule($a, ['starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30)]);
    expect($moved->starts_at->equalTo($start->addHour()))->toBeTrue();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --filter=AppointmentScheduler`
Expected: FAIL — `App\Services\Tenant\AppointmentScheduler` not found.

- [ ] **Step 3: Implement the scheduler**

Create `backend/app/Services/Tenant/AppointmentScheduler.php`:

```php
<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cabinet-side scheduling. Unlike the public widget (which enforces
 * AvailabilityCalculator::isBookable — open hours, grid, lead, horizon), the
 * cabinet has authority: the ONLY hard rule is "no overlap for the same
 * practitioner". Open-hours/grid/lead are intentionally NOT enforced here.
 */
class AppointmentScheduler
{
    /** @param array $data fillable appointment fields (practitioner_id, service_id, starts_at, ends_at, patient_*, parent_*) */
    public function create(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            $this->assertNoOverlap($data['practitioner_id'], $data['starts_at'], $data['ends_at']);

            return Appointment::create($data + [
                'status' => 'confirmed',
                'cancellation_token' => (string) Str::uuid(),
            ]);
        });
    }

    /** @param array $changes subset of fillable fields (e.g. starts_at/ends_at for drag&drop) */
    public function reschedule(Appointment $appointment, array $changes): Appointment
    {
        return DB::transaction(function () use ($appointment, $changes) {
            $this->assertNoOverlap(
                $changes['practitioner_id'] ?? $appointment->practitioner_id,
                $changes['starts_at'] ?? $appointment->starts_at,
                $changes['ends_at'] ?? $appointment->ends_at,
                $appointment->id,
            );
            $appointment->update($changes);

            return $appointment->refresh();
        });
    }

    private function assertNoOverlap(int $practitionerId, $startsAt, $endsAt, ?string $exceptId = null): void
    {
        // Lock the practitioner ROW (never an aggregate — PostgreSQL rule), as in
        // the Phase 2 booking flow, to serialise concurrent writes.
        Practitioner::query()->whereKey($practitionerId)->lockForUpdate()->first();

        $conflict = Appointment::query()
            ->where('practitioner_id', $practitionerId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        abort_if($conflict, 409, 'Überschneidung mit einem bestehenden Termin.');
    }
}
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter=AppointmentScheduler`
Expected: PASS (override allowed, overlap throws 409, reschedule excludes self).

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/Tenant/AppointmentScheduler.php tests/Feature/TenantSchema/AppointmentSchedulerTest.php
git commit -m "feat: AppointmentScheduler with cabinet-override overlap check"
```

---

## Task 7: Form Requests (manual create + update)

**Files:**
- Create: `backend/app/Http/Requests/Tenant/StoreManualAppointmentRequest.php`
- Create: `backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php`

> No standalone test — exercised via Task 8/9 HTTP tests.

- [ ] **Step 1: Create `StoreManualAppointmentRequest`**

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind 'auth'
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'integer', 'exists:practitioners,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'patient_first_name' => ['required', 'string', 'max:255'],
            'patient_last_name' => ['required', 'string', 'max:255'],
            'patient_birthdate' => ['required', 'date'],
            'parent_first_name' => ['required', 'string', 'max:255'],
            'parent_last_name' => ['required', 'string', 'max:255'],
            'parent_phone' => ['required', 'string', 'max:255'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'notes_internal' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 2: Create `UpdateAppointmentRequest`**

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'practitioner_id' => ['sometimes', 'integer', 'exists:practitioners,id'],
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'patient_first_name' => ['sometimes', 'string', 'max:255'],
            'patient_last_name' => ['sometimes', 'string', 'max:255'],
            'patient_birthdate' => ['sometimes', 'date'],
            'parent_first_name' => ['sometimes', 'string', 'max:255'],
            'parent_last_name' => ['sometimes', 'string', 'max:255'],
            'parent_phone' => ['sometimes', 'string', 'max:255'],
            'parent_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes_internal' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
cd backend && git add app/Http/Requests/Tenant/StoreManualAppointmentRequest.php app/Http/Requests/Tenant/UpdateAppointmentRequest.php
git commit -m "feat: form requests for manual appointment create + update"
```

---

## Task 8: `store` — create manual appointment (+ confirmation if email)

**Files:**
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/ManualBookingTest.php`

- [ ] **Step 1: Add the route**

In `backend/routes/web.php`, inside the `auth` group after the `events` route:

```php
    Route::post('/termine', [AppointmentController::class, 'store'])->name('tenant.appointments.store');
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/TenantSchema/ManualBookingTest.php`:

```php
<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function manualPayload(Practitioner $p, Service $s, array $override = []): array
{
    return array_merge([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin')->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller', 'parent_phone' => '+49 170 0',
    ], $override);
}

it('creates a manual appointment without email and queues no confirmation', function () {
    Mail::fake();
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s))
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(Appointment::whereNull('parent_email')->count())->toBe(1);
});

it('queues a confirmation when a parent email is provided', function () {
    Mail::fake();
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s, ['parent_email' => 'anna@example.de']))
        ->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, fn ($m) => $m->hasTo('anna@example.de'));
});

it('persists notes_internal even though it is not mass-assignable', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s, ['notes_internal' => 'Allergie Penicillin']))
        ->assertCreated();

    expect(Appointment::first()->notes_internal)->toBe('Allergie Penicillin');
});

it('rejects a manual booking overlapping the same practitioner (409)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin');
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)->postJson('/termine', manualPayload($p, $s))
        ->assertStatus(409);
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `cd backend && php artisan test --filter=ManualBooking`
Expected: FAIL — `store` method/route not implemented (404 or method-not-found).

- [ ] **Step 4: Implement `store`**

In `backend/app/Http/Controllers/Tenant/AppointmentController.php`, add the imports:

```php
use App\Http\Requests\Tenant\StoreManualAppointmentRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Services\Tenant\AppointmentScheduler;
use Illuminate\Support\Facades\Mail;
```

Add the method:

```php
    public function store(StoreManualAppointmentRequest $request, AppointmentScheduler $scheduler): JsonResponse
    {
        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at'], self::TZ);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        // Cabinet override: only the overlap rule applies (inside the scheduler).
        $appointment = $scheduler->create([
            'practitioner_id' => $data['practitioner_id'],
            'service_id' => $data['service_id'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'patient_first_name' => $data['patient_first_name'],
            'patient_last_name' => $data['patient_last_name'],
            'patient_birthdate' => $data['patient_birthdate'],
            'parent_first_name' => $data['parent_first_name'],
            'parent_last_name' => $data['parent_last_name'],
            'parent_phone' => $data['parent_phone'],
            'parent_email' => $data['parent_email'] ?? null,
            // Manual bookings carry no explicit electronic consent record.
            'parent_consent_at' => null,
        ]);

        // notes_internal is intentionally NOT $fillable -> set by direct assignment.
        if (filled($data['notes_internal'] ?? null)) {
            $appointment->notes_internal = $data['notes_internal'];
            $appointment->save();
        }

        // Confirmation only when we actually have an address. Post-commit + rescue
        // so a queue-push failure can't 500 the already-created appointment.
        // Mirrors the widget controller (single-tenant: no tenant() calls).
        if (filled($appointment->parent_email)) {
            $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
            rescue(fn () => Mail::to($appointment->parent_email)->queue(
                new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
            ));
        }

        return response()->json($this->toDto($appointment->load(['service', 'practitioner'])), 201);
    }
```

- [ ] **Step 5: Run the test**

Run: `cd backend && php artisan test --filter=ManualBooking`
Expected: PASS (all four cases).

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Http/Controllers/Tenant/AppointmentController.php routes/web.php tests/Feature/TenantSchema/ManualBookingTest.php
git commit -m "feat: create manual appointment from cabinet (confirmation if email)"
```

---

## Task 9: `update` — reschedule (drag&drop) + edit

**Files:**
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/RescheduleTest.php`

- [ ] **Step 1: Add the route**

In `backend/routes/web.php`, inside the `auth` group:

```php
    Route::patch('/termine/{appointment}', [AppointmentController::class, 'update'])->name('tenant.appointments.update');
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/TenantSchema/RescheduleTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

it('reschedules an appointment to a new slot (drag&drop)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $new = $start->addHour();
    $this->actingAs($user)->patchJson("/termine/{$a->id}", [
        'starts_at' => $new->format('Y-m-d H:i:s'),
        'ends_at' => $new->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertOk();

    expect($a->fresh()->starts_at->equalTo($new))->toBeTrue();
});

it('rejects a reschedule onto another appointment of the same practitioner (409)', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start->addHour(), 'ends_at' => $start->addHour()->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)->patchJson("/termine/{$a->id}", [
        'starts_at' => $start->addHour()->format('Y-m-d H:i:s'),
        'ends_at' => $start->addHour()->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertStatus(409);
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `cd backend && php artisan test --filter=Reschedule`
Expected: FAIL — `update` route/method missing.

- [ ] **Step 4: Implement `update`**

In `backend/app/Http/Controllers/Tenant/AppointmentController.php`, add the imports:

```php
use App\Http\Requests\Tenant\UpdateAppointmentRequest;
use App\Models\Tenant\Appointment;
```

Add the method (handles drag&drop reschedule AND field edits; recomputes `ends_at` when `service_id` changes but no explicit `ends_at` is sent):

```php
    public function update(UpdateAppointmentRequest $request, AppointmentScheduler $scheduler, Appointment $appointment): JsonResponse
    {
        $data = $request->validated();

        // notes_internal is not $fillable — strip it from the scheduler payload
        // and apply it directly afterwards.
        $notesInternal = $data['notes_internal'] ?? null;
        $hasNotes = array_key_exists('notes_internal', $data);
        unset($data['notes_internal']);

        // Normalise dates to Berlin. If the service changed without an explicit
        // ends_at, recompute the end from the (new or existing) start + duration.
        if (isset($data['starts_at'])) {
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], self::TZ);
        }
        if (isset($data['ends_at'])) {
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], self::TZ);
        } elseif (isset($data['service_id'])) {
            $service = Service::findOrFail($data['service_id']);
            $start = $data['starts_at'] ?? $appointment->starts_at;
            $data['ends_at'] = CarbonImmutable::parse($start, self::TZ)->addMinutes($service->duration_minutes);
        }

        $appointment = $scheduler->reschedule($appointment, $data);

        if ($hasNotes) {
            $appointment->notes_internal = $notesInternal;
            $appointment->save();
        }

        return response()->json($this->toDto($appointment->load(['service', 'practitioner'])));
    }
```

- [ ] **Step 5: Run the test**

Run: `cd backend && php artisan test --filter=Reschedule`
Expected: PASS (move succeeds, overlap → 409).

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Http/Controllers/Tenant/AppointmentController.php routes/web.php tests/Feature/TenantSchema/RescheduleTest.php
git commit -m "feat: reschedule/edit appointment (drag&drop) with overlap check"
```

---

## Task 10: `destroy` — cancel appointment + auth guard test

**Files:**
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/CancelAppointmentTest.php`, `backend/tests/Feature/TenantSchema/CalendarAuthTest.php`

- [ ] **Step 1: Add the route**

In `backend/routes/web.php`, inside the `auth` group:

```php
    Route::delete('/termine/{appointment}', [AppointmentController::class, 'destroy'])->name('tenant.appointments.destroy');
```

- [ ] **Step 2: Write the failing tests**

Create `backend/tests/Feature/TenantSchema/CancelAppointmentTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use App\Services\Tenant\AppointmentScheduler;
use Carbon\CarbonImmutable;

it('cancels an appointment and frees the slot for re-booking', function () {
    $user = User::factory()->create();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $start = CarbonImmutable::parse('2026-06-01 09:00', 'Europe/Berlin');
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->deleteJson("/termine/{$a->id}")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    expect($a->fresh()->status)->toBe('cancelled');

    // The freed slot no longer overlaps (cancelled excluded), so a new booking succeeds.
    $fresh = app(AppointmentScheduler::class)->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
        'patient_first_name' => 'Tom', 'patient_last_name' => 'Berg', 'patient_birthdate' => '2018-01-01',
        'parent_first_name' => 'Ben', 'parent_last_name' => 'Berg', 'parent_phone' => '+49 170 1',
    ]);
    expect($fresh->status)->toBe('confirmed');
});
```

Create `backend/tests/Feature/TenantSchema/CalendarAuthTest.php`:

```php
<?php

it('redirects guests away from the calendar', function () {
    $this->get('/termine')->assertRedirect();
});
```

- [ ] **Step 3: Run them to confirm they fail**

Run: `cd backend && php artisan test --filter="CancelAppointment|CalendarAuth"`
Expected: the cancel test FAILS — `destroy` route missing. The auth test should already PASS (the `/termine` route is behind `auth` since Task 3); if it doesn't redirect, the route/middleware is wrong.

- [ ] **Step 4: Implement `destroy`**

In `backend/app/Http/Controllers/Tenant/AppointmentController.php`, add the method:

```php
    public function destroy(Appointment $appointment): JsonResponse
    {
        // Cabinet cancellation: free the slot (the feed excludes 'cancelled').
        // MVP: no parent email — the cabinet handles communication directly.
        if ($appointment->status !== 'cancelled') {
            $appointment->update(['status' => 'cancelled']);
        }

        return response()->json(['status' => 'cancelled']);
    }
```

- [ ] **Step 5: Run the tests**

Run: `cd backend && php artisan test --filter="CancelAppointment|CalendarAuth"`
Expected: PASS (cancel frees the slot; guest redirected).

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Http/Controllers/Tenant/AppointmentController.php routes/web.php tests/Feature/TenantSchema/CancelAppointmentTest.php tests/Feature/TenantSchema/CalendarAuthTest.php
git commit -m "feat: cancel appointment from cabinet + calendar auth guard test"
```

---

## Task 11: `AppointmentForm.vue` + wire create/edit/drag&drop/cancel in `Calendar.vue`

**Files:**
- Create: `backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`
- Modify: `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue`

> Verified visually in Chrome (next section). No automated test — interactions are integration-level; the mapper (Task 4) and all endpoints (Tasks 6/8/9/10) are already tested.

- [ ] **Step 1: Create the form modal**

Create `backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`:

```vue
<script setup lang="ts">
import { ref, reactive, watch } from 'vue'
import type { AppointmentDto } from '@/lib/calendar'

const props = defineProps<{
    open: boolean
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
    // edit mode: existing appointment; create mode: a prefilled {starts_at, practitioner_id?}
    appointment: AppointmentDto | null
    prefill: { starts_at?: string; practitioner_id?: number } | null
}>()

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const form = reactive({
    practitioner_id: 0, service_id: 0, starts_at: '',
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_phone: '', parent_email: '',
    notes_internal: '',
})

const isEdit = ref(false)

watch(() => props.open, (open) => {
    if (!open) return
    errors.value = {}
    if (props.appointment) {
        isEdit.value = true
        const a = props.appointment
        Object.assign(form, {
            practitioner_id: a.practitioner.id, service_id: a.service.id,
            starts_at: a.starts_at.slice(0, 16),
            patient_first_name: a.patient_first_name, patient_last_name: a.patient_last_name,
            patient_birthdate: a.patient_birthdate ?? '',
            parent_first_name: a.parent_first_name, parent_last_name: a.parent_last_name,
            parent_phone: a.parent_phone ?? '', parent_email: a.parent_email ?? '',
            notes_internal: a.notes_internal ?? '',
        })
    } else {
        isEdit.value = false
        Object.assign(form, {
            practitioner_id: props.prefill?.practitioner_id ?? (props.practitioners[0]?.id ?? 0),
            service_id: props.services[0]?.id ?? 0,
            starts_at: (props.prefill?.starts_at ?? '').slice(0, 16),
            patient_first_name: '', patient_last_name: '', patient_birthdate: '',
            parent_first_name: '', parent_last_name: '', parent_phone: '', parent_email: '', notes_internal: '',
        })
    }
})

const submit = async () => {
    saving.value = true
    errors.value = {}
    const payload = { ...form, parent_email: form.parent_email || null }
    try {
        if (isEdit.value && props.appointment) {
            await window.axios.patch(`/termine/${props.appointment.id}`, payload)
        } else {
            await window.axios.post('/termine', payload)
        }
        emit('saved')
    } catch (e: any) {
        if (e.response?.status === 422) errors.value = e.response.data.errors ?? {}
        else if (e.response?.status === 409) errors.value = { starts_at: [e.response.data.message ?? 'Überschneidung.'] }
        else errors.value = { _: ['Ein Fehler ist aufgetreten.'] }
    } finally {
        saving.value = false
    }
}

const cancelAppointment = async () => {
    if (!props.appointment) return
    if (!confirm('Diesen Termin stornieren?')) return
    saving.value = true
    try {
        await window.axios.delete(`/termine/${props.appointment.id}`)
        emit('saved')
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="emit('close')">
        <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <h2 class="text-xl font-bold mb-4">{{ isEdit ? 'Termin bearbeiten' : 'Neuer Termin' }}</h2>

            <p v-if="errors._" class="text-red-600 text-sm mb-2">{{ errors._[0] }}</p>

            <div class="grid grid-cols-2 gap-3">
                <label class="col-span-2 text-sm">Behandler
                    <select v-model.number="form.practitioner_id" class="w-full border rounded p-2">
                        <option v-for="p in practitioners" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>
                <label class="col-span-2 text-sm">Leistung
                    <select v-model.number="form.service_id" class="w-full border rounded p-2">
                        <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }} ({{ s.duration_minutes }} Min.)</option>
                    </select>
                </label>
                <label class="col-span-2 text-sm">Termin (Beginn)
                    <input v-model="form.starts_at" type="datetime-local" class="w-full border rounded p-2" />
                    <span v-if="errors.starts_at" class="text-red-600 text-xs">{{ errors.starts_at[0] }}</span>
                </label>

                <label class="text-sm">Kind Vorname
                    <input v-model="form.patient_first_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Kind Nachname
                    <input v-model="form.patient_last_name" class="w-full border rounded p-2" />
                </label>
                <label class="col-span-2 text-sm">Geburtsdatum
                    <input v-model="form.patient_birthdate" type="date" class="w-full border rounded p-2" />
                </label>

                <label class="text-sm">Eltern Vorname
                    <input v-model="form.parent_first_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Eltern Nachname
                    <input v-model="form.parent_last_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Telefon
                    <input v-model="form.parent_phone" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">E-Mail (optional)
                    <input v-model="form.parent_email" type="email" class="w-full border rounded p-2" />
                </label>
                <label class="col-span-2 text-sm">Interne Notiz
                    <textarea v-model="form.notes_internal" class="w-full border rounded p-2" rows="2"></textarea>
                </label>
            </div>

            <div class="flex justify-between items-center mt-5">
                <button v-if="isEdit" @click="cancelAppointment" :disabled="saving" class="text-red-600 text-sm">Termin stornieren</button>
                <span v-else></span>
                <div class="flex gap-2">
                    <button @click="emit('close')" :disabled="saving" class="px-4 py-2 rounded border">Abbrechen</button>
                    <button @click="submit" :disabled="saving" class="px-4 py-2 rounded bg-blue-700 text-white">Speichern</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Wire the form + interactions into `Calendar.vue`**

Replace the `<script setup>` block of `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue` to add selection/drag handlers and the modal state (keep the existing imports/options and ADD the marked parts):

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import deLocale from '@fullcalendar/core/locales/de'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'
import AppointmentForm from './AppointmentForm.vue'

defineOptions({ layout: TenantLayout })

const props = defineProps<{
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
}>()

const calendarRef = ref()
const activePractitioners = ref<number[]>(props.practitioners.map((p) => p.id))

// modal state
const formOpen = ref(false)
const editing = ref<AppointmentDto | null>(null)
const prefill = ref<{ starts_at?: string; practitioner_id?: number } | null>(null)

const refetch = () => calendarRef.value?.getApi().refetchEvents()

const togglePractitioner = (id: number) => {
    const i = activePractitioners.value.indexOf(id)
    if (i === -1) activePractitioners.value.push(id)
    else activePractitioners.value.splice(i, 1)
    refetch()
}

const fetchEvents = async (info: { startStr: string; endStr: string }, success: (e: any[]) => void, failure: (e: any) => void) => {
    try {
        const { data } = await window.axios.get('/termine/events', {
            params: { start: info.startStr, end: info.endStr, practitioner_ids: activePractitioners.value },
        })
        success((data as AppointmentDto[]).map(toCalendarEvent))
    } catch (e) {
        failure(e)
    }
}

const openCreate = (startStr: string) => {
    editing.value = null
    prefill.value = {
        starts_at: startStr,
        practitioner_id: activePractitioners.value.length === 1 ? activePractitioners.value[0] : undefined,
    }
    formOpen.value = true
}

const openEdit = (dto: AppointmentDto) => {
    editing.value = dto
    prefill.value = null
    formOpen.value = true
}

const onDrop = async (info: any) => {
    try {
        await window.axios.patch(`/termine/${info.event.id}`, {
            starts_at: info.event.startStr,
            ends_at: info.event.endStr,
        })
    } catch (e) {
        info.revert()
    }
}

const onSaved = () => {
    formOpen.value = false
    refetch()
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    locale: deLocale,
    timeZone: 'Europe/Berlin',
    firstDay: 1,
    nowIndicator: true,
    selectable: true,
    editable: true,
    eventDurationEditable: true,
    slotMinTime: '07:00:00',
    slotMaxTime: '20:00:00',
    allDaySlot: false,
    height: 'auto',
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
    events: fetchEvents,
    dateClick: (arg: any) => openCreate(arg.dateStr),
    eventClick: (arg: any) => openEdit(arg.event.extendedProps as AppointmentDto),
    eventDrop: onDrop,
    eventResize: onDrop,
}))
</script>
```

And ADD the modal to the template (just before the closing `</div>` of the page wrapper):

```vue
        <AppointmentForm
            :open="formOpen"
            :practitioners="props.practitioners"
            :services="props.services"
            :appointment="editing"
            :prefill="prefill"
            @close="formOpen = false"
            @saved="onSaved"
        />
```

- [ ] **Step 3: Build to verify it compiles**

Run: `cd backend && npm run build`
Expected: build succeeds.

- [ ] **Step 4: Run the full frontend + backend suites**

Run: `cd backend && npm run test:widget && composer test`
Expected: Vitest green (incl. `calendar.test.ts`); `composer test` green (single `RefreshDatabase` suite, incl. all Phase 5 tests).

- [ ] **Step 5: Commit**

```bash
cd backend && git add resources/js/Pages/Tenant/Appointments/AppointmentForm.vue resources/js/Pages/Tenant/Appointments/Calendar.vue
git commit -m "feat: appointment create/edit/cancel modal + drag&drop reschedule"
```

---

## Manual / Chrome verification (after all tasks)

1. `cd backend && npm run build` then `php artisan serve --host=127.0.0.1 --port=8000`. Log in at `/login` as the seeded staff user (see `database/seeders/KidsClubSeeder.php`).
2. Open **`/termine`** → the week view renders with the practitioner filter chips; existing seeded appointments appear color-coded.
3. **Create**: click an empty slot → modal prefilled with that time → fill child/parent/phone (leave email empty) → Speichern → the event appears; confirm no email in `storage/logs/laravel.log`. Repeat WITH an email → confirm an `AppointmentConfirmationMail` is logged (run `php artisan queue:work --once`).
4. **Drag&drop**: drag an event to a new slot → persists. Drag it onto another event of the same practitioner → snaps back (409 → `revert()`).
5. **Override**: create an appointment at 20:00 (outside opening hours) → accepted.
6. **Cancel**: open an event → "Termin stornieren" → disappears from the calendar; re-creating on its old slot succeeds.
7. Capture a short GIF of create + drag&drop for the PR.

---

## Self-Review (notes for the executor)

- **Spec coverage:** migration nullable (T1) · FullCalendar deps (T2) · index+events feed+filter (T3) · TS mapper (T4) · read calendar page + nav (T5) · scheduler override+overlap lock (T6) · form requests (T7) · manual create + confirmation-if-email + notes_internal direct-assign (T8) · reschedule/drag+overlap-excl-self (T9) · cancel + auth guard (T10) · form modal + drag&drop wiring (T11). Every spec section (§4–§10) maps to a task. §12 lots A/B = Tasks 1–5 / 6–11.
- **Single-tenant:** routes in `routes/web.php` (`auth` group); one `RefreshDatabase` suite; tests use relative URLs + `User::factory()->create()`; confirmation mail uses `route('storno.show', ['token' => …])` + `config('app.name')` (mirrors `Widget\AppointmentController`); no `tenant()`, no `TenantTestCase`, no `--testsuite=tenant`. `Tenant\` namespaces + `TenantSchema/` folder kept (vestigial).
- **Type/signature consistency:** `AppointmentScheduler::create(array)`/`reschedule(Appointment, array)` used identically in T6/T8/T9; controller `toDto()` shape matches `AppointmentDto` in `lib/calendar.ts` (T3↔T4); route names `tenant.appointments.*` consistent; `self::TZ = AvailabilityCalculator::CLINIC_TIMEZONE` used throughout the controller.
- **No placeholders:** every code/test step is complete.
- **Test placement:** all backend tests in `tests/Feature/TenantSchema` (run via `php artisan test --filter` / `composer test`); the mapper test in `tests/widget` (Vitest). `Mail::fake()` for mail assertions.
- **Cabinet override proven:** the 20:00 test (T6 + T8) demonstrates `isBookable()` is intentionally NOT applied; overlap-only is enforced (409 tests in T6/T8/T9).
