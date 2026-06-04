# Dashboard moderne, rôles & couleurs KidsClub — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Doter le cabinet pédiatrique KidsClub d'un dashboard staff moderne (icônes Lucide, charte 5 pastels), de deux rôles cosmétiques (médecin/secrétaire), et d'un système de couleurs où l'enfant choisit « sa salle » à la réservation, couleur qui pilote ensuite le calendrier.

**Architecture:** Une seule décision (le `room` choisi à la réservation) traverse widget public → base → calendrier staff. Source de vérité unique des couleurs dans un enum PHP `App\Support\Room`, exposé au front. Rôles = colonnes sur `users` + helpers, sans aucune Policy ni gate. Dashboard reconstruit avec le skill `frontend-design`.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 `<script setup lang="ts">`, Tailwind 3, `lucide-vue-next` (déjà installé), FullCalendar, PostgreSQL, Pest 4, Vitest.

**Toutes les commandes se lancent depuis `backend/`.**

---

## Carte des fichiers

**Créer**
- `app/Support/Room.php` — enum source de vérité (valeur ↔ couleur ↔ libellé).
- `database/migrations/2026_06_04_000001_add_room_to_appointments.php`
- `database/migrations/2026_06_04_000002_add_role_and_practitioner_to_users.php`
- `resources/js/components/ui/RoomPicker.vue` — 5 pastilles cliquables (`v-model`).
- `resources/js/components/ui/RoomLegend.vue` — lecture seule des 5 salles.
- `resources/js/components/ui/StatCard.vue` — carte KPI (icône Lucide + valeur + libellé).
- `tests/widget/RoomPicker.test.ts`, `tests/widget/RoomLegend.test.ts`
- `tests/Feature/TenantSchema/RoomBookingTest.php`, `tests/Feature/TenantSchema/UserRoleTest.php`, `tests/Feature/TenantSchema/DashboardTest.php`

**Modifier**
- `tailwind.config.js` (palette `kids`)
- `app/Models/User.php`, `app/Models/Tenant/Appointment.php`
- `app/Http/Requests/Widget/StoreAppointmentRequest.php`, `app/Http/Controllers/Widget/AppointmentController.php`
- `app/Http/Requests/Tenant/StoreManualAppointmentRequest.php`, `app/Http/Requests/Tenant/UpdateAppointmentRequest.php`
- `app/Http/Controllers/Tenant/AppointmentController.php` (store + toDto)
- `app/Http/Controllers/Tenant/DashboardController.php`
- `resources/js/widget/types.ts`, `resources/js/widget/steps/FormStep.vue`
- `resources/js/lib/calendar.ts`, `resources/js/Pages/Tenant/Appointments/Calendar.vue`, `resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`
- `resources/js/Pages/Tenant/Dashboard.vue`, `resources/js/Layouts/TenantLayout.vue`
- `database/seeders/KidsClubSeeder.php`
- Docs : `public/wireframe.html`, `docs/database-diagram.html` (+ copie `public/`), `app/Services/ProjectProgressService.php`

---

## Phase 0 — Filet de sécurité des types

### Task 0: Installer `vue-tsc` + script `type-check` (base verte)

**Files:**
- Modify: `package.json` (devDependencies + scripts)
- Possibly modify: `tsconfig.json` et fichiers `.ts`/`.vue` avec erreurs de types pré-existantes

> **Pourquoi :** le code est en TS (32/33 `.vue` en `lang="ts"`) mais aucun `vue-tsc`
> ne tourne — les types sont effacés par esbuild sans vérification. On installe le
> filet AVANT le reste pour que les tâches suivantes écrivent du code réellement
> type-safe. On vise une base `type-check` **verte**.

- [ ] **Step 1: Installer `vue-tsc`**

Run: `npm install -D vue-tsc`
Expected: ajouté aux devDependencies (compatible avec `typescript ^6`).

- [ ] **Step 2: Ajouter le script `type-check`**

In `backend/package.json`, add to `"scripts"`:

```json
        "type-check": "vue-tsc --noEmit"
```

- [ ] **Step 3: Lancer le type-check et mesurer l'ampleur**

Run: `npm run type-check`
Expected: soit vert (idéal), soit une liste d'erreurs pré-existantes.

- [ ] **Step 4: Corriger les erreurs pré-existantes**

Corriger les erreurs remontées (types manquants, props, `undefined` non gérés).
**Garde-fou anti-rabbit-hole :** si le volume est important (> ~15 erreurs ou des
erreurs structurelles touchant la config), s'ARRÊTER et faire un point avec
l'utilisateur sur le périmètre avant de continuer — ne pas réécrire l'archi.

- [ ] **Step 5: Relancer jusqu'au vert**

