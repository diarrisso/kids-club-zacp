# Phase 2 — Booking Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backend booking engine — a parent can list a practice's services, pick a practitioner, see free slots, book an appointment for their child (with consent), and cancel by token — with strict cross-tenant isolation and zero double-booking under concurrency.

**Architecture:** A pure `AvailabilityCalculator` service computes free slots (availabilities − exceptions − appointments, duration-aligned, 2h lead / 60d horizon). A public JSON API under `/api/v1/widget/{tenant}/*` identifies the tenant **by path** (the widget is cross-origin) and reuses Phase 1's `SearchPathBootstrapper`. Booking uses a pessimistic row lock in a transaction. Anonymous, rate-limited, honeypot-protected.

**Tech Stack:** Laravel 11, stancl/tenancy v3 (path identification), PostgreSQL schema-per-tenant, Pest 3. Builds on Phase 1 (`backend/`). All tasks run in `backend/`.

**Spec:** `docs/superpowers/specs/2026-05-29-masinga-booking-phase-2-booking-core-design.md`

---

## File Structure

- Create `backend/routes/api.php` — public widget routes (tenant-by-path group)
- Modify `backend/bootstrap/app.php` — register api routes + map path-identification 404
- Create `backend/database/migrations/tenant/2026_06_01_000015_create_appointments_table.php`
- Create `backend/app/Models/Tenant/Appointment.php` + `backend/database/factories/Tenant/AppointmentFactory.php`
- Create `backend/app/Services/Tenant/Slot.php` (DTO) + `backend/app/Services/Tenant/AvailabilityCalculator.php`
- Create `backend/app/Http/Controllers/Widget/{ServiceController,SlotController,AppointmentController,CancellationController}.php`
- Create `backend/app/Http/Requests/Widget/StoreAppointmentRequest.php`
- Modify `backend/app/Providers/AppServiceProvider.php` — rate limiters
- Modify `backend/config/cors.php` (created via `php artisan config:publish cors`)
- Tests under `backend/tests/Feature/TenantSchema/` (tenant suite, real schemas)

---

### Task 1: Enable API routes + path-based tenant identification

**Files:**
- Create: `backend/routes/api.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/TenantSchema/WidgetTenantIdentificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/WidgetTenantIdentificationTest.php
use App\Models\Tenant\Service;

it('resolves the tenant from the path', function () {
    Service::factory()->create(['name' => 'Erstuntersuchung', 'is_active' => true]);

    $this->getJson('http://central.masinga-booking.test/api/v1/widget/testtenant/services')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Erstuntersuchung']);
});

it('returns 404 for an unknown tenant slug', function () {
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/does-not-exist/services')
        ->assertNotFound();
});
```

Note: the widget API is reached on ANY host (the tenant comes from the path, not the domain), so we call it on the central domain. `TenantTestCase` already created `testtenant` with its schema.

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetTenantIdentificationTest`
Expected: FAIL (route not defined → 404 for both, or 500).

- [ ] **Step 3: Create the api routes file**

```php
<?php
// routes/api.php
use App\Http\Controllers\Widget\ServiceController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

Route::middleware([InitializeTenancyByPath::class])
    ->prefix('v1/widget/{tenant}')
    ->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
    });
```

`InitializeTenancyByPath` reads the first route parameter (`{tenant}`, the default name in stancl's `PathTenantResolver`) and resolves the tenant by its key (our tenant id = slug). It runs the `SearchPathBootstrapper` from Phase 1, so tenant tables resolve.

- [ ] **Step 4: Register api routes + map the path-identification exception to 404**

Edit `bootstrap/app.php` — add the api file to `withRouting` and render the path exception as 404:

```php
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TenantCouldNotBeIdentifiedOnDomainException $e) {
            abort(404);
        });
        $exceptions->render(function (TenantCouldNotBeIdentifiedByPathException $e) {
            abort(404);
        });
    })->create();
