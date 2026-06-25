# Staff-App Finalisation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendre la branche `feature/warteliste-hifi-redesign` déployable : supprimer Serientermine (redondant), corriger 2 bugs bloquants, et câbler réellement les réglages Einstellungen.

**Architecture:** Laravel 13 + Inertia 2 + Vue 3 + PostgreSQL. Les réglages vivent dans le singleton typé `PracticeSettings::current()`, lu par la commande de rappel, le contrôleur de réservation widget et le contrôleur d'annulation. Suppression d'une feature, branchements ciblés, tests Pest pour chaque comportement.

**Tech Stack:** Pest 4, Mailables Laravel (queue, `Mail::fake()`), Yasumi (déjà en place).

## Global Constraints

- **PostgreSQL** : pas de string vide là où une heure est attendue (NOT NULL `start_time`/`end_time`).
- **Mono-cabinet** : « Tenant » est vestigial — pas de scoping multi-tenant.
- **URLs allemandes, noms de routes anglais** — toujours `route('name')`.
- **Canal de rappel = e-mail uniquement** (SMS hors-scope, non implémenté).
- **`Appointment::$fillable` exclut** `notes_internal` et `reminder_sent_at` — ne pas les rendre mass-assignable.
- Tests dans `tests/Feature/TenantSchema/`, suite via `composer test`.

---

### Task 1: Supprimer Serientermine

**Files:**
- Delete: `backend/app/Http/Controllers/Tenant/BulkAppointmentController.php`
- Delete: `backend/resources/js/Pages/Tenant/BulkAppointments/Index.vue`
- Modify: `backend/routes/web.php` (retirer les routes + l'import `BulkAppointmentController`)
- Modify: `backend/resources/js/Layouts/TenantLayout.vue` (retirer l'entrée nav « Serientermine »)

**Interfaces:**
- Consumes: rien.
- Produces: plus aucune référence à `tenant.bulk-appointments.*` ni à `BulkAppointmentController`.

- [ ] **Step 1: Repérer toutes les références**

Run: `cd backend && grep -rn "bulk-appointments\|BulkAppointment\|Serientermine" routes/ app/ resources/js/`
Expected: occurrences dans `routes/web.php`, `TenantLayout.vue`, et les 2 fichiers à supprimer.

- [ ] **Step 2: Supprimer le contrôleur et la page Vue**

```bash
cd backend
rm app/Http/Controllers/Tenant/BulkAppointmentController.php
rm resources/js/Pages/Tenant/BulkAppointments/Index.vue
rmdir resources/js/Pages/Tenant/BulkAppointments 2>/dev/null || true
```

- [ ] **Step 3: Retirer les routes + l'import dans `routes/web.php`**

Supprimer la ligne d'import `use App\Http\Controllers\Tenant\BulkAppointmentController;` et le bloc de routes `tenant.bulk-appointments.*` (index + store). Vérifier qu'aucune autre route ne dépend du contrôleur.

- [ ] **Step 4: Retirer l'entrée nav dans `TenantLayout.vue`**

Supprimer l'item de navigation pointant vers `route('tenant.bulk-appointments.index')` / labellisé « Serientermine » (lien + icône associés).

- [ ] **Step 5: Vérifier qu'il ne reste aucune référence**

Run: `cd backend && grep -rn "bulk-appointments\|BulkAppointment\|Serientermine" routes/ app/ resources/js/`
Expected: aucune sortie.

- [ ] **Step 6: Build + tests**

Run: `cd backend && npm run build && composer test`
Expected: build OK, suite verte (aucun test ne référençait la feature).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(staff-app): remove Serientermine — redundant with the slot engine"
```

---

### Task 2: Bugs bloquants — accesseur `name` (C1) + validation `batchUpdate` (I1)

**Files:**
- Modify: `backend/app/Models/Tenant/Practitioner.php`
- Modify: `backend/app/Http/Controllers/Tenant/AvailabilityController.php:85-118`
- Test: `backend/tests/Feature/TenantSchema/AvailabilityBatchUpdateTest.php` (create)

**Interfaces:**
- Consumes: `Practitioner` (modèle), route `tenant.availabilities.batch-update`.
- Produces: `Practitioner->name` (string, = `fullName()`) ; `batchUpdate` rejette (422) tout jour ouvert sans heures valides et n'écrit jamais `NULL`.

- [ ] **Step 1: Écrire les tests qui échouent**

```php
<?php

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;

use function Pest\Laravel\actingAs;

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->practitioner = Practitioner::factory()->create();
});