Run: `npm run type-check`
Expected: PASS (aucune erreur).

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json tsconfig.json
git commit -m "build: add vue-tsc type-check script + green baseline"
```

---

## Phase 1 — Fondations

### Task 1: Palette Tailwind `kids`

**Files:**
- Modify: `tailwind.config.js:25` (objet `colors`)

- [ ] **Step 1: Ajouter la palette dans `theme.extend.colors`**

Insérer, juste après l'ouverture `colors: {` (ligne 25), avant `border: 'hsl(var(--border))',` :

```js
                kids: {
                    green: '#BDCCC2',
                    yellow: '#F7E29D',
                    peach: '#FCE8E1',
                    blue: '#98ACBA',
                    purple: '#CCC8CE',
                },
```

- [ ] **Step 2: Vérifier que le build passe**

Run: `npm run build`
Expected: build OK, aucune erreur Tailwind.

- [ ] **Step 3: Commit**

```bash
git add tailwind.config.js
git commit -m "feat(ui): add KidsClub pastel palette to Tailwind"
```

---

### Task 2: Enum `App\Support\Room` (source de vérité des couleurs)

**Files:**
- Create: `app/Support/Room.php`
- Test: `tests/Feature/TenantSchema/RoomBookingTest.php` (créé ici, étoffé en Task 5/6)

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/TenantSchema/RoomBookingTest.php`:

```php
<?php

use App\Support\Room;

it('maps each room to its KidsClub hex color', function () {
    expect(Room::Green->color())->toBe('#BDCCC2')
        ->and(Room::Yellow->color())->toBe('#F7E29D')
        ->and(Room::Peach->color())->toBe('#FCE8E1')
        ->and(Room::Blue->color())->toBe('#98ACBA')
        ->and(Room::Purple->color())->toBe('#CCC8CE');
});

it('exposes options as value/color/label rows for the front', function () {
    $options = Room::options();

    expect($options)->toHaveCount(5)
        ->and($options[0])->toHaveKeys(['value', 'color', 'label'])
        ->and(collect($options)->pluck('value')->all())
        ->toBe(['green', 'yellow', 'peach', 'blue', 'purple']);
});
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=RoomBookingTest`
Expected: FAIL (`Class "App\Support\Room" not found`).

- [ ] **Step 3: Créer l'enum**

Create `app/Support/Room.php`:

```php
<?php

namespace App\Support;

/**
 * The five colored treatment rooms of the KidsClub practice. This enum is the
 * single source of truth for room → color/label; the front receives it via
 * props (staff) and widget config, so a color lives in exactly one place.
 */
enum Room: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Peach = 'peach';
    case Blue = 'blue';
    case Purple = 'purple';

    public function color(): string
    {
        return match ($this) {
            self::Green => '#BDCCC2',
            self::Yellow => '#F7E29D',
            self::Peach => '#FCE8E1',
            self::Blue => '#98ACBA',
            self::Purple => '#CCC8CE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Grünes Zimmer',
            self::Yellow => 'Gelbes Zimmer',
            self::Peach => 'Oranges Zimmer',
            self::Blue => 'Blaues Zimmer',
            self::Purple => 'Lila Zimmer',
        };
    }

    /** @return list<array{value:string,color:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'color' => $r->color(), 'label' => $r->label()],
            self::cases(),
        );
    }
}
```

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=RoomBookingTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Room.php tests/Feature/TenantSchema/RoomBookingTest.php
git commit -m "feat(room): add Room enum as single source of truth for colors"
```

---

### Task 3: Migration + modèle — `appointments.room`

**Files:**
- Create: `database/migrations/2026_06_04_000001_add_room_to_appointments.php`
- Modify: `app/Models/Tenant/Appointment.php:13` (`$fillable`), `:22` (`$casts`)
- Test: `tests/Feature/TenantSchema/RoomBookingTest.php`

- [ ] **Step 1: Ajouter un test de cast/fillable**

Append to `tests/Feature/TenantSchema/RoomBookingTest.php`:

```php
use App\Models\Tenant\Appointment;
use Database\Factories\Tenant\AppointmentFactory;

it('stores room as a Room enum and allows null', function () {
    $withRoom = AppointmentFactory::new()->create(['room' => 'blue']);
    $withoutRoom = AppointmentFactory::new()->create(['room' => null]);

    expect($withRoom->fresh()->room)->toBe(Room::Blue)
        ->and($withoutRoom->fresh()->room)->toBeNull();
});

it('keeps notes_internal and reminder_sent_at out of mass assignment', function () {
    $a = new Appointment();

    expect($a->isFillable('room'))->toBeTrue()
        ->and($a->isFillable('notes_internal'))->toBeFalse()
        ->and($a->isFillable('reminder_sent_at'))->toBeFalse();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `php artisan test --filter=RoomBookingTest`
Expected: FAIL (colonne `room` inconnue / cast absent).

- [ ] **Step 3: Créer la migration**

Create `database/migrations/2026_06_04_000001_add_room_to_appointments.php`:

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
            // Nullable: the child's room choice is optional (fun preference, not
            // a hard resource booking). Constrained to the 5 KidsClub rooms.
            $table->string('room')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('room');
        });
    }
};
```

- [ ] **Step 4: Ajouter `room` au modèle**

In `app/Models/Tenant/Appointment.php`, add `'room'` to `$fillable` (after `'status',` on line 14):

```php
        'practitioner_id', 'service_id', 'starts_at', 'ends_at', 'status', 'room',
```

And add the cast inside `$casts` (after `'parent_consent_at' => 'datetime',`):

```php
        'room' => \App\Support\Room::class,
```

- [ ] **Step 5: Migrer + lancer le test**

Run: `php artisan migrate && php artisan test --filter=RoomBookingTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_04_000001_add_room_to_appointments.php app/Models/Tenant/Appointment.php tests/Feature/TenantSchema/RoomBookingTest.php
git commit -m "feat(room): add nullable room column + enum cast on appointments"
```

---

### Task 4: Migration + modèle — `users.role` & `users.practitioner_id`

**Files:**
- Create: `database/migrations/2026_06_04_000002_add_role_and_practitioner_to_users.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/TenantSchema/UserRoleTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/TenantSchema/UserRoleTest.php`:

```php
<?php

use App\Models\User;
use App\Models\Tenant\Practitioner;
use Database\Factories\Tenant\PractitionerFactory;

it('defaults new users to the secretaire role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe('secretaire')
        ->and($user->isSecretaire())->toBeTrue()
        ->and($user->isMedecin())->toBeFalse();
});

it('links a medecin user to a practitioner fiche', function () {
    $practitioner = PractitionerFactory::new()->create();
    $user = User::factory()->create([
        'role' => 'medecin',
        'practitioner_id' => $practitioner->id,
    ]);

    expect($user->isMedecin())->toBeTrue()
        ->and($user->practitioner)->toBeInstanceOf(Practitioner::class)
        ->and($user->practitioner->id)->toBe($practitioner->id);
});

