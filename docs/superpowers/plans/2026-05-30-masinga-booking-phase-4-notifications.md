# Phase 4 — Notifications email · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send three queued German transactional emails around an appointment's lifecycle (confirmation to parent, 24h reminder to parent, cancellation alert to cabinet) plus a public Blade cancellation page that the email links target.

**Architecture:** Driver-agnostic Laravel Mail (dev `log`, prod `postmark`, tests `array`+`Mail::fake()`). Each email is an isolated `ShouldQueue` Mailable rendered from a Markdown Blade view, dispatched at three triggers: booking (`AppointmentController::store`), cancellation (API `CancellationController::cancel` **and** the new web page), and an hourly multi-tenant scan command (`appointments:send-reminders`). A `CabinetNotifier` support class centralises "who at the cabinet gets notified + queue the cancelled mail". A new tenant migration adds `reminder_sent_at` for reminder idempotency. The public cancellation page is a standalone Blade route group in `web.php` using `InitializeTenancyByPath` (path-based tenant, gets `web` middleware for session+CSRF).

**Tech Stack:** Laravel 11, stancl/tenancy v3 (schema-per-tenant PostgreSQL, `QueueTenancyBootstrapper` already enabled → queued jobs auto-restore tenant context), Pest 3, Markdown Mailables, Redis queue (prod).

---

## Key facts the engineer must know (verified against the codebase)

- **Two test suites run as separate processes** via `composer test`: `Unit,central` (RefreshDatabase) then `tenant` (real committed schemas). NEVER run both in one process — deadlock. All Phase 4 backend tests that touch appointments/tenant data go in `backend/tests/Feature/TenantSchema/` (extend `TenantTestCase`, see `backend/tests/Pest.php`). Run them with: `php artisan test --testsuite=tenant`.
- **`TenantTestCase`** (`backend/tests/TenantTestCase.php`) gives you: `$this->tenant` (id `testtenant`), an initialized tenant context in `setUp()`, and `$this->makeTenantUser()` → a `tenant_owner` `User` row (central) belonging to the test tenant. After an HTTP call in a test, the tenant context is torn down by the request lifecycle — re-call `tenancy()->initialize($this->tenant)` before asserting on tenant-DB rows (see existing `WidgetBookingTest`).
- **Path identification consumes the `{tenant}` route param** before the controller runs. So controller methods receive only the *remaining* params (e.g. `CancellationController::show(string $token)` — no `$tenant` arg). Do the same for the web page controller.
- **`tenant()`** returns the current `App\Models\Tenant`; `tenant()->name` and `tenant()->getTenantKey()` (the schema/path id, e.g. `testtenant`) are available.
- **`Appointment`** (`app/Models/Tenant/Appointment.php`): UUID PK; fields incl. `parent_email`, `parent_first_name`, `patient_first_name`, `starts_at`/`ends_at` (cast `datetime`), `status`, `cancellation_token`; `service()` and `practitioner()` are `belongsTo` relations. `Practitioner::fullName()` exists. `Service` has `name`.
- **Mail config** (`config/mail.php`): `from.address` from `MAIL_FROM_ADDRESS`. Per-email from-name = the cabinet name, set via the Mailable `Envelope`.
- **Tenant migrations** live in `backend/database/migrations/tenant/`, run automatically on tenant creation. Last one is `2026_06_01_000015_create_appointments_table.php`.
- All commits in English, branch is already `feature/phase-4-notifications`. Co-author trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## File Structure

**Create:**
- `backend/database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php` — adds nullable `reminder_sent_at`.
- `backend/app/Support/CabinetNotifier.php` — `recipients(): array` + `notifyCancelled(Appointment): void`.
- `backend/app/Mail/AppointmentConfirmationMail.php` — parent confirmation (ShouldQueue).
- `backend/app/Mail/AppointmentReminderMail.php` — parent 24h reminder (ShouldQueue).
- `backend/app/Mail/AppointmentCancelledMail.php` — cabinet cancellation alert (ShouldQueue).
- `backend/resources/views/emails/confirmation.blade.php` — German Markdown.
- `backend/resources/views/emails/reminder.blade.php` — German Markdown.
- `backend/resources/views/emails/cancelled.blade.php` — German Markdown.
- `backend/app/Console/Commands/SendAppointmentReminders.php` — `appointments:send-reminders`.
- `backend/app/Http/Controllers/Public/CancellationPageController.php` — Blade page show + cancel.
- `backend/resources/views/storno/show.blade.php` — page with cancel button.
- `backend/resources/views/storno/done.blade.php` — confirmation of cancellation.
- Test files under `backend/tests/Feature/TenantSchema/`: `CabinetNotifierTest.php`, `AppointmentMailRenderTest.php`, `BookingConfirmationMailTest.php`, `CancellationMailTest.php`, `SendAppointmentRemindersTest.php`, `CancellationPageTest.php`.