it('exposes name accessor as full name', function () {
    $p = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Anna', 'last_name' => 'Klein']);
    expect($p->name)->toBe('Dr. Anna Klein');
});

it('rejects an open day without times and writes nothing', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => $d === 1, 'start_time' => null, 'end_time' => null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasErrors();

    expect(Availability::where('practitioner_id', $this->practitioner->id)->count())->toBe(0);
});

it('rejects end_time before start_time', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => $d === 1,
        'start_time' => $d === 1 ? '17:00' : null,
        'end_time'   => $d === 1 ? '09:00' : null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasErrors();
});

it('persists open days with valid times', function () {
    $schedule = collect(range(1, 7))->map(fn ($d) => [
        'day_of_week' => $d, 'open' => in_array($d, [1, 2]),
        'start_time' => in_array($d, [1, 2]) ? '09:00' : null,
        'end_time'   => in_array($d, [1, 2]) ? '17:00' : null,
    ])->all();

    actingAs($this->user)
        ->put(route('tenant.availabilities.batch-update'), [
            'practitioner_id' => $this->practitioner->id,
            'schedule' => $schedule,
        ])
        ->assertSessionHasNoErrors();

    expect(Availability::where('practitioner_id', $this->practitioner->id)->count())->toBe(2);
});
```

- [ ] **Step 2: Lancer les tests — ils échouent**

Run: `cd backend && php artisan test --filter=AvailabilityBatchUpdate`
Expected: FAIL (le `name` n'existe pas ; un jour ouvert sans heures crée ou crashe au lieu de 422).

- [ ] **Step 3: Ajouter l'accesseur `name` sur `Practitioner`**

Dans `app/Models/Tenant/Practitioner.php`, après `fullName()` :

```php
    public function getNameAttribute(): string
    {
        return $this->fullName();
    }
```

- [ ] **Step 4: Durcir la validation dans `batchUpdate`**

Dans `AvailabilityController::batchUpdate`, remplacer le bloc `$request->validate([...])` par une validation suivie d'un `after`-hook :

```php
    public function batchUpdate(Request $request): RedirectResponse
    {
        $validator = \Validator::make($request->all(), [
            'practitioner_id'        => 'required|exists:practitioners,id',
            'schedule'               => 'required|array|size:7',
            'schedule.*.day_of_week' => 'required|integer|between:1,7',
            'schedule.*.open'        => 'required|boolean',
            'schedule.*.start_time'  => 'nullable|date_format:H:i',
            'schedule.*.end_time'    => 'nullable|date_format:H:i',
        ]);

        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->input('schedule', []) as $i => $day) {
                if (! ($day['open'] ?? false)) {
                    continue;
                }
                $start = $day['start_time'] ?? null;
                $end = $day['end_time'] ?? null;
                if (! $start || ! $end) {
                    $v->errors()->add("schedule.$i.start_time", 'Bitte Uhrzeiten für geöffnete Tage angeben.');

                    continue;
                }
                if ($end <= $start) {
                    $v->errors()->add("schedule.$i.end_time", 'Ende muss nach dem Beginn liegen.');
                }
            }
        });

        $data = $validator->validate();

        DB::transaction(function () use ($data) {
            Availability::where('practitioner_id', $data['practitioner_id'])
                ->whereNull('valid_from')
                ->whereNull('valid_to')
                ->delete();

            foreach ($data['schedule'] as $day) {
                if (! $day['open']) {
                    continue;
                }
                Availability::create([
                    'practitioner_id' => $data['practitioner_id'],
                    'day_of_week'     => $day['day_of_week'],
                    'start_time'      => $day['start_time'],
                    'end_time'        => $day['end_time'],
                    'valid_from'      => null,
                    'valid_to'        => null,
                ]);
            }
        });

        $practitioner = Practitioner::find($data['practitioner_id']);

        return redirect()->route('tenant.availabilities.index')
            ->with('success', "Sprechzeiten für {$practitioner->name} gespeichert.");
    }
