# Warteliste (Liste d'attente) V1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre aux parents de s'inscrire sur une liste d'attente via le widget quand il n'y a plus de créneau disponible, et donner au cabinet une page admin pour gérer ces demandes.

**Architecture:** Nouvelle table `waitlist_entries` (uuid, statut, contact parent, service souhaité). L'API widget `POST /api/v1/widget/warteliste` crée l'entrée et notifie le cabinet par e-mail (pattern `CabinetNotifier`). Le widget affiche une nouvelle étape `WaitlistStep.vue` accessible depuis l'écran « aucun créneau ». La page admin `/warteliste` liste et permet de changer le statut des entrées. Un badge de comptage `pending` dans le layout partage le compteur via `HandleInertiaRequests`.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 (`<script setup lang="ts">`), Tailwind 3, Pest 4, PostgreSQL, widget IIFE (Vite séparé).

## Global Constraints

- **PostgreSQL** est la cible réelle (tests forcent `DB_CONNECTION=pgsql`).
- **URLs allemandes, noms de route anglais** — `/warteliste` → `tenant.waitlist.index` / `tenant.waitlist.update`. Jamais de chemin hardcodé.
- **Widget** : IIFE séparé (`npm run build:widget`), Shadow DOM, alias `@widget`. L'étape `waitlist` est **latérale** (hors `ORDER` du flux normal `termin|kind|form|confirm|success`). On y va via `w.go('waitlist')`, pas via `w.advance()`.
- **Rate-limit `widget-book`** (5/min IP + 30/min global) sur l'endpoint `POST /api/v1/widget/warteliste`.
- **`HasUuids`** sur le modèle `WaitlistEntry` (pattern `Appointment`).
- **Notification cabinet = pattern `CabinetNotifier`** : `rescue()` + `->queue()` (ShouldQueue), saut silencieux si pas de `PRACTICE_NOTIFICATION_EMAIL`.
- **`status` casté en enum `WaitlistStatus`**, hors `$fillable` (comme `attendance`) → assigné directement.
- **`pendingCount`** partagé dans `HandleInertiaRequests::share()` comme `'waitlist_pending_count'` (lambda pour éviter N+1 sur routes non-auth).
- **Tests Pest** style `it(...)` ; `composer test` reste vert.

---

### Task 1: Migration + modèle + enum + mailable + CabinetNotifier + API widget + tests

**Files:**
- Create: `backend/database/migrations/2026_06_23_000001_create_waitlist_entries_table.php`
- Create: `backend/app/Support/WaitlistStatus.php`
- Create: `backend/app/Models/WaitlistEntry.php`
- Create: `backend/app/Mail/WaitlistEntryMail.php`
- Create: `backend/resources/views/emails/waitlist-entry.blade.php`
- Modify: `backend/app/Support/CabinetNotifier.php` (ajouter `notifyWaitlist`)
- Create: `backend/app/Http/Requests/Widget/StoreWaitlistRequest.php`
- Create: `backend/app/Http/Controllers/Widget/WaitlistController.php`
- Modify: `backend/routes/api.php` (route POST)
- Test: `backend/tests/Feature/Widget/WaitlistApiTest.php`

**Interfaces:**
- Produces: `App\Models\WaitlistEntry` (attrs: `id uuid`, `patient_first_name`, `patient_last_name`, `parent_first_name`, `parent_last_name`, `parent_phone`, `parent_email nullable`, `service_id nullable`, `notes nullable`, `status: WaitlistStatus`, `created_at`) ; `POST /api/v1/widget/warteliste` → 201 `{ message: 'Auf der Warteliste eingetragen.' }` ; `CabinetNotifier::notifyWaitlist(WaitlistEntry $entry): void`.

- [ ] **Step 1: Write the failing tests**

Créer `backend/tests/Feature/Widget/WaitlistApiTest.php` :