```

(Keep the existing domain render; add the path one.)

- [ ] **Step 5: Create a minimal ServiceController to satisfy the route**

```php
<?php
// app/Http/Controllers/Widget/ServiceController.php
namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Service;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Service::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes', 'color', 'description'])
        );
    }
}
```

- [ ] **Step 6: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetTenantIdentificationTest`
Expected: PASS (2 tests). The `{tenant}` parameter `testtenant` resolves; `does-not-exist` → 404.

- [ ] **Step 7: Commit**

```bash
git add backend/routes/api.php backend/bootstrap/app.php backend/app/Http/Controllers/Widget/ServiceController.php backend/tests/Feature/TenantSchema/WidgetTenantIdentificationTest.php
git commit -m "feat: public widget API skeleton with path-based tenant identification"
```

---

### Task 2: appointments migration + model + factory

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000015_create_appointments_table.php`
- Create: `backend/app/Models/Tenant/Appointment.php`
- Create: `backend/database/factories/Tenant/AppointmentFactory.php`
- Test: `backend/tests/Feature/TenantSchema/AppointmentModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/AppointmentModelTest.php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('creates an appointment with a uuid id and cancellation token', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $a = Appointment::create([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'starts_at' => '2026-09-01 09:00:00',
        'ends_at' => '2026-09-01 09:30:00',
        'patient_first_name' => 'Lina',
        'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna',
        'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de',
        'parent_consent_at' => now(),
        'cancellation_token' => (string) Str::uuid(),
    ]);

    expect($a->id)->toBeString()->toHaveLength(36)
        ->and($a->status)->toBe('confirmed')
        ->and($a->practitioner->id)->toBe($p->id);
});
```

(Add `use Illuminate\Support\Str;` at the top of the test.)

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=AppointmentModelTest`
Expected: FAIL ("Class App\\Models\\Tenant\\Appointment not found").

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/tenant/2026_06_01_000015_create_appointments_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('confirmed'); // pending|confirmed|cancelled|completed|no_show

            // Patient (the child) — sensitive medical data
            $table->string('patient_first_name');
            $table->string('patient_last_name');
            $table->date('patient_birthdate');

            // Parent / guardian (the booker)
            $table->string('parent_first_name');
            $table->string('parent_last_name');
            $table->string('parent_email');
            $table->string('parent_phone')->nullable();
            $table->timestamp('parent_consent_at');

            $table->text('notes_parent')->nullable();
            $table->text('notes_internal')->nullable();
            $table->uuid('cancellation_token')->unique();
            $table->timestamps();

            $table->index(['practitioner_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Models/Tenant/Appointment.php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'practitioner_id', 'service_id', 'starts_at', 'ends_at', 'status',
        'patient_first_name', 'patient_last_name', 'patient_birthdate',
        'parent_first_name', 'parent_last_name', 'parent_email', 'parent_phone',
        'parent_consent_at', 'notes_parent', 'notes_internal', 'cancellation_token',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'patient_birthdate' => 'date',
        'parent_consent_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'confirmed'];

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\AppointmentFactory::new();
    }
}
```

`HasUuids` auto-generates the UUID `id`. The `cancellation_token` is supplied explicitly by the booking logic.

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/Tenant/AppointmentFactory.php
namespace Database\Factories\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'service_id' => Service::factory(),
            'starts_at' => '2026-09-01 09:00:00',
            'ends_at' => '2026-09-01 09:30:00',
            'status' => 'confirmed',
            'patient_first_name' => 'Lina',
            'patient_last_name' => 'Müller',
            'patient_birthdate' => '2019-04-12',
            'parent_first_name' => 'Anna',
            'parent_last_name' => 'Müller',
            'parent_email' => 'anna@example.de',
            'parent_phone' => '+49 170 0000000',
            'parent_consent_at' => now(),
            'cancellation_token' => (string) Str::uuid(),
        ];
    }
}
```

