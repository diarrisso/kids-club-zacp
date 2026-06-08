# Parent Cancellation Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send a parent-facing cancellation confirmation email on every cancellation path (parent storno link, widget API, staff `/termine`), while leaving the existing cabinet alert untouched.

**Architecture:** Mirror the existing cabinet notification. Add `AppointmentCancelledParentMail` (markdown mailable, parent-worded) + `emails.cancelled-parent` template + a `ParentNotifier::notifyCancelled()` helper (no-op if `parent_email` is null, `rescue()`-wrapped). Wire `ParentNotifier::notifyCancelled()` into the three cancellation entry points, only on a real cancel transition.

**Tech Stack:** Laravel 13 · PHP 8.4 · PostgreSQL · Pest 4 · Laravel markdown mailables.

---

## Context the implementer needs (read before starting)

- **Single-tenant app.** `App\Models\Tenant\*`, `App\Mail\*`, route names `tenant.*` are *vestigial* multi-tenant naming — one DB, one practice, no tenant resolution.
- **Run all commands from `backend/`.** Full suite: `composer test`. Single file: `php artisan test tests/Feature/TenantSchema/<File>.php`. Tests pinned to PostgreSQL (`phpunit.xml`); `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `PRACTICE_NOTIFICATION_EMAIL=praxis@kidsclub.test` are preset there.
- **Pint** formats PHP: `vendor/bin/pint app tests` before each commit. If pint reformats files you did NOT touch (the repo has pre-existing pint drift), `git checkout` those back — commit only your files.
- **The pattern to mirror** is the cabinet side:
  - `app/Mail/AppointmentCancelledMail.php` — markdown mailable, `from: new Address(config('mail.from.address'), $cabinetName)`, `content()` does `$this->appointment->loadMissing(['service', 'practitioner'])` then `return new Content(markdown: 'emails.cancelled')`.
  - `resources/views/emails/cancelled.blade.php` — `<x-mail::message>` markdown; uses `$appointment->starts_at->timezone('Europe/Berlin')`, `$appointment->service->name`, `$appointment->practitioner->fullName()`.
  - `app/Support/CabinetNotifier.php` — static `notifyCancelled()`, `rescue(fn () => Mail::to(...)->queue(new ...))`.
- **The three cancellation entry points** (all flip `status` to `cancelled`, return the appointment only on a real transition):
  - `app/Http/Controllers/Public/CancellationPageController.php::cancel()` — `$cancelled = DB::transaction(...)`; then `if ($cancelled) { CabinetNotifier::notifyCancelled($cancelled); }`.
  - `app/Http/Controllers/Widget/CancellationController.php::cancel()` — same shape, returns JSON.
  - `app/Http/Controllers/Tenant/AppointmentController.php::destroy()` — `if ($appointment->status !== 'cancelled') { $appointment->update(['status' => 'cancelled']); }`; currently notifies NOBODY. Staff routes are behind auth.
- **`parent_email` is NULLABLE** (manual staff bookings may omit it). `practitioner->fullName()` exists (used by the cabinet template).
- **CSRF in tests:** Laravel skips `VerifyCsrfToken` under `runningUnitTests()`, so `$this->post('/storno/...')` works without a token (existing `CancellationPageTest` relies on this).

---

## File Structure

| File | Responsibility | Action |
|------|----------------|--------|
| `app/Mail/AppointmentCancelledParentMail.php` | Parent-facing cancellation mailable (markdown, no internal data) | Create |
| `resources/views/emails/cancelled-parent.blade.php` | Parent cancellation email body (German) | Create |
| `app/Support/ParentNotifier.php` | Queue the parent mail; no-op if `parent_email` null; `rescue()`-wrapped | Create |
| `app/Http/Controllers/Public/CancellationPageController.php` | + parent notify on cancel | Modify |
| `app/Http/Controllers/Widget/CancellationController.php` | + parent notify on cancel | Modify |
| `app/Http/Controllers/Tenant/AppointmentController.php` | + parent notify on real `destroy` transition | Modify |
| `tests/Feature/TenantSchema/AppointmentMailRenderTest.php` | + render assertion for the parent mail | Modify |
| `tests/Feature/TenantSchema/ParentCancellationMailTest.php` | Integration: parent notified on all 3 paths, null-email + idempotent guards | Create |

---

## Task 1: Parent cancellation mailable + template (TDD via render test)

**Files:**
- Test: `tests/Feature/TenantSchema/AppointmentMailRenderTest.php` (Modify — add one render test)
- Create: `app/Mail/AppointmentCancelledParentMail.php`
- Create: `resources/views/emails/cancelled-parent.blade.php`

- [ ] **Step 1: Add the failing render test**

Append this `it(...)` block to `tests/Feature/TenantSchema/AppointmentMailRenderTest.php` (it reuses the existing `mailAppointment()` helper already defined at the top of that file):

```php
it('renders the parent cancellation mail in German without internal data', function () {
    $html = (new App\Mail\AppointmentCancelledParentMail(mailAppointment(), 'Kids Club'))->render();

    expect($html)
        ->toContain('storniert')
        ->toContain('Prophylaxe')   // service name from mailAppointment()
        ->toContain('Lina')         // patient first name
        ->toContain('Kids Club');
});
```

- [ ] **Step 2: Run it — expect FAIL**

Run: `php artisan test tests/Feature/TenantSchema/AppointmentMailRenderTest.php --filter="parent cancellation"`
Expected: FAIL — `Class "App\Mail\AppointmentCancelledParentMail" not found`.

- [ ] **Step 3: Create the mailable**

`app/Mail/AppointmentCancelledParentMail.php`:

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

/**
 * Parent-facing confirmation that their appointment was cancelled. Sent on every
 * cancellation path (parent storno, widget, staff). Distinct from the internal
 * AppointmentCancelledMail (cabinet alert); exposes no internal fields.
 */
class AppointmentCancelledParentMail extends Mailable implements ShouldQueue
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
            subject: "Ihr Termin wurde storniert — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.cancelled-parent');
    }
}
```