```php
<?php

use App\Mail\WaitlistEntryMail;
use App\Models\Tenant\Service;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

it('stores a waitlist entry with all fields and returns 201', function () {
    $service = Service::factory()->create();

    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Emma',
        'patient_last_name'  => 'Müller',
        'parent_first_name'  => 'Katrin',
        'parent_last_name'   => 'Müller',
        'parent_phone'       => '+49 160 1234567',
        'parent_email'       => 'katrin@example.com',
        'service_id'         => $service->id,
        'notes'              => 'So früh wie möglich',
    ])
        ->assertStatus(201)
        ->assertJson(['message' => 'Auf der Warteliste eingetragen.']);

    expect(WaitlistEntry::count())->toBe(1);
    $e = WaitlistEntry::first();
    expect($e->patient_first_name)->toBe('Emma');
    expect($e->parent_phone)->toBe('+49 160 1234567');
    expect($e->status->value)->toBe('pending');
});

it('stores a waitlist entry without optional fields (email, service, notes)', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Lina',
        'patient_last_name'  => 'Schmidt',
        'parent_first_name'  => 'Anna',
        'parent_last_name'   => 'Schmidt',
        'parent_phone'       => '+49 170 9876543',
    ])
        ->assertStatus(201);

    $e = WaitlistEntry::first();
    expect($e->parent_email)->toBeNull();
    expect($e->service_id)->toBeNull();
});

it('rejects a request without parent_phone (required field)', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name'  => 'Becker',
        'parent_first_name'  => 'Tom',
        'parent_last_name'   => 'Becker',
        // parent_phone missing
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_phone']);
});

it('rejects a non-existent service_id', function () {
    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Max',
        'patient_last_name'  => 'Becker',
        'parent_first_name'  => 'Tom',
        'parent_last_name'   => 'Becker',
        'parent_phone'       => '+49 170 000',
        'service_id'         => 9999,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_id']);
});

it('queues a cabinet notification email on successful registration', function () {
    Service::factory()->create()->tap(function ($s) {
        $this->postJson('/api/v1/widget/warteliste', [
            'patient_first_name' => 'Emma',
            'patient_last_name'  => 'Müller',
            'parent_first_name'  => 'Katrin',
            'parent_last_name'   => 'Müller',
            'parent_phone'       => '+49 160 1234567',
            'service_id'         => $s->id,
        ])->assertStatus(201);
    });

    Mail::assertQueued(WaitlistEntryMail::class);
});

it('sends no notification email if PRACTICE_NOTIFICATION_EMAIL is not configured', function () {
    config(['mail.practice_notification_address' => null]);

    $this->postJson('/api/v1/widget/warteliste', [
        'patient_first_name' => 'Emma',
        'patient_last_name'  => 'Müller',
        'parent_first_name'  => 'Katrin',
        'parent_last_name'   => 'Müller',
        'parent_phone'       => '+49 160 1234567',
    ])->assertStatus(201);

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=WaitlistApiTest`
Expected: FAIL (table `waitlist_entries` n'existe pas).

- [ ] **Step 3: Create the migration**

`backend/database/migrations/2026_06_23_000001_create_waitlist_entries_table.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('patient_first_name');
            $table->string('patient_last_name');
            $table->string('parent_first_name');
            $table->string('parent_last_name');
            $table->string('parent_phone');
            $table->string('parent_email')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
```

- [ ] **Step 4: Create the enum**

`backend/app/Support/WaitlistStatus.php` :

```php
<?php

namespace App\Support;

enum WaitlistStatus: string
{
    case Pending   = 'pending';
    case Contacted = 'contacted';
    case Booked    = 'booked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Ausstehend',
            self::Contacted => 'Kontaktiert',
            self::Booked    => 'Gebucht',
            self::Cancelled => 'Storniert',
        };
    }

    /** @return array<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
```

- [ ] **Step 5: Create the model**

`backend/app/Models/WaitlistEntry.php` :

```php
<?php

namespace App\Models;

use App\Support\WaitlistStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant\Service;

class WaitlistEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_first_name', 'patient_last_name',
        'parent_first_name', 'parent_last_name',
        'parent_phone', 'parent_email',
        'service_id', 'notes',
        // status is intentionally NOT fillable — set by direct assignment (staff-only)
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
        ];
    }

    protected $attributes = ['status' => 'pending'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
```

> Note: Il n'y a pas encore de factory pour `WaitlistEntry`. Les tests Task 1 créent les entrées via `postJson`. Pour Task 3 (admin), créer `database/factories/WaitlistEntryFactory.php` :
>
> ```php
> <?php
> namespace Database\Factories;
> use App\Models\WaitlistEntry;
> use Illuminate\Database\Eloquent\Factories\Factory;
> class WaitlistEntryFactory extends Factory {
>     protected $model = WaitlistEntry::class;
>     public function definition(): array {
>         return [
>             'patient_first_name' => 'Emma',
>             'patient_last_name'  => 'Test',
>             'parent_first_name'  => 'Katrin',
>             'parent_last_name'   => 'Test',
>             'parent_phone'       => '+49 160 1234567',
>             'parent_email'       => null,
>             'service_id'         => null,
>             'notes'              => null,
>         ];
>     }
> }
> ```

- [ ] **Step 6: Create the mailable + view**

`backend/app/Mail/WaitlistEntryMail.php` :

```php
<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistEntryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WaitlistEntry $entry,
        public string $cabinetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Neue Warteliste-Anfrage — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        $this->entry->loadMissing('service');

        return new Content(markdown: 'emails.waitlist-entry');
    }
}
```

`backend/resources/views/emails/waitlist-entry.blade.php` :

```blade
<x-mail::message>
# Neue Warteliste-Anfrage

Ein Elternteil hat sich auf die Warteliste eingetragen.

**Kind:** {{ $entry->patient_first_name }} {{ $entry->patient_last_name }}

**Elternteil:** {{ $entry->parent_first_name }} {{ $entry->parent_last_name }}

**Telefon:** {{ $entry->parent_phone }}

@if($entry->parent_email)
**E-Mail:** {{ $entry->parent_email }}
@endif

**Gewünschte Leistung:** {{ $entry->service?->name ?? 'Keine Präferenz' }}

@if($entry->notes)
**Notiz:** {{ $entry->notes }}
@endif

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 7: Add `notifyWaitlist` to CabinetNotifier**

Dans `backend/app/Support/CabinetNotifier.php`, ajouter :

```php
use App\Mail\WaitlistEntryMail;
use App\Models\WaitlistEntry;
```
(en tête, à côté des imports existants)

Et ajouter la méthode statique après `notifyCancelled` :

```php
    /** Queue the waitlist-entry alert to the cabinet (no-op if unconfigured). */
    public static function notifyWaitlist(WaitlistEntry $entry): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        rescue(fn () => Mail::to($recipients)->queue(
            new WaitlistEntryMail($entry, config('app.name'))
        ));
    }
