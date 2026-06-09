# Bulk Availability Creation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow staff to create multiple recurring availability slots in one form submission, with period bounds, optional slot interval override, cabinet-wide closure shortcut, and automatic German public holiday exclusion.

**Architecture:** Extend existing `Sprechzeiten` and `Abwesenheiten` forms — no new pages. Backend: single migration, enriched form requests, bulk-create loops in controllers. Holiday exclusion via `yasumi/yasumi` with per-year cache in `AvailabilityCalculator`.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 `<script setup lang="ts">`, Pest 4, PostgreSQL, `yasumi/yasumi`

---

## File Map

| File | Change |
|---|---|
| `database/migrations/…_add_slot_interval_to_availabilities.php` | Create |
| `app/Models/Tenant/Availability.php` | Add `slot_interval_minutes` to `$fillable` + cast |
| `config/booking.php` | Create |
| `.env.example` | Add `APP_COUNTRY`, `APP_BUNDESLAND` |
| `app/Services/Tenant/AvailabilityCalculator.php` | Holiday check + slot_interval support |
| `app/Http/Requests/Tenant/StoreAvailabilityRequest.php` | Rewrite for multi-day + period + interval |
| `app/Http/Controllers/Tenant/AvailabilityController.php` | `store()` bulk loop |
| `resources/js/Pages/Tenant/Availabilities/Form.vue` | Full rewrite |
| `app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php` | Add `cabinet_closure` type |
| `app/Http/Controllers/Tenant/AvailabilityExceptionController.php` | `store()` bulk for cabinet |
| `resources/js/Pages/Tenant/Exceptions/Form.vue` | Add cabinet closure toggle |
| `tests/Feature/TenantSchema/AvailabilityCalculatorTest.php` | New tests: holidays + interval |
| `tests/Feature/TenantSchema/AvailabilityTest.php` | New tests: bulk store |
| `tests/Feature/TenantSchema/ExceptionTest.php` | New tests: cabinet_closure |

---

### Task 1 — Migration + Model

**Files:**
- Create: `database/migrations/2026_06_09_000001_add_slot_interval_to_availabilities.php`
- Modify: `app/Models/Tenant/Availability.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_slot_interval_to_availabilities --table=availabilities
```

Replace generated body:

```php
public function up(): void
{
    Schema::table('availabilities', function (Blueprint $table) {
        $table->unsignedSmallInteger('slot_interval_minutes')->nullable()->after('end_time');
    });
}

public function down(): void
{
    Schema::table('availabilities', function (Blueprint $table) {
        $table->dropColumn('slot_interval_minutes');
    });
}
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_09_000001_add_slot_interval_to_availabilities` then `Migrated`.

- [ ] **Step 3: Update Availability model**

In `app/Models/Tenant/Availability.php`, update `$fillable` and `$casts`:

```php
protected $fillable = [
    'practitioner_id', 'day_of_week', 'start_time', 'end_time',
    'valid_from', 'valid_to', 'slot_interval_minutes',
];

protected $casts = [
    'start_time' => 'datetime:H:i',
    'end_time' => 'datetime:H:i',
    'valid_from' => 'date',
    'valid_to' => 'date',
    'slot_interval_minutes' => 'integer',
];
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_09_000001_add_slot_interval_to_availabilities.php app/Models/Tenant/Availability.php
git commit -m "feat(availability): add slot_interval_minutes column"
```

---

### Task 2 — Config booking.php + env

**Files:**
- Create: `config/booking.php`
- Modify: `.env.example`

- [ ] **Step 1: Create config/booking.php**

```php
<?php

return [
    'country'    => env('APP_COUNTRY', 'Germany'),
    'bundesland' => env('APP_BUNDESLAND', ''),
];
```

- [ ] **Step 2: Add to .env.example**

Append after existing env vars:

```dotenv
APP_COUNTRY=Germany
APP_BUNDESLAND=NorthRhineWestphalia
```

Valid Yasumi Bundesland values: `Bavaria`, `Berlin`, `Brandenburg`, `Bremen`, `Hamburg`, `Hesse`, `MecklenburgWesternPomerania`, `LowerSaxony`, `NorthRhineWestphalia`, `RhinelandPalatinate`, `Saarland`, `Saxony`, `SaxonyAnhalt`, `SchleswigHolstein`, `Thuringia`.

- [ ] **Step 3: Commit**