- [ ] **Step 6: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=AppointmentModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/tenant/2026_06_01_000015_create_appointments_table.php backend/app/Models/Tenant/Appointment.php backend/database/factories/Tenant/AppointmentFactory.php backend/tests/Feature/TenantSchema/AppointmentModelTest.php
git commit -m "feat: appointments tenant model + migration + factory (parent/child/consent)"
```

---

### Task 3: Slot DTO + AvailabilityCalculator — base grid from availabilities

**Files:**
- Create: `backend/app/Services/Tenant/Slot.php`
- Create: `backend/app/Services/Tenant/AvailabilityCalculator.php`
- Test: `backend/tests/Feature/TenantSchema/AvailabilityCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/AvailabilityCalculatorTest.php
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;

function makeCalc(): AvailabilityCalculator
{
    return app(AvailabilityCalculator::class);
}

it('generates duration-aligned slots within an availability', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    // 2026-09-07 is a Monday (day_of_week = 1)
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);

    $slots = makeCalc()->forPractitionerService(
        $p, $s,
        CarbonImmutable::parse('2026-09-07 00:00'),
        CarbonImmutable::parse('2026-09-07 23:59'),
    );

    expect($slots->pluck('starts_at')->map->format('H:i')->all())
        ->toBe(['09:00', '09:30', '10:00', '10:30']); // last fits 30min before 11:00
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=AvailabilityCalculatorTest`
Expected: FAIL ("Class App\\Services\\Tenant\\AvailabilityCalculator not found").

- [ ] **Step 3: Write the Slot DTO**

```php
<?php
// app/Services/Tenant/Slot.php
namespace App\Services\Tenant;

use Carbon\CarbonImmutable;

readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public function getStartsAtAttribute(): CarbonImmutable
    {
        return $this->startsAt;
    }

    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ];
    }
}
```

Note: the test uses `$slots->pluck('starts_at')`. To support that on a Collection of `Slot`, expose `starts_at`/`ends_at` as readable. Simplest: add public readonly properties named to match. Replace the DTO with snake_case public props:

```php
<?php
// app/Services/Tenant/Slot.php
namespace App\Services\Tenant;

use Carbon\CarbonImmutable;

readonly class Slot
{
    public function __construct(
        public CarbonImmutable $starts_at,
        public CarbonImmutable $ends_at,
    ) {}

    public function toArray(): array
    {
        return [
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
        ];
    }
}
```

(Use this second version — `pluck('starts_at')` reads the public property.)

- [ ] **Step 4: Write the calculator (base grid only for now)**

```php
<?php
// app/Services/Tenant/AvailabilityCalculator.php
namespace App\Services\Tenant;

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AvailabilityCalculator
{
    public const LEAD_MINUTES = 120;   // 2h minimum lead time
    public const HORIZON_DAYS = 60;    // book up to 60 days ahead

    /** @return Collection<int, Slot> */
    public function forPractitionerService(
        Practitioner $practitioner,
        Service $service,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $duration = $service->duration_minutes;
        $slots = collect();

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $dow = $day->dayOfWeekIso; // 1 = Monday ... 7 = Sunday

            $availabilities = $practitioner->availabilities()
                ->where('day_of_week', $dow)
                ->get();

            foreach ($availabilities as $availability) {
                $slots = $slots->merge(
                    $this->slotsForDay($day, $availability->start_time, $availability->end_time, $duration)
                );
            }
        }

        return $slots->values();
    }

    /** @return Collection<int, Slot> */
    private function slotsForDay(CarbonImmutable $day, CarbonInterface $start, CarbonInterface $end, int $duration): Collection
    {
        $slots = collect();
        $cursor = $day->setTime((int) $start->format('H'), (int) $start->format('i'));
        $dayEnd = $day->setTime((int) $end->format('H'), (int) $end->format('i'));

        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayEnd)) {
            $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
            $cursor = $cursor->addMinutes($duration);
        }

        return $slots;
    }
}
```

`Practitioner` must have an `availabilities()` relation. Add it in the next step if missing.

- [ ] **Step 5: Ensure `Practitioner::availabilities()` exists**

Add to `app/Models/Tenant/Practitioner.php` (if not already present):

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function availabilities(): HasMany
{
    return $this->hasMany(Availability::class);
}
```