```

Ajouter `use Illuminate\Support\Facades\Validator;` en tête si `\Validator` n'est pas déjà importé (sinon garder le `\Validator` global).

- [ ] **Step 5: Lancer les tests — ils passent**

Run: `cd backend && php artisan test --filter=AvailabilityBatchUpdate`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Tenant/Practitioner.php app/Http/Controllers/Tenant/AvailabilityController.php tests/Feature/TenantSchema/AvailabilityBatchUpdateTest.php
git commit -m "fix(staff-app): Practitioner.name accessor + batchUpdate rejects open days without valid times (Postgres NOT NULL)"
```

---

### Task 3: Câblage du rappel configurable (enabled + lead_hours + message)

**Files:**
- Modify: `backend/app/Console/Commands/SendAppointmentReminders.php`
- Modify: `backend/app/Mail/AppointmentReminderMail.php`
- Modify: `backend/resources/views/emails/reminder.blade.php`
- Test: `backend/tests/Feature/TenantSchema/ReminderSettingsTest.php` (create)

**Interfaces:**
- Consumes: `PracticeSettings::current()` → `reminder_enabled` (bool), `reminder_lead_hours` (int ∈ {2,24,48}), `reminder_message` (string avec `{Datum}`/`{Uhrzeit}`).
- Produces: `AppointmentReminderMail($appointment, $cabinetName, $cancelUrl, $reminderMessage)` — 4ᵉ paramètre, message déjà substitué.

- [ ] **Step 1: Écrire les tests qui échouent**

```php
<?php

use App\Mail\AppointmentReminderMail;
use App\Models\PracticeSettings;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\Mail;

it('sends no reminder when reminders are disabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['reminder_enabled' => false]);
    Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(30),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('uses the configured lead time window', function () {
    Mail::fake();
    PracticeSettings::current()->update(['reminder_enabled' => true, 'reminder_lead_hours' => 48]);

    $due = Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(48)->addMinutes(20),
    ]);
    Appointment::factory()->create([ // would be due at 24h, not at 48h → skipped
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(20),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect($due->fresh()->reminder_sent_at)->not->toBeNull();
});

it('injects the configured message with date and time substituted', function () {
    Mail::fake();
    PracticeSettings::current()->update([
        'reminder_enabled' => true, 'reminder_lead_hours' => 24,
        'reminder_message' => 'Hallo! Termin am {Datum} um {Uhrzeit}. Danke.',
    ]);
    $appt = Appointment::factory()->create([
        'status' => 'confirmed', 'reminder_sent_at' => null,
        'starts_at' => now()->addHours(24)->addMinutes(20),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, function (AppointmentReminderMail $mail) use ($appt) {
        return str_contains($mail->reminderMessage, 'Termin am')
            && ! str_contains($mail->reminderMessage, '{Datum}')
            && ! str_contains($mail->reminderMessage, '{Uhrzeit}');
    });
});
```

> Note: si la factory `Appointment` n'expose pas tous les champs requis, s'appuyer sur ses défauts existants (déjà utilisés par les tests de rappel actuels dans `tests/Feature/TenantSchema/`).

- [ ] **Step 2: Lancer les tests — ils échouent**

Run: `cd backend && php artisan test --filter=ReminderSettings`
Expected: FAIL (lead hardcodé à 24h, pas de `reminderMessage` sur le mailable).

- [ ] **Step 3: Ajouter le 4ᵉ paramètre au mailable**

Dans `app/Mail/AppointmentReminderMail.php`, étendre le constructeur :

```php
    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
        public string $cancelUrl,
        public string $reminderMessage = '',
    ) {}
```

- [ ] **Step 4: Afficher le message custom dans le blade**

Dans `resources/views/emails/reminder.blade.php`, remplacer la ligne d'intro hardcodée (`wir möchten Sie an den morgigen Termin …`) par le message configuré, en conservant les détails structurés :