it('leaves practitioner null for unlinked users', function () {
    expect(User::factory()->create()->practitioner)->toBeNull();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `php artisan test --filter=UserRoleTest`
Expected: FAIL (colonne `role` inconnue / méthodes absentes).

- [ ] **Step 3: Créer la migration**

Create `database/migrations/2026_06_04_000002_add_role_and_practitioner_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cosmetic role only: both roles are full "admin"; this just
            // personalizes the dashboard/sidebar. No policy depends on it.
            $table->string('role')->default('secretaire')->after('email');
            // Optional link so a medecin's dashboard can highlight "their" RDV.
            $table->foreignId('practitioner_id')->nullable()->after('role')
                ->constrained('practitioners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practitioner_id');
            $table->dropColumn('role');
        });
    }
};
```

- [ ] **Step 4: Étendre le modèle `User`**

In `app/Models/User.php`:

Add `use App\Models\Tenant\Practitioner;` and `use Illuminate\Database\Eloquent\Relations\BelongsTo;` to the imports.

Add `'role'` and `'practitioner_id'` to `$fillable`:

```php
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'practitioner_id',
    ];
```

Add these methods to the class body (after `casts()`):

```php
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function isMedecin(): bool
    {
        return $this->role === 'medecin';
    }

    public function isSecretaire(): bool
    {
        return $this->role === 'secretaire';
    }
```

- [ ] **Step 5: Migrer + lancer le test**

Run: `php artisan migrate && php artisan test --filter=UserRoleTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_04_000002_add_role_and_practitioner_to_users.php app/Models/User.php tests/Feature/TenantSchema/UserRoleTest.php
git commit -m "feat(roles): add cosmetic role + optional practitioner link on users"
```

---

## Phase 2 — Couleurs de bout en bout

### Task 5: Composants `RoomPicker` & `RoomLegend`

**Files:**
- Create: `resources/js/components/ui/RoomPicker.vue`, `resources/js/components/ui/RoomLegend.vue`
- Test: `tests/widget/RoomPicker.test.ts`, `tests/widget/RoomLegend.test.ts`

> **Note interface partagée :** les deux composants reçoivent les salles via une prop
> `rooms: Array<{ value: string; color: string; label: string }>` (la sortie de
> `Room::options()`). Aucune couleur n'est codée en dur dans le front.

- [ ] **Step 1: Écrire les tests qui échouent**

Create `tests/widget/RoomPicker.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoomPicker from '@/components/ui/RoomPicker.vue'

const rooms = [
  { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
  { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
]

describe('RoomPicker', () => {
  it('renders one swatch per room', () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: null } })
    expect(wrapper.findAll('button[data-room]')).toHaveLength(2)
  })

  it('emits the room value when a swatch is clicked', async () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: null } })
    await wrapper.find('button[data-room="blue"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['blue'])
  })

  it('toggles selection off when the active swatch is clicked again (optional)', async () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: 'blue' } })
    await wrapper.find('button[data-room="blue"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
  })
})
```

Create `tests/widget/RoomLegend.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoomLegend from '@/components/ui/RoomLegend.vue'

const rooms = [
  { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
  { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
]

describe('RoomLegend', () => {
  it('renders a label per room', () => {
    const wrapper = mount(RoomLegend, { props: { rooms } })
    expect(wrapper.text()).toContain('Grünes Zimmer')
    expect(wrapper.text()).toContain('Blaues Zimmer')
  })
})
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `npm run test:widget -- RoomPicker RoomLegend`
Expected: FAIL (composants introuvables).

- [ ] **Step 3: Créer `RoomPicker.vue`**

Create `resources/js/components/ui/RoomPicker.vue`:

```vue
<script setup lang="ts">
interface RoomOption { value: string; color: string; label: string }

const props = defineProps<{
  rooms: RoomOption[]
  modelValue: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

// Optional choice: clicking the active swatch clears it (back to neutral).
function pick(value: string) {
  emit('update:modelValue', props.modelValue === value ? null : value)
}
</script>

<template>
  <div class="flex gap-2" role="group" aria-label="Zimmerfarbe">
    <button
      v-for="room in rooms"
      :key="room.value"
      type="button"
      :data-room="room.value"
      :title="room.label"
      :aria-label="room.label"
      :aria-pressed="modelValue === room.value"
      class="h-9 w-9 rounded-full border border-slate-300 transition focus:outline-none focus:ring-2 focus:ring-slate-400"
      :class="modelValue === room.value ? 'ring-2 ring-offset-2 ring-slate-700' : ''"
      :style="{ backgroundColor: room.color }"
      @click="pick(room.value)"
    >
      <span v-if="modelValue === room.value" class="text-slate-800 text-sm">✓</span>
    </button>
  </div>
</template>
```

- [ ] **Step 4: Créer `RoomLegend.vue`**

Create `resources/js/components/ui/RoomLegend.vue`:

```vue
<script setup lang="ts">
interface RoomOption { value: string; color: string; label: string }
defineProps<{ rooms: RoomOption[] }>()
</script>

<template>
  <ul class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-600">
    <li v-for="room in rooms" :key="room.value" class="flex items-center gap-2">
      <span class="inline-block h-3 w-3 rounded-full border border-slate-300" :style="{ backgroundColor: room.color }"></span>
      {{ room.label }}
    </li>
  </ul>
</template>
```

- [ ] **Step 5: Lancer, vérifier le succès**

Run: `npm run test:widget -- RoomPicker RoomLegend`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/ui/RoomPicker.vue resources/js/components/ui/RoomLegend.vue tests/widget/RoomPicker.test.ts tests/widget/RoomLegend.test.ts
git commit -m "feat(ui): add reusable RoomPicker + RoomLegend components"
```

---

### Task 6: Choix de salle dans le widget (parent)

**Files:**
- Modify: `app/Http/Requests/Widget/StoreAppointmentRequest.php:28`
- Modify: `app/Http/Controllers/Widget/AppointmentController.php` (le tableau `Appointment::create`)
- Modify: `resources/js/widget/types.ts`, `resources/js/widget/steps/FormStep.vue`
- Test: `tests/Feature/TenantSchema/RoomBookingTest.php`

- [ ] **Step 1: Test de validation/persistance (widget)**

Append to `tests/Feature/TenantSchema/RoomBookingTest.php`:

```php
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Database\Factories\Tenant\ServiceFactory;
use Database\Factories\Tenant\PractitionerFactory;

function bookingPayload(array $overrides = []): array
{
    $service = ServiceFactory::new()->create(['duration_minutes' => 30, 'is_active' => true]);
    $practitioner = PractitionerFactory::new()->create(['is_active' => true]);
    $practitioner->services()->attach($service->id);

    return array_merge([
        'practitioner_id' => $practitioner->id,
        'service_id' => $service->id,
        // A far-future Monday 09:00 Berlin to satisfy lead/horizon/open-hours
        // is environment-specific; this dataset focuses on validation rules,
        // so we assert the 422 *fields*, not a successful booking.
        'starts_at' => now()->addDays(3)->setTime(9, 0)->toIso8601String(),
        'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
        'patient_birthdate' => '2018-05-01',
        'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
        'parent_email' => 'eva@example.com', 'consent' => true,
    ], $overrides);
}

it('rejects a room outside the five allowed values', function () {
    $res = $this->postJson('/api/v1/widget/appointments', bookingPayload(['room' => 'rouge']));
    $res->assertStatus(422);
    expect($res->json('errors'))->toHaveKey('room');
});

it('accepts a missing room (room is optional)', function () {
    $res = $this->postJson('/api/v1/widget/appointments', bookingPayload());
    // Either booked (no room error) or rejected for scheduling — never a room error.
    expect($res->json('errors.room'))->toBeNull();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `php artisan test --filter=RoomBookingTest`
Expected: FAIL (le `room` invalide n'est pas rejeté → pas d'erreur `room`).

- [ ] **Step 3: Ajouter la règle de validation**

In `app/Http/Requests/Widget/StoreAppointmentRequest.php`, add inside `rules()` (after `'website' => ['nullable', 'string'],`):

```php
            'room' => ['nullable', 'in:green,yellow,peach,blue,purple'],
```

- [ ] **Step 4: Persister `room` à la création**

In `app/Http/Controllers/Widget/AppointmentController.php`, inside the `Appointment::create([...])` array, add (after `'cancellation_token' => (string) Str::uuid(),`):

```php
                'room' => $data['room'] ?? null,
```

- [ ] **Step 5: Lancer, vérifier le succès**

Run: `php artisan test --filter=RoomBookingTest`
Expected: PASS.

- [ ] **Step 6: Câbler le widget front**

In `resources/js/widget/types.ts`, add `room?: string | null` to `BookingPayload` (after `consent: boolean`):

```ts
    consent: boolean
    room?: string | null
    website?: string // honeypot
```

In `resources/js/widget/steps/FormStep.vue`:

Add to the `reactive` form (after `notes_parent: '', consent: false, website: '',`):

```ts
    room: null as string | null,
```

Define the room options + picker. Add to `<script setup>` (after the `form` declaration):

```ts
const rooms = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]
```

> Note : le widget est un bundle IIFE autonome (Shadow DOM, alias `@widget`), il
> n'importe PAS depuis `@/components`. On inline donc la rangée de pastilles ici (le
> composant `RoomPicker` partagé sert au formulaire staff Inertia). Les couleurs
> proviennent de la même liste que `Room::options()` — à garder synchronisées.

Add the picker markup inside the `<form>` (before the honeypot input, after the `notes_parent` textarea):

```html
        <fieldset class="mb-4">
            <legend class="font-medium mb-2">Welches Zimmer möchtest du? (optional)</legend>
            <div class="flex gap-2" role="group" aria-label="Zimmerfarbe">
                <button v-for="r in rooms" :key="r.value" type="button"
                        :title="r.label" :aria-label="r.label" :aria-pressed="form.room === r.value"
                        class="h-9 w-9 rounded-full border border-slate-300"
                        :class="form.room === r.value ? 'ring-2 ring-offset-2 ring-slate-700' : ''"
                        :style="{ backgroundColor: r.color }"
                        @click="form.room = form.room === r.value ? null : r.value">
                </button>
            </div>
        </fieldset>
```

(`form.room` flows automatically: `submit()` emits `{ ...form }` → `App.vue onSubmit` merges it into `api.book`.)

- [ ] **Step 7: Test widget + build**

Run: `npm run test:widget && npm run build:widget`
Expected: tests PASS, build du widget OK.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Widget/StoreAppointmentRequest.php app/Http/Controllers/Widget/AppointmentController.php resources/js/widget/types.ts resources/js/widget/steps/FormStep.vue tests/Feature/TenantSchema/RoomBookingTest.php
git commit -m "feat(widget): optional room choice in booking flow"
```

---

### Task 7: Choix de salle côté staff (AppointmentForm)

**Files:**
- Modify: `app/Http/Requests/Tenant/StoreManualAppointmentRequest.php`, `app/Http/Requests/Tenant/UpdateAppointmentRequest.php`
- Modify: `app/Http/Controllers/Tenant/AppointmentController.php` (le tableau passé à `$scheduler->create`)
- Modify: `resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`
- Modify: `resources/js/lib/calendar.ts` (ajouter `room` au DTO — fait aussi en Task 8)
- Test: `tests/Feature/TenantSchema/RoomBookingTest.php`

- [ ] **Step 1: Test — store/update staff persiste `room`**

Append to `tests/Feature/TenantSchema/RoomBookingTest.php`:

```php
use App\Models\User;

it('persists room on a manual staff booking', function () {
    $service = ServiceFactory::new()->create(['duration_minutes' => 30]);
    $practitioner = PractitionerFactory::new()->create();

    $this->actingAs(User::factory()->create())
        ->postJson('/termine', [
            'practitioner_id' => $practitioner->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
            'patient_birthdate' => '2018-05-01',
            'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
            'parent_phone' => '030 123', 'room' => 'green',
        ])->assertCreated();

    expect(Appointment::first()->room)->toBe(Room::Green);
});

it('rejects an invalid room on a manual staff booking', function () {
    $service = ServiceFactory::new()->create(['duration_minutes' => 30]);
    $practitioner = PractitionerFactory::new()->create();

    $this->actingAs(User::factory()->create())
        ->postJson('/termine', [
            'practitioner_id' => $practitioner->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
            'patient_birthdate' => '2018-05-01',
            'parent_first_name' => 'Eva', 'parent_last_name' => 'Muster',
            'parent_phone' => '030 123', 'room' => 'rouge',
        ])->assertStatus(422)->assertJsonValidationErrors('room');
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `php artisan test --filter=RoomBookingTest`
Expected: FAIL (room non validé/persisté côté staff).

- [ ] **Step 3: Valider `room` dans les deux Form Requests**

In `app/Http/Requests/Tenant/StoreManualAppointmentRequest.php`, add inside `rules()` (after `'notes_internal' => ['nullable', 'string'],`):

```php
            'room' => ['nullable', 'in:green,yellow,peach,blue,purple'],
```

In `app/Http/Requests/Tenant/UpdateAppointmentRequest.php`, add inside `rules()` (after `'notes_internal' => ['sometimes', 'nullable', 'string'],`):

```php
            'room' => ['sometimes', 'nullable', 'in:green,yellow,peach,blue,purple'],
```

- [ ] **Step 4: Persister `room` dans le `store` staff**

In `app/Http/Controllers/Tenant/AppointmentController.php`, inside `store()`'s `$scheduler->create([...])` array, add (after `'parent_consent_at' => null,`):

```php
            'room' => $data['room'] ?? null,
```

> `update()` passes `$data` (which now includes `room` when present) straight to
> `$scheduler->reschedule()`, so room updates flow through mass assignment (it is
> `$fillable`). No extra change needed there.

- [ ] **Step 5: Lancer, vérifier le succès**

Run: `php artisan test --filter=RoomBookingTest`
Expected: PASS.

- [ ] **Step 6: Ajouter le `RoomPicker` au formulaire staff**

In `resources/js/Pages/Tenant/Appointments/AppointmentForm.vue`:

Import the picker + options (after the existing imports):

```ts
import RoomPicker from '@/components/ui/RoomPicker.vue'

const rooms = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]
```

Add `room` to the `reactive` form (after `notes_internal: '',`):

```ts
    room: null as string | null,
```

In the edit branch `Object.assign(form, {...})`, add (after `notes_internal: a.notes_internal ?? '',`):

```ts
        room: a.room ?? null,
```

In the create branch `Object.assign(form, {...})`, add (after `parent_email: '', notes_internal: '',`):

```ts
        room: null,
```

Add the picker field in the template (after the `Interne Notiz` label block, inside the grid):

```html
                <div class="col-span-2 text-sm">
                    <span class="block mb-1">Zimmer (optional)</span>
                    <RoomPicker v-model="form.room" :rooms="rooms" />
                </div>
```

- [ ] **Step 7: Vérifier le build**

Run: `npm run build`
Expected: build OK (RoomPicker importé, `a.room` typé — voir Task 8 pour le DTO).

> Si le type `AppointmentDto.room` n'existe pas encore, faire d'abord le Step 1 de
> Task 8 (ajout du champ au DTO) puis revenir au build.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Tenant/StoreManualAppointmentRequest.php app/Http/Requests/Tenant/UpdateAppointmentRequest.php app/Http/Controllers/Tenant/AppointmentController.php resources/js/Pages/Tenant/Appointments/AppointmentForm.vue tests/Feature/TenantSchema/RoomBookingTest.php
git commit -m "feat(staff): room picker on manual appointment form"
```

---

### Task 8: Calendrier coloré par salle

**Files:**
- Modify: `app/Http/Controllers/Tenant/AppointmentController.php:156` (`toDto`)
- Modify: `resources/js/lib/calendar.ts`
- Modify: `resources/js/Pages/Tenant/Appointments/Calendar.vue`
- Test: `tests/widget/calendar.test.ts`, `tests/Feature/TenantSchema/RoomBookingTest.php`

- [ ] **Step 1: Étendre le DTO + le mapper (test front d'abord)**

In `tests/widget/calendar.test.ts`, add (adapter au style du fichier existant — voici les assertions à couvrir) :

```ts
import { describe, it, expect } from 'vitest'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'

const base = (room: string | null): AppointmentDto => ({
  id: 'a1', starts_at: '2026-06-04T09:00:00+02:00', ends_at: '2026-06-04T09:30:00+02:00',
  status: 'confirmed', patient_first_name: 'Max', patient_last_name: 'Muster',
  patient_birthdate: null, parent_first_name: 'Eva', parent_last_name: 'Muster',
  parent_email: null, parent_phone: null, notes_internal: null, room,
  practitioner: { id: 1, name: 'Dr. X', color: '#123456' },
  service: { id: 1, name: 'Kontrolle', duration_minutes: 30 },
})

describe('toCalendarEvent room coloring', () => {
  it('fills with the room color and borders with the practitioner color', () => {
    const e = toCalendarEvent(base('blue'))
    expect(e.backgroundColor).toBe('#98ACBA')
    expect(e.borderColor).toBe('#123456')
    expect(e.textColor).toBe('#1E293B')
  })

  it('falls back to neutral slate when no room is chosen', () => {
    expect(toCalendarEvent(base(null)).backgroundColor).toBe('#E2E8F0')
  })
})
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `npm run test:widget -- calendar`
Expected: FAIL (`room` absent du DTO / pas de `textColor`).

- [ ] **Step 3: Mettre à jour `lib/calendar.ts`**

In `resources/js/lib/calendar.ts`:

Add a room→color map at the top of the file (after the imports/interfaces start):

```ts
const ROOM_COLORS: Record<string, string> = {
  green: '#BDCCC2', yellow: '#F7E29D', peach: '#FCE8E1', blue: '#98ACBA', purple: '#CCC8CE',
}
const NEUTRAL = '#E2E8F0' // slate-200 — no room chosen
```

Add `room: string | null` to `AppointmentDto` (after `notes_internal: string | null`).

Add `textColor: string` to the `CalendarEvent` interface (after `borderColor: string`).

Rewrite the return of `toCalendarEvent` so fill = room, border = practitioner, text = slate-800:

```ts
    return {
        id: a.id,
        title: `${a.patient_first_name} ${lastInitial} — ${a.service.name}`.replace(/\s+—/, ' —'),
        start: a.starts_at,
        end: a.ends_at,
        backgroundColor: a.room ? (ROOM_COLORS[a.room] ?? NEUTRAL) : NEUTRAL,
        borderColor: a.practitioner.color,
        textColor: '#1E293B',
        extendedProps: a,
    }
```

- [ ] **Step 4: Exposer `room` dans `toDto` (backend)**

In `app/Http/Controllers/Tenant/AppointmentController.php`, inside `toDto()`, add (after `'notes_internal' => $a->notes_internal,`):

```php
            'room' => $a->room?->value,
```

- [ ] **Step 5: Test backend — le feed renvoie `room`**

Append to `tests/Feature/TenantSchema/RoomBookingTest.php`:

```php
it('exposes room in the calendar events feed', function () {
    $a = AppointmentFactory::new()->create(['room' => 'purple', 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create())
        ->getJson('/termine/events?start=' . $a->starts_at->copy()->subDay()->toDateString()
            . '&end=' . $a->ends_at->copy()->addDay()->toDateString()
            . '&practitioner_ids[]=' . $a->practitioner_id)
        ->assertOk()
        ->assertJsonFragment(['room' => 'purple']);
});
```

- [ ] **Step 6: Lancer les deux suites**

Run: `npm run test:widget -- calendar && php artisan test --filter=RoomBookingTest`
Expected: PASS.

- [ ] **Step 7: Légende + habillage dans `Calendar.vue`**

In `resources/js/Pages/Tenant/Appointments/Calendar.vue`:

Import the legend + options (after existing imports):

```ts
import RoomLegend from '@/components/ui/RoomLegend.vue'

const rooms = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]
```

Restyle the calendar card and append the legend — replace the existing
`<div class="bg-white rounded shadow p-4">…</div>` block with:

```html
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </div>

        <div class="mt-4">
            <h2 class="text-sm font-medium text-slate-500 mb-2">Zimmer</h2>
            <RoomLegend :rooms="rooms" />
        </div>
```

- [ ] **Step 8: Build + commit**

Run: `npm run build`
Expected: build OK.

```bash
git add app/Http/Controllers/Tenant/AppointmentController.php resources/js/lib/calendar.ts resources/js/Pages/Tenant/Appointments/Calendar.vue tests/widget/calendar.test.ts tests/Feature/TenantSchema/RoomBookingTest.php
git commit -m "feat(calendar): color events by room (fill) + practitioner (border)"
```

---

## Phase 3 — Dashboard moderne

### Task 9: `DashboardController` — agrégats

**Files:**
- Modify: `app/Http/Controllers/Tenant/DashboardController.php`
- Test: `tests/Feature/TenantSchema/DashboardTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/TenantSchema/DashboardTest.php`:

```php
<?php

use App\Models\User;
use App\Models\Tenant\Appointment;
use Database\Factories\Tenant\AppointmentFactory;
use Database\Factories\Tenant\PractitionerFactory;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects a guest to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('returns today and week counts to staff', function () {
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->count(2)->create(['starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dashboard')
            ->where('stats.todayCount', 2)
            ->has('todayAppointments', 2)
            ->has('rooms', 5)
        );
});

it('filters today list to the linked practitioner for a medecin', function () {
    $mine = PractitionerFactory::new()->create();
    $other = PractitionerFactory::new()->create();
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->create(['practitioner_id' => $mine->id, 'starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);
    AppointmentFactory::new()->create(['practitioner_id' => $other->id, 'starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $medecin = User::factory()->create(['role' => 'medecin', 'practitioner_id' => $mine->id]);

    $this->actingAs($medecin)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('todayAppointments', 1));
});

it('shows all appointments to an unlinked medecin (graceful degradation)', function () {
    $today = now('Europe/Berlin')->setTime(9, 0);
    AppointmentFactory::new()->count(2)->create(['starts_at' => $today, 'ends_at' => $today->copy()->addMinutes(30), 'status' => 'confirmed']);

    $medecin = User::factory()->create(['role' => 'medecin', 'practitioner_id' => null]);

    $this->actingAs($medecin)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('todayAppointments', 2));
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL (props absentes).

- [ ] **Step 3: Implémenter le contrôleur**

Replace `app/Http/Controllers/Tenant/DashboardController.php` with:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Support\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const TZ = 'Europe/Berlin';

    public function index(Request $request): Response
    {
        $user = $request->user();
        $now = CarbonImmutable::now(self::TZ);
        $dayStart = $now->startOfDay();
        $dayEnd = $now->endOfDay();
        $weekStart = $now->startOfWeek();
        $weekEnd = $now->endOfWeek();

        // A medecin linked to a fiche sees only their RDV; everyone else (and an
        // unlinked medecin) sees all — graceful degradation, never a hard block.
        $practitionerId = $user->isMedecin() ? $user->practitioner_id : null;

        $scoped = fn ($q) => $q
            ->where('status', '!=', 'cancelled')
            ->when($practitionerId, fn ($qq) => $qq->where('practitioner_id', $practitionerId));

        $todayCount = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$dayStart, $dayEnd])->count();

        $weekCount = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$weekStart, $weekEnd])->count();

        $todayAppointments = $scoped(Appointment::query())
            ->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->with(['service', 'practitioner'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'time' => $a->starts_at->format('H:i'),
                'patient' => trim($a->patient_first_name . ' ' . mb_substr($a->patient_last_name, 0, 1) . '.'),
                'service' => $a->service->name,
                'room' => $a->room?->value,
                'practitioner' => ['name' => $a->practitioner->fullName(), 'color' => $a->practitioner->color],
            ])->all();

        $next = $scoped(Appointment::query())
            ->where('starts_at', '>=', $now)
            ->with('service')
            ->orderBy('starts_at')
            ->first();

        return Inertia::render('Tenant/Dashboard', [
            'role' => $user->role,
            'practitioner' => $user->practitioner
                ? ['id' => $user->practitioner->id, 'name' => $user->practitioner->fullName(), 'color' => $user->practitioner->color]
                : null,
            'stats' => [
                'todayCount' => $todayCount,
                'weekCount' => $weekCount,
                'activePractitioners' => Practitioner::where('is_active', true)->count(),
                'nextAppointment' => $next ? [
                    'time' => $next->starts_at->format('H:i'),
                    'patient' => trim($next->patient_first_name . ' ' . mb_substr($next->patient_last_name, 0, 1) . '.'),
                    'service' => $next->service->name,
                ] : null,
            ],
            'todayAppointments' => $todayAppointments,
            'rooms' => Room::options(),
        ]);
    }
}
```

- [ ] **Step 4: Lancer, vérifier le succès**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Tenant/DashboardController.php tests/Feature/TenantSchema/DashboardTest.php
git commit -m "feat(dashboard): aggregate stats + role-scoped today list"
```

---

### Task 10: `StatCard` + reconstruction `Dashboard.vue` (skill `frontend-design`)

**Files:**
- Create: `resources/js/components/ui/StatCard.vue`
- Modify: `resources/js/Pages/Tenant/Dashboard.vue`

> **REQUIRED SUB-SKILL pour ce task : `frontend-design`.** Le dashboard doit être
> distinctif et soigné (identité pédiatrique, charte 5 pastels, formes douces,
> espacements généreux, micro-interactions discrètes) — pas un template générique.
> Le balisage ci-dessous est un **squelette fonctionnel de départ** ; l'agent doit
> l'élever au niveau `frontend-design` tout en gardant les props/structure de données.

- [ ] **Step 1: Créer `StatCard.vue`**

Create `resources/js/components/ui/StatCard.vue`:

```vue
<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
  icon: Component
  value: string | number
  label: string
  // Tailwind background class for the icon pill, e.g. 'bg-kids-blue'
  color: string
}>()
</script>

<template>
  <div class="rounded-2xl bg-white border border-slate-100 shadow-sm p-5 flex items-center gap-4">
    <div class="h-12 w-12 rounded-2xl flex items-center justify-center shrink-0" :class="color">
      <component :is="icon" class="h-6 w-6 text-slate-700" :stroke-width="1.75" />
    </div>
    <div>
      <div class="text-2xl font-bold text-slate-800 leading-tight">{{ value }}</div>
      <div class="text-sm text-slate-500">{{ label }}</div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Reconstruire `Dashboard.vue` (squelette à élever)**

Replace `resources/js/Pages/Tenant/Dashboard.vue` with:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { CalendarDays, CalendarRange, Clock, Stethoscope, Plus, Calendar, QrCode } from 'lucide-vue-next'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import StatCard from '@/components/ui/StatCard.vue'
import RoomLegend from '@/components/ui/RoomLegend.vue'

defineOptions({ layout: TenantLayout })

interface RoomOption { value: string; color: string; label: string }
interface TodayRow { id: string; time: string; patient: string; service: string; room: string | null; practitioner: { name: string; color: string } }

const props = defineProps<{
  role: string
  practitioner: { id: number; name: string; color: string } | null
  stats: {
    todayCount: number
    weekCount: number
    activePractitioners: number
    nextAppointment: { time: string; patient: string; service: string } | null
  }
  todayAppointments: TodayRow[]
  rooms: RoomOption[]
}>()

const roomColor = (value: string | null) =>
  props.rooms.find((r) => r.value === value)?.color ?? '#E2E8F0'

const greeting = computed(() =>
  props.practitioner ? `Guten Tag, ${props.practitioner.name}` : 'Guten Tag · Empfang',
)

const today = new Date().toLocaleDateString('de-DE', {
  weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
})

const nextLabel = computed(() => props.stats.nextAppointment
  ? `${props.stats.nextAppointment.time} ${props.stats.nextAppointment.patient}`
  : '—')
</script>

<template>
  <Head title="Dashboard" />
  <div class="p-8 space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-3">
        <span v-if="practitioner" class="inline-block h-4 w-4 rounded-full" :style="{ background: practitioner.color }"></span>
        <h1 class="text-2xl font-bold text-slate-800">{{ greeting }}</h1>
      </div>
      <p class="text-slate-500">{{ today }}</p>
    </header>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard :icon="CalendarDays" :value="`${stats.todayCount} Termine`" label="Heute" color="bg-kids-blue" />
      <StatCard :icon="CalendarRange" :value="`${stats.weekCount} Termine`" label="Diese Woche" color="bg-kids-green" />
      <StatCard :icon="Clock" :value="nextLabel" label="Nächster Termin" color="bg-kids-yellow" />
      <StatCard :icon="Stethoscope" :value="`${stats.activePractitioners} aktiv`" label="Behandler" color="bg-kids-peach" />
    </section>

    <section class="grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 rounded-2xl bg-white border border-slate-100 shadow-sm p-5">
        <h2 class="font-bold text-slate-800 mb-4">Termine heute</h2>
        <ul v-if="todayAppointments.length" class="divide-y divide-slate-100">
          <li v-for="row in todayAppointments" :key="row.id" class="flex items-center gap-3 py-3">
            <span class="font-medium text-slate-700 w-14">{{ row.time }}</span>
            <span class="inline-block h-3 w-3 rounded-full border border-slate-300" :style="{ background: roomColor(row.room) }"></span>
            <span class="flex-1 text-slate-800">{{ row.patient }} · {{ row.service }}</span>
            <span class="inline-block h-3 w-3 rounded-full" :style="{ background: row.practitioner.color }" :title="row.practitioner.name"></span>
          </li>
        </ul>
        <p v-else class="text-slate-400 py-8 text-center">Heute keine Termine. 🦷</p>
      </div>

      <aside class="space-y-4">
        <div class="rounded-2xl bg-white border border-slate-100 shadow-sm p-5">
          <h2 class="font-bold text-slate-800 mb-3">Schnellzugriff</h2>
          <div class="space-y-2">
            <Link href="/termine" class="flex items-center gap-2 rounded-xl px-3 py-2 hover:bg-kids-blue/20 text-slate-700">
              <Plus class="h-5 w-5" :stroke-width="1.75" /> Neuer Termin
            </Link>
            <Link href="/termine" class="flex items-center gap-2 rounded-xl px-3 py-2 hover:bg-kids-blue/20 text-slate-700">
              <Calendar class="h-5 w-5" :stroke-width="1.75" /> Kalender öffnen
            </Link>
            <Link href="/termin-qr-code" class="flex items-center gap-2 rounded-xl px-3 py-2 hover:bg-kids-blue/20 text-slate-700">
              <QrCode class="h-5 w-5" :stroke-width="1.75" /> QR-Code
            </Link>
          </div>
        </div>
        <div class="rounded-2xl bg-white border border-slate-100 shadow-sm p-5">
          <h2 class="font-bold text-slate-800 mb-3">Zimmer</h2>
          <RoomLegend :rooms="rooms" />
        </div>
      </aside>
    </section>
  </div>
</template>
```

- [ ] **Step 3: Élever le design (skill `frontend-design`)**

Invoke `frontend-design` and refine `Dashboard.vue` + `StatCard.vue`: identité pédiatrique
distinctive, hiérarchie typographique, usage subtil des 5 pastels, états vides soignés,
focus visibles, responsive mobile-first. Conserver les props et la forme des données.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: build OK (toutes les icônes Lucide importées existent).

- [ ] **Step 5: Vérification visuelle (Chrome)**

Run: `php artisan serve` puis ouvrir `/dashboard` connecté.
Expected: dashboard moderne, KPIs corrects, liste du jour colorée par salle.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/ui/StatCard.vue resources/js/Pages/Tenant/Dashboard.vue
git commit -m "feat(dashboard): modern KidsClub dashboard with Lucide + pastels"
```

---

## Phase 4 — Sidebar modernisée

### Task 11: `TenantLayout` — nav Lucide + libellé de rôle

**Files:**
- Modify: `resources/js/Layouts/TenantLayout.vue`

- [ ] **Step 1: Réécrire le layout**

Replace `resources/js/Layouts/TenantLayout.vue` with:

```vue
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  LayoutDashboard, CalendarDays, Stethoscope, ClipboardList,
  Clock, TreePalm, QrCode, LogOut,
} from 'lucide-vue-next'