```

- [ ] **Step 8: Create the Form Request**

`backend/app/Http/Requests/Widget/StoreWaitlistRequest.php` :

```php
<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_first_name' => ['required', 'string', 'max:255'],
            'patient_last_name'  => ['required', 'string', 'max:255'],
            'parent_first_name'  => ['required', 'string', 'max:255'],
            'parent_last_name'   => ['required', 'string', 'max:255'],
            'parent_phone'       => ['required', 'string', 'max:255'],
            'parent_email'       => ['nullable', 'email', 'max:255'],
            'service_id'         => ['nullable', 'integer', 'exists:services,id'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 9: Create the Widget Controller**

`backend/app/Http/Controllers/Widget/WaitlistController.php` :

```php
<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Support\CabinetNotifier;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request): JsonResponse
    {
        $entry = WaitlistEntry::create($request->validated());

        CabinetNotifier::notifyWaitlist($entry);

        return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
    }
}
```

- [ ] **Step 10: Register the API route**

Dans `backend/routes/api.php`, dans le groupe `throttle:widget-book`, ajouter :

```php
use App\Http\Controllers\Widget\WaitlistController;
```
(en tête, avec les autres imports Widget)

Et dans le groupe `Route::middleware('throttle:widget-book')` :

```php
        Route::post('/warteliste', [WaitlistController::class, 'store']);
```

- [ ] **Step 11: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=WaitlistApiTest`
Expected: PASS (6 tests).

- [ ] **Step 12: Pint + commit**

```bash
cd backend && vendor/bin/pint app/Support/WaitlistStatus.php app/Models/WaitlistEntry.php app/Mail/WaitlistEntryMail.php app/Support/CabinetNotifier.php app/Http/Requests/Widget/StoreWaitlistRequest.php app/Http/Controllers/Widget/WaitlistController.php tests/Feature/Widget/WaitlistApiTest.php
cd .. && git add \
  backend/database/migrations/2026_06_23_000001_create_waitlist_entries_table.php \
  backend/app/Support/WaitlistStatus.php \
  backend/app/Models/WaitlistEntry.php \
  backend/app/Mail/WaitlistEntryMail.php \
  backend/resources/views/emails/waitlist-entry.blade.php \
  backend/app/Support/CabinetNotifier.php \
  backend/app/Http/Requests/Widget/StoreWaitlistRequest.php \
  backend/app/Http/Controllers/Widget/WaitlistController.php \
  backend/routes/api.php \
  backend/tests/Feature/Widget/WaitlistApiTest.php
git commit -m "feat(waitlist): API widget + mailable + CabinetNotifier (Task 1)"
```

---

### Task 2: Admin — WaitlistController Tenant + page Inertia + badge nav

**Files:**
- Create: `backend/app/Http/Requests/Tenant/UpdateWaitlistRequest.php`
- Create: `backend/app/Http/Controllers/Tenant/WaitlistController.php`
- Modify: `backend/routes/web.php` (2 routes)
- Modify: `backend/app/Http/Middleware/HandleInertiaRequests.php` (`waitlist_pending_count`)
- Create: `backend/database/factories/WaitlistEntryFactory.php`
- Create: `backend/resources/js/Pages/Tenant/Waitlist/Index.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue` (nav entry + badge)
- Test: `backend/tests/Feature/TenantSchema/WaitlistAdminTest.php`

**Interfaces:**
- Consumes: `App\Models\WaitlistEntry` (Task 1), `App\Support\WaitlistStatus` (Task 1).
- Produces: `GET /warteliste` (tenant.waitlist.index) → Inertia `Tenant/Waitlist/Index` avec props `{ entries: PaginatedEntries, filters: { status: string }, statusOptions: [{value,label}] }` ; `PATCH /warteliste/{entry}` (tenant.waitlist.update) → JSON 200 `{ status: string }`.

- [ ] **Step 1: Create the WaitlistEntryFactory**

`backend/database/factories/WaitlistEntryFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        return [
            'patient_first_name' => 'Emma',
            'patient_last_name'  => 'Test',
            'parent_first_name'  => 'Katrin',
            'parent_last_name'   => 'Test',
            'parent_phone'       => '+49 160 1234567',
            'parent_email'       => null,
            'service_id'         => null,
            'notes'              => null,
        ];
    }
}
```

- [ ] **Step 2: Write the failing tests**

Créer `backend/tests/Feature/TenantSchema/WaitlistAdminTest.php` :

```php
<?php

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\WaitlistStatus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function waitlistStaff(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

it('lists waitlist entries filtered by pending status by default', function () {
    WaitlistEntry::factory()->count(3)->create();
    $entry = WaitlistEntry::factory()->create();
    $entry->status = WaitlistStatus::Contacted;
    $entry->save();

    $this->actingAs(waitlistStaff())
        ->get('/warteliste')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Waitlist/Index')
            ->has('entries.data', 3)    // only pending
            ->has('statusOptions'));
});