- [ ] **Step 6: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=AvailabilityCalculatorTest`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Tenant/ backend/app/Models/Tenant/Practitioner.php backend/tests/Feature/TenantSchema/AvailabilityCalculatorTest.php
git commit -m "feat: AvailabilityCalculator base grid (duration-aligned slots)"
```

---

### Task 4: AvailabilityCalculator — exceptions, existing appointments, lead time, horizon

**Files:**
- Modify: `backend/app/Services/Tenant/AvailabilityCalculator.php`
- Test: `backend/tests/Feature/TenantSchema/AvailabilityCalculatorTest.php` (append)

- [ ] **Step 1: Append failing tests**

```php
it('removes slots overlapping an availability exception', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);
    \App\Models\Tenant\AvailabilityException::factory()->create([
        'practitioner_id' => $p->id,
        'starts_at' => '2026-09-07 09:30', 'ends_at' => '2026-09-07 10:30', 'type' => 'block',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s,
        CarbonImmutable::parse('2026-09-07 00:00'), CarbonImmutable::parse('2026-09-07 23:59'));

    // 09:30 and 10:00 overlap the block; 09:00 and 10:30 survive
    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '10:30']);
});

it('removes slots overlapping an existing appointment', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '11:00',
    ]);
    \App\Models\Tenant\Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30', 'status' => 'confirmed',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s,
        CarbonImmutable::parse('2026-09-07 00:00'), CarbonImmutable::parse('2026-09-07 23:59'));

    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '09:30', '10:30']);
});

it('ignores cancelled appointments when computing slots', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);
    \App\Models\Tenant\Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => '2026-09-07 09:00', 'ends_at' => '2026-09-07 09:30', 'status' => 'cancelled',
    ]);

    $slots = makeCalc()->forPractitionerService($p, $s,
        CarbonImmutable::parse('2026-09-07 00:00'), CarbonImmutable::parse('2026-09-07 23:59'));

    expect($slots->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '09:30']);
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=AvailabilityCalculatorTest`
Expected: FAIL (exception/appointment slots still present).

- [ ] **Step 3: Add filtering to the calculator**

Replace `forPractitionerService` body to apply lead/horizon bounds and filter overlaps:

```php
public function forPractitionerService(
    Practitioner $practitioner,
    Service $service,
    CarbonImmutable $from,
    CarbonImmutable $to,
): Collection {
    $duration = $service->duration_minutes;

    $earliest = CarbonImmutable::now()->addMinutes(self::LEAD_MINUTES);
    $latest = CarbonImmutable::now()->addDays(self::HORIZON_DAYS);
    $from = $from->greaterThan($earliest) ? $from : $earliest;
    $to = $to->lessThan($latest) ? $to : $latest;

    if ($from->greaterThan($to)) {
        return collect();
    }

    $exceptions = $practitioner->availabilityExceptions()
        ->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->get();

    $appointments = $practitioner->appointments()
        ->whereIn('status', ['pending', 'confirmed'])
        ->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->get();

    $slots = collect();
    for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
        $availabilities = $practitioner->availabilities()
            ->where('day_of_week', $day->dayOfWeekIso)->get();

        foreach ($availabilities as $availability) {
            foreach ($this->slotsForDay($day, $availability->start_time, $availability->end_time, $duration) as $slot) {
                if ($slot->starts_at->lessThan($earliest)) {
                    continue;
                }
                if ($this->overlapsAny($slot, $exceptions) || $this->overlapsAny($slot, $appointments)) {
                    continue;
                }
                $slots->push($slot);
            }
        }
    }

    return $slots->values();
}

/** @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $intervals */
private function overlapsAny(Slot $slot, Collection $intervals): bool
{
    foreach ($intervals as $i) {
        if ($slot->starts_at->lessThan($i->ends_at) && $slot->ends_at->greaterThan($i->starts_at)) {
            return true;
        }
    }

    return false;
}
```

