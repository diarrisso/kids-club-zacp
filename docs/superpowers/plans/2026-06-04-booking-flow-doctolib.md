# Flow de réservation « date-first » style Doctolib — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre le widget public en un parcours date-first façon Doctolib — soin → calendrier → créneaux multi-médecins avec filtre — sans casser le contrat de réservation existant.

**Architecture:** Le backend gagne deux capacités de lecture (jours disponibles tous médecins ; créneaux fusionnés multi-médecins, chacun étiqueté de son praticien) et rend le délai/horizon configurables via `Setting`. Le widget passe de 5 à 4 étapes : l'étape praticien disparaît (le praticien est porté par le créneau choisi), date+créneaux fusionnent dans une étape « Termin » avec calendrier maison + filtre médecin côté client.

**Tech Stack:** Laravel 13 · PostgreSQL · Pest 4 (backend) · Vue 3 `<script setup lang="ts">` · Vitest + @vue/test-utils (widget IIFE).

**Working directory:** toutes les commandes PHP/npm se lancent depuis `backend/`.

**Branche :** `feature/booking-flow-doctolib` (déjà créée depuis `main`).

**⚠️ Dépendance de séquencement :** la PR #20 (widget redesign, style pastel) touche `App.vue` + les `steps/*.vue`. Ce plan opère sur le code de `main` (style basique fonctionnel). Recommandation : merger la PR #20 d'abord puis rebaser cette branche pour conserver le style — ou prévoir un follow-up de restylage de `TerminStep.vue` + `BookingCalendar.vue`. Le présent plan livre la **logique** et des composants Tailwind sobres ; le polish visuel est volontairement hors scope.

---

## File Structure

**Backend (à modifier) :**
- `app/Services/Tenant/AvailabilityCalculator.php` — lead/horizon via `Setting` (helpers privés) + nouvelle méthode `availableDates()`.
- `app/Http/Controllers/Widget/SlotController.php` — `practitioner_id` optionnel, fusion multi-médecins, enrichissement praticien.
- `app/Http/Controllers/Widget/AvailabilityController.php` — **créer** : endpoint `days`.
- `routes/api.php` — router `/availability/days`.

**Backend (tests, à créer/modifier) :**
- `tests/Feature/TenantSchema/BookingSettingsTest.php` — **créer**.
- `tests/Feature/TenantSchema/WidgetAvailabilityDaysTest.php` — **créer**.
- `tests/Feature/TenantSchema/WidgetSlotTest.php` — **modifier** (ajouter le cas multi-médecins).

**Widget (à modifier) :**
- `resources/js/widget/types.ts` — `Slot` porte `practitioner`.
- `resources/js/widget/useWizard.ts` — 4 étapes, suppression de l'étape praticien.
- `resources/js/widget/api.ts` — `slots()` signature + `availabilityDays()`.
- `resources/js/widget/App.vue` — réorchestration.

**Widget (à créer) :**
- `resources/js/widget/components/BookingCalendar.vue` — calendrier mensuel maison.
- `resources/js/widget/steps/TerminStep.vue` — calendrier + filtre + créneaux.

**Widget (à supprimer) :**
- `resources/js/widget/steps/PractitionerStep.vue` + `resources/js/widget/steps/SlotStep.vue`.
- `tests/widget/SlotStep.test.ts`.

**Widget (tests, à créer/modifier) :**
- `tests/widget/BookingCalendar.test.ts` — **créer**.
- `tests/widget/TerminStep.test.ts` — **créer**.
- `tests/widget/wizard.test.ts` — **réécrire** (nouveau flow).
- `tests/widget/steps.test.ts` — **modifier** (retirer PractitionerStep).
- `tests/widget/app.test.ts` — **réécrire** (parcours date-first).

---

## Task 1: Lead time & horizon configurables via `Setting`

**Files:**
- Modify: `app/Services/Tenant/AvailabilityCalculator.php`
- Test: `tests/Feature/TenantSchema/BookingSettingsTest.php` (create)

- [ ] **Step 1: Write the failing test**

Crée `tests/Feature/TenantSchema/BookingSettingsTest.php` :

```php
<?php

use App\Models\Setting;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;

it('clamps the horizon to the booking.horizon_days setting', function () {
    Setting::put('booking.horizon_days', '3');

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);

    // A weekly availability that only becomes valid 10 days out (valid_from),
    // so it can only be reached if the horizon extends past 3 days.
    $far = CarbonImmutable::now()->addDays(10);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => $far->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'valid_from' => $far->toDateString(),
    ]);

    $slots = app(AvailabilityCalculator::class)->forPractitionerService(
        $p, $s,
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->addDays(60)->endOfDay(),
    );

    expect($slots)->toHaveCount(0); // horizon=3 never reaches the day-10 availability
});

it('uses the constant horizon by default (no setting row)', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);

    $far = CarbonImmutable::now()->addDays(10);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => $far->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'valid_from' => $far->toDateString(),
    ]);

    $slots = app(AvailabilityCalculator::class)->forPractitionerService(
        $p, $s,
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->addDays(60)->endOfDay(),
    );

    expect($slots->count())->toBeGreaterThan(0); // default horizon 60 reaches day 10
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BookingSettings`
Expected: FAIL — le 1er cas trouve des créneaux (horizon codé en dur à 60 ignore le setting).