```blade
<x-mail::message>
# Erinnerung an Ihren Termin

Hallo {{ $appointment->parent_first_name }},

{{ $reminderMessage }}

- **Referenz:** {{ $appointment->publicReference() }}
- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Bis morgen!<br>
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 5: Câbler la commande sur les réglages**

Dans `app/Console/Commands/SendAppointmentReminders.php`, réécrire `handle()` :

```php
    public function handle(): int
    {
        $settings = \App\Models\PracticeSettings::current();

        if (! $settings->reminder_enabled) {
            return self::SUCCESS;
        }

        $lead = $settings->reminder_lead_hours;

        Appointment::query()
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>=', now()->addHours($lead))
            ->where('starts_at', '<', now()->addHours($lead + 1))
            ->with(['service', 'practitioner'])
            ->get()
            ->each(function (Appointment $appointment) use ($settings) {
                try {
                    $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);

                    $message = str_replace(
                        ['{Datum}', '{Uhrzeit}'],
                        [
                            $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y'),
                            $appointment->clinicStartsAt()->format('H:i').' Uhr',
                        ],
                        $settings->reminder_message,
                    );

                    $appointment->reminder_sent_at = now();
                    $appointment->save();

                    try {
                        Mail::to($appointment->parent_email)->queue(
                            new AppointmentReminderMail($appointment, config('app.name'), $cancelUrl, $message)
                        );
                    } catch (\Throwable $e) {
                        $appointment->reminder_sent_at = null;
                        $appointment->save();
                        throw $e;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return self::SUCCESS;
    }
```

- [ ] **Step 6: Lancer les tests — ils passent**

Run: `cd backend && php artisan test --filter=ReminderSettings`
Expected: PASS (3 tests). Lancer aussi les tests de rappel existants : `php artisan test --filter=Reminder` (ne pas régresser).

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/SendAppointmentReminders.php app/Mail/AppointmentReminderMail.php resources/views/emails/reminder.blade.php tests/Feature/TenantSchema/ReminderSettingsTest.php
git commit -m "feat(settings): reminders honor enabled flag, configurable lead time and message"
```

---

### Task 4: Confirmation parent togglable

**Files:**
- Modify: `backend/app/Http/Controllers/Widget/AppointmentController.php:78-87`
- Test: `backend/tests/Feature/TenantSchema/BookingConfirmationToggleTest.php` (create)

**Interfaces:**
- Consumes: `PracticeSettings::current()->booking_confirmation_enabled` (bool).
- Produces: l'email `AppointmentConfirmationMail` n'est mis en file que si le toggle est actif. La réservation (201 + ligne) reste inchangée dans tous les cas.

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

use App\Mail\AppointmentConfirmationMail;
use App\Models\PracticeSettings;
use Illuminate\Support\Facades\Mail;

it('skips the parent confirmation mail when disabled but still books', function () {
    Mail::fake();
    PracticeSettings::current()->update(['booking_confirmation_enabled' => false]);

    $payload = makeValidBookingPayload(); // helper existant dans la suite widget

    $this->postJson(route('widget.appointments.store'), $payload)->assertCreated();

    Mail::assertNotQueued(AppointmentConfirmationMail::class);
});

it('sends the parent confirmation mail when enabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['booking_confirmation_enabled' => true]);

    $payload = makeValidBookingPayload();

    $this->postJson(route('widget.appointments.store'), $payload)->assertCreated();

    Mail::assertQueued(AppointmentConfirmationMail::class);
});
```

> Réutiliser le helper de payload de réservation des tests widget existants (`WidgetBookingTest`). Si aucun helper partagé n'existe, copier la construction de payload depuis ce test.

- [ ] **Step 2: Lancer le test — il échoue**

Run: `cd backend && php artisan test --filter=BookingConfirmationToggle`
Expected: FAIL (le mail part toujours).

- [ ] **Step 3: Entourer l'envoi du toggle**

Dans `AppointmentController::store`, envelopper le bloc `RateLimiter::attempt(...)` :

```php
        $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);

        if (\App\Models\PracticeSettings::current()->booking_confirmation_enabled) {
            $emailKey = 'confirm-mail:'.sha1(mb_strtolower(trim($appointment->parent_email)));
            RateLimiter::attempt(
                $emailKey,
                maxAttempts: 3,
                callback: fn () => rescue(fn () => Mail::to($appointment->parent_email)->queue(
                    new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
                )),
                decaySeconds: 3600,
            );
        }
```

- [ ] **Step 4: Lancer le test — il passe**

Run: `cd backend && php artisan test --filter=BookingConfirmationToggle`
Expected: PASS (2 tests). Vérifier la non-régression : `php artisan test --filter=WidgetBooking`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Widget/AppointmentController.php tests/Feature/TenantSchema/BookingConfirmationToggleTest.php
git commit -m "feat(settings): parent booking confirmation can be toggled off"
```

---

### Task 5: Notif cabinet — annulation togglable + nouvelle notif réservation

**Files:**
- Create: `backend/app/Mail/AppointmentBookedMail.php`
- Create: `backend/resources/views/emails/booked-cabinet.blade.php`
- Modify: `backend/app/Support/CabinetNotifier.php` (ajouter `notifyBooked`)
- Modify: `backend/app/Http/Controllers/Widget/AppointmentController.php` (déclencher `notifyBooked` si `notify_on_booking`)
- Modify: `backend/app/Http/Controllers/Widget/CancellationController.php:47-48` (garde `notify_on_cancellation`)
- Test: `backend/tests/Feature/TenantSchema/CabinetNotificationToggleTest.php` (create)

**Interfaces:**
- Consumes: `PracticeSettings::current()->notify_on_booking` / `->notify_on_cancellation` (bool) ; `CabinetNotifier::recipients()`.
- Produces: `CabinetNotifier::notifyBooked(Appointment $appointment): void` (queue + `rescue()`, no-op si pas de destinataire) ; `AppointmentBookedMail($appointment, $cabinetName)`.

- [ ] **Step 1: Écrire les tests qui échouent**

```php
<?php

use App\Mail\AppointmentBookedMail;
use App\Mail\AppointmentCancelledMail;
use App\Models\PracticeSettings;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => config()->set('mail.practice_notification_address', 'praxis@example.test'));

it('notifies the cabinet on a new booking when enabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_booking' => true]);

    $this->postJson(route('widget.appointments.store'), makeValidBookingPayload())->assertCreated();

    Mail::assertQueued(AppointmentBookedMail::class);
});

it('does not notify the cabinet on booking when disabled', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_booking' => false]);

    $this->postJson(route('widget.appointments.store'), makeValidBookingPayload())->assertCreated();

    Mail::assertNotQueued(AppointmentBookedMail::class);
});

it('respects the cancellation notification toggle', function () {
    Mail::fake();
    PracticeSettings::current()->update(['notify_on_cancellation' => false]);

    $appt = bookAndReturnAppointment(); // helper widget existant ou inline
    $this->postJson(route('widget.cancellation.cancel'), ['token' => $appt->cancellation_token])
        ->assertOk();

    Mail::assertNotQueued(AppointmentCancelledMail::class);
});
```

> Adapter les noms de routes/helpers aux conventions de la suite widget existante (`WidgetBookingTest`, `CancellationController` test).

- [ ] **Step 2: Lancer les tests — ils échouent**

Run: `cd backend && php artisan test --filter=CabinetNotificationToggle`
Expected: FAIL (`AppointmentBookedMail` n'existe pas ; annulation notifie toujours).

- [ ] **Step 3: Créer le mailable `AppointmentBookedMail`**

`app/Mail/AppointmentBookedMail.php` :

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

class AppointmentBookedMail extends Mailable implements ShouldQueue
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
            subject: "Neue Online-Buchung bei {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.booked-cabinet');
    }
}
```

- [ ] **Step 4: Créer le blade `emails/booked-cabinet.blade.php`**

```blade
<x-mail::message>
# Neue Online-Buchung