const page = usePage()
const tenantName = computed(() => (page.props as any).app_name ?? 'KidsClub')
const user = computed(() => (page.props as any).auth?.user)

const roleLabel = computed(() => {
  const u = user.value
  if (!u) return ''
  if (u.role === 'medecin') return u.practitioner?.name ?? 'Behandler'
  return 'Réception'
})

const logout = () => router.post('/logout')

const nav = [
  { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { href: '/termine', label: 'Termine', icon: CalendarDays },
  { href: '/behandler', label: 'Behandler', icon: Stethoscope },
  { href: '/leistungen', label: 'Leistungen', icon: ClipboardList },
  { href: '/sprechzeiten', label: 'Sprechzeiten', icon: Clock },
  { href: '/abwesenheiten', label: 'Abwesenheiten', icon: TreePalm },
  { href: '/termin-qr-code', label: 'QR-Code', icon: QrCode },
]

const isActive = (href: string) => {
  const url = (page as any).url ?? ''
  return url === href || url.startsWith(href + '/')
}
</script>

<template>
  <div class="min-h-screen flex bg-slate-50">
    <aside class="w-64 bg-white border-r border-slate-100 p-6 flex flex-col">
      <h2 class="text-xl font-bold mb-8" style="color:#7d93a3">{{ tenantName }}</h2>
      <nav class="space-y-1 flex-1">
        <Link v-for="item in nav" :key="item.href" :href="item.href"
              class="flex items-center gap-3 px-3 py-2 rounded-xl text-slate-600 hover:bg-kids-blue/20 transition"
              :class="isActive(item.href) ? 'bg-kids-blue/20 text-slate-800 font-medium' : ''">
          <component :is="item.icon" class="h-5 w-5" :stroke-width="1.75" />
          {{ item.label }}
        </Link>
      </nav>
      <div class="mt-6">
        <div class="text-sm font-medium text-slate-700">{{ roleLabel }}</div>
        <div class="text-xs text-slate-500 mb-2">{{ user?.email }}</div>
        <button @click="logout" class="flex items-center gap-1 text-sm text-red-600 hover:underline">
          <LogOut class="h-4 w-4" /> Abmelden
        </button>
      </div>
    </aside>
    <main class="flex-1"><slot /></main>
  </div>
</template>
```

> **Dépendance de données :** `roleLabel` lit `user.role` et `user.practitioner.name`.
> Vérifier que le partage Inertia de `auth.user` (dans `HandleInertiaRequests` ou le
> middleware d'auth) expose `role` et, si lié, `practitioner` (`{name}`). Sinon, ajouter
> `$request->user()?->load('practitioner')` + ces champs au payload partagé. (Si non
> exposés, `roleLabel` retombe sur 'Réception'/'Behandler' — dégradation propre.)

- [ ] **Step 2: Exposer `role`/`practitioner` à Inertia (si absent)**

Check `app/Http/Middleware/HandleInertiaRequests.php` `share()`. Ensure the shared
`auth.user` includes `role` and an optional `practitioner` name. If it currently shares
the raw user, add:

```php
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'practitioner' => $request->user()->practitioner
                        ? ['name' => $request->user()->practitioner->fullName()]
                        : null,
                ] : null,
            ],