- [ ] **Step 4: Add the relations used above to `Practitioner`**

Add to `app/Models/Tenant/Practitioner.php`:

```php
public function availabilityExceptions(): HasMany
{
    return $this->hasMany(AvailabilityException::class);
}

public function appointments(): HasMany
{
    return $this->hasMany(Appointment::class);
}
```

- [ ] **Step 5: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=AvailabilityCalculatorTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Tenant/AvailabilityCalculator.php backend/app/Models/Tenant/Practitioner.php backend/tests/Feature/TenantSchema/AvailabilityCalculatorTest.php
git commit -m "feat: AvailabilityCalculator filters exceptions, appointments, lead time, horizon"
```

---

### Task 5: GET /services/{service}/practitioners endpoint

**Files:**
- Modify: `backend/app/Http/Controllers/Widget/ServiceController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TenantSchema/WidgetServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/WidgetServiceTest.php
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('lists active practitioners for a service', function () {
    $service = Service::factory()->create();
    $active = Practitioner::factory()->create(['is_active' => true]);
    $inactive = Practitioner::factory()->create(['is_active' => false]);
    $service->practitioners()->attach([$active->id, $inactive->id]);

    $this->getJson("http://central.masinga-booking.test/api/v1/widget/testtenant/services/{$service->id}/practitioners")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $active->id]);
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetServiceTest`
Expected: FAIL (404 — route undefined).

- [ ] **Step 3: Add the controller method**

Add to `app/Http/Controllers/Widget/ServiceController.php`:

```php
use App\Models\Tenant\Service;

public function practitioners(Service $service): JsonResponse
{
    return response()->json(
        $service->practitioners()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get(['practitioners.id', 'first_name', 'last_name', 'title', 'color'])
    );
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, inside the group:

```php
Route::get('/services/{service}/practitioners', [ServiceController::class, 'practitioners']);
```

- [ ] **Step 5: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetServiceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Widget/ServiceController.php backend/routes/api.php backend/tests/Feature/TenantSchema/WidgetServiceTest.php
git commit -m "feat: widget endpoint listing active practitioners for a service"
```

---

### Task 6: GET /slots endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Widget/SlotController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TenantSchema/WidgetSlotTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/WidgetSlotTest.php
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('returns free slots for a practitioner and service', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1,
        'start_time' => '09:00', 'end_time' => '10:00',
    ]);

    $this->getJson("http://central.masinga-booking.test/api/v1/widget/testtenant/slots?"
        . http_build_query([
            'practitioner_id' => $p->id, 'service_id' => $s->id,
            'from' => '2026-09-07', 'to' => '2026-09-07',
        ]))
        ->assertOk()
        ->assertJsonStructure([['starts_at', 'ends_at']])
        ->assertJsonCount(2); // 09:00, 09:30
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetSlotTest`
Expected: FAIL (404).

- [ ] **Step 3: Write the controller**

```php
<?php
// app/Http/Controllers/Widget/SlotController.php
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
            'practitioner_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $practitioner = Practitioner::findOrFail($data['practitioner_id']);
        $service = Service::findOrFail($data['service_id']);

        $slots = $calculator->forPractitionerService(
            $practitioner,
            $service,
            CarbonImmutable::parse($data['from'])->startOfDay(),
            CarbonImmutable::parse($data['to'])->endOfDay(),
        );

        return response()->json($slots->map->toArray()->values());
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`:

```php
use App\Http\Controllers\Widget\SlotController;

Route::get('/slots', [SlotController::class, 'index']);
```

- [ ] **Step 5: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetSlotTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Widget/SlotController.php backend/routes/api.php backend/tests/Feature/TenantSchema/WidgetSlotTest.php
git commit -m "feat: widget slots endpoint backed by AvailabilityCalculator"
```

---

### Task 7: POST /appointments — booking with pessimistic lock + validation + honeypot

**Files:**
- Create: `backend/app/Http/Requests/Widget/StoreAppointmentRequest.php`
- Create: `backend/app/Http/Controllers/Widget/AppointmentController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TenantSchema/WidgetBookingTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/TenantSchema/WidgetBookingTest.php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

function bookingPayload(array $override = []): array
{
    return array_merge([
        'practitioner_id' => null, 'service_id' => null,
        'starts_at' => '2026-09-07 09:00:00',
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller',
        'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '', // honeypot empty
    ], $override);
}

function bookUrl(): string
{
    return 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';
}

it('books an appointment for a child', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);

    $this->postJson(bookUrl(), bookingPayload(['practitioner_id' => $p->id, 'service_id' => $s->id]))
        ->assertCreated()
        ->assertJsonStructure(['cancellation_token', 'starts_at', 'ends_at']);

    tenancy()->initialize($this->tenant);
    $a = Appointment::firstOrFail();
    expect($a->status)->toBe('confirmed')
        ->and($a->ends_at->format('H:i'))->toBe('09:30')
        ->and($a->parent_consent_at)->not->toBeNull();
});