- [ ] **Step 3: Implement — read lead/horizon from Setting**

Dans `app/Services/Tenant/AvailabilityCalculator.php`, ajoute l'import en haut :

```php
use App\Models\Setting;
```

Ajoute deux helpers privés (par ex. juste avant `overlapsAny`) :

```php
private function leadMinutes(): int
{
    return (int) Setting::get('booking.lead_minutes', (string) self::LEAD_MINUTES);
}

private function horizonDays(): int
{
    return (int) Setting::get('booking.horizon_days', (string) self::HORIZON_DAYS);
}
```

Dans `forPractitionerService`, remplace les deux lignes :

```php
$earliest = CarbonImmutable::now()->addMinutes(self::LEAD_MINUTES);
$latest = CarbonImmutable::now()->addDays(self::HORIZON_DAYS);
```

par :

```php
$earliest = CarbonImmutable::now()->addMinutes($this->leadMinutes());
$latest = CarbonImmutable::now()->addDays($this->horizonDays());
```

Dans `isBookable`, remplace :

```php
if ($startsAt->lessThan($now->addMinutes(self::LEAD_MINUTES))) {
    return false;
}
if ($startsAt->greaterThan($now->addDays(self::HORIZON_DAYS))) {
    return false;
}
```

par :

```php
if ($startsAt->lessThan($now->addMinutes($this->leadMinutes()))) {
    return false;
}
if ($startsAt->greaterThan($now->addDays($this->horizonDays()))) {
    return false;
}
```