```

- [ ] **Step 3: Build + vérif**

Run: `npm run build`
Expected: build OK.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/TenantLayout.vue app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat(ui): modern sidebar with Lucide icons + role label"
```

---

## Phase 5 — Seeder, suite complète & docs

### Task 12: Seeder — utilisateurs/rôles + rooms de démo

**Files:**
- Modify: `database/seeders/KidsClubSeeder.php`

- [ ] **Step 1: Lire le seeder actuel**

Run: `sed -n '1,200p' database/seeders/KidsClubSeeder.php`
Expected: comprendre comment praticiens/services/RDV sont créés.

- [ ] **Step 2: Ajouter 2 users (médecin lié + secrétaire) + rooms variés**

Add, near the start of `run()` (after practitioners are created — adapter aux variables
réelles du fichier, p.ex. `$practitioner`/`$practitioners->first()`):

```php
        // Demo staff (LOCAL/SEED ONLY — never a predictable password in prod).
        \App\Models\User::firstOrCreate(
            ['email' => 'arzt@kidsclub.test'],
            ['name' => 'Dr. Müller', 'password' => bcrypt('password'),
             'role' => 'medecin', 'practitioner_id' => $practitioners->first()->id],
        );
        \App\Models\User::firstOrCreate(
            ['email' => 'empfang@kidsclub.test'],
            ['name' => 'Empfang', 'password' => bcrypt('password'), 'role' => 'secretaire'],
        );
```