it('rejects booking without consent', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'consent' => false,
    ]))->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('silently drops a booking when the honeypot is filled', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'website' => 'http://spam.test',
    ]))->assertOk();

    tenancy()->initialize($this->tenant);
    expect(Appointment::count())->toBe(0);
});

it('blocks a double booking on the same slot', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['duration_minutes' => 30]);
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => '2026-09-07 09:00:00', 'ends_at' => '2026-09-07 09:30:00', 'status' => 'confirmed',
    ]);

    $this->postJson(bookUrl(), bookingPayload(['practitioner_id' => $p->id, 'service_id' => $s->id]))
        ->assertStatus(409);
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetBookingTest`
Expected: FAIL (404).

- [ ] **Step 3: Write the FormRequest**

```php
<?php
// app/Http/Requests/Widget/StoreAppointmentRequest.php
namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'service_id' => ['required', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'patient_first_name' => ['required', 'string', 'max:255'],
            'patient_last_name' => ['required', 'string', 'max:255'],
            'patient_birthdate' => ['required', 'date', 'before:today'],
            'parent_first_name' => ['required', 'string', 'max:255'],
            'parent_last_name' => ['required', 'string', 'max:255'],
            'parent_email' => ['required', 'email', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:50'],
            'notes_parent' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'], // must be true
            'website' => ['nullable', 'string'], // honeypot
        ];
    }
}
```

- [ ] **Step 4: Write the controller (honeypot + pessimistic lock)**

```php
<?php
// app/Http/Controllers/Widget/AppointmentController.php
namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreAppointmentRequest;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        // Honeypot: a bot filled the hidden field — pretend success, create nothing.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true]);
        }

        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $appointment = DB::transaction(function () use ($data, $startsAt, $endsAt) {
            $conflict = Appointment::query()
                ->where('practitioner_id', $data['practitioner_id'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->whereIn('status', ['pending', 'confirmed'])
                ->lockForUpdate()
                ->exists();

            abort_if($conflict, 409, 'Slot already taken.');

            return Appointment::create([
                'practitioner_id' => $data['practitioner_id'],
                'service_id' => $data['service_id'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'confirmed',
                'patient_first_name' => $data['patient_first_name'],
                'patient_last_name' => $data['patient_last_name'],
                'patient_birthdate' => $data['patient_birthdate'],
                'parent_first_name' => $data['parent_first_name'],
                'parent_last_name' => $data['parent_last_name'],
                'parent_email' => $data['parent_email'],
                'parent_phone' => $data['parent_phone'] ?? null,
                'parent_consent_at' => now(),
                'notes_parent' => $data['notes_parent'] ?? null,
                'cancellation_token' => (string) Str::uuid(),
            ]);
        });

        return response()->json([
            'cancellation_token' => $appointment->cancellation_token,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ], 201);
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/api.php`:

```php
use App\Http\Controllers\Widget\AppointmentController;

Route::post('/appointments', [AppointmentController::class, 'store']);
```

- [ ] **Step 6: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetBookingTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Widget/StoreAppointmentRequest.php backend/app/Http/Controllers/Widget/AppointmentController.php backend/routes/api.php backend/tests/Feature/TenantSchema/WidgetBookingTest.php
git commit -m "feat: widget booking endpoint (consent, honeypot, pessimistic lock)"
```

---

### Task 8: Cancellation by token

**Files:**
- Create: `backend/app/Http/Controllers/Widget/CancellationController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TenantSchema/WidgetCancellationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/WidgetCancellationTest.php
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('cancels an appointment by token and frees the slot', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);

    $base = 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';

    $this->getJson("{$base}/{$a->cancellation_token}")
        ->assertOk()->assertJsonFragment(['status' => 'confirmed']);

    $this->postJson("{$base}/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->status)->toBe('cancelled');
});

it('returns 404 for an unknown cancellation token', function () {
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/'.\Illuminate\Support\Str::uuid())
        ->assertNotFound();
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetCancellationTest`
Expected: FAIL (404 route undefined for both, including the valid token).

- [ ] **Step 3: Write the controller**

```php
<?php
// app/Http/Controllers/Widget/CancellationController.php
namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use Illuminate\Http\JsonResponse;

class CancellationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $a = Appointment::where('cancellation_token', $token)->firstOrFail();

        return response()->json([
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'status' => $a->status,
            'service' => $a->service->name,
        ]);
    }

    public function cancel(string $token): JsonResponse
    {
        $a = Appointment::where('cancellation_token', $token)->firstOrFail();
        $a->update(['status' => 'cancelled']);

        return response()->json(['status' => 'cancelled']);
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/api.php`:

```php
use App\Http\Controllers\Widget\CancellationController;

Route::get('/appointments/{token}', [CancellationController::class, 'show']);
Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel']);
```

- [ ] **Step 5: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetCancellationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Widget/CancellationController.php backend/routes/api.php backend/tests/Feature/TenantSchema/WidgetCancellationTest.php
git commit -m "feat: appointment cancellation by token (frees the slot)"
```

---

### Task 9: Rate limiting + CORS

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/routes/api.php`
- Create: `backend/config/cors.php` (via publish)
- Test: `backend/tests/Feature/TenantSchema/WidgetRateLimitTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenantSchema/WidgetRateLimitTest.php
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('rate-limits booking attempts', function () {
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $url = 'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments';

    // 5 allowed per minute; the 6th must be throttled.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($url, ['practitioner_id' => $p->id, 'service_id' => $s->id]); // invalid body is fine; limiter runs first
    }
    $this->postJson($url, ['practitioner_id' => $p->id, 'service_id' => $s->id])
        ->assertStatus(429);
});
```

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --testsuite=tenant --filter=WidgetRateLimitTest`
Expected: FAIL (no 429 — gets 422 instead).

- [ ] **Step 3: Define rate limiters**

Add to `app/Providers/AppServiceProvider.php` `boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('widget-read', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
    RateLimiter::for('widget-book', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
}
```

- [ ] **Step 4: Apply throttle middleware to the routes**

In `routes/api.php`, wrap reads with `throttle:widget-read` and the booking POST with `throttle:widget-book`:

```php
Route::middleware([InitializeTenancyByPath::class])
    ->prefix('v1/widget/{tenant}')
    ->group(function () {
        Route::middleware('throttle:widget-read')->group(function () {
            Route::get('/services', [ServiceController::class, 'index']);
            Route::get('/services/{service}/practitioners', [ServiceController::class, 'practitioners']);
            Route::get('/slots', [SlotController::class, 'index']);
            Route::get('/appointments/{token}', [CancellationController::class, 'show']);
        });

        Route::middleware('throttle:widget-book')->group(function () {
            Route::post('/appointments', [AppointmentController::class, 'store']);
            Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel']);
        });
    });
```

(Replace the flat route list from earlier tasks with this grouped version; keep the same `use` imports.)

- [ ] **Step 5: Publish & configure CORS**

Run: `php artisan config:publish cors`
Edit `config/cors.php`:

```php
'paths' => ['api/*'],
'allowed_methods' => ['*'],
'allowed_origins' => ['*'], // MVP: widget embeds on the tenant's own site; tighten per-tenant later
'allowed_headers' => ['*'],
'supports_credentials' => false,
```

- [ ] **Step 6: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetRateLimitTest`
Expected: PASS. Then re-run the booking suite to confirm 5/min still allows the earlier tests:
Run: `php artisan test --testsuite=tenant --filter=WidgetBookingTest`
Expected: PASS (each test makes ≤1 booking call).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/config/cors.php backend/tests/Feature/TenantSchema/WidgetRateLimitTest.php
git commit -m "feat: rate limiting (read/book) + CORS for the widget API"
```

---

### Task 10: Cross-tenant isolation + full-suite + README

**Files:**
- Test: `backend/tests/Feature/TenantSchema/WidgetCrossTenantTest.php`
- Modify: `backend/README.md`

- [ ] **Step 1: Write the isolation test**

```php
<?php
// tests/Feature/TenantSchema/WidgetCrossTenantTest.php
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;

it('does not leak appointments across tenants via the widget API', function () {
    // testtenant (the default) gets one appointment...
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);
    $count = Appointment::count();
    expect($count)->toBe(1);
    tenancy()->end();

    // ...a second tenant has its own (empty) schema.
    $other = Tenant::factory()->create(['id' => 'cabinet-x']);
    $other->domains()->create(['domain' => 'cabinet-x.masinga-booking.test', 'is_primary' => true]);

    // The widget API for cabinet-x sees zero services (its schema is empty).
    $this->getJson('http://central.masinga-booking.test/api/v1/widget/cabinet-x/services')
        ->assertOk()->assertJsonCount(0);
});
```

- [ ] **Step 2: Run, expect pass**

Run: `php artisan test --testsuite=tenant --filter=WidgetCrossTenantTest`
Expected: PASS.

- [ ] **Step 3: Run the entire test suite**

Run: `composer test`
Expected: central suite green + tenant suite green (all Phase 1 + Phase 2 tests).

- [ ] **Step 4: Update the README**

Add a "Booking API (Phase 2)" section to `backend/README.md` documenting the public endpoints:

```markdown
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
```

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Feature/TenantSchema/WidgetCrossTenantTest.php backend/README.md
git commit -m "test: widget cross-tenant isolation + README booking API section"
```

---

## End-of-Plan Acceptance Criteria

- `composer test` green (central + tenant suites).
- A parent can: list services → list practitioners for a service → fetch free slots → book for their child (consent required) → cancel by token; all via `/api/v1/widget/{slug}/*`.
- Double-booking under concurrency returns 409; honeypot drops bots; rate limits enforced; unknown slug → 404.
- Cross-tenant isolation holds across the widget API.

## Self-Review

- Spec §2 decisions → grid duration (T3), auto-confirm (T2 default + T7), specific practitioner (T5/T6), 2h/60d (T4 constants). ✓
- Spec §4 model → T2. §5 calculator → T3+T4. §6 API → T1/T5/T6/T7/T8. §7 lock → T7. §8 cancellation → T8. §9 consent/token → T7/T8. §10 tests → every task + T10. ✓
- Types consistent: `AvailabilityCalculator::forPractitionerService(Practitioner, Service, CarbonImmutable, CarbonImmutable): Collection<Slot>`; `Slot` public props `starts_at`/`ends_at`; relations `Practitioner::availabilities/availabilityExceptions/appointments` added in T3/T4. ✓
- No placeholders: every code step is complete. ✓