**Modify:**
- `backend/app/Models/Tenant/Appointment.php` — add `reminder_sent_at` to `$fillable` + `$casts`.
- `backend/app/Http/Controllers/Widget/AppointmentController.php` — queue confirmation after booking.
- `backend/app/Http/Controllers/Widget/CancellationController.php` — queue cabinet alert on API cancel.
- `backend/routes/web.php` — add the `/storno/{tenant}/{token}` group.
- `backend/routes/console.php` — schedule `appointments:send-reminders` hourly.
- `backend/.env.example` — document Postmark + `MAIL_FROM_*`.

---

## Task 1: Migration + model field for `reminder_sent_at`

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php`
- Modify: `backend/app/Models/Tenant/Appointment.php`
- Test: `backend/tests/Feature/TenantSchema/AppointmentModelTest.php` (append)

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php`:

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
        Schema::table('appointments', function (Blueprint $table) {
            // Set once when the 24h reminder is queued, so it is never sent twice.
            $table->timestamp('reminder_sent_at')->nullable()->after('parent_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
```

- [ ] **Step 2: Add the field to the model**

In `backend/app/Models/Tenant/Appointment.php`, add `'reminder_sent_at'` to `$fillable` (after `'cancellation_token'`) and to `$casts`:

```php
    protected $fillable = [
        'practitioner_id', 'service_id', 'starts_at', 'ends_at', 'status',
        'patient_first_name', 'patient_last_name', 'patient_birthdate',
        'parent_first_name', 'parent_last_name', 'parent_email', 'parent_phone',
        'parent_consent_at', 'notes_parent', 'cancellation_token', 'reminder_sent_at',
        // notes_internal is staff-only; never mass-assignable from the public API
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'patient_birthdate' => 'date',
        'parent_consent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];
```

- [ ] **Step 3: Write the failing test**

Append to `backend/tests/Feature/TenantSchema/AppointmentModelTest.php`:

```php
it('stores reminder_sent_at as a nullable datetime', function () {
    $p = \App\Models\Tenant\Practitioner::factory()->create();
    $s = \App\Models\Tenant\Service::factory()->create();

    $a = \App\Models\Tenant\Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
    ]);
    expect($a->reminder_sent_at)->toBeNull();

    $a->update(['reminder_sent_at' => now()]);
    expect($a->fresh()->reminder_sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --testsuite=tenant --filter="reminder_sent_at"`
Expected: PASS (the new tenant schema is migrated fresh per test, so the column exists).

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php app/Models/Tenant/Appointment.php tests/Feature/TenantSchema/AppointmentModelTest.php
git commit -m "feat: add reminder_sent_at column to appointments"
```

---

## Task 2: `CabinetNotifier` support class

Centralises "which cabinet emails to notify" and "queue the cancellation alert", so the API controller, the web page, and tests share one path.

**Files:**
- Create: `backend/app/Support/CabinetNotifier.php`
- Test: `backend/tests/Feature/TenantSchema/CabinetNotifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/CabinetNotifierTest.php`:

```php
<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use App\Support\CabinetNotifier;
use Illuminate\Support\Facades\Mail;

it('returns the tenant_owner emails of the current tenant', function () {
    $owner = User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    // A user of another role must be excluded.
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'staff', 'email' => 'staff@kidsclub.de',
    ]);

    expect(CabinetNotifier::recipients())->toBe(['praxis@kidsclub.de']);
});

it('queues a cancellation mail to every cabinet recipient', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('queues nothing when the cabinet has no tenant_owner', function () {
    Mail::fake();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=CabinetNotifier`
Expected: FAIL — `Class "App\Support\CabinetNotifier" not found` (and `AppointmentCancelledMail` not found; that arrives in Task 3 — these two tasks are co-dependent, so if you run Task 2 standalone, expect the mail-class error until Task 3 lands. Implement Step 3 now; the `recipients()` test passes immediately, the mail tests pass after Task 3).

- [ ] **Step 3: Implement `CabinetNotifier`**

Create `backend/app/Support/CabinetNotifier.php`:

```php
<?php

namespace App\Support;

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Who, at the current tenant cabinet, gets operational notifications — and the
 * one place that queues the "an appointment was cancelled" alert. The central
 * User model is pinned to the central connection (CentralConnection trait), so
 * this query is safe to run inside a tenant context.
 */
class CabinetNotifier
{
    /** @return list<string> emails of the current tenant's owners */
    public static function recipients(): array
    {
        return User::query()
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('role', 'tenant_owner')
            ->pluck('email')
            ->all();
    }

    /** Queue the cancellation alert to every cabinet recipient (no-op if none). */
    public static function notifyCancelled(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        $appointment->loadMissing(['service', 'practitioner']);

        Mail::to($recipients)->queue(
            new AppointmentCancelledMail($appointment, tenant()->name)
        );
    }
}
```

- [ ] **Step 4: Run the `recipients` test now (mail tests pass after Task 3)**

Run: `cd backend && php artisan test --testsuite=tenant --filter="returns the tenant_owner emails"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Support/CabinetNotifier.php tests/Feature/TenantSchema/CabinetNotifierTest.php
git commit -m "feat: add CabinetNotifier for tenant cabinet notifications"
```

---

## Task 3: The three Mailables + Markdown views

All three are `ShouldQueue`, Markdown-rendered, with the cabinet name as the from-name. German content. We pass the `Appointment` model (safe: `QueueTenancyBootstrapper` restores tenant context on the worker) and eager-load relations before queueing so the worker never lazy-loads outside a tenant.

**Files:**
- Create: `backend/app/Mail/AppointmentConfirmationMail.php`, `AppointmentReminderMail.php`, `AppointmentCancelledMail.php`
- Create: `backend/resources/views/emails/{confirmation,reminder,cancelled}.blade.php`
- Test: `backend/tests/Feature/TenantSchema/AppointmentMailRenderTest.php`

- [ ] **Step 1: Write the failing render test**

Create `backend/tests/Feature/TenantSchema/AppointmentMailRenderTest.php`:

```php
<?php

use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmationMail;
use App\Mail\AppointmentReminderMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;

function mailAppointment(): Appointment
{
    $p = Practitioner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Berg', 'title' => 'Dr.']);
    $s = Service::factory()->create(['name' => 'Prophylaxe']);

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-09-07 09:30', 'Europe/Berlin'),
        'patient_first_name' => 'Lina', 'parent_first_name' => 'Sven',
    ])->load(['service', 'practitioner']);
}

it('renders the confirmation mail in German with the cancel link', function () {
    $html = (new AppointmentConfirmationMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)
        ->toContain('bestätigt')
        ->toContain('Prophylaxe')
        ->toContain('Lina')
        ->toContain('https://x.test/storno/t/abc');
});

it('renders the reminder mail in German with the cancel link', function () {
    $html = (new AppointmentReminderMail(mailAppointment(), 'Kids Club', 'https://x.test/storno/t/abc'))->render();

    expect($html)->toContain('Erinnerung')->toContain('Prophylaxe')->toContain('https://x.test/storno/t/abc');
});

it('renders the cancelled mail for the cabinet', function () {
    $html = (new AppointmentCancelledMail(mailAppointment(), 'Kids Club'))->render();

    expect($html)->toContain('storniert')->toContain('Prophylaxe')->toContain('Lina');
});

it('sets the cabinet name as the from-name on every mail', function () {
    $a = mailAppointment();
    $env = (new AppointmentConfirmationMail($a, 'Kids Club', 'https://x.test'))->envelope();
    expect($env->from->name)->toBe('Kids Club')
        ->and($env->subject)->toContain('Kids Club');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=AppointmentMailRender`
Expected: FAIL — `Class "App\Mail\AppointmentConfirmationMail" not found`.

- [ ] **Step 3: Implement `AppointmentConfirmationMail`**

Create `backend/app/Mail/AppointmentConfirmationMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
        public string $cancelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ihr Termin bei {$this->cabinetName} ist bestätigt",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.confirmation');
    }
}
```

- [ ] **Step 4: Implement `AppointmentReminderMail`**

Create `backend/app/Mail/AppointmentReminderMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
        public string $cancelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Erinnerung: Ihr Termin morgen bei {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.reminder');
    }
}
```

- [ ] **Step 5: Implement `AppointmentCancelledMail`**

Create `backend/app/Mail/AppointmentCancelledMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ein Termin wurde storniert — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.cancelled');
    }
}
```

- [ ] **Step 6: Create the confirmation view**

Create `backend/resources/views/emails/confirmation.blade.php`:

```blade
<x-mail::message>
# Ihr Termin ist bestätigt

Hallo {{ $appointment->parent_first_name }},

der Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** wurde gebucht.

- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Sollten Sie den Termin nicht wahrnehmen können, stornieren Sie ihn bitte rechtzeitig über den Button oben.

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 7: Create the reminder view**

Create `backend/resources/views/emails/reminder.blade.php`:

```blade
<x-mail::message>
# Erinnerung an Ihren Termin

Hallo {{ $appointment->parent_first_name }},

wir möchten Sie an den morgigen Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** erinnern.

- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Bis morgen!<br>
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 8: Create the cancelled view (cabinet-facing)**

Create `backend/resources/views/emails/cancelled.blade.php`:

```blade
<x-mail::message>
# Ein Termin wurde storniert

Ein Patiententermin wurde über die Online-Buchung storniert.

- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}
- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}
- **Elternteil:** {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }}

Der Termin ist nun wieder frei buchbar.

{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 9: Run the render test + the deferred CabinetNotifier mail tests**

Run: `cd backend && php artisan test --testsuite=tenant --filter="AppointmentMailRender|CabinetNotifier"`
Expected: PASS (all render assertions + the two mail tests from Task 2).

- [ ] **Step 10: Commit**

```bash
cd backend && git add app/Mail/ resources/views/emails/ tests/Feature/TenantSchema/AppointmentMailRenderTest.php
git commit -m "feat: add appointment confirmation, reminder and cancellation mailables"
```

---

## Task 4: Cancellation web page (`/storno/{tenant}/{token}`)

The link in every email. Standalone Blade (not Inertia), German, minimal inline styling. `web` middleware gives session + CSRF; `InitializeTenancyByPath` resolves the tenant from the path. We build this **before** wiring the confirmation email (Task 6) because the email needs `route('storno.show', ...)`.

**Files:**
- Create: `backend/app/Http/Controllers/Public/CancellationPageController.php`
- Create: `backend/resources/views/storno/show.blade.php`, `backend/resources/views/storno/done.blade.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/CancellationPageTest.php`

- [ ] **Step 1: Add the route group**

In `backend/routes/web.php`, add `use` imports at the top and append the group **after** the `foreach` (outside the domain constraint — the page works via path on any domain):

```php
use App\Http\Controllers\Public\CancellationPageController;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
```

```php
/*
 * Public cancellation page — the target of the link in appointment emails.
 * Path-based tenant (/storno/{tenant}/...). 'web' supplies session + CSRF for
 * the POST form; this is a separate group from the central Route::domain routes.
 */
Route::middleware(['web', InitializeTenancyByPath::class])
    ->prefix('storno/{tenant}')
    ->group(function () {
        Route::get('/{token}', [CancellationPageController::class, 'show'])->name('storno.show');
        Route::post('/{token}', [CancellationPageController::class, 'cancel'])->name('storno.cancel');
    });
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/TenantSchema/CancellationPageTest.php`:

```php
<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function stornoAppointment(string $status = 'confirmed'): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create(['name' => 'Prophylaxe']);

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => $status,
    ]);
}

function stornoUrl(Appointment $a): string
{
    return "http://central.masinga-booking.test/storno/testtenant/{$a->cancellation_token}";
}

it('shows the cancellation page with the appointment details', function () {
    $a = stornoAppointment();

    $this->get(stornoUrl($a))
        ->assertOk()
        ->assertSee('Prophylaxe')
        ->assertSee('Termin stornieren');
});

it('returns 404 for an unknown token', function () {
    $this->get('http://central.masinga-booking.test/storno/testtenant/'.Str::uuid())
        ->assertNotFound();
});

it('cancels the appointment from the page and notifies the cabinet', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $a = stornoAppointment();

    $this->post(stornoUrl($a))
        ->assertOk()
        ->assertSee('storniert');

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('does not re-cancel or re-notify an already cancelled appointment', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $a = stornoAppointment('cancelled');

    $this->post(stornoUrl($a))->assertOk();

    Mail::assertNothingQueued();
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=CancellationPage`
Expected: FAIL — controller/views missing (route resolves, controller class not found).

- [ ] **Step 4: Implement the controller**

Create `backend/app/Http/Controllers/Public/CancellationPageController.php` (note: `InitializeTenancyByPath` consumes the `{tenant}` param, so methods receive only `$token`):

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Support\CabinetNotifier;
use Illuminate\Contracts\View\View;

class CancellationPageController extends Controller
{
    public function show(string $token): View
    {
        $appointment = Appointment::where('cancellation_token', $token)
            ->with(['service', 'practitioner'])
            ->firstOrFail();

        if ($appointment->status === 'cancelled') {
            return view('storno.done', ['cabinetName' => tenant()->name]);
        }

        return view('storno.show', [
            'appointment' => $appointment,
            'cabinetName' => tenant()->name,
            'token' => $token,
        ]);
    }

    public function cancel(string $token): View
    {
        $appointment = Appointment::where('cancellation_token', $token)->firstOrFail();

        // Idempotent: only cancel + notify once. A second POST is a no-op.
        if ($appointment->status !== 'cancelled') {
            $appointment->update(['status' => 'cancelled']);
            CabinetNotifier::notifyCancelled($appointment);
        }

        return view('storno.done', ['cabinetName' => tenant()->name]);
    }
}
```

- [ ] **Step 5: Create the show view**

Create `backend/resources/views/storno/show.blade.php`:

```blade
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termin stornieren — {{ $cabinetName }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; color: #111827; }
        .card { max-width: 32rem; margin: 0 auto; background: #fff; border-radius: 0.75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        dl { margin: 1rem 0; }
        dt { font-weight: 600; color: #6b7280; font-size: .875rem; }
        dd { margin: 0 0 .75rem; }
        button { background: #dc2626; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.5rem; font-size: 1rem; cursor: pointer; }
        button:hover { background: #b91c1c; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Termin stornieren</h1>
        <p>Möchten Sie den folgenden Termin bei <strong>{{ $cabinetName }}</strong> stornieren?</p>
        <dl>
            <dt>Datum</dt>
            <dd>{{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}</dd>
            <dt>Uhrzeit</dt>
            <dd>{{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr</dd>
            <dt>Leistung</dt>
            <dd>{{ $appointment->service->name }}</dd>
            <dt>Kind</dt>
            <dd>{{ $appointment->patient_first_name }}</dd>
        </dl>
        <form method="POST" action="{{ route('storno.cancel', ['tenant' => tenant()->getTenantKey(), 'token' => $token]) }}">
            @csrf
            <button type="submit">Termin stornieren</button>
        </form>
    </div>
</body>
</html>
```

- [ ] **Step 6: Create the done view**

Create `backend/resources/views/storno/done.blade.php`:

```blade
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termin storniert — {{ $cabinetName }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; color: #111827; }
        .card { max-width: 32rem; margin: 0 auto; background: #fff; border-radius: 0.75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ihr Termin wurde storniert</h1>
        <p>Vielen Dank. Falls Sie einen neuen Termin benötigen, buchen Sie jederzeit online bei <strong>{{ $cabinetName }}</strong>.</p>
    </div>
</body>
</html>
```

- [ ] **Step 7: Run the test**

Run: `cd backend && php artisan test --testsuite=tenant --filter=CancellationPage`
Expected: PASS (all four cases).

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Http/Controllers/Public/CancellationPageController.php resources/views/storno/ routes/web.php tests/Feature/TenantSchema/CancellationPageTest.php
git commit -m "feat: add public cancellation page at /storno/{tenant}/{token}"
```

---

## Task 5: Queue the confirmation email on booking

**Files:**
- Modify: `backend/app/Http/Controllers/Widget/AppointmentController.php`
- Test: `backend/tests/Feature/TenantSchema/BookingConfirmationMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/BookingConfirmationMailTest.php`:

```php
<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function confirmBookingSetup(): array
{
    $p = Practitioner::factory()->create(['is_active' => true]);
    $s = Service::factory()->create(['duration_minutes' => 30, 'is_active' => true]);
    $s->practitioners()->attach($p->id);
    Availability::factory()->create([
        'practitioner_id' => $p->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    $monday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
    $startsAt = CarbonImmutable::parse($monday->toDateString().' 09:00', 'Europe/Berlin');

    return [$p, $s, $startsAt];
}

it('queues a confirmation mail to the parent after a successful booking', function () {
    Mail::fake();
    [$p, $s, $startsAt] = confirmBookingSetup();

    $this->postJson('http://central.masinga-booking.test/api/v1/widget/testtenant/appointments', [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'parent_phone' => '+49 170 0000000',
        'consent' => true, 'website' => '',
    ])->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class, fn ($m) => $m->hasTo('anna@example.de'));
});

it('does not queue a confirmation mail when the honeypot is filled', function () {
    Mail::fake();
    [$p, $s, $startsAt] = confirmBookingSetup();

    $this->postJson('http://central.masinga-booking.test/api/v1/widget/testtenant/appointments', [
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'patient_first_name' => 'Lina', 'patient_last_name' => 'Müller', 'patient_birthdate' => '2019-04-12',
        'parent_first_name' => 'Anna', 'parent_last_name' => 'Müller',
        'parent_email' => 'anna@example.de', 'consent' => true, 'website' => 'http://spam.test',
    ])->assertOk();

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=BookingConfirmationMail`
Expected: FAIL — first test fails on `assertQueued` (no mail queued yet).

- [ ] **Step 3: Wire the confirmation into `store`**

In `backend/app/Http/Controllers/Widget/AppointmentController.php`, add imports:

```php
use App\Mail\AppointmentConfirmationMail;
use Illuminate\Support\Facades\Mail;
```

Then, between the `DB::transaction(...)` block (which ends at `});`) and the `return response()->json([...], 201);`, add:

```php
        // Notify the parent. Queued so it never blocks the booking response; the
        // cancel link targets the public /storno page (path-based tenant).
        $appointment->loadMissing(['service', 'practitioner']);
        $cancelUrl = route('storno.show', [
            'tenant' => tenant()->getTenantKey(),
            'token' => $appointment->cancellation_token,
        ]);
        Mail::to($appointment->parent_email)->queue(
            new AppointmentConfirmationMail($appointment, tenant()->name, $cancelUrl)
        );
```

(The honeypot branch returns early before any `Appointment` is created, so no mail is queued — the second test passes for free.)

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --testsuite=tenant --filter=BookingConfirmationMail`
Expected: PASS (both cases).

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Http/Controllers/Widget/AppointmentController.php tests/Feature/TenantSchema/BookingConfirmationMailTest.php
git commit -m "feat: queue confirmation email to parent on booking"
```

---

## Task 6: Notify the cabinet on API cancellation

The widget cancels via `POST /api/v1/widget/{tenant}/appointments/{token}/cancel`. Route it through `CabinetNotifier` so it matches the web page's behaviour.

**Files:**
- Modify: `backend/app/Http/Controllers/Widget/CancellationController.php`
- Test: `backend/tests/Feature/TenantSchema/CancellationMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/CancellationMailTest.php`:

```php
<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('notifies the cabinet when a parent cancels via the API', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'confirmed',
    ]);

    $this->postJson("http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/{$a->cancellation_token}/cancel")
        ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('does not re-notify when cancelling an already cancelled appointment via the API', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id, 'status' => 'cancelled',
    ]);

    $this->postJson("http://central.masinga-booking.test/api/v1/widget/testtenant/appointments/{$a->cancellation_token}/cancel")
        ->assertOk();

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=CancellationMail`
Expected: FAIL — first test fails on `assertQueued`.

- [ ] **Step 3: Wire the cabinet notification into `cancel`**

Replace the body of `cancel` in `backend/app/Http/Controllers/Widget/CancellationController.php`, adding the import `use App\Support\CabinetNotifier;` at the top:

```php
    public function cancel(string $token): JsonResponse
    {
        $a = Appointment::where('cancellation_token', $token)->firstOrFail();

        // Idempotent: only flip + notify the cabinet once.
        if ($a->status !== 'cancelled') {
            $a->update(['status' => 'cancelled']);
            CabinetNotifier::notifyCancelled($a);
        }

        return response()->json(['status' => 'cancelled']);
    }
```

- [ ] **Step 4: Run the test (and the existing cancellation test to confirm no regression)**

Run: `cd backend && php artisan test --testsuite=tenant --filter="CancellationMail|cancels an appointment by token"`
Expected: PASS (new mail cases + existing `WidgetCancellationTest` still green).

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Http/Controllers/Widget/CancellationController.php tests/Feature/TenantSchema/CancellationMailTest.php
git commit -m "feat: notify cabinet on API appointment cancellation"
```

---

## Task 7: `appointments:send-reminders` command + schedule

Hourly multi-tenant scan: for each tenant, find `confirmed` appointments starting in [now+24h, now+25h) with no `reminder_sent_at`, queue the reminder, mark it sent. A per-appointment `try/catch` means one failure doesn't abort the batch.

**Files:**
- Create: `backend/app/Console/Commands/SendAppointmentReminders.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/TenantSchema/SendAppointmentRemindersTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/SendAppointmentRemindersTest.php`:

```php
<?php

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

// Create a confirmed appointment in the CURRENT tenant context starting at $startsAt.
function reminderAppointment(CarbonImmutable $startsAt, string $status = 'confirmed', ?CarbonImmutable $reminderSentAt = null): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    return Appointment::factory()->create([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(30),
        'status' => $status, 'reminder_sent_at' => $reminderSentAt,
        'parent_email' => 'anna@example.de',
    ]);
}

it('queues a reminder for an appointment ~24h out and marks it sent', function () {
    Mail::fake();
    $a = reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30));

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, fn ($m) => $m->hasTo('anna@example.de'));

    tenancy()->initialize($this->tenant);
    expect($a->fresh()->reminder_sent_at)->not->toBeNull();
});

it('does not queue a reminder for an appointment more than 25h out', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(26));

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not re-send a reminder that was already sent', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30), 'confirmed', CarbonImmutable::now());

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('ignores cancelled appointments', function () {
    Mail::fake();
    reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30), 'cancelled');

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('only reminds within the tenant that owns the appointment', function () {
    Mail::fake();
    tenancy()->end(); // leave the default test tenant

    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);
    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    tenancy()->initialize($tenantA);
    $aId = reminderAppointment(CarbonImmutable::now()->addHours(24)->addMinutes(30))->id;
    tenancy()->end();

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, 1); // exactly one, from tenant A

    tenancy()->initialize($tenantA);
    expect(Appointment::find($aId)->reminder_sent_at)->not->toBeNull();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(Appointment::count())->toBe(0);
    tenancy()->end();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php artisan test --testsuite=tenant --filter=SendAppointmentReminders`
Expected: FAIL — `Command "appointments:send-reminders" is not defined`.

- [ ] **Step 3: Implement the command**

Create `backend/app/Console/Commands/SendAppointmentReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Queue a 24h reminder email for each upcoming confirmed appointment (all tenants).';

    public function handle(): int
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $tenant->run(function () {
                Appointment::query()
                    ->where('status', 'confirmed')
                    ->whereNull('reminder_sent_at')
                    ->whereBetween('starts_at', [now()->addHours(24), now()->addHours(25)])
                    ->with(['service', 'practitioner'])
                    ->get()
                    ->each(function (Appointment $appointment) {
                        try {
                            $cancelUrl = route('storno.show', [
                                'tenant' => tenant()->getTenantKey(),
                                'token' => $appointment->cancellation_token,
                            ]);
                            Mail::to($appointment->parent_email)->queue(
                                new AppointmentReminderMail($appointment, tenant()->name, $cancelUrl)
                            );
                            $appointment->update(['reminder_sent_at' => now()]);
                        } catch (\Throwable $e) {
                            // One bad appointment must not abort the whole batch.
                            report($e);
                        }
                    });
            });
        });

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --testsuite=tenant --filter=SendAppointmentReminders`
Expected: PASS (all five cases, including multi-tenant isolation).

- [ ] **Step 5: Schedule the command**

In `backend/routes/console.php`, add the import and schedule call:

```php
use Illuminate\Support\Facades\Schedule;
```

```php
// 24h appointment reminders: hourly scan across all tenants. The [24h,25h)
// window + hourly cadence means each appointment is reminded exactly once.
Schedule::command('appointments:send-reminders')->hourly()->withoutOverlapping();
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `cd backend && php artisan schedule:list`
Expected: output lists `appointments:send-reminders` running `0 * * * *` (hourly).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Console/Commands/SendAppointmentReminders.php routes/console.php tests/Feature/TenantSchema/SendAppointmentRemindersTest.php
git commit -m "feat: add hourly appointments:send-reminders command"
```

---

## Task 8: Mail configuration & env documentation

Wire Postmark for production and document the env vars. No behaviour change in tests (they use `array` + `Mail::fake()`).

**Files:**
- Modify: `backend/.env.example`

- [ ] **Step 1: Document the mail env in `.env.example`**

In `backend/.env.example`, ensure the mail block reads (add/adjust keys; keep `MAIL_MAILER=log` as the dev default):

```dotenv
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@masinga-booking.de"
MAIL_FROM_NAME="Masinga Booking"

# Production: set MAIL_MAILER=postmark and provide the server token.
POSTMARK_TOKEN=
```

`config/services.php` already has a `postmark` block and `config/mail.php` already defines the `postmark` transport — no code change needed there. The per-email from-name (the cabinet) overrides `MAIL_FROM_NAME` via the Mailable `Envelope`.

- [ ] **Step 2: Run the full backend suite to confirm everything is green**

Run: `cd backend && composer test`
Expected: PASS — `Unit,central` suite, then `tenant` suite (all Phase 4 tests included).

- [ ] **Step 3: Commit**

```bash
cd backend && git add .env.example
git commit -m "docs: document Postmark + mail-from env for production"
```

---

## Manual / Chrome verification (after all tasks)

1. **Confirmation + cancel page (dev `log` driver):**
   - `cd backend && php artisan serve` (and a queue worker: `php artisan queue:work` — or set `QUEUE_CONNECTION=sync` in `.env` for the check).
   - Book an appointment through the widget test page (`public/widget/test.html`) against the seeded tenant.
   - Confirm a confirmation email is written to `storage/logs/laravel.log`; copy the `/storno/{tenant}/{token}` link from it.
   - Open that link in Chrome → the German cancellation page renders with the appointment details and a red "Termin stornieren" button.
   - Click it → "Ihr Termin wurde storniert"; check the log for the cabinet `AppointmentCancelledMail`; re-open the link → it now shows the cancelled state.
2. **Reminder command:** seed a confirmed appointment ~24.5h out, run `php artisan appointments:send-reminders`, confirm one reminder email in the log and `reminder_sent_at` set (re-run → nothing sent).
3. Capture a short GIF of the cancel-page flow for the PR.

---

## Self-Review (done while writing — notes for the executor)

- **Spec coverage:** confirmation (Task 5) · reminder command + schedule + `reminder_sent_at` (Tasks 1, 7) · cabinet cancellation on both API and web page (Tasks 4, 6) · `CabinetNotifier` (Task 2) · `/storno` Blade page with `['web', InitializeTenancyByPath]` (Task 4) · driver-agnostic mail + Postmark prod + cabinet from-name (Tasks 3, 8) · multi-tenant isolation + idempotency + DSGVO (tests in Tasks 6, 7) — every spec section maps to a task.
- **Type/signature consistency:** Mailable constructors are stable across tasks — `AppointmentConfirmationMail($appointment, $cabinetName, $cancelUrl)`, `AppointmentReminderMail($appointment, $cabinetName, $cancelUrl)`, `AppointmentCancelledMail($appointment, $cabinetName)`. `CabinetNotifier::recipients(): array` / `notifyCancelled(Appointment): void`. Route name `storno.show` used consistently in Tasks 4, 5, 7. Controller methods take only `$token` (the `{tenant}` param is consumed by `InitializeTenancyByPath`).
- **No placeholders:** every code/test step contains complete content.
- **Test placement:** all backend tests are in `Feature/TenantSchema` (run via `--testsuite=tenant`), matching `tests/Pest.php`.
```
