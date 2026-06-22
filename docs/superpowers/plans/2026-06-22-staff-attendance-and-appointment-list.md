# Lot A — Pointage de présence + Liste des rendez-vous — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre au staff de marquer chaque rendez-vous comme « venu » / « absent » et d'accéder à une liste de RDV paginée avec recherche par nom et filtres.

**Architecture:** Une colonne `attendance` (enum nullable) sur `appointments`, mise à jour via le `PATCH /termine/{id}` existant (jamais mass-assignable, comme `notes_internal`). Une nouvelle action `list` rend une page Inertia paginée avec recherche `ILIKE` paramétrée. Badges sur le calendrier FullCalendar + boutons de pointage dans le modal et la liste.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 (`<script setup lang="ts">`), Tailwind 3, Pest 4, PostgreSQL.

## Global Constraints

- **PostgreSQL** est la cible réelle (les tests forcent `DB_CONNECTION=pgsql`).
- **URLs en allemand, noms de route en anglais** — jamais de chemin hardcodé, toujours `route('name')`.
- **`attendance` JAMAIS dans `$fillable`** — assignation directe dans le contrôleur staff uniquement (protection mass-assignment, comme `notes_internal`).
- **Injection SQL** : la recherche `q` utilise UNIQUEMENT des bindings Eloquent (`->where(col, 'ILIKE', $pattern)`), jamais `whereRaw`/`DB::raw` avec saisie utilisateur. Wildcards LIKE échappés via `addcslashes($q, '%_\\')`.
- **Anti N+1** : eager-load `practitioner` + `service` sur toute liste de RDV.
- **Tests** : `composer test` doit passer (lance `config:clear` puis `artisan test`). Style Pest (`it(...)`).
- **Enum casté** suit le pattern de `App\Support\Room` (string-backed, `label()`, `options()`).
- Valeurs d'attendance : `null` (pas pointé), `'arrived'` (venu), `'no_show'` (absent).
- Libellés DE : `arrived` → « Erschienen », `no_show` → « Nicht erschienen ».

---

### Task 1: Enum Attendance + migration + cast modèle

**Files:**
- Create: `backend/app/Support/Attendance.php`
- Create: `backend/database/migrations/2026_06_22_000001_add_attendance_to_appointments.php`
- Modify: `backend/app/Models/Tenant/Appointment.php` (bloc `$casts`)
- Test: `backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php`

**Interfaces:**
- Produces: `App\Support\Attendance` enum (cases `Arrived='arrived'`, `NoShow='no_show'` ; méthodes `label(): string`, `options(): array<int,array{value:string,label:string}>`). Colonne `appointments.attendance` (nullable string). Cast `'attendance' => Attendance::class` sur le modèle `Appointment`.

- [ ] **Step 1: Write the failing test**

Créer `backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php` :

```php
<?php

use App\Models\Tenant\Appointment;
use App\Support\Attendance;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('casts the attendance column to the Attendance enum', function () {
    $appointment = Appointment::factory()->create(['attendance' => 'no_show']);

    expect($appointment->fresh()->attendance)->toBe(Attendance::NoShow);
});

it('defaults attendance to null for a fresh appointment', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->fresh()->attendance)->toBeNull();
});

it('exposes German labels via the enum', function () {
    expect(Attendance::Arrived->label())->toBe('Erschienen')
        ->and(Attendance::NoShow->label())->toBe('Nicht erschienen');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AppointmentAttendanceTest`
Expected: FAIL (classe `App\Support\Attendance` introuvable + colonne `attendance` inexistante).

- [ ] **Step 3: Create the enum**

`backend/app/Support/Attendance.php` :