Es wurde ein neuer Termin online gebucht:

- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}
- **Eltern:** {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }}
- **Kontakt:** {{ $appointment->parent_email }}@if($appointment->parent_phone) · {{ $appointment->parent_phone }}@endif
- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:** {{ $appointment->practitioner->name }}
- **Referenz:** {{ $appointment->publicReference() }}

{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 5: Ajouter `notifyBooked` à `CabinetNotifier`**

Dans `app/Support/CabinetNotifier.php`, ajouter l'import `use App\Mail\AppointmentBookedMail;` et la méthode :

```php
    /** Queue the "new online booking" alert to the cabinet (no-op if unconfigured). */
    public static function notifyBooked(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        rescue(fn () => Mail::to($recipients)->queue(
            new AppointmentBookedMail($appointment, config('app.name'))
        ));
    }
```

- [ ] **Step 6: Déclencher `notifyBooked` après la réservation**

Dans `AppointmentController::store`, juste après le bloc de confirmation parent (Task 4) et avant le `return response()->json(...)` :

```php
        if (\App\Models\PracticeSettings::current()->notify_on_booking) {
            \App\Support\CabinetNotifier::notifyBooked($appointment);
        }
```

- [ ] **Step 7: Garder l'annulation derrière le toggle**

Dans `CancellationController`, remplacer l'appel inconditionnel :

```php
        if ($cancelled) {
            if (\App\Models\PracticeSettings::current()->notify_on_cancellation) {
                CabinetNotifier::notifyCancelled($cancelled);
            }
            ParentNotifier::notifyCancelled($cancelled);
            WaitlistNotifier::notifySlotAvailable();
        }
```

> Note : la notif **parent** d'annulation (`ParentNotifier`) et la waitlist restent inconditionnelles — le toggle ne concerne que l'alerte **cabinet**.

- [ ] **Step 8: Lancer les tests — ils passent**

Run: `cd backend && php artisan test --filter=CabinetNotificationToggle`
Expected: PASS (3 tests). Non-régression : `php artisan test --filter=Cancellation`.

- [ ] **Step 9: Commit**

```bash
git add app/Mail/AppointmentBookedMail.php resources/views/emails/booked-cabinet.blade.php app/Support/CabinetNotifier.php app/Http/Controllers/Widget/AppointmentController.php app/Http/Controllers/Widget/CancellationController.php tests/Feature/TenantSchema/CabinetNotificationToggleTest.php
git commit -m "feat(settings): cabinet booking alert (new) + cancellation alert toggle"
```

---

### Task 6: Validation canal e-mail + retrait UI SMS + test SettingsController

**Files:**
- Modify: `backend/app/Http/Requests/Tenant/UpdateSettingsRequest.php:18`
- Modify: `backend/resources/js/Pages/Tenant/Settings/Index.vue:92-111`
- Test: `backend/tests/Feature/TenantSchema/SettingsControllerTest.php` (create)

**Interfaces:**
- Consumes: route `tenant.settings.update`, `PracticeSettings`.
- Produces: `reminder_channel` validé à `email` uniquement ; l'UI ne propose plus SMS.

- [ ] **Step 1: Écrire les tests qui échouent**

```php
<?php

use App\Models\PracticeSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->user = User::factory()->create());

function validSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'reminder_enabled' => true,
        'reminder_channel' => 'email',
        'reminder_lead_hours' => 24,
        'reminder_message' => 'Hallo {Datum} {Uhrzeit}',
        'booking_confirmation_enabled' => true,
        'notify_on_booking' => true,
        'notify_on_cancellation' => false,
    ], $overrides);
}

it('persists validated settings', function () {
    actingAs($this->user)
        ->put(route('tenant.settings.update'), validSettingsPayload(['reminder_lead_hours' => 48]))
        ->assertSessionHasNoErrors();

    expect(PracticeSettings::current()->reminder_lead_hours)->toBe(48);
});

it('rejects a lead time outside the allowed set', function () {
    actingAs($this->user)
        ->put(route('tenant.settings.update'), validSettingsPayload(['reminder_lead_hours' => 12]))
        ->assertSessionHasErrors('reminder_lead_hours');
});

it('rejects a non-email reminder channel', function () {
    actingAs($this->user)
        ->put(route('tenant.settings.update'), validSettingsPayload(['reminder_channel' => 'sms']))
        ->assertSessionHasErrors('reminder_channel');
});
```

- [ ] **Step 2: Lancer les tests — ils échouent**

Run: `cd backend && php artisan test --filter=SettingsController`
Expected: FAIL (le canal `sms` est encore accepté).

- [ ] **Step 3: Restreindre le canal à `email`**

Dans `UpdateSettingsRequest::rules()`, remplacer la règle `reminder_channel` :

```php
            'reminder_channel'             => ['required', 'string', 'in:email'],
```

- [ ] **Step 4: Retirer les options SMS de l'UI**

Dans `resources/js/Pages/Tenant/Settings/Index.vue`, remplacer le sélecteur « Versand über » (lignes ~89-111) par un libellé statique e-mail (le canal n'a plus qu'une valeur) :

```vue
                    <!-- Versand über (E-Mail uniquement — SMS non disponible) -->
                    <div>
                        <div class="text-sm font-medium text-slate-900 mb-2">Versand über</div>
                        <div class="inline-flex items-center gap-1.5 rounded-[8px] bg-slate-100 px-3.5 py-1.5 text-sm text-slate-700">
                            <Mail class="h-3.5 w-3.5" :stroke-width="1.75" />
                            E-Mail
                        </div>
                    </div>
```

Retirer du `<script setup>` les imports d'icônes devenus inutiles (`MessageSquare`, `Send`) s'ils ne servent nulle part ailleurs, et s'assurer que `form.reminder_channel` vaut `'email'` à l'initialisation.

- [ ] **Step 5: Lancer les tests — ils passent + build**

Run: `cd backend && php artisan test --filter=SettingsController && npm run build`
Expected: PASS (3 tests), build OK.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Tenant/UpdateSettingsRequest.php resources/js/Pages/Tenant/Settings/Index.vue tests/Feature/TenantSchema/SettingsControllerTest.php
git commit -m "feat(settings): email-only reminder channel + remove SMS UI + controller tests"
```

---

### Task 7: Vérification finale & seed du singleton

**Files:**
- Modify: `backend/database/migrations/2026_06_24_000001_create_practice_settings_table.php` (optionnel — seed de la ligne par défaut)
- Modify: `backend/app/Models/PracticeSettings.php` (durcir `firstOrCreate` — M1)

**Interfaces:**
- Consumes: tout le travail précédent.
- Produces: branche prête pour review → PR → merge.

- [ ] **Step 1: Durcir `current()` contre la course (M1)**

Dans `PracticeSettings::current()`, rendre la résolution déterministe :

```php
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::create([
            'reminder_enabled'             => true,
            'reminder_channel'             => 'email',
            'reminder_lead_hours'          => 24,
            'reminder_message'             => 'Liebe Familie, wir erinnern an den Termin im Kids Club am {Datum} um {Uhrzeit}. Bis bald!',
            'booking_confirmation_enabled' => true,
            'notify_on_booking'            => true,
            'notify_on_cancellation'       => false,
        ]);
    }