Laisse les constantes `LEAD_MINUTES` / `HORIZON_DAYS` en place (valeurs par défaut + référencées ailleurs, ex. `AppointmentController`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BookingSettings`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing calculator/slot tests for regressions**

Run: `php artisan test --filter="AvailabilityCalculator|WidgetSlot"`
Expected: PASS (les constantes restent les défauts → comportement inchangé).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Tenant/AvailabilityCalculator.php backend/tests/Feature/TenantSchema/BookingSettingsTest.php
git commit -m "feat(booking): make lead time and horizon configurable via Setting"
```

---

## Task 2: `availableDates()` + endpoint `GET /availability/days`

**Files:**
- Modify: `app/Services/Tenant/AvailabilityCalculator.php`
- Create: `app/Http/Controllers/Widget/AvailabilityController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/TenantSchema/WidgetAvailabilityDaysTest.php` (create)

- [ ] **Step 1: Write the failing test**

Crée `tests/Feature/TenantSchema/WidgetAvailabilityDaysTest.php` :

```php
<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

it('lists distinct dates with at least one free slot across practitioners', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s->practitioners()->attach([$p1->id, $p2->id]);

    // p1 works Monday, p2 works Tuesday → both days available, Wednesday not.
    Availability::factory()->create([
        'practitioner_id' => $p1->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Availability::factory()->create([
        'practitioner_id' => $p2->id, 'day_of_week' => 2,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/availability/days?' . http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->addDays(2)->toDateString(),
    ]))
        ->assertOk()
        ->assertExactJson([
            $monday->toDateString(),
            $monday->addDay()->toDateString(),
        ]);
});

it('validates that the service exists', function () {
    $this->getJson('/api/v1/widget/availability/days?' . http_build_query([
        'service_id' => 999999,
        'from' => now()->toDateString(),
        'to' => now()->addDay()->toDateString(),
    ]))->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WidgetAvailabilityDays`
Expected: FAIL (404/route inexistante).

- [ ] **Step 3: Add `availableDates()` to the calculator**

Dans `app/Services/Tenant/AvailabilityCalculator.php`, ajoute cette méthode publique (par ex. juste après `forPractitionerService`) :

```php
/** @return Collection<int, string> distinct YYYY-MM-DD dates (clinic tz) having >=1 free slot */
public function availableDates(Service $service, CarbonImmutable $from, CarbonImmutable $to): Collection
{
    $dates = collect();

    foreach ($service->practitioners()->where('is_active', true)->get() as $practitioner) {
        foreach ($this->forPractitionerService($practitioner, $service, $from, $to) as $slot) {
            $dates->push($slot->starts_at->setTimezone(self::CLINIC_TIMEZONE)->toDateString());
        }
    }

    return $dates->unique()->sort()->values();
}
```

- [ ] **Step 4: Create the controller**

Crée `app/Http/Controllers/Widget/AvailabilityController.php` :

```php
<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function days(Request $request, AvailabilityCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $service = Service::findOrFail($data['service_id']);

        $dates = $calculator->availableDates(
            $service,
            CarbonImmutable::parse($data['from'])->startOfDay(),
            CarbonImmutable::parse($data['to'])->endOfDay(),
        );

        return response()->json($dates->values());
    }
}
```

- [ ] **Step 5: Route it**

Dans `routes/api.php`, ajoute l'import :

```php
use App\Http\Controllers\Widget\AvailabilityController;
```

et, dans le groupe `throttle:widget-read`, ajoute la route (après la ligne `/slots`) :

```php
Route::get('/availability/days', [AvailabilityController::class, 'days']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=WidgetAvailabilityDays`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Tenant/AvailabilityCalculator.php backend/app/Http/Controllers/Widget/AvailabilityController.php backend/routes/api.php backend/tests/Feature/TenantSchema/WidgetAvailabilityDaysTest.php
git commit -m "feat(booking): availability days endpoint (dates with free slots, all practitioners)"
```

---

## Task 3: Étendre `/slots` — multi-médecins + enrichissement praticien

**Files:**
- Modify: `app/Http/Controllers/Widget/SlotController.php`
- Test: `tests/Feature/TenantSchema/WidgetSlotTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

Ajoute ces deux cas à la fin de `tests/Feature/TenantSchema/WidgetSlotTest.php` :

```php
it('merges slots from all practitioners offering the service, each tagged with its practitioner', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'color' => '#98ACBA']);
    $p2 = Practitioner::factory()->create(['first_name' => 'Tom', 'last_name' => 'Adler', 'color' => '#F7E29D']);
    $s->practitioners()->attach([$p1->id, $p2->id]);

    Availability::factory()->create([
        'practitioner_id' => $p1->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    Availability::factory()->create([
        'practitioner_id' => $p2->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson('/api/v1/widget/slots?' . http_build_query([
        'service_id' => $s->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonStructure([['starts_at', 'ends_at', 'practitioner' => ['id', 'first_name', 'last_name', 'color']]])
        ->assertJsonCount(4); // 2 practitioners × (09:00, 09:30)
});

it('restricts to one practitioner when practitioner_id is given', function () {
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

    $s = Service::factory()->create(['duration_minutes' => 30]);
    $p1 = Practitioner::factory()->create();
    $p2 = Practitioner::factory()->create();
    $s->practitioners()->attach([$p1->id, $p2->id]);
    Availability::factory()->create(['practitioner_id' => $p1->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);
    Availability::factory()->create(['practitioner_id' => $p2->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

    $this->getJson('/api/v1/widget/slots?' . http_build_query([
        'service_id' => $s->id,
        'practitioner_id' => $p1->id,
        'from' => $monday->toDateString(),
        'to' => $monday->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonCount(2) // only p1's two slots
        ->assertJsonPath('0.practitioner.id', $p1->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WidgetSlot`
Expected: FAIL — l'endpoint exige `practitioner_id` (required) et n'inclut pas `practitioner` dans la réponse.

- [ ] **Step 3: Rewrite the controller**

Remplace tout le contenu de `app/Http/Controllers/Widget/SlotController.php` par :

```php
<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotController extends Controller
{
    public function index(Request $request, AvailabilityCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],
        ]);

        $service = Service::findOrFail($data['service_id']);

        // One practitioner if explicitly requested (back-compat), else every active
        // practitioner offering this service (the date-first, multi-doctor flow).
        $practitioners = isset($data['practitioner_id'])
            ? Practitioner::query()->whereKey($data['practitioner_id'])->get()
            : $service->practitioners()->where('is_active', true)->get();

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();

        $slots = collect();
        foreach ($practitioners as $practitioner) {
            foreach ($calculator->forPractitionerService($practitioner, $service, $from, $to) as $slot) {
                $slots->push($slot->toArray() + [
                    'practitioner' => [
                        'id' => $practitioner->id,
                        'first_name' => $practitioner->first_name,
                        'last_name' => $practitioner->last_name,
                        'title' => $practitioner->title,
                        'color' => $practitioner->color,
                    ],
                ]);
            }
        }

        return response()->json(
            $slots->sortBy([
                ['starts_at', 'asc'],
                ['practitioner.last_name', 'asc'],
            ])->values()->all()
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WidgetSlot`
Expected: PASS — incluant l'ancien cas `returns free slots for a practitioner and service` (la clé `practitioner` en plus ne casse pas `assertJsonStructure([['starts_at','ends_at']])`).

- [ ] **Step 5: Run the widget API tests for regressions**

Run: `php artisan test --filter="WidgetSlot|WidgetRateLimit|WidgetBooking"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Widget/SlotController.php backend/tests/Feature/TenantSchema/WidgetSlotTest.php
git commit -m "feat(booking): slots endpoint merges all practitioners, tags each slot with its doctor"
```

---

## Task 4: Widget — `types.ts` + `useWizard.ts` (4 étapes)

**Files:**
- Modify: `resources/js/widget/types.ts`
- Modify: `resources/js/widget/useWizard.ts`
- Test: `tests/widget/wizard.test.ts` (rewrite)

- [ ] **Step 1: Rewrite the wizard test (failing)**

Remplace tout le contenu de `tests/widget/wizard.test.ts` par :

```ts
import { describe, it, expect } from 'vitest'
import { useWizard } from '@widget/useWizard'

const slot = {
    starts_at: '2026-09-07T09:00:00+02:00',
    ends_at: '2026-09-07T09:30:00+02:00',
    practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' },
}

describe('useWizard', () => {
    it('advances service → termin → form', () => {
        const w = useWizard()
        expect(w.step.value).toBe('service')

        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)

        w.chooseSlot(slot)
        expect(w.step.value).toBe('form')
        expect(w.selection.slot?.practitioner.id).toBe(2)
    })

    it('goes back one step linearly, retaining the service', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot) // termin -> form
        w.back() // form -> termin
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)
    })

    it('moves to success after booking', () => {
        const w = useWizard()
        w.complete()
        expect(w.step.value).toBe('success')
    })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:widget -- wizard`
Expected: FAIL — `chooseService` mène encore à `'practitioner'`, `choosePractitioner` existe encore.

- [ ] **Step 3: Update `types.ts`**

Dans `resources/js/widget/types.ts`, remplace l'interface `Slot` :

```ts
export interface Slot { starts_at: string; ends_at: string }
```

par (réutilise l'interface `Practitioner` déjà déclarée juste au-dessus) :

```ts
export interface Slot { starts_at: string; ends_at: string; practitioner: Practitioner }
```

Le reste de `types.ts` (dont `BookingPayload.practitioner_id`) est inchangé.

- [ ] **Step 4: Rewrite `useWizard.ts`**

Remplace tout le contenu de `resources/js/widget/useWizard.ts` par :

```ts
import { ref, reactive } from 'vue'
import type { Service, Slot } from './types'

export type Step = 'service' | 'termin' | 'form' | 'success'
const ORDER: Step[] = ['service', 'termin', 'form', 'success']

export function useWizard() {
    const step = ref<Step>('service')
    const selection = reactive<{ service?: Service; slot?: Slot }>({})

    const go = (s: Step) => { step.value = s }

    return {
        step,
        selection,
        chooseService(s: Service) { selection.service = s; go('termin') },
        chooseSlot(slot: Slot) { selection.slot = slot; go('form') },
        complete() { go('success') },
        back() {
            // Linear back: the practitioner is now carried by the chosen slot,
            // so the flow is service → termin → form → success.
            const i = ORDER.indexOf(step.value)
            if (i > 0) go(ORDER[i - 1])
        },
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npm run test:widget -- wizard`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/resources/js/widget/types.ts backend/resources/js/widget/useWizard.ts backend/tests/widget/wizard.test.ts
git commit -m "feat(widget): 4-step wizard, slot carries its practitioner"
```

---

## Task 5: Widget — `api.ts` (`slots` signature + `availabilityDays`)

**Files:**
- Modify: `resources/js/widget/api.ts`
- Test: `tests/widget/api.test.ts` (add one case)

- [ ] **Step 1: Add a failing test**

Ajoute ce cas à l'intérieur du `describe('api client', ...)` de `tests/widget/api.test.ts` :

```ts
it('builds the availability/days URL with service and window', async () => {
    const spy = mockFetch(200, ['2026-09-07'])
    const days = await api.availabilityDays(1, '2026-09-01', '2026-09-30')
    expect(spy).toHaveBeenCalledWith(
        'https://app.test/api/v1/widget/availability/days?service_id=1&from=2026-09-01&to=2026-09-30',
        expect.anything(),
    )
    expect(days).toEqual(['2026-09-07'])
})

it('omits practitioner_id from the slots URL when not provided', async () => {
    const spy = mockFetch(200, [])
    await api.slots(1, '2026-09-07', '2026-09-07')
    expect(spy).toHaveBeenCalledWith(
        'https://app.test/api/v1/widget/slots?service_id=1&from=2026-09-07&to=2026-09-07',
        expect.anything(),
    )
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:widget -- api`
Expected: FAIL — `availabilityDays` n'existe pas ; `slots` a l'ancienne signature `(practitionerId, serviceId, from, to)`.

- [ ] **Step 3: Update `api.ts`**

Dans `resources/js/widget/api.ts`, remplace la propriété `slots:` de l'objet retourné :

```ts
        slots: (practitionerId: number, serviceId: number, from: string, to: string) => {
            const qs = new URLSearchParams({
                practitioner_id: String(practitionerId),
                service_id: String(serviceId),
                from, to,
            })
            return request<Slot[]>(`/slots?${qs.toString()}`)
        },
```

par :

```ts
        slots: (serviceId: number, from: string, to: string, practitionerId?: number) => {
            const params: Record<string, string> = { service_id: String(serviceId), from, to }
            if (practitionerId != null) params.practitioner_id = String(practitionerId)
            return request<Slot[]>(`/slots?${new URLSearchParams(params).toString()}`)
        },
        availabilityDays: (serviceId: number, from: string, to: string) => {
            const qs = new URLSearchParams({ service_id: String(serviceId), from, to })
            return request<string[]>(`/availability/days?${qs.toString()}`)
        },
```

(La propriété `practitioners:` existante reste en place — elle ne gêne pas ; cleanup optionnel hors scope.)

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:widget -- api`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/api.ts backend/tests/widget/api.test.ts
git commit -m "feat(widget): api client gains availabilityDays, slots takes optional practitioner_id"
```

---

## Task 6: Widget — composant `BookingCalendar.vue`

**Files:**
- Create: `resources/js/widget/components/BookingCalendar.vue`
- Test: `tests/widget/BookingCalendar.test.ts` (create)

- [ ] **Step 1: Write the failing test**

Crée `tests/widget/BookingCalendar.test.ts` :

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BookingCalendar from '@widget/components/BookingCalendar.vue'

function todayYmd(): string {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

describe('BookingCalendar', () => {
    it('emits month-change on mount with a from/to window', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        const ev = wrapper.emitted('month-change')?.[0]?.[0] as { from: string; to: string }
        expect(ev).toBeTruthy()
        expect(ev.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
        expect(ev.to).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    })

    it('marks an available day clickable and emits select with its date', async () => {
        const today = todayYmd()
        const wrapper = mount(BookingCalendar, { props: { availableDates: [today] } })
        const cell = wrapper.get(`[data-day="${today}"]`)
        expect(cell.attributes('data-available')).toBeDefined()
        await cell.trigger('click')
        expect(wrapper.emitted('select')?.[0]?.[0]).toBe(today)
    })

    it('disables a day without availability', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        const cell = wrapper.get(`[data-day="${todayYmd()}"]`)
        expect((cell.element as HTMLButtonElement).disabled).toBe(true)
    })

    it('navigates to the next month and re-emits month-change', async () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        await wrapper.get('[data-next-month]').trigger('click')
        expect(wrapper.emitted('month-change')?.length).toBe(2)
    })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:widget -- BookingCalendar`
Expected: FAIL — le composant n'existe pas.

- [ ] **Step 3: Create the component**

Crée `resources/js/widget/components/BookingCalendar.vue` :

```vue
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

const props = defineProps<{ availableDates: string[]; selectedDate?: string }>()
const emit = defineEmits<{ 'month-change': [{ from: string; to: string }]; select: [date: string] }>()

const today = new Date()
const todayStr = ymd(today)
const viewYear = ref(today.getFullYear())
const viewMonth = ref(today.getMonth()) // 0-11

function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }),
)

const weekdayLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']

// Cells: leading blanks (Mon-first) then each day of the month.
const cells = computed(() => {
    const first = new Date(viewYear.value, viewMonth.value, 1)
    const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate()
    const lead = (first.getDay() + 6) % 7 // JS Sun=0 → Mon-first offset
    const out: Array<{ key: string; day: number | null; date: string | null; available: boolean }> = []

    for (let i = 0; i < lead; i++) out.push({ key: `b${i}`, day: null, date: null, available: false })

    for (let day = 1; day <= daysInMonth; day++) {
        const date = ymd(new Date(viewYear.value, viewMonth.value, day))
        const available = props.availableDates.includes(date) && date >= todayStr
        out.push({ key: date, day, date, available })
    }
    return out
})

function emitMonthChange() {
    const start = new Date(viewYear.value, viewMonth.value, 1)
    const end = new Date(viewYear.value, viewMonth.value + 1, 0)
    const startStr = ymd(start)
    emit('month-change', { from: startStr < todayStr ? todayStr : startStr, to: ymd(end) })
}

function prevMonth() {
    if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value-- }
    else viewMonth.value--
    emitMonthChange()
}

function nextMonth() {
    if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++ }
    else viewMonth.value++
    emitMonthChange()
}

onMounted(emitMonthChange)
</script>

<template>
    <div data-calendar>
        <div class="flex items-center justify-between mb-2">
            <button type="button" data-prev-month @click="prevMonth"
                    class="px-2 py-1 rounded hover:bg-slate-100" aria-label="Vorheriger Monat">‹</button>
            <span class="font-medium capitalize">{{ monthLabel }}</span>
            <button type="button" data-next-month @click="nextMonth"
                    class="px-2 py-1 rounded hover:bg-slate-100" aria-label="Nächster Monat">›</button>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-xs text-slate-400 mb-1">
            <span v-for="w in weekdayLabels" :key="w">{{ w }}</span>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-sm">
            <template v-for="cell in cells" :key="cell.key">
                <span v-if="cell.day === null"></span>
                <button v-else type="button"
                        :data-day="cell.date ?? undefined"
                        :data-available="cell.available || undefined"
                        :disabled="!cell.available"
                        @click="cell.date && cell.available && $emit('select', cell.date)"
                        :class="[
                            'py-2 rounded',
                            cell.available ? 'cursor-pointer hover:bg-blue-100' : 'text-slate-300 cursor-default',
                            cell.date === selectedDate ? 'bg-blue-500 text-white hover:bg-blue-500' : (cell.available ? 'bg-blue-50' : ''),
                        ]">
                    {{ cell.day }}
                </button>
            </template>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:widget -- BookingCalendar`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/components/BookingCalendar.vue backend/tests/widget/BookingCalendar.test.ts
git commit -m "feat(widget): month calendar component (available days clickable, month nav)"
```

---

## Task 7: Widget — `steps/TerminStep.vue` (calendrier + filtre + créneaux)

**Files:**
- Create: `resources/js/widget/steps/TerminStep.vue`
- Test: `tests/widget/TerminStep.test.ts` (create)

- [ ] **Step 1: Write the failing test**

Crée `tests/widget/TerminStep.test.ts` :

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TerminStep from '@widget/steps/TerminStep.vue'

const slots = [
    { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00', practitioner: { id: 1, first_name: 'Anna', last_name: 'Berg', color: '#98ACBA' } },
    { starts_at: '2026-09-07T09:30:00+02:00', ends_at: '2026-09-07T10:00:00+02:00', practitioner: { id: 2, first_name: 'Tom', last_name: 'Adler', color: '#F7E29D' } },
]

const base = { availableDates: ['2026-09-07'], loadingSlots: false, selectedDate: '2026-09-07' }

describe('TerminStep', () => {
    it('shows one filter chip per practitioner and filters slots client-side', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        expect(wrapper.findAll('[data-slot]')).toHaveLength(2)
        expect(wrapper.findAll('[data-filter]')).toHaveLength(3) // Alle + 2

        await wrapper.get('[data-filter][data-filter-id="2"]').trigger('click')
        const visible = wrapper.findAll('[data-slot]')
        expect(visible).toHaveLength(1)
        expect(visible[0].text()).toContain('Tom')
    })

    it('hides the filter row when only one practitioner has slots', () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots: [slots[0]] } })
        expect(wrapper.find('[data-filters]').exists()).toBe(false)
    })

    it('emits select with the chosen slot', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        await wrapper.get('[data-slot]').trigger('click')
        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ practitioner: { id: 1 } })
    })

    it('shows the empty message when no dates are available', () => {
        const wrapper = mount(TerminStep, { props: { availableDates: [], slots: [], loadingSlots: false } })
        expect(wrapper.text()).toContain('Kein freier Termin verfügbar')
    })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:widget -- TerminStep`
Expected: FAIL — le composant n'existe pas.

- [ ] **Step 3: Create the component**

Crée `resources/js/widget/steps/TerminStep.vue` :

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import type { Slot } from '../types'
import BookingCalendar from '../components/BookingCalendar.vue'

const props = defineProps<{
    availableDates: string[]
    slots: Slot[]
    loadingSlots: boolean
    selectedDate?: string
}>()
const emit = defineEmits<{
    'month-change': [{ from: string; to: string }]
    'pick-date': [date: string]
    select: [slot: Slot]
}>()

const filterId = ref<number | null>(null) // null = Alle Behandler

const practitioners = computed(() => {
    const map = new Map<number, Slot['practitioner']>()
    for (const s of props.slots) map.set(s.practitioner.id, s.practitioner)
    return Array.from(map.values())
})

const visibleSlots = computed(() =>
    filterId.value == null ? props.slots : props.slots.filter(s => s.practitioner.id === filterId.value),
)

const time = (iso: string) => iso.slice(11, 16)
const docLabel = (p: Slot['practitioner']) => `${p.title ? p.title + ' ' : ''}${p.first_name} ${p.last_name}`

function onPickDate(date: string) {
    filterId.value = null // reset the doctor filter when the day changes
    emit('pick-date', date)
}
</script>

<template>
    <div>
        <h2 class="text-lg font-bold mb-4">Termin wählen</h2>

        <BookingCalendar
            :available-dates="availableDates"
            :selected-date="selectedDate"
            @month-change="$emit('month-change', $event)"
            @select="onPickDate" />

        <p v-if="availableDates.length === 0" class="text-slate-500 mt-3">Kein freier Termin verfügbar.</p>

        <div v-if="selectedDate" class="mt-4">
            <div v-if="practitioners.length > 1" data-filters class="flex flex-wrap gap-2 mb-3">
                <button type="button" data-filter :data-filter-id="''"
                        @click="filterId = null"
                        :class="['px-3 py-1 rounded-full border text-sm',
                                 filterId === null ? 'bg-slate-800 text-white border-slate-800' : 'bg-white']">
                    Alle Behandler
                </button>
                <button v-for="p in practitioners" :key="p.id" type="button" data-filter :data-filter-id="p.id"
                        @click="filterId = p.id"
                        :style="filterId === p.id ? { backgroundColor: p.color, color: '#1E293B', borderColor: p.color } : {}"
                        class="px-3 py-1 rounded-full border text-sm bg-white">
                    {{ docLabel(p) }}
                </button>
            </div>

            <p v-if="loadingSlots" class="text-slate-500">Lädt …</p>
            <p v-else-if="visibleSlots.length === 0" class="text-slate-500">Keine freien Termine an diesem Tag.</p>
            <div v-else class="flex flex-wrap gap-2">
                <button v-for="s in visibleSlots" :key="s.starts_at + '-' + s.practitioner.id" type="button" data-slot
                        @click="$emit('select', s)"
                        class="px-3 py-2 border rounded hover:bg-blue-50 flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: s.practitioner.color }"></span>
                    {{ time(s.starts_at) }} · {{ docLabel(s.practitioner) }}
                </button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:widget -- TerminStep`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/steps/TerminStep.vue backend/tests/widget/TerminStep.test.ts
git commit -m "feat(widget): Termin step — calendar + doctor filter + per-doctor slots"
```

---

## Task 8: Widget — réorchestrer `App.vue`, supprimer les steps obsolètes

**Files:**
- Modify: `resources/js/widget/App.vue`
- Delete: `resources/js/widget/steps/PractitionerStep.vue`, `resources/js/widget/steps/SlotStep.vue`, `tests/widget/SlotStep.test.ts`
- Modify: `tests/widget/steps.test.ts`
- Test: `tests/widget/app.test.ts` (rewrite)

- [ ] **Step 1: Rewrite `app.test.ts` (failing)**

Remplace tout le contenu de `tests/widget/app.test.ts` par :

```ts
import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '@widget/App.vue'

afterEach(() => { vi.restoreAllMocks() })

const today = (() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})()

const fakeApi = {
    services: vi.fn().mockResolvedValue([{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]),
    availabilityDays: vi.fn().mockResolvedValue([today]),
    slots: vi.fn().mockResolvedValue([
        { starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00`, practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
    ]),
    book: vi.fn().mockResolvedValue({ cancellation_token: 'tok-123', starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00` }),
    cancel: vi.fn().mockResolvedValue({ status: 'cancelled' }),
}

async function fillAndSubmit(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('[name="patient_first_name"]').setValue('Lina')
    await wrapper.get('[name="patient_last_name"]').setValue('Müller')
    await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
    await wrapper.get('[name="parent_first_name"]').setValue('Anna')
    await wrapper.get('[name="parent_last_name"]').setValue('Müller')
    await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
    await wrapper.get('[name="consent"]').setValue(true)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()
}

describe('App', () => {
    it('walks the full date-first flow to success', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises() // services loaded

        await wrapper.get('button').trigger('click') // choose the only service
        await flushPromises() // calendar mounts → availabilityDays loaded
        expect(fakeApi.availabilityDays).toHaveBeenCalled()

        await wrapper.get(`[data-day="${today}"]`).trigger('click') // pick today
        await flushPromises() // slots loaded
        expect(fakeApi.slots).toHaveBeenCalled()

        await wrapper.get('[data-slot]').trigger('click') // choose the slot
        await fillAndSubmit(wrapper)

        expect(fakeApi.book).toHaveBeenCalled()
        expect(fakeApi.book.mock.calls[0][0].practitioner_id).toBe(2)
        expect(wrapper.text()).toContain('tok-123')
    })

    it('cancels the appointment from the success screen', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true)
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('button').trigger('click')
        await flushPromises()
        await wrapper.get(`[data-day="${today}"]`).trigger('click')
        await flushPromises()
        await wrapper.get('[data-slot]').trigger('click')
        await fillAndSubmit(wrapper)

        await wrapper.get('button').trigger('click') // success screen → "Termin stornieren"
        await flushPromises()
        expect(fakeApi.cancel).toHaveBeenCalledWith('tok-123')
        expect(wrapper.text()).toContain('Termin storniert')
    })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:widget -- app`
Expected: FAIL — `App.vue` rend encore l'étape praticien et appelle l'ancienne API.

- [ ] **Step 3: Rewrite `App.vue`**

Remplace tout le contenu de `resources/js/widget/App.vue` par :

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { Api } from './api'
import type { Service, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import ServiceStep from './steps/ServiceStep.vue'
import TerminStep from './steps/TerminStep.vue'
import FormStep from './steps/FormStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string }>()
const w = useWizard()

const services = ref<Service[]>([])
const availableDates = ref<string[]>([])
const slots = ref<Slot[]>([])
const selectedDate = ref<string | undefined>(undefined)
const loadingSlots = ref(false)
const result = ref<BookingResult | null>(null)
const cancelled = ref(false)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)

onMounted(async () => {
    try { services.value = await props.api.services() }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
})

function onService(s: Service) {
    w.chooseService(s)
    selectedDate.value = undefined
    slots.value = []
    availableDates.value = []
    // Availability for the visible month is loaded via the calendar's
    // month-change event, emitted on mount of TerminStep.
}

async function onMonthChange(win: { from: string; to: string }) {
    if (!w.selection.service) return
    try { availableDates.value = await props.api.availabilityDays(w.selection.service.id, win.from, win.to) }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
}

async function onPickDate(date: string) {
    if (!w.selection.service) return
    selectedDate.value = date
    loadingSlots.value = true
    slots.value = []
    try { slots.value = await props.api.slots(w.selection.service.id, date, date) }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
    finally { loadingSlots.value = false }
}

async function onSubmit(formData: Record<string, unknown>) {
    if (loading.value) return
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...(formData as any),
            practitioner_id: w.selection.slot!.practitioner.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        if (result.value?.cancellation_token) w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') serverErrors.value = e.errors
        else if (e.kind === 'slot_taken') { banner.value = 'Termin nicht mehr verfügbar.'; w.back() }
        else if (e.kind === 'rate_limited') banner.value = 'Zu viele Versuche, bitte später erneut.'
        else banner.value = 'Verbindungsfehler. Bitte erneut versuchen.'
    } finally {
        loading.value = false
    }
}

async function onCancel() {
    if (!result.value) return
    if (typeof window !== 'undefined' && !window.confirm('Termin wirklich stornieren?')) return
    try {
        await props.api.cancel(result.value.cancellation_token)
        cancelled.value = true
    } catch {
        banner.value = 'Stornierung fehlgeschlagen.'
    }
}
</script>

<template>
    <div class="font-sans text-slate-800 max-w-md mx-auto p-4">
        <div v-if="banner" class="bg-amber-100 text-amber-800 p-2 rounded mb-3 text-sm">{{ banner }}</div>

        <ServiceStep v-if="w.step.value === 'service'" :services="services" @select="onService" />
        <TerminStep v-else-if="w.step.value === 'termin'"
                    :available-dates="availableDates" :slots="slots"
                    :loading-slots="loadingSlots" :selected-date="selectedDate"
                    @month-change="onMonthChange" @pick-date="onPickDate" @select="w.chooseSlot" />
        <FormStep v-else-if="w.step.value === 'form'" :server-errors="serverErrors" @submit="onSubmit" />
        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :cancelled="cancelled" @cancel="onCancel" />

        <button v-if="w.step.value !== 'service' && w.step.value !== 'success'" @click="w.back()"
                class="text-sm text-blue-600 mt-3">← Zurück</button>
    </div>
</template>
```

- [ ] **Step 4: Delete obsolete step components and their test**

```bash
git rm backend/resources/js/widget/steps/PractitionerStep.vue \
       backend/resources/js/widget/steps/SlotStep.vue \
       backend/tests/widget/SlotStep.test.ts
```

- [ ] **Step 5: Update `steps.test.ts` (drop PractitionerStep)**

Remplace tout le contenu de `tests/widget/steps.test.ts` par :

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceStep from '@widget/steps/ServiceStep.vue'

describe('ServiceStep', () => {
    it('renders services and emits select on click', async () => {
        const wrapper = mount(ServiceStep, {
            props: { services: [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }] },
        })
        expect(wrapper.text()).toContain('Prophylaxe')
        await wrapper.get('button').trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ id: 1 })
    })
})
```

- [ ] **Step 6: Run the full widget suite**

Run: `npm run test:widget`
Expected: PASS — tous les fichiers (`app`, `wizard`, `steps`, `api`, `BookingCalendar`, `TerminStep`, `FormStep`, `RoomPicker`, `RoomLegend`, `calendar`, `smoke`).

- [ ] **Step 7: Commit**

```bash
git add backend/resources/js/widget/App.vue backend/tests/widget/app.test.ts backend/tests/widget/steps.test.ts
git commit -m "feat(widget): date-first orchestration in App, remove practitioner & slot steps"
```

---

## Task 9: Build du widget + suite complète + style

**Files:**
- (build artifact) `public/widget/masinga-widget.js`

- [ ] **Step 1: Build the widget bundle (catches TS/compile errors the unit tests miss)**

Run: `npm run build:widget`
Expected: build OK, `public/widget/masinga-widget.js` régénéré sans erreur.

- [ ] **Step 2: Run the full widget unit suite**

Run: `npm run test:widget`
Expected: PASS.

- [ ] **Step 3: Run the full backend suite**

Run: `composer test`
Expected: PASS (aucune régression sur les ~30 fichiers de tests Feature).

- [ ] **Step 4: PHP code style**

Run: `vendor/bin/pint`
Expected: aucun fichier à corriger (ou corrige et re-commit).

- [ ] **Step 5: Commit (si pint a modifié quelque chose)**

```bash
git add -A
git commit -m "style: pint formatting for booking flow changes"
```

> Note : `public/widget/*` est gitignored — l'artefact n'est pas commité ici. Le déploiement du widget reste manuel (`build:widget` + rsync) ou via la PR #19. À mentionner dans la PR.

---

## Self-Review

**1. Spec coverage**

| Exigence spec | Tâche |
|---|---|
| 4 étapes, suppression étape praticien | Task 4 (wizard), Task 8 (App) |
| Étape Termin = calendrier + clic jour → créneaux | Task 6 (calendar), Task 7 (TerminStep), Task 8 (App) |
| Filtre médecin client-side, masqué si 1 médecin | Task 7 |
| Créneau porte (heure + médecin) | Task 3 (backend), Task 4 (types), Task 7 (UI) |
| Endpoint `availability/days` | Task 2 |
| `/slots` multi-médecins + enrichi praticien + `practitioner_id` optionnel | Task 3 |
| Lead/horizon via `Setting` (défaut = constantes) | Task 1 |
| Contrat de réservation inchangé (`practitioner_id` au POST) | Task 8 (`slot.practitioner.id`) |
| Edge: aucune dispo → message ; mono-médecin → pas de chips | Task 7 |
| Throttle `widget-read` sur les 2 lectures | Task 2 (route dans le groupe existant) |
| Tests Pest (availableDates, slots merge, settings) | Tasks 1–3 |
| Tests Vitest (calendrier, TerminStep, wizard) | Tasks 4, 6, 7, 8 |

Aucune lacune. (Le micro-court-circuit perf de `availableDates` évoqué dans la spec est volontairement simplifié — `availableDates` réutilise `forPractitionerService` ; résultat identique, optimisation = follow-up si besoin réel.)

**2. Placeholder scan** — aucun « TBD/TODO », tout le code est complet et exécutable.

**3. Type consistency** — `Slot.practitioner` ({id, first_name, last_name, title?, color?}) cohérent entre backend (Task 3), `types.ts` (Task 4), `TerminStep` (Task 7), `app.test` (Task 8) ; `api.slots(serviceId, from, to, practitionerId?)` cohérent entre `api.ts` (Task 5) et `App.vue` (Task 8) ; `availabilityDays(serviceId, from, to)` idem ; events `month-change`/`pick-date`/`select` cohérents Calendar↔TerminStep↔App.