When creating demo appointments, spread rooms across the palette + leave some null, e.g.:

```php
        $rooms = ['green', 'yellow', 'peach', 'blue', 'purple', null];
        // inside the appointment-creation loop, index $i:
        // 'room' => $rooms[$i % count($rooms)],
```

- [ ] **Step 3: Re-seed et vérifier**

Run: `php artisan migrate:fresh --seed`
Expected: seed OK ; `arzt@kidsclub.test` lié à un praticien, RDV de démo colorés.

- [ ] **Step 4: Commit**

```bash
git add database/seeders/KidsClubSeeder.php
git commit -m "feat(seed): demo medecin/secretaire users + varied room colors"
```

---

### Task 13: Suite complète + docs de référence

**Files:**
- Modify: `public/wireframe.html`, `docs/database-diagram.html` (+ copie `public/`), `app/Services/ProjectProgressService.php`

- [ ] **Step 1: Lancer toute la suite backend**

Run: `composer test`
Expected: PASS (tous verts).

- [ ] **Step 2: Lancer la suite widget/front**

Run: `npm run test:widget`
Expected: PASS.

- [ ] **Step 3: Build complet (app + widget)**

Run: `npm run build && npm run build:widget`
Expected: les deux builds OK.

- [ ] **Step 4: Mettre à jour le diagramme BDD**