```bash
git add config/booking.php .env.example
git commit -m "feat(config): add booking country/bundesland config for Yasumi"
```

---

### Task 3 — Install Yasumi + holiday check in AvailabilityCalculator

**Files:**
- Modify: `app/Services/Tenant/AvailabilityCalculator.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/TenantSchema/AvailabilityCalculatorTest.php`, add after existing tests:

```php
it('returns no slots on a german public holiday', function () {
    // Christmas Day 2026 = Friday = ISO day 5
    $christmas = CarbonImmutable::parse('2026-12-25', 'Europe/Berlin');

    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => 5, // Friday
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    // Override horizon to reach Dec 25
    \App\Models\Setting::set('booking.horizon_days', '200');

    $slots = makeCalc()->forPractitionerService(
        $p, $s,
        $christmas->startOfDay(),
        $christmas->endOfDay(),
    );

    expect($slots)->toBeEmpty();
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test --filter="returns no slots on a german public holiday"
```

Expected: FAIL (slots are still returned — Yasumi not installed yet).

- [ ] **Step 3: Install Yasumi**

```bash
composer require yasumi/yasumi
```

- [ ] **Step 4: Add holiday cache property and isPublicHoliday() to AvailabilityCalculator**

Add to the class (after existing properties):

```php
use Yasumi\Yasumi;

// In class body:
private array $holidayCache = [];

private function isPublicHoliday(CarbonImmutable $day): bool
{
    $year = $day->year;
    if (! isset($this->holidayCache[$year])) {
        $country    = config('booking.country', 'Germany');
        $bundesland = config('booking.bundesland', '');
        $provider   = $bundesland ? "{$country}\\{$bundesland}" : $country;
        $this->holidayCache[$year] = Yasumi::create($provider, $year, 'de_DE');
    }

    return $this->holidayCache[$year]->isHoliday($day->toDateTimeImmutable());
}
```

- [ ] **Step 5: Add holiday check inside the day loop in forPractitionerService()**

Locate the `for` loop starting at line ~47. Add `isPublicHoliday` check as first thing inside the loop:

```php
for ($day = $from->setTimezone(self::CLINIC_TIMEZONE)->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
    if ($this->isPublicHoliday($day)) {
        continue;
    }

    $availabilities = $practitioner->availabilities()
    // ... rest unchanged
```

- [ ] **Step 6: Run test to confirm it passes**

```bash
php artisan test --filter="returns no slots on a german public holiday"
```

Expected: PASS.

- [ ] **Step 7: Run full suite to check no regression**

```bash
composer test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Tenant/AvailabilityCalculator.php composer.json composer.lock tests/Feature/TenantSchema/AvailabilityCalculatorTest.php
git commit -m "feat(calculator): skip public holidays via Yasumi (Germany)"
```

---

### Task 4 — slot_interval_minutes in slotsForDay + isBookable

**Files:**
- Modify: `app/Services/Tenant/AvailabilityCalculator.php`

- [ ] **Step 1: Write failing tests**

Add to `AvailabilityCalculatorTest.php`:

```php
it('uses slot_interval_minutes as step between slots', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 45]);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '12:00',
        'slot_interval_minutes' => 30,
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s, $monday->startOfDay(), $monday->endOfDay());

    // 30-min step: 09:00, 09:30, 10:00, 10:30, 11:00, 11:15 (last that fits 45min before 12:00)
    expect($slots->pluck('starts_at')->map->format('H:i')->all())
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:15']);
});

it('isBookable uses slot_interval as alignment step', function () {
    $monday = bookableMonday();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 45]);
    $svc = \App\Models\Tenant\PractitionerService::create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
    ]) || $p->services()->attach($s->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '12:00',
        'slot_interval_minutes' => 30,
    ]);

    $startsAt = CarbonImmutable::parse($monday->toDateString().' 09:30', 'Europe/Berlin');

    expect(makeCalc()->isBookable($p, $s, $startsAt))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --filter="uses slot_interval_minutes"
php artisan test --filter="isBookable uses slot_interval"
```

Expected: FAIL.

- [ ] **Step 3: Update slotsForDay signature to accept $step**