```php
<?php

namespace App\Support;

/**
 * Whether a patient actually showed up for their appointment. Stored on
 * `appointments.attendance` (nullable: null = not yet recorded). Staff-only —
 * NEVER mass-assignable from the public widget (set by direct assignment in
 * the cabinet controller, like notes_internal). Mirrors the Room enum pattern.
 */
enum Attendance: string
{
    case Arrived = 'arrived';
    case NoShow = 'no_show';

    /** The German display label (e.g. "Erschienen"). */
    public function label(): string
    {
        return match ($this) {
            self::Arrived => 'Erschienen',
            self::NoShow => 'Nicht erschienen',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $a) => ['value' => $a->value, 'label' => $a->label()],
            self::cases(),
        );
    }
}
```

- [ ] **Step 4: Create the migration**

`backend/database/migrations/2026_06_22_000001_add_attendance_to_appointments.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // null = not yet recorded · 'arrived' · 'no_show'. Validation lives
            // in the Form Request (Rule::enum), not a DB CHECK — project convention.
            $table->string('attendance')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('attendance');
        });
    }
};
```

- [ ] **Step 5: Add the cast to the model**

Dans `backend/app/Models/Tenant/Appointment.php`, ajouter l'import en haut :

```php
use App\Support\Attendance;
```

Puis dans le tableau `$casts`, ajouter la ligne `attendance` (à côté de `'room' => Room::class`) :

```php
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'patient_birthdate' => 'date',
        'parent_consent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'room' => Room::class,
        'attendance' => Attendance::class,
    ];
```

**Ne PAS** ajouter `attendance` à `$fillable` (protection mass-assignment volontaire).

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=AppointmentAttendanceTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Support/Attendance.php backend/database/migrations/2026_06_22_000001_add_attendance_to_appointments.php backend/app/Models/Tenant/Appointment.php backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php
git commit -m "feat(appointments): add attendance enum, column and model cast"
```

---

### Task 2: Pointage via PATCH /termine/{id} + protection mass-assignment

**Files:**
- Modify: `backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php:17-31` (ajout règle)
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php:111-145` (update) et `:160-179` (toDto)
- Test: `backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php` (ajout de cas)

**Interfaces:**
- Consumes: `App\Support\Attendance` (Task 1), `appointments.attendance` colonne (Task 1).
- Produces: `PATCH /termine/{appointment}` accepte `attendance ∈ {null, 'arrived', 'no_show'}`. Le DTO `toDto()` inclut désormais la clé `attendance` (string|null).

- [ ] **Step 1: Write the failing tests**

Ajouter à la fin de `backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php` :

```php
it('updates attendance via the staff PATCH endpoint', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create();

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'arrived'])
        ->assertOk()
        ->assertJsonPath('attendance', 'arrived');

    expect($appointment->fresh()->attendance)->toBe(Attendance::Arrived);
});

it('clears attendance back to null when sent null', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create(['attendance' => 'no_show']);

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => null])
        ->assertOk()
        ->assertJsonPath('attendance', null);

    expect($appointment->fresh()->attendance)->toBeNull();
});

it('rejects an invalid attendance value with 422', function () {
    $user = \App\Models\User::factory()->create(['two_factor_confirmed_at' => now()]);
    $appointment = Appointment::factory()->create();

    $this->actingAs($user)
        ->patchJson("/termine/{$appointment->id}", ['attendance' => 'maybe'])
        ->assertStatus(422);
});

it('never lets the public widget set attendance (mass-assignment guard)', function () {
    [$p, $s, $startsAt] = bookingSetup();

    $response = $this->postJson('/api/v1/widget/appointments', bookingPayload([
        'service_id' => $s->id,
        'practitioner_id' => $p->id,
        'starts_at' => $startsAt->toIso8601String(),
        'attendance' => 'arrived', // hostile field
    ]));

    $response->assertStatus(201);
    $appointment = Appointment::query()->latest('created_at')->first();
    expect($appointment->attendance)->toBeNull();
});
```