In `docs/database-diagram.html`: add `appointments.room` (string, nullable),
`users.role` (string, default secretaire), `users.practitioner_id` (FK nullable →
practitioners). Then copy to public:

Run: `cp docs/database-diagram.html public/database-diagram.html`

- [ ] **Step 5: Mettre à jour le wireframe**

In `public/wireframe.html`: add screens for the modern dashboard, the room picker
(widget + staff form), and the modernized sidebar.

- [ ] **Step 6: Mettre à jour `ProjectProgressService`**

Add a "Dashboard / Rôles / Couleurs" module with items + checks across the 5 layers
(models: `Room`, `User.role`; controllers: `DashboardController`; pages: `Dashboard.vue`,
`Calendar.vue`, `FormStep.vue`; migrations: the two new ones; tests: `DashboardTest`,
`UserRoleTest`, `RoomBookingTest`).

- [ ] **Step 7: Commit**

```bash
git add public/wireframe.html docs/database-diagram.html public/database-diagram.html app/Services/ProjectProgressService.php
git commit -m "docs: update DB diagram, wireframe & progress for dashboard/roles/rooms"
```

---

## Vérification finale (avant PR)

- [ ] `composer test` — vert
- [ ] `npm run test:widget` — vert
- [ ] `npm run type-check` — vert (aucune erreur de types)
- [ ] `npm run build && npm run build:widget` — OK
- [ ] `vendor/bin/pint` — style PHP propre
- [ ] Vérif Chrome : dashboard (`/dashboard`), calendrier coloré par salle (`/termine`),
      widget avec le sélecteur de salle, sidebar modernisée + libellé de rôle.
- [ ] Code review (pr-review-toolkit:code-reviewer) sur le diff complet de la branche.
- [ ] Docs de référence à jour (diagramme BDD, wireframe, ProjectProgressService).