it('lists all entries when status filter is empty', function () {
    WaitlistEntry::factory()->count(2)->create();
    $e = WaitlistEntry::factory()->create();
    $e->status = WaitlistStatus::Contacted; $e->save();

    $this->actingAs(waitlistStaff())
        ->get('/warteliste?status=')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 3));
});

it('updates the status of a waitlist entry', function () {
    $entry = WaitlistEntry::factory()->create();

    $this->actingAs(waitlistStaff())
        ->patchJson("/warteliste/{$entry->id}", ['status' => 'contacted'])
        ->assertOk()
        ->assertJson(['status' => 'contacted']);

    expect($entry->fresh()->status)->toBe(WaitlistStatus::Contacted);
});

it('rejects an invalid status on update', function () {
    $entry = WaitlistEntry::factory()->create();

    $this->actingAs(waitlistStaff())
        ->patchJson("/warteliste/{$entry->id}", ['status' => 'invalid'])
        ->assertStatus(422);
});

it('shares waitlist_pending_count in Inertia props', function () {
    WaitlistEntry::factory()->count(4)->create();

    $this->actingAs(waitlistStaff())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('waitlist_pending_count', 4));
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=WaitlistAdminTest`
Expected: FAIL (routes inexistantes).

- [ ] **Step 4: Create the UpdateWaitlistRequest**

`backend/app/Http/Requests/Tenant/UpdateWaitlistRequest.php` :

```php
<?php

namespace App\Http\Requests\Tenant;

use App\Support\WaitlistStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WaitlistStatus::class)],
        ];
    }
}
```

- [ ] **Step 5: Create the Tenant WaitlistController**

`backend/app/Http/Controllers/Tenant/WaitlistController.php` :

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Support\WaitlistStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistController extends Controller
{
    public function index(Request $request): Response
    {
        $statusFilter = $request->query('status', 'pending');

        $entries = WaitlistEntry::query()
            ->with('service')
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Waitlist/Index', [
            'entries'       => $entries,
            'filters'       => ['status' => $statusFilter],
            'statusOptions' => WaitlistStatus::options(),
        ]);
    }

    public function update(UpdateWaitlistRequest $request, WaitlistEntry $entry): JsonResponse
    {
        $entry->status = WaitlistStatus::from($request->validated()['status']);
        $entry->save();

        return response()->json(['status' => $entry->status->value]);
    }
}
```