```

- [ ] **Step 2: Pint + suite complète + build**

Run: `cd backend && vendor/bin/pint && composer test && npm run build`
Expected: Pint clean, suite verte, build OK.

- [ ] **Step 3: Commit**

```bash
git add app/Models/PracticeSettings.php backend/database/migrations/2026_06_24_000001_create_practice_settings_table.php
git commit -m "chore(settings): deterministic singleton resolution + pint"
```

---

## Self-Review

**Spec coverage :**
- D1 (supprimer Serientermine) → Task 1 ✅
- C1 (accesseur name) + I1 (validation batchUpdate) → Task 2 ✅
- Rappel enabled/lead/message → Task 3 ✅
- Confirmation parent togglable → Task 4 ✅
- Notif cabinet annulation toggle + notif booking nouvelle → Task 5 ✅
- Canal e-mail seul + UI SMS retirée + tests SettingsController → Task 6 ✅
- D2 (garder practice_settings) → respecté (aucune migration vers Setting) ✅
- M1 (race firstOrCreate) → Task 7 ✅
- Hors-scope SMS → respecté (canal forcé email, UI nettoyée) ✅

**Placeholder scan :** code complet à chaque étape, pas de TODO. Les helpers de payload widget (`makeValidBookingPayload`, `bookAndReturnAppointment`) sont signalés comme « réutiliser l'existant » — à l'exécution, lire `tests/Feature/TenantSchema/WidgetBookingTest.php` pour la construction réelle du payload avant d'écrire les tests des Tasks 4-5.

**Type consistency :** `AppointmentReminderMail` 4ᵉ param `reminderMessage` (Task 3) cohérent avec le test (Task 3). `CabinetNotifier::notifyBooked(Appointment)` (Task 5) cohérent avec son appel dans `AppointmentController` (Task 5). `Practitioner->name` (Task 2) utilisé dans `booked-cabinet.blade` (Task 5) — OK car Task 2 précède Task 5.