- [ ] **Step 4: Create the template**

`resources/views/emails/cancelled-parent.blade.php`:

```blade
<x-mail::message>
# Ihr Termin wurde storniert

Guten Tag {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }},

der folgende Termin wurde storniert:

- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}
- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}

Möchten Sie einen neuen Termin vereinbaren? Besuchen Sie unsere Website oder kontaktieren Sie uns direkt.

Freundliche Grüße
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 5: Run the render test — expect PASS**

Run: `php artisan test tests/Feature/TenantSchema/AppointmentMailRenderTest.php`
Expected: PASS (all render tests green, including the new parent one).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app tests
git add app/Mail/AppointmentCancelledParentMail.php resources/views/emails/cancelled-parent.blade.php tests/Feature/TenantSchema/AppointmentMailRenderTest.php
git commit -m "feat(mail): add parent-facing AppointmentCancelledParentMail + template"
```

---

## Task 2: ParentNotifier + wire all three cancellation paths (TDD via integration tests)

**Files:**
- Test: `tests/Feature/TenantSchema/ParentCancellationMailTest.php` (Create)
- Create: `app/Support/ParentNotifier.php`
- Modify: `app/Http/Controllers/Public/CancellationPageController.php`
- Modify: `app/Http/Controllers/Widget/CancellationController.php`
- Modify: `app/Http/Controllers/Tenant/AppointmentController.php`

- [ ] **Step 1: Write the failing integration test**

`tests/Feature/TenantSchema/ParentCancellationMailTest.php`:

```php
<?php

use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentCancelledParentMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function cancellableAppointment(array $overrides = []): Appointment
{
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();

    return Appointment::factory()->create(array_merge([
        'practitioner_id' => $p->id,
        'service_id' => $s->id,
        'status' => 'confirmed',
        'starts_at' => CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-09-07 09:30', 'Europe/Berlin'),
        'parent_email' => 'parent@example.de',
    ], $overrides));
}

it('emails the parent (and the cabinet) when cancelled via the storno page', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->post("/storno/{$a->cancellation_token}")->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
    Mail::assertQueued(AppointmentCancelledMail::class); // cabinet alert unchanged
});

it('emails the parent when cancelled via the widget API', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->postJson("/api/v1/widget/appointments/{$a->cancellation_token}/cancel")->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
});

it('emails the parent when cancelled by staff via /termine (no cabinet alert)', function () {
    Mail::fake();
    $a = cancellableAppointment();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/termine/{$a->id}")
        ->assertOk();

    Mail::assertQueued(AppointmentCancelledParentMail::class, fn ($m) => $m->hasTo('parent@example.de'));
    Mail::assertNotQueued(AppointmentCancelledMail::class); // staff cancel: no cabinet self-alert
});

it('does not email a parent without an address, but still cancels', function () {
    Mail::fake();
    $a = cancellableAppointment(['parent_email' => null]);

    $this->actingAs(User::factory()->create())
        ->deleteJson("/termine/{$a->id}")
        ->assertOk();

    expect($a->fresh()->status)->toBe('cancelled');
    Mail::assertNotQueued(AppointmentCancelledParentMail::class);
});

it('does not re-email on an already-cancelled appointment (idempotent)', function () {
    Mail::fake();
    $a = cancellableAppointment(['status' => 'cancelled']);

    $this->post("/storno/{$a->cancellation_token}")->assertOk();

    Mail::assertNotQueued(AppointmentCancelledParentMail::class);
});
```

- [ ] **Step 2: Run it — expect FAIL**

Run: `php artisan test tests/Feature/TenantSchema/ParentCancellationMailTest.php`
Expected: FAIL — first failure is `Class "App\Support\ParentNotifier" not found` once wired, but initially the parent-mail assertions fail because nothing queues `AppointmentCancelledParentMail`.