```php
private function slotsForDay(
    CarbonImmutable $day,
    CarbonInterface $start,
    CarbonInterface $end,
    int $duration,
    ?int $step = null,
): Collection {
    $step ??= $duration;
    $tz = self::CLINIC_TIMEZONE;
    $date = $day->setTimezone($tz)->toDateString();
    $cursor = CarbonImmutable::parse("{$date} {$start->format('H:i')}", $tz);
    $dayEnd = CarbonImmutable::parse("{$date} {$end->format('H:i')}", $tz);

    $slots = collect();
    while ($cursor->addMinutes($step)->lessThanOrEqualTo($dayEnd)) {
        $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
        $cursor = $cursor->addMinutes($step);
    }

    return $slots;
}
```

- [ ] **Step 4: Pass slot_interval_minutes from availability to slotsForDay**

In `forPractitionerService()`, update the call:

```php
foreach ($this->slotsForDay(
    $day,
    $availability->start_time,
    $availability->end_time,
    $duration,
    $availability->slot_interval_minutes,
) as $slot) {
```

- [ ] **Step 5: Update isBookable alignment check**

Replace the `% $service->duration_minutes` check:

```php
$step = $a->slot_interval_minutes ?? $service->duration_minutes;
if (((int) $winStart->diffInMinutes($startsAt)) % $step !== 0) {
    continue; // not aligned to the slot grid
}
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter="slot_interval"
composer test
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Tenant/AvailabilityCalculator.php tests/Feature/TenantSchema/AvailabilityCalculatorTest.php
git commit -m "feat(calculator): support slot_interval_minutes override per availability"
```

---

### Task 5 — StoreAvailabilityRequest rewrite

**Files:**
- Modify: `app/Http/Requests/Tenant/StoreAvailabilityRequest.php`

- [ ] **Step 1: Rewrite the request**

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $modeB = $this->has('days_hours');

        return [
            'practitioner_id'       => ['required', 'exists:practitioners,id'],
            'days'                  => $modeB ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'days.*'                => ['integer', 'between:1,7'],
            'start_time'            => $modeB ? ['nullable'] : ['required', 'date_format:H:i'],
            'end_time'              => $modeB ? ['nullable'] : ['required', 'date_format:H:i', 'after:start_time'],
            'days_hours'            => $modeB ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'days_hours.*.start'    => ['required_with:days_hours', 'date_format:H:i'],
            'days_hours.*.end'      => ['required_with:days_hours', 'date_format:H:i'],
            'valid_from'            => ['required', 'date', 'after_or_equal:today'],
            'valid_to'              => ['nullable', 'date', 'after:valid_from'],
            'slot_interval_minutes' => ['nullable', 'integer', 'in:20,30'],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/Tenant/StoreAvailabilityRequest.php
git commit -m "feat(availability): rewrite StoreAvailabilityRequest for bulk + period + interval"
```

---

### Task 6 — AvailabilityController::store() bulk loop

**Files:**
- Modify: `app/Http/Controllers/Tenant/AvailabilityController.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/TenantSchema/AvailabilityTest.php`, add:

```php
it('creates one availability per selected day (mode A)', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(\App\Models\User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days' => [1, 3, 5],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addMonths(3)->toDateString(),
            'slot_interval_minutes' => null,
        ])
        ->assertRedirect('/sprechzeiten');

    expect(Availability::where('practitioner_id', $p->id)->count())->toBe(3);
    expect(Availability::where('practitioner_id', $p->id)->pluck('day_of_week')->sort()->values()->all())
        ->toBe([1, 3, 5]);
});

it('creates one availability per day with per-day hours (mode B)', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(\App\Models\User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days_hours' => [
                1 => ['start' => '08:00', 'end' => '12:00'],
                2 => ['start' => '13:00', 'end' => '17:00'],
            ],
            'valid_from' => now()->toDateString(),
            'valid_to' => null,
        ])
        ->assertRedirect('/sprechzeiten');

    expect(Availability::where('practitioner_id', $p->id)->count())->toBe(2);
    expect(Availability::where('day_of_week', 1)->first()->start_time->format('H:i'))->toBe('08:00');
    expect(Availability::where('day_of_week', 2)->first()->start_time->format('H:i'))->toBe('13:00');
});