> Note : `bookingSetup()` et `bookingPayload()` sont des helpers globaux définis dans `WidgetBookingTest.php`, chargés par Pest pour tout le dossier `Feature/`. Si l'autoload des helpers échoue (selon la config Pest), dupliquer un `bookingSetup` minimal en tête de fichier.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=AppointmentAttendanceTest`
Expected: FAIL (la règle `attendance` n'existe pas → ignorée ; `toDto` ne renvoie pas `attendance` → `assertJsonPath` échoue).

- [ ] **Step 3: Add the validation rule**

Dans `backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php`, ajouter l'import :

```php
use App\Support\Attendance;
use Illuminate\Validation\Rule;
```

Puis ajouter dans le tableau `rules()`, après la ligne `room` :

```php
            'attendance' => ['sometimes', 'nullable', Rule::enum(Attendance::class)],
```

- [ ] **Step 4: Handle attendance in the controller (direct assignment + DTO)**

Dans `backend/app/Http/Controllers/Tenant/AppointmentController.php`, méthode `update()`, juste après le bloc qui extrait `notes_internal` (`unset($data['notes_internal']);`), ajouter l'extraction d'`attendance` (elle non plus n'est pas `$fillable`) :

```php
        // attendance is not $fillable — strip it from the scheduler payload
        // and apply it directly afterwards (staff-only, like notes_internal).
        $hasAttendance = array_key_exists('attendance', $data);
        $attendance = $data['attendance'] ?? null;
        unset($data['attendance']);
```

Puis, après le bloc `if ($hasNotes) { ... }` et avant le `return`, ajouter :

```php
        if ($hasAttendance) {
            $appointment->attendance = $attendance;
            $appointment->save();
        }
```

Enfin, dans `toDto()`, ajouter la clé `attendance` après `'notes_internal' => ...` :

```php
            'attendance' => $a->attendance?->value,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=AppointmentAttendanceTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Run the full appointment suite (no regression)**

Run: `cd backend && php artisan test --filter=Appointment`
Expected: PASS (tous les tests RDV existants verts).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php backend/app/Http/Controllers/Tenant/AppointmentController.php backend/tests/Feature/TenantSchema/AppointmentAttendanceTest.php
git commit -m "feat(appointments): record attendance via staff PATCH (mass-assignment safe)"
```

---

### Task 3: Endpoint liste (recherche ILIKE paramétrée, filtres, pagination)

**Files:**
- Create: `backend/app/Http/Requests/Tenant/ListAppointmentsRequest.php`
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php` (nouvelle méthode `list`)
- Modify: `backend/routes/web.php` (route `tenant.appointments.list`, dans le groupe auth)
- Test: `backend/tests/Feature/TenantSchema/AppointmentListTest.php`

**Interfaces:**
- Consumes: `appointments.attendance` (Task 1), DTO incluant `attendance` (Task 2).
- Produces: `GET /termine/liste` (nom `tenant.appointments.list`) → page Inertia `Tenant/Appointments/List` avec props `appointments` (paginator : `data[]` au format DTO + `links`/`meta`) et `filters` (`{q, from, to, attendance}`). Query params : `q` (string, max 100), `from`/`to` (date), `attendance` (enum), `page` (int).

- [ ] **Step 1: Write the failing tests**

Créer `backend/tests/Feature/TenantSchema/AppointmentListTest.php` :

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function staffUser(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

it('finds appointments by patient last name (case-insensitive)', function () {
    Appointment::factory()->create(['patient_last_name' => 'Diallo']);
    Appointment::factory()->create(['patient_last_name' => 'Barry']);

    $this->actingAs(staffUser())
        ->get('/termine/liste?q=diallo')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Appointments/List')
            ->where('appointments.data.0.patient_last_name', 'Diallo')
            ->has('appointments.data', 1));
});

it('finds appointments by parent last name', function () {
    Appointment::factory()->create(['parent_last_name' => 'Soumah', 'patient_last_name' => 'X']);
    Appointment::factory()->create(['parent_last_name' => 'Bah', 'patient_last_name' => 'Y']);

    $this->actingAs(staffUser())
        ->get('/termine/liste?q=soumah')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('is immune to SQL injection in the search term', function () {
    Appointment::factory()->count(3)->create();

    $this->actingAs(staffUser())
        ->get('/termine/liste?'.http_build_query(['q' => "'; DROP TABLE appointments; --"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    // The table must still exist and hold its rows.
    expect(DB::table('appointments')->count())->toBe(3);
});

it('filters by attendance', function () {
    Appointment::factory()->create(['attendance' => 'no_show']);
    Appointment::factory()->create(['attendance' => 'arrived']);
    Appointment::factory()->create(['attendance' => null]);

    $this->actingAs(staffUser())
        ->get('/termine/liste?attendance=no_show')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('paginates at 25 per page', function () {
    Appointment::factory()->count(30)->create();

    $this->actingAs(staffUser())
        ->get('/termine/liste')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments.data', 25));
});

it('rejects an over-long search term with 422', function () {
    $this->actingAs(staffUser())
        ->get('/termine/liste?q='.str_repeat('a', 101))
        ->assertStatus(422);
});

it('does not N+1 across the appointment list', function () {
    Appointment::factory()->count(20)->create();

    DB::enableQueryLog();
    $this->actingAs(staffUser())->get('/termine/liste')->assertOk();
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();

    Appointment::factory()->count(20)->create();
    $this->actingAs(staffUser())->get('/termine/liste')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Query count must not grow with the number of rows (eager-load).
    expect($second)->toBe($first);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=AppointmentListTest`
Expected: FAIL (route `/termine/liste` inexistante → 404).

- [ ] **Step 3: Create the Form Request**

`backend/app/Http/Requests/Tenant/ListAppointmentsRequest.php` :

```php
<?php

namespace App\Http\Requests\Tenant;

use App\Support\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'attendance' => ['nullable', Rule::enum(Attendance::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 4: Add the `list` action to the controller**

Dans `backend/app/Http/Controllers/Tenant/AppointmentController.php`, ajouter l'import du Form Request en haut :

```php
use App\Http\Requests\Tenant\ListAppointmentsRequest;
```

Puis ajouter la méthode `list()` après `index()` :

```php
    public function list(ListAppointmentsRequest $request): Response
    {
        $filters = $request->validated();
        $q = $filters['q'] ?? null;

        $appointments = Appointment::query()
            ->with(['service', 'practitioner'])
            ->when($q, function ($query) use ($q) {
                // Escape LIKE wildcards so a typed % / _ doesn't widen the search.
                // The value is a BOUND parameter — never concatenated into SQL.
                $term = '%'.addcslashes($q, '%_\\').'%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('patient_first_name', 'ILIKE', $term)
                        ->orWhere('patient_last_name', 'ILIKE', $term)
                        ->orWhere('parent_last_name', 'ILIKE', $term);
                });
            })
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('starts_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('starts_at', '<=', $to))
            ->when($filters['attendance'] ?? null, fn ($query, $att) => $query->where('attendance', $att))
            ->orderByDesc('starts_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Appointment $a) => $this->toDto($a));

        return Inertia::render('Tenant/Appointments/List', [
            'appointments' => $appointments,
            'filters' => [
                'q' => $q,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'attendance' => $filters['attendance'] ?? null,
            ],
        ]);
    }
```

- [ ] **Step 5: Register the route**

Dans `backend/routes/web.php`, dans le groupe `Route::middleware(['auth', 'two-factor.enrolled'])`, ajouter AVANT la ligne `Route::get('/termine', ...)` (pour que `/termine/liste` ne soit pas capturé par un éventuel paramètre) :

```php
    Route::get('/termine/liste', [AppointmentController::class, 'list'])->name('tenant.appointments.list');
```

> `/termine` est une route fixe (pas `/termine/{appointment}`), donc l'ordre n'entre pas en conflit ici — mais placer `liste` juste avant le bloc `/termine` garde les routes RDV groupées et lisibles.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=AppointmentListTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Tenant/ListAppointmentsRequest.php backend/app/Http/Controllers/Tenant/AppointmentController.php backend/routes/web.php backend/tests/Feature/TenantSchema/AppointmentListTest.php
git commit -m "feat(appointments): paginated list endpoint with safe ILIKE search + filters"
```

---

### Task 4: Frontend — DTO TypeScript + badges de présence sur le calendrier

**Files:**
- Modify: `backend/resources/js/lib/calendar.ts` (interface `AppointmentDto` + `toCalendarEvent`)
- Modify: `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue` (option `eventDidMount` ou `eventClassNames`)
- Test: `backend/tests/widget/` n/a — couvert par un test Vitest optionnel si présent ; sinon vérification visuelle.

**Interfaces:**
- Consumes: DTO backend avec clé `attendance: string | null` (Task 2/3).
- Produces: `AppointmentDto.attendance: 'arrived' | 'no_show' | null`. Constante TS `ATTENDANCE_LABELS` réutilisable par la liste (Task 6).

- [ ] **Step 1: Add `attendance` to the DTO type**

Dans `backend/resources/js/lib/calendar.ts`, dans l'interface `AppointmentDto`, ajouter après `notes_internal` :

```ts
    attendance: 'arrived' | 'no_show' | null
```

Puis, en bas du fichier, exporter les libellés partagés (réutilisés par la liste) :

```ts
export const ATTENDANCE_LABELS: Record<'arrived' | 'no_show', string> = {
    arrived: 'Erschienen',
    no_show: 'Nicht erschienen',
}
```

- [ ] **Step 2: Reflect attendance in the calendar event class**

Dans `toCalendarEvent()` (même fichier), ajouter une classe CSS selon `attendance` pour permettre l'atténuation visuelle. Modifier l'objet retourné pour inclure `classNames` :

```ts
    return {
        id: a.id,
        title: `${a.patient_first_name} ${lastInitial} — ${a.service.name}`.replace(/\s+—/, ' —'),
        start: a.starts_at,
        end: a.ends_at,
        backgroundColor: roomColor(a.room),
        borderColor: a.practitioner.color,
        textColor: '#1E293B',
        classNames: a.attendance ? [`att-${a.attendance}`] : [],
        extendedProps: a,
    }
```

Et ajouter `classNames` à l'interface `CalendarEvent` :

```ts
    classNames: string[]
```

- [ ] **Step 3: Add the badge + dimming styles in Calendar.vue**

Dans `backend/resources/js/Pages/Tenant/Appointments/Calendar.vue`, ajouter un bloc `<style>` (scoped non — FullCalendar rend hors scope, utiliser `:deep` ou un style global ciblé). Ajouter en bas du fichier :

```vue
<style>
/* Attendance visual cues on calendar events (classes set in toCalendarEvent). */
.att-no_show { opacity: 0.55; }
.att-no_show .fc-event-title::after { content: ' ✗'; font-weight: 700; color: #e11d48; }
.att-arrived .fc-event-title::after { content: ' ✓'; font-weight: 700; color: #16a34a; }
</style>
```

- [ ] **Step 4: Build the assets to verify no TS/compile error**

Run: `cd backend && npm run build`
Expected: build réussi sans erreur (esbuild ne type-check pas, mais une erreur de syntaxe casserait le build).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/lib/calendar.ts backend/resources/js/Pages/Tenant/Appointments/Calendar.vue
git commit -m "feat(calendar): show attendance badges on appointment events"
```

---

### Task 5: Frontend — boutons de pointage dans le modal RDV

**Files:**
- Modify: `backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue` (state + ligne UI + handler)

**Interfaces:**
- Consumes: `AppointmentDto.attendance` (Task 4), endpoint `PATCH /termine/{id}` acceptant `{attendance}` (Task 2).
- Produces: bouton de pointage qui émet `saved` après succès (le parent rafraîchit le calendrier).

- [ ] **Step 1: Add attendance to the form state**

Dans `AppointmentForm.vue`, dans le `reactive(form)`, ajouter le champ après `room` :

```ts
    attendance: null as 'arrived' | 'no_show' | null,
```

Dans le `watch(() => props.open)`, branche `if (props.appointment)` (mode édition), ajouter dans le `Object.assign(form, {...})` :

```ts
            attendance: a.attendance ?? null,
```

Et dans la branche `else` (mode création), ajouter `attendance: null,` au `Object.assign`.

- [ ] **Step 2: Add a dedicated attendance handler**

Dans le `<script setup>`, ajouter une fonction qui toggle et persiste immédiatement (sans soumettre tout le formulaire) :

```ts
const setAttendance = async (value: 'arrived' | 'no_show') => {
    if (!props.appointment) return
    // Toggle: clicking the active state clears it back to null.
    const next = form.attendance === value ? null : value
    saving.value = true
    errors.value = {}
    try {
        await window.axios.patch(`/termine/${props.appointment.id}`, { attendance: next })
        form.attendance = next
        emit('saved')
    } catch (e: any) {
        errors.value = { _: ['Anwesenheit konnte nicht gespeichert werden.'] }
    } finally {
        saving.value = false
    }
}
```

- [ ] **Step 3: Add the attendance row to the template (edit mode only)**

Dans le `<template>` du modal, ajouter une ligne « Anwesenheit » visible uniquement en mode édition (`v-if="isEdit"`), avant les boutons de soumission/annulation :

```vue
        <div v-if="isEdit" class="mt-4">
            <label class="block text-sm font-medium mb-1">Anwesenheit</label>
            <div class="flex gap-2">
                <button type="button"
                        @click="setAttendance('arrived')"
                        :disabled="saving"
                        :class="form.attendance === 'arrived' ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-700'"
                        class="rounded-full px-3 py-1.5 text-sm font-semibold disabled:opacity-50">
                    ✓ Erschienen
                </button>
                <button type="button"
                        @click="setAttendance('no_show')"
                        :disabled="saving"
                        :class="form.attendance === 'no_show' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-700'"
                        class="rounded-full px-3 py-1.5 text-sm font-semibold disabled:opacity-50">
                    ✗ Nicht erschienen
                </button>
            </div>
        </div>
```

- [ ] **Step 4: Build to verify no compile error**

Run: `cd backend && npm run build`
Expected: build réussi.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue
git commit -m "feat(calendar): attendance toggle buttons in the appointment modal"
```

---

### Task 6: Frontend — page liste `/termine/liste` + lien navigation

**Files:**
- Create: `backend/resources/js/Pages/Tenant/Appointments/List.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue:23-31` (entrée nav)

**Interfaces:**
- Consumes: page Inertia `Tenant/Appointments/List` avec props `appointments` (paginator Laravel : `{data: AppointmentDto[], links: [...], meta?}`) et `filters` (Task 3) ; `ATTENDANCE_LABELS` (Task 4) ; endpoint `PATCH /termine/{id}` (Task 2).
- Produces: page liste fonctionnelle, lien « Liste » dans le menu.

- [ ] **Step 1: Create the List page**

`backend/resources/js/Pages/Tenant/Appointments/List.vue` :

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, Link } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import type { AppointmentDto } from '@/lib/calendar'
import { ATTENDANCE_LABELS } from '@/lib/calendar'

defineOptions({ layout: TenantLayout })

interface Paginator<T> {
    data: T[]
    links: Array<{ url: string | null; label: string; active: boolean }>
}

const props = defineProps<{
    appointments: Paginator<AppointmentDto>
    filters: { q: string | null; from: string | null; to: string | null; attendance: string | null }
}>()

const q = ref(props.filters.q ?? '')
const attendance = ref(props.filters.attendance ?? '')

// Re-query the server with the current filters (server is the source of truth).
const applyFilters = () => {
    router.get('/termine/liste', {
        q: q.value || undefined,
        attendance: attendance.value || undefined,
    }, { preserveState: true, replace: true })
}

const setAttendance = async (a: AppointmentDto, value: 'arrived' | 'no_show') => {
    const next = a.attendance === value ? null : value
    try {
        await window.axios.patch(`/termine/${a.id}`, { attendance: next })
        a.attendance = next // optimistic local update
    } catch {
        router.reload({ only: ['appointments'] }) // rollback by refetching
    }
}

const fmt = (iso: string) =>
    new Date(iso).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })
</script>

<template>
    <Head title="Terminliste" />
    <div class="p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold">Terminliste</h1>
            <Link href="/termine" class="text-sm font-medium text-kids-blue underline">Zum Kalender</Link>
        </div>

        <div class="flex flex-wrap gap-3 mb-4">
            <input v-model="q" @keyup.enter="applyFilters" type="search"
                   placeholder="Suche: Name Kind / Eltern…"
                   class="border rounded px-3 py-2 text-sm w-64" />
            <select v-model="attendance" @change="applyFilters" class="border rounded px-3 py-2 text-sm">
                <option value="">Alle</option>
                <option value="arrived">Erschienen</option>
                <option value="no_show">Nicht erschienen</option>
            </select>
            <button type="button" @click="applyFilters"
                    class="rounded bg-kids-blue px-4 py-2 text-sm font-semibold text-white">Suchen</button>
        </div>

        <table class="w-full text-sm">
            <thead class="text-left text-slate-500 border-b">
                <tr>
                    <th class="py-2 pr-4">Datum/Zeit</th>
                    <th class="py-2 pr-4">Kind</th>
                    <th class="py-2 pr-4">Behandler</th>
                    <th class="py-2 pr-4">Leistung</th>
                    <th class="py-2 pr-4">Anwesenheit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in appointments.data" :key="a.id" class="border-b hover:bg-slate-50">
                    <td class="py-2 pr-4 whitespace-nowrap">{{ fmt(a.starts_at) }}</td>
                    <td class="py-2 pr-4">{{ a.patient_first_name }} {{ a.patient_last_name }}</td>
                    <td class="py-2 pr-4">{{ a.practitioner.name }}</td>
                    <td class="py-2 pr-4">{{ a.service.name }}</td>
                    <td class="py-2 pr-4">
                        <div class="flex gap-1">
                            <button type="button" @click="setAttendance(a, 'arrived')"
                                    :class="a.attendance === 'arrived' ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="rounded-full px-2 py-1 text-xs font-semibold" title="Erschienen">✓</button>
                            <button type="button" @click="setAttendance(a, 'no_show')"
                                    :class="a.attendance === 'no_show' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="rounded-full px-2 py-1 text-xs font-semibold" title="Nicht erschienen">✗</button>
                        </div>
                    </td>
                </tr>
                <tr v-if="appointments.data.length === 0">
                    <td colspan="5" class="py-6 text-center text-slate-400">Keine Termine gefunden.</td>
                </tr>
            </tbody>
        </table>

        <nav class="mt-4 flex flex-wrap gap-1">
            <component :is="link.url ? 'button' : 'span'" v-for="(link, i) in appointments.links" :key="i"
                       @click="link.url && router.get(link.url, {}, { preserveState: true })"
                       v-html="link.label"
                       :class="link.active ? 'bg-kids-blue text-white' : 'text-slate-600'"
                       class="rounded px-3 py-1 text-sm" />
        </nav>
    </div>