- [ ] **Step 3: Create the notifier**

`app/Support/ParentNotifier.php`:

```php
<?php

namespace App\Support;

use App\Mail\AppointmentCancelledParentMail;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the "your appointment was cancelled" confirmation to the parent.
 * No-op when parent_email is absent (manual staff bookings may have none).
 * Mirrors CabinetNotifier; rescue()-wrapped so a mail failure never fails
 * the user-facing cancellation.
 */
class ParentNotifier
{
    public static function notifyCancelled(Appointment $appointment): void
    {
        $email = $appointment->parent_email;
        if (! $email) {
            return;
        }

        rescue(fn () => Mail::to($email)->queue(
            new AppointmentCancelledParentMail($appointment, config('app.name'))
        ));
    }
}
```

- [ ] **Step 4: Wire the storno page controller**

In `app/Http/Controllers/Public/CancellationPageController.php`, add the import near the existing `use App\Support\CabinetNotifier;`:

```php
use App\Support\ParentNotifier;
```

Then, inside `cancel()`, in the existing `if ($cancelled) { ... }` block, add the parent notify after the cabinet one:

```php
        if ($cancelled) {
            CabinetNotifier::notifyCancelled($cancelled);
            ParentNotifier::notifyCancelled($cancelled);
        }
```

- [ ] **Step 5: Wire the widget controller**

In `app/Http/Controllers/Widget/CancellationController.php`, add the import near `use App\Support\CabinetNotifier;`:

```php
use App\Support\ParentNotifier;
```

Then in `cancel()`'s `if ($cancelled) { ... }` block:

```php
        if ($cancelled) {
            CabinetNotifier::notifyCancelled($cancelled);
            ParentNotifier::notifyCancelled($cancelled);
        }
```

- [ ] **Step 6: Wire the staff destroy controller**

In `app/Http/Controllers/Tenant/AppointmentController.php`, add the import (alphabetically near the other `App\Support`/`App\Services` uses):

```php
use App\Support\ParentNotifier;
```

Then change `destroy()` so the parent is notified ONLY on a real transition:

```php
    public function destroy(Appointment $appointment): JsonResponse
    {
        // Cabinet cancellation: free the slot (the feed excludes 'cancelled').
        // Notify the parent only on a real transition (not if already cancelled).
        if ($appointment->status !== 'cancelled') {
            $appointment->update(['status' => 'cancelled']);
            ParentNotifier::notifyCancelled($appointment);
        }

        return response()->json(['status' => 'cancelled']);
    }
```

- [ ] **Step 7: Run the integration test — expect PASS**

Run: `php artisan test tests/Feature/TenantSchema/ParentCancellationMailTest.php`
Expected: PASS (5 passed).

- [ ] **Step 8: Run the FULL suite — confirm no regressions**

Run: `composer test`
Expected: all green. Pay attention to `CancellationPageTest`, `WidgetCancellationTest`, `CabinetNotifierTest`, `CancelAppointmentTest` — if any asserted "nothing else was queued" it may now need updating; adjust only if it breaks, keeping its original intent.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint app tests
git add app/Support/ParentNotifier.php \
  app/Http/Controllers/Public/CancellationPageController.php \
  app/Http/Controllers/Widget/CancellationController.php \
  app/Http/Controllers/Tenant/AppointmentController.php \
  tests/Feature/TenantSchema/ParentCancellationMailTest.php
git commit -m "feat(cancel): notify the parent by email on all cancellation paths"
```

---

## Self-review notes (for the executor)

- **Spec §3.1 (mailable)** → Task 1 (mailable, no internal fields, `ShouldQueue`).
- **Spec §3.2 (template)** → Task 1 (`cancelled-parent.blade.php`, neutral German wording).
- **Spec §3.3 (ParentNotifier, null-guard, rescue)** → Task 2 Step 3.
- **Spec §3.4 (wire 3 paths, real-transition only)** → Task 2 Steps 4–6.
- **Spec §5 (tests)** → Task 1 render test + Task 2's 5 integration cases (storno, widget, staff, null-email, idempotent).
- **Type consistency:** `AppointmentCancelledParentMail(Appointment $appointment, string $cabinetName)` constructed identically in the render test, `ParentNotifier`, and matches the cabinet mailable's two-arg shape. `ParentNotifier::notifyCancelled(Appointment)` static, same signature as `CabinetNotifier::notifyCancelled`.
- **No `.env`/cron/server change** — this ships purely through the normal deploy pipeline (unlike the email-reliability work). Reminder: deploy only on the user's explicit "deploy".
- **Loose end:** a test appointment (cancellation_token `8a585db2-16cb-45bb-8d5e-06a8a10c53b6`) is currently seeded in PROD for the storno demo — unrelated to this code; clean it up (delete) when convenient, independent of this plan.