it('rejects submission with no days selected', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs(\App\Models\User::factory()->create())
        ->post('/sprechzeiten', [
            'practitioner_id' => $p->id,
            'days' => [],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('days');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --filter="creates one availability per selected day"
php artisan test --filter="creates one availability per day with per-day"
php artisan test --filter="rejects submission with no days"
```

Expected: FAIL.

- [ ] **Step 3: Rewrite store() in AvailabilityController**

Add `use Illuminate\Support\Facades\DB;` at top of file.

```php
public function store(StoreAvailabilityRequest $request): RedirectResponse
{
    $data = $request->validated();

    DB::transaction(function () use ($data) {
        $base = [
            'practitioner_id'       => $data['practitioner_id'],
            'valid_from'            => $data['valid_from'],
            'valid_to'              => $data['valid_to'] ?? null,
            'slot_interval_minutes' => $data['slot_interval_minutes'] ?? null,
        ];

        if (isset($data['days_hours'])) {
            foreach ($data['days_hours'] as $dayOfWeek => $hours) {
                Availability::create(array_merge($base, [
                    'day_of_week' => (int) $dayOfWeek,
                    'start_time'  => $hours['start'],
                    'end_time'    => $hours['end'],
                ]));
            }
        } else {
            foreach ($data['days'] as $dayOfWeek) {
                Availability::create(array_merge($base, [
                    'day_of_week' => (int) $dayOfWeek,
                    'start_time'  => $data['start_time'],
                    'end_time'    => $data['end_time'],
                ]));
            }
        }
    });

    return redirect()->route('tenant.availabilities.index')
        ->with('success', 'Sprechzeiten angelegt.');
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter="availability"
composer test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Tenant/AvailabilityController.php tests/Feature/TenantSchema/AvailabilityTest.php
git commit -m "feat(availability): bulk create N availabilities per submission"
```

---

### Task 7 — Form.vue rewrite

**Files:**
- Modify: `resources/js/Pages/Tenant/Availabilities/Form.vue`

- [ ] **Step 1: Replace the entire Form.vue**

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>
}>()

type DayKey = 1 | 2 | 3 | 4 | 5 | 6 | 7
const ALL_DAYS: DayKey[] = [1, 2, 3, 4, 5, 6, 7]
const DAY_LABELS: Record<DayKey, string> = { 1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So' }
const DAY_FULL: Record<DayKey, string> = { 1: 'Montag', 2: 'Dienstag', 3: 'Mittwoch', 4: 'Donnerstag', 5: 'Freitag', 6: 'Samstag', 7: 'Sonntag' }

const practitionerId = ref(props.practitioners[0]?.id ?? null)
const selectedDays = ref<DayKey[]>([1, 2, 3, 4, 5])
const sameHours = ref(true)
const globalStart = ref('09:00')
const globalEnd = ref('17:00')
const dayHours = ref<Record<DayKey, { start: string; end: string }>>({
  1: { start: '09:00', end: '17:00' }, 2: { start: '09:00', end: '17:00' },
  3: { start: '09:00', end: '17:00' }, 4: { start: '09:00', end: '17:00' },
  5: { start: '09:00', end: '17:00' }, 6: { start: '09:00', end: '17:00' },
  7: { start: '09:00', end: '17:00' },
})

const validFrom = ref(new Date().toISOString().slice(0, 10))
const durationMonths = ref<number | null>(3) // null = custom, 0 = unlimited
const customValidTo = ref('')
const slotInterval = ref<number | null>(null)

const DURATIONS = [
  { label: '1 Monat', months: 1 }, { label: '2 Monate', months: 2 },
  { label: '3 Monate', months: 3 }, { label: '4 Monate', months: 4 },
  { label: '6 Monate', months: 6 }, { label: '1 Jahr', months: 12 },
  { label: 'Benutzerdefiniert…', months: null }, { label: 'Unbegrenzt', months: 0 },
]

const computedValidTo = computed<string | null>(() => {
  if (durationMonths.value === 0) return null
  if (durationMonths.value === null) return customValidTo.value || null
  if (!validFrom.value) return null
  const d = new Date(validFrom.value)
  d.setMonth(d.getMonth() + durationMonths.value)
  d.setDate(d.getDate() - 1)
  return d.toISOString().slice(0, 10)
})

const validToLabel = computed(() => {
  if (!computedValidTo.value) return null
  return new Date(computedValidTo.value).toLocaleDateString('de-DE', {
    day: '2-digit', month: '2-digit', year: 'numeric',
  })
})

const buttonLabel = computed(() => {
  const n = selectedDays.value.length
  if (n === 0) return 'Bitte Tage auswählen'
  const labels = selectedDays.value.map(d => DAY_LABELS[d]).join(', ')
  return `${n} Sprechzeit${n !== 1 ? 'en' : ''} anlegen (${labels})`
})

const errors = ref<Record<string, string>>({})

function toggleDay(day: DayKey) {
  const i = selectedDays.value.indexOf(day)
  if (i >= 0) selectedDays.value.splice(i, 1)
  else selectedDays.value.push(day)
}

function submit() {
  errors.value = {}
  if (selectedDays.value.length === 0) {
    errors.value.days = 'Bitte mindestens einen Tag auswählen.'
    return
  }

  const base = {
    practitioner_id: practitionerId.value,
    valid_from: validFrom.value,
    valid_to: computedValidTo.value,
    slot_interval_minutes: slotInterval.value,
  }

  if (sameHours.value) {
    router.post('/sprechzeiten', {
      ...base, days: selectedDays.value,
      start_time: globalStart.value, end_time: globalEnd.value,
    })
  } else {
    const days_hours: Record<number, { start: string; end: string }> = {}
    selectedDays.value.forEach(d => { days_hours[d] = dayHours.value[d] })
    router.post('/sprechzeiten', { ...base, days_hours })
  }
}
</script>

<template>
  <Head title="Sprechzeiten anlegen" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">Sprechzeiten anlegen</h1>
    <Card as="div">
      <div class="space-y-6">

        <!-- Practitioner -->
        <FormField label="Behandler" required>
          <select v-model.number="practitionerId" class="w-full p-2 border rounded">
            <option v-for="p in practitioners" :key="p.id" :value="p.id">
              {{ p.title }} {{ p.first_name }} {{ p.last_name }}
            </option>
          </select>
        </FormField>

        <!-- Day pills -->
        <FormField label="Wochentage" required :error="errors.days">
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="d in ALL_DAYS" :key="d" type="button"
              @click="toggleDay(d)"
              :class="['w-10 h-10 rounded-lg font-bold text-sm transition',
                selectedDays.includes(d)
                  ? 'bg-slate-800 text-white'
                  : 'bg-slate-100 text-slate-400 hover:bg-slate-200']">
              {{ DAY_LABELS[d] }}
            </button>
          </div>
        </FormField>

        <!-- Hours mode toggle + fields -->
        <div class="rounded-xl border bg-slate-50 p-4 space-y-3">
          <div class="flex justify-between items-center">
            <span class="text-sm font-semibold">Öffnungszeiten</span>
            <button type="button" @click="sameHours = !sameHours"
              class="text-xs text-blue-600 underline">
              {{ sameHours ? 'Unterschiedliche Zeiten pro Tag' : 'Gleiche Zeiten für alle' }}
            </button>
          </div>

          <!-- Mode A: same hours -->
          <div v-if="sameHours" class="grid grid-cols-2 gap-3">
            <FormField label="Von">
              <input v-model="globalStart" type="time" class="w-full p-2 border rounded bg-white" />
            </FormField>
            <FormField label="Bis">
              <input v-model="globalEnd" type="time" class="w-full p-2 border rounded bg-white" />
            </FormField>
          </div>

          <!-- Mode B: per-day hours -->
          <div v-else class="space-y-2">
            <div v-for="d in ALL_DAYS" :key="d"
              :class="['grid grid-cols-[28px_60px_1fr_1fr] gap-2 items-center',
                !selectedDays.includes(d) && 'opacity-40']">
              <input type="checkbox"
                :checked="selectedDays.includes(d)"
                @change="toggleDay(d)"
                class="w-4 h-4" />
              <span class="text-sm font-semibold">{{ DAY_LABELS[d] }}</span>
              <input v-model="dayHours[d].start" type="time"
                :disabled="!selectedDays.includes(d)"
                class="w-full p-1.5 border rounded text-sm bg-white" />
              <input v-model="dayHours[d].end" type="time"
                :disabled="!selectedDays.includes(d)"
                class="w-full p-1.5 border rounded text-sm bg-white" />
            </div>
          </div>
        </div>

        <!-- Period -->
        <div class="grid grid-cols-2 gap-3">
          <FormField label="Gültig ab" required>
            <input v-model="validFrom" type="date" class="w-full p-2 border rounded" />
          </FormField>
          <FormField label="Dauer">
            <select v-model="durationMonths" class="w-full p-2 border rounded">
              <option v-for="opt in DURATIONS" :key="String(opt.months)" :value="opt.months">
                {{ opt.label }}
              </option>
            </select>
          </FormField>
        </div>

        <!-- Custom valid_to -->
        <FormField v-if="durationMonths === null" label="Enddatum">
          <input v-model="customValidTo" type="date" class="w-full p-2 border rounded" />
        </FormField>

        <!-- Computed end date hint -->
        <p v-if="validToLabel" class="text-sm text-green-700">
          → Gültig bis {{ validToLabel }}
        </p>

        <!-- Slot interval -->
        <FormField label="Slot-Intervall">
          <div class="flex gap-3 flex-wrap">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="slotInterval" type="radio" :value="null" /> Standard (Leistungsdauer)
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="slotInterval" type="radio" :value="20" /> 20 Min.
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="slotInterval" type="radio" :value="30" /> 30 Min.
            </label>
          </div>
          <p class="text-xs text-slate-400 mt-1">Abstand zwischen Terminen, unabhängig von der Leistungsdauer.</p>
        </FormField>

        <PrimaryButton type="button" @click="submit" :disabled="selectedDays.length === 0">
          {{ buttonLabel }}
        </PrimaryButton>

      </div>
    </Card>
  </div>
</template>
```

- [ ] **Step 2: Test visually**

```bash
composer dev
```

Navigate to `/sprechzeiten/create`. Verify:
- Day pills toggle correctly
- Mode toggle switches between same/per-day hours
- Duration select shows "Gültig bis DD.MM.YYYY"
- "Benutzerdefiniert…" shows date input
- Button label updates with selected days
- Submit creates N records and redirects

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Tenant/Availabilities/Form.vue
git commit -m "feat(availability): rewrite Form.vue — multi-day pills, period, interval"
```

---

### Task 8 — Exception request + controller for cabinet closure

**Files:**
- Modify: `app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php`
- Modify: `app/Http/Controllers/Tenant/AvailabilityExceptionController.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/TenantSchema/ExceptionTest.php`, add:

```php
it('creates one exception per active practitioner on cabinet closure', function () {
    $p1 = Practitioner::factory()->create(['is_active' => true]);
    $p2 = Practitioner::factory()->create(['is_active' => true]);
    Practitioner::factory()->create(['is_active' => false]); // should be excluded

    $this->actingAs(\App\Models\User::factory()->create())
        ->post('/abwesenheiten', [
            'is_cabinet_closure' => true,
            'starts_at' => '2026-12-24 00:00:00',
            'ends_at' => '2026-12-26 23:59:59',
            'reason' => 'Weihnachten',
        ])
        ->assertRedirect();

    expect(AvailabilityException::count())->toBe(2);
    expect(AvailabilityException::pluck('type')->unique()->first())->toBe('cabinet_closure');
    expect(AvailabilityException::pluck('practitioner_id')->sort()->values()->all())
        ->toBe([$p1->id, $p2->id]);
});
```

- [ ] **Step 2: Run test to confirm failure**

```bash
php artisan test --filter="creates one exception per active practitioner"
```

Expected: FAIL.

- [ ] **Step 3: Update StoreAvailabilityExceptionRequest**

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isCabinet = $this->boolean('is_cabinet_closure');

        return [
            'is_cabinet_closure' => ['boolean'],
            'practitioner_id'    => $isCabinet
                ? ['nullable']
                : ['required', 'exists:practitioners,id'],
            'starts_at'          => ['required', 'date'],
            'ends_at'            => ['required', 'date', 'after:starts_at'],
            'type'               => $isCabinet
                ? ['nullable']
                : ['required', 'in:vacation,sick,block,cabinet_closure'],
            'reason'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Update AvailabilityExceptionController::store()**

Add `use App\Models\Tenant\Practitioner; use Illuminate\Support\Facades\DB;` at top.

```php
public function store(StoreAvailabilityExceptionRequest $request): RedirectResponse
{
    $data = $request->validated();

    if ($request->boolean('is_cabinet_closure')) {
        DB::transaction(function () use ($data) {
            foreach (Practitioner::active()->get() as $practitioner) {
                AvailabilityException::create([
                    'practitioner_id' => $practitioner->id,
                    'starts_at'       => $data['starts_at'],
                    'ends_at'         => $data['ends_at'],
                    'type'            => 'cabinet_closure',
                    'reason'          => $data['reason'] ?? null,
                ]);
            }
        });
    } else {
        AvailabilityException::create($data);
    }

    return redirect()->route('tenant.exceptions.index')
        ->with('success', 'Abwesenheit angelegt.');
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter="exception"
composer test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php app/Http/Controllers/Tenant/AvailabilityExceptionController.php tests/Feature/TenantSchema/ExceptionTest.php
git commit -m "feat(exception): cabinet_closure toggle creates exception for all active practitioners"
```

---

### Task 9 — Exceptions/Form.vue — cabinet closure toggle

**Files:**
- Modify: `resources/js/Pages/Tenant/Exceptions/Form.vue`

- [ ] **Step 1: Add cabinet closure toggle to Form.vue**

Replace the entire `<script setup>` and `<template>`:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  exception: null | {
    id: number; practitioner_id: number; starts_at: string;
    ends_at: string; type: string; reason: string | null;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const isCabinetClosure = ref(false)

const types = [
  { value: 'vacation', label: 'Urlaub' },
  { value: 'sick', label: 'Krankheit' },
  { value: 'block', label: 'Blockierung' },
  { value: 'cabinet_closure', label: 'Betriebsschließung' },
]

const form = useForm({
  practitioner_id: props.exception?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  starts_at: props.exception?.starts_at ?? '',
  ends_at: props.exception?.ends_at ?? '',
  type: props.exception?.type ?? 'vacation',
  reason: props.exception?.reason ?? '',
})

const submit = () => {
  if (props.exception) {
    form.put(`/abwesenheiten/${props.exception.id}`)
  } else if (isCabinetClosure.value) {
    router.post('/abwesenheiten', {
      is_cabinet_closure: true,
      starts_at: form.starts_at,
      ends_at: form.ends_at,
      reason: form.reason,
    })
  } else {
    form.post('/abwesenheiten')
  }
}
</script>

<template>
  <Head :title="exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit' }}
    </h1>
    <Card as="form" @submit.prevent="submit">

      <!-- Cabinet closure toggle (only on create) -->
      <div v-if="!exception" class="mb-4 flex items-center gap-3 p-3 rounded-xl bg-amber-50 border border-amber-200">
        <button type="button"
          @click="isCabinetClosure = !isCabinetClosure"
          :class="['relative w-10 h-6 rounded-full transition', isCabinetClosure ? 'bg-slate-800' : 'bg-slate-300']">
          <span :class="['absolute top-0.5 w-5 h-5 bg-white rounded-full transition-transform',
            isCabinetClosure ? 'translate-x-4' : 'translate-x-0.5']" />
        </button>
        <span class="text-sm font-semibold text-amber-900">
          Betriebsschließung (alle Behandler)
        </span>
        <span v-if="isCabinetClosure" class="text-xs text-amber-700">
          — erstellt eine Ausnahme pro aktivem Behandler
        </span>
      </div>

      <FormField v-if="!isCabinetClosure" label="Behandler" required>
        <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>

      <FormField v-if="!isCabinetClosure" label="Typ" required>
        <select v-model="form.type" class="w-full p-2 border rounded">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </FormField>

      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required>
          <input v-model="form.starts_at" type="datetime-local" required class="w-full p-2 border rounded" />
        </FormField>
        <FormField label="Bis" required :error="form.errors.ends_at">
          <input v-model="form.ends_at" type="datetime-local" required class="w-full p-2 border rounded" />
        </FormField>
      </div>

      <FormField label="Grund">
        <TextInput v-model="form.reason" />
      </FormField>

      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
```

- [ ] **Step 2: Update Index.vue label mapping**

In `resources/js/Pages/Tenant/Exceptions/Index.vue`, add `cabinet_closure` to labels:

```typescript
const labels: Record<string, string> = {
  vacation: 'Urlaub', sick: 'Krankheit', block: 'Blockierung',
  cabinet_closure: 'Betriebsschließung',
}
```

- [ ] **Step 3: Test visually**

```bash
composer dev
```

Navigate to `/abwesenheiten/create`. Toggle the cabinet closure switch. Verify:
- Practitioner selector and type selector disappear
- Submit creates N exceptions (one per active practitioner)
- Index shows "Betriebsschließung" label

- [ ] **Step 4: Run full test suite**

```bash
composer test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Tenant/Exceptions/Form.vue resources/js/Pages/Tenant/Exceptions/Index.vue
git commit -m "feat(exception): add Betriebsschließung toggle for cabinet-wide closures"
```

---

**Plan complet — Bulk Availability.** 9 tâches, toutes avec tests en premier (TDD).