</template>
```

- [ ] **Step 2: Add the nav link**

Dans `backend/resources/js/Layouts/TenantLayout.vue`, dans le tableau `nav`, ajouter une entrée juste après la ligne `Termine` (réutiliser une icône déjà importée, p.ex. `ClipboardList` si disponible, sinon importer `List` de `lucide-vue-next`) :

```ts
    { href: '/termine/liste', label: 'Terminliste', icon: ListChecks },
```

Ajouter l'import en haut du fichier (à la liste des icônes lucide existante) :

```ts
import { ListChecks } from 'lucide-vue-next'
```

> Vérifier que l'import existant des icônes lucide est une liste `import { ... } from 'lucide-vue-next'` ; y ajouter `ListChecks` plutôt qu'une seconde ligne d'import.

- [ ] **Step 3: Build the assets**

Run: `cd backend && npm run build`
Expected: build réussi.

- [ ] **Step 4: Run the full backend suite (no regression)**

Run: `cd backend && composer test`
Expected: PASS (toute la suite verte, dont les nouveaux tests Task 1-3).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/Pages/Tenant/Appointments/List.vue backend/resources/js/Layouts/TenantLayout.vue
git commit -m "feat(appointments): appointment list page with search, filters and quick attendance"
```

---

### Task 7: Vérification visuelle Chrome + mise à jour docs de référence