- [ ] **Step 6: Register the admin routes**

Dans `backend/routes/web.php`, dans le groupe `Route::middleware(['auth', 'two-factor.enrolled'])`, ajouter :

```php
use App\Http\Controllers\Tenant\WaitlistController;
```
(en tête, avec les autres imports Tenant)

Et dans le groupe auth+2FA :

```php
    Route::get('/warteliste', [WaitlistController::class, 'index'])
        ->name('tenant.waitlist.index');
    Route::patch('/warteliste/{entry}', [WaitlistController::class, 'update'])
        ->name('tenant.waitlist.update');
```

- [ ] **Step 7: Add `waitlist_pending_count` to HandleInertiaRequests**

Dans `backend/app/Http/Middleware/HandleInertiaRequests.php`, ajouter dans `share()` (après `'flash'`) :

```php
            'waitlist_pending_count' => fn () => \App\Models\WaitlistEntry::where('status', 'pending')->count(),
```

- [ ] **Step 8: Create the Inertia page**

`backend/resources/js/Pages/Tenant/Waitlist/Index.vue` :

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'

defineOptions({ layout: TenantLayout })

interface WaitlistEntry {
    id: string
    patient_first_name: string
    patient_last_name: string
    parent_first_name: string
    parent_last_name: string
    parent_phone: string
    parent_email: string | null
    service: { id: number; name: string } | null
    notes: string | null
    status: string
    created_at: string
}

interface StatusOption { value: string; label: string }

const props = defineProps<{
    entries: { data: WaitlistEntry[]; links: any[]; meta: any }
    filters: { status: string }
    statusOptions: StatusOption[]
}>()

const statusFilter = ref(props.filters.status)

const applyFilter = () => {
    router.get('/warteliste', { status: statusFilter.value }, { preserveState: true, replace: true })
}