**Files:**
- Modify: `docs/database-diagram.html` (+ copie `backend/public/` si applicable) — colonne `attendance`
- Modify: `public/wireframe.html` ou équivalent — écran `/termine/liste`
- Modify: `backend/app/Services/ProjectProgressService.php` — module gestion RDV (si présent)

- [ ] **Step 1: Lancer l'app et vérifier visuellement**

Run: `cd backend && composer dev`
Vérifier dans Chrome (connexion → 2FA → `/termine`) :
- Marquer un RDV « Erschienen » dans le modal → badge ✓ vert apparaît sur l'événement.
- Marquer « Nicht erschienen » → événement atténué + ✗ rouge.
- Recliquer l'état actif → revient à neutre.
- `/termine/liste` : recherche par nom, filtre présence, pagination, boutons ✓/✗ par ligne.

- [ ] **Step 2: Mettre à jour le diagramme BDD**

Ajouter la colonne `attendance` (nullable) à la table `appointments` dans `docs/database-diagram.html`. Copier dans `backend/public/` si le projet maintient une copie servie.

- [ ] **Step 3: Mettre à jour le wireframe**

Ajouter l'écran `/termine/liste` (tableau + recherche + filtres) dans le wireframe.

- [ ] **Step 4: Mettre à jour ProjectProgressService (si présent)**

Marquer le module « gestion RDV » : pointage présence + liste paginée (items + checks sur models/controllers/pages/migrations/tests).

- [ ] **Step 5: Commit**

```bash
git add docs/ backend/public/ backend/app/Services/ProjectProgressService.php
git commit -m "docs: update reference diagrams for attendance + appointment list"
```

---

## Self-Review

**Spec coverage :**
- Colonne `attendance` + enum → Task 1 ✓
- `PATCH` étend attendance + mass-assignment guard → Task 2 ✓
- Endpoint liste + recherche ILIKE + filtres + pagination → Task 3 ✓
- Injection SQL (bindings + escape wildcards + validation max:100) → Task 3 (règle + test dédié) ✓
- Anti N+1 → Task 3 (test query-count) ✓
- Badges calendrier → Task 4 ✓
- Boutons modal → Task 5 ✓
- Page liste + nav → Task 6 ✓
- Notes internes → déjà fait (hors périmètre, confirmé dans la spec) ✓
- Tests (8 backend + visuel) → Tasks 1-3 + 7 ✓
- Docs de référence → Task 7 ✓

**Placeholder scan :** aucun TODO/TBD ; tout le code est complet.

**Type consistency :** `attendance` typé `'arrived' | 'no_show' | null` partout (DTO TS, form, handlers) ; `Attendance::class` enum côté PHP ; clé DTO `'attendance' => $a->attendance?->value` (string|null) cohérente avec le type TS.