const updateStatus = (entry: WaitlistEntry, newStatus: string) => {
    router.patch(`/warteliste/${entry.id}`, { status: newStatus }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const fmtDate = (dt: string) =>
    new Date(dt).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
</script>

<template>
    <Head title="Warteliste" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Warteliste</h1>

        <!-- Filter -->
        <div class="flex flex-wrap items-end gap-3 mb-6">
            <label class="text-sm">Status
                <select v-model="statusFilter" @change="applyFilter"
                        class="block border rounded px-3 py-2 text-sm mt-1">
                    <option value="">Alle</option>
                    <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <template v-if="entries.data.length > 0">
            <table class="w-full text-sm">
                <thead class="text-left text-slate-500 border-b">
                    <tr>
                        <th class="py-2 pr-4">Datum</th>
                        <th class="py-2 pr-4">Kind</th>
                        <th class="py-2 pr-4">Elternteil</th>
                        <th class="py-2 pr-4">Telefon / E-Mail</th>
                        <th class="py-2 pr-4">Leistung</th>
                        <th class="py-2 pr-4">Notiz</th>
                        <th class="py-2 pr-4">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id" class="border-b">
                        <td class="py-2 pr-4 whitespace-nowrap">{{ fmtDate(entry.created_at) }}</td>
                        <td class="py-2 pr-4">{{ entry.patient_first_name }} {{ entry.patient_last_name }}</td>
                        <td class="py-2 pr-4">{{ entry.parent_first_name }} {{ entry.parent_last_name }}</td>
                        <td class="py-2 pr-4">
                            <div>{{ entry.parent_phone }}</div>
                            <div v-if="entry.parent_email" class="text-slate-400 text-xs">{{ entry.parent_email }}</div>
                        </td>
                        <td class="py-2 pr-4">{{ entry.service?.name ?? '—' }}</td>
                        <td class="py-2 pr-4 text-slate-500 max-w-xs truncate">{{ entry.notes ?? '—' }}</td>
                        <td class="py-2 pr-4">
                            <select :value="entry.status"
                                    @change="updateStatus(entry, ($event.target as HTMLSelectElement).value)"
                                    class="border rounded px-2 py-1 text-xs">
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="flex gap-2 mt-4 text-sm">
                <component v-for="link in entries.links" :key="link.label"
                           :is="link.url ? 'a' : 'span'"
                           :href="link.url ?? undefined"
                           v-html="link.label"
                           class="px-3 py-1 rounded border"
                           :class="link.active ? 'bg-kids-blue text-white border-kids-blue' : 'text-slate-500'" />
            </div>
        </template>

        <p v-else class="py-12 text-center text-slate-400">Keine Einträge.</p>
    </div>
</template>
```

- [ ] **Step 9: Add nav link + badge in TenantLayout**

Dans `backend/resources/js/Layouts/TenantLayout.vue` :

**9a.** Dans le bloc `import { ..., ChartColumn } from 'lucide-vue-next'`, ajouter `Users` :
```ts
import {
    LayoutDashboard, CalendarDays, ListChecks, Stethoscope, ClipboardList,
    Clock, TreePalm, Palette, QrCode, ShieldCheck, LogOut, ChartColumn, Users,
} from 'lucide-vue-next'
```

**9b.** Ajouter le computed `pendingCount` après `flashSuccess` :
```ts
const pendingCount = computed(() => (page.props as any).waitlist_pending_count as number ?? 0)
```

**9c.** Dans le tableau `nav`, ajouter l'entrée après la ligne `Statistiken` :
```ts
    { href: '/warteliste', label: 'Warteliste', icon: Users },
```

**9d.** Dans le template, remplacer le rendu du lien nav pour afficher le badge sur l'entrée Warteliste. Remplacer le `<Link v-for="item in nav"...>` par :

```html
                <Link v-for="item in nav" :key="item.href" :href="item.href"
                      class="flex items-center gap-3 px-3 py-2 rounded-xl text-slate-600 hover:bg-kids-blue/20 transition"
                      :class="isActive(item.href) ? 'bg-kids-blue/20 text-slate-800 font-medium' : ''">
                    <component :is="item.icon" class="h-5 w-5" :stroke-width="1.75" />
                    {{ item.label }}
                    <span v-if="item.href === '/warteliste' && pendingCount > 0"
                          class="ml-auto text-xs font-bold bg-rose-500 text-white rounded-full px-1.5 py-0.5 leading-none">
                        {{ pendingCount }}
                    </span>
                </Link>
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=WaitlistAdminTest`
Expected: PASS (5 tests).

- [ ] **Step 11: Build + full suite**

Run: `cd backend && npm run build && composer test`
Expected: build OK, tous les tests verts.

- [ ] **Step 12: Pint + commit**

```bash
cd backend && vendor/bin/pint app/Http/Requests/Tenant/UpdateWaitlistRequest.php app/Http/Controllers/Tenant/WaitlistController.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/TenantSchema/WaitlistAdminTest.php
cd .. && git add \
  backend/database/factories/WaitlistEntryFactory.php \
  backend/app/Http/Requests/Tenant/UpdateWaitlistRequest.php \
  backend/app/Http/Controllers/Tenant/WaitlistController.php \
  backend/routes/web.php \
  backend/app/Http/Middleware/HandleInertiaRequests.php \
  backend/resources/js/Pages/Tenant/Waitlist/Index.vue \
  backend/resources/js/Layouts/TenantLayout.vue \
  backend/tests/Feature/TenantSchema/WaitlistAdminTest.php
git commit -m "feat(waitlist): admin page + nav badge + Inertia shared count (Task 2)"
```

---

### Task 3: Widget — étape WaitlistStep.vue + bouton dans TerminStep + App.vue

**Files:**
- Modify: `backend/resources/js/widget/useWizard.ts` (ajout type `'waitlist'`)
- Create: `backend/resources/js/widget/steps/WaitlistStep.vue`
- Modify: `backend/resources/js/widget/steps/TerminStep.vue` (bouton + emit)
- Modify: `backend/resources/js/widget/App.vue` (case waitlist + listener)

**Interfaces:**
- Consumes: `POST /api/v1/widget/warteliste` (Task 1) ; services de `props.api.services()` (dans App.vue) ; `w.selection.service` (service pré-sélectionné).
- Produces: étape `waitlist` dans le wizard accessible depuis `TerminStep` via `$emit('waitlist')`.

- [ ] **Step 1: Update useWizard.ts**

Dans `backend/resources/js/widget/useWizard.ts`, changer :

```ts
export type Step = 'termin' | 'kind' | 'form' | 'confirm' | 'success'
```
en :
```ts
export type Step = 'termin' | 'kind' | 'form' | 'confirm' | 'success' | 'waitlist'
```

Pas de changement dans `ORDER` (waitlist est hors du flux `next()`/`prev()`).

- [ ] **Step 2: Create WaitlistStep.vue**

`backend/resources/js/widget/steps/WaitlistStep.vue` :

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import type { Service } from '../types'

const props = defineProps<{
    api: { services(): Promise<Service[]> }
    services: Service[]
    preselectedServiceId?: number
}>()

const emit = defineEmits<{ (e: 'back'): void; (e: 'done'): void }>()

const form = ref({
    patient_first_name: '',
    patient_last_name: '',
    parent_first_name: '',
    parent_last_name: '',
    parent_phone: '',
    parent_email: '',
    service_id: props.preselectedServiceId ?? null as number | null,
    notes: '',
})

const saving = ref(false)
const done = ref(false)
const errors = ref<Record<string, string[]>>({})

const submit = async () => {
    saving.value = true
    errors.value = {}
    try {
        await window.axios.post('/api/v1/widget/warteliste', {
            ...form.value,
            parent_email: form.value.parent_email || null,
            service_id: form.value.service_id || null,
            notes: form.value.notes || null,
        })
        done.value = true
    } catch (e: any) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {}
        }
    } finally {
        saving.value = false
    }
}

const fieldError = (field: string) => errors.value[field]?.[0]
</script>

<template>
    <!-- Success state -->
    <div v-if="done" class="text-center py-6 space-y-3">
        <p class="text-2xl">✓</p>
        <p class="font-semibold text-widget-text">Auf der Warteliste eingetragen!</p>
        <p class="text-sm text-widget-text/70">Wir melden uns, sobald ein Termin frei wird.</p>
    </div>

    <!-- Form -->
    <div v-else class="space-y-3">
        <h2 class="text-base font-semibold text-widget-text">Auf die Warteliste eintragen</h2>
        <p class="text-xs text-widget-text/70">Wir kontaktieren Sie, sobald ein Termin frei wird.</p>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <input v-model="form.patient_first_name" type="text" placeholder="Vorname Kind *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('patient_first_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('patient_first_name') }}</p>
            </div>
            <div>
                <input v-model="form.patient_last_name" type="text" placeholder="Nachname Kind *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('patient_last_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('patient_last_name') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <input v-model="form.parent_first_name" type="text" placeholder="Vorname Elternteil *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('parent_first_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_first_name') }}</p>
            </div>
            <div>
                <input v-model="form.parent_last_name" type="text" placeholder="Nachname Elternteil *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('parent_last_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_last_name') }}</p>
            </div>
        </div>

        <div>
            <input v-model="form.parent_phone" type="tel" placeholder="Telefon *"
                   class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
            <p v-if="fieldError('parent_phone')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_phone') }}</p>
        </div>

        <input v-model="form.parent_email" type="email" placeholder="E-Mail (optional)"
               class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />

        <select v-model="form.service_id"
                class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text">
            <option :value="null">Keine Präferenz</option>
            <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>

        <textarea v-model="form.notes" placeholder="Notiz (optional)"
                  rows="2"
                  class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40 resize-none" />

        <div class="flex gap-2 pt-1">
            <button type="button" @click="emit('back')"
                    class="px-4 py-2 rounded-xl border text-sm text-widget-text/70 hover:bg-tint">
                ← Zurück
            </button>
            <button type="button" @click="submit" :disabled="saving"
                    class="flex-1 rounded-xl bg-accent text-white text-sm font-semibold py-2 disabled:opacity-50">
                {{ saving ? 'Wird eingetragen…' : 'Auf die Warteliste' }}
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Add the waitlist button in TerminStep.vue**

Dans `backend/resources/js/widget/steps/TerminStep.vue` :

**3a.** Dans `defineEmits`, ajouter `'waitlist'` :
```ts
const emit = defineEmits<{
    // ... existing emits ...
    (e: 'waitlist'): void
}>()
```

**3b.** Trouver la section `availableDates.length === 0` (ligne ~93). Juste APRÈS le `</p>` de ce message (« Kein freier Termin verfügbar. »), ajouter :
```html
            <button type="button" @click="$emit('waitlist')"
                    class="mt-2 text-sm font-medium text-accent hover:underline">
                Auf die Warteliste →
            </button>
```

- [ ] **Step 4: Wire WaitlistStep in App.vue**

Dans `backend/resources/js/widget/App.vue` :

**4a.** Ajouter l'import :
```ts
import WaitlistStep from './steps/WaitlistStep.vue'
```

**4b.** Sur le composant `TerminStep`, ajouter le listener d'événement :
```html
<TerminStep v-if="w.step.value === 'termin'"
            ...
            @waitlist="w.go('waitlist')"
            ...
```

**4c.** Après le bloc `<SuccessStep v-else-if="...">`, ajouter :
```html
        <WaitlistStep v-else-if="w.step.value === 'waitlist'"
                      :api="props.api"
                      :services="services"
                      :preselected-service-id="w.selection.service?.id"
                      @back="w.go('termin')" />
```

- [ ] **Step 5: Build widget**

Run: `cd backend && npm run build:widget`
Expected: build réussi sans erreur.

- [ ] **Step 6: Commit**

```bash
cd .. && git add \
  backend/resources/js/widget/useWizard.ts \
  backend/resources/js/widget/steps/WaitlistStep.vue \
  backend/resources/js/widget/steps/TerminStep.vue \
  backend/resources/js/widget/App.vue
git commit -m "feat(waitlist): widget step WaitlistStep + button in TerminStep (Task 3)"
```

---

### Task 4: Vérification

**Files:** aucun (vérification).

- [ ] **Step 1: Run the full backend suite**

Run: `cd backend && composer test`
Expected: PASS (toute la suite verte, dont les 11 nouveaux tests Warteliste).

- [ ] **Step 2: Vérification visuelle (connexion par l'utilisateur)**

- Ouvrir le widget sur `http://wordpress.p710158.webspaceconfig.de/` (ou local) : choisir un service + date sans créneau → vérifier que le bouton « Auf die Warteliste → » apparaît → cliquer → remplir le formulaire → soumettre → confirmation « Wir melden uns! ».
- Ouvrir `http://127.0.0.1:8000/warteliste` (connecté en staff) → vérifier que la demande apparaît → changer le statut en « Kontaktiert » → vérifier la mise à jour.
- Vérifier le badge (N) dans le menu nav.

- [ ] **Step 3: Confirmer (pas de commit)**

---

## Self-Review

**Spec coverage :**
- Table `waitlist_entries` + migration → Task 1 ✓
- Enum `WaitlistStatus` → Task 1 ✓
- API widget POST + validation + 201 → Task 1 (steps 8,9,10) ✓
- Notification cabinet `WaitlistEntryMail` + `CabinetNotifier::notifyWaitlist` → Task 1 ✓
- Tests API widget (6 cas) → Task 1 ✓
- Factory → Task 2 (step 1) ✓
- Admin `GET /warteliste` + `PATCH /warteliste/{entry}` → Task 2 ✓
- `waitlist_pending_count` partagé + badge nav → Task 2 ✓
- Page Inertia + filtre + tableau + pagination + select statut inline → Task 2 ✓
- Tests admin (5 cas) → Task 2 ✓
- `useWizard` type `'waitlist'` → Task 3 ✓
- `WaitlistStep.vue` formulaire + confirmation → Task 3 ✓
- Bouton `TerminStep` quand `availableDates.length === 0` → Task 3 ✓
- Câblage `App.vue` → Task 3 ✓
- Build widget → Task 3 ✓
- Vérif → Task 4 ✓

**Placeholder scan :** aucun TODO/TBD ; code complet à chaque étape.

**Type consistency :** `WaitlistEntry` (modèle) ↔ props `Tenant/Waitlist/Index.vue` (même champs) ↔ `StoreWaitlistRequest` (mêmes clés) ↔ `WaitlistStep.vue` (même payload). `WaitlistStatus::options()` → `statusOptions` prop. `waitlist_pending_count` partagé → `pendingCount` dans TenantLayout.
