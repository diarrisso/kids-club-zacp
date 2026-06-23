# Notification de re-planification — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Envoyer un e-mail « Termin verschoben » au parent quand le staff déplace un RDV (heure ou praticien), sans toucher la mécanique de reschedule.

**Architecture:** Nouveau mailable `AppointmentRescheduledMail` + vue markdown, calqués sur `AppointmentConfirmationMail`. Dans `AppointmentController::update()`, capturer l'ancien `starts_at` (brut, pour la détection) + l'ancien `clinicStartsAt()` (Berlin, pour l'affichage) + l'ancien praticien AVANT le reschedule, puis envoyer l'e-mail après si l'heure ou le praticien a changé. Aucune migration.

**Tech Stack:** Laravel 13, Pest 4, Blade markdown mail, PostgreSQL.

## Global Constraints

- **Déclencheur** : envoyer si `starts_at` (instant) **OU** `practitioner_id` a changé. JAMAIS sur un simple changement de `notes_internal` / `attendance` / nom patient / salle.
- **Comparaison d'heure brut↔brut** : `$appointment->starts_at->ne($oldStartsAt)` où `$oldStartsAt` est l'ancien `starts_at` brut. NE PAS comparer à `clinicStartsAt()` (transformation d'affichage → faux positif permanent).
- **Heures affichées en `Europe/Berlin`** via `Appointment::clinicStartsAt()` (et l'ancien start capturé via `clinicStartsAt()`). Jamais `starts_at` brut dans la vue.
- **Robustesse = pattern de `store()`** : envoi post-commit, `rescue(fn () => Mail::to(...)->queue(...))`, saut silencieux si `parent_email` vide.
- **Suffixe `Mail`** sur la classe (convention : `AppointmentConfirmationMail`, `AppointmentReminderMail`…).
- **Lien storno** : `route('storno.show', ['token' => $appointment->cancellation_token])`.
- **Aucune migration.** Tests Pest (`it(...)`, `Mail::fake()`) ; `composer test` reste vert.

---

### Task 1: Mailable + vue + déclenchement dans update() + tests

**Files:**
- Create: `backend/app/Mail/AppointmentRescheduledMail.php`
- Create: `backend/resources/views/emails/rescheduled.blade.php`
- Modify: `backend/app/Http/Controllers/Tenant/AppointmentController.php` (méthode `update()`)
- Test: `backend/tests/Feature/TenantSchema/AppointmentRescheduleNotificationTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant\Appointment` (`clinicStartsAt(): CarbonImmutable`, `starts_at` Carbon, `practitioner_id`, `parent_email`, `cancellation_token`, relations `service`/`practitioner`), `App\Services\Tenant\AppointmentScheduler::reschedule()` (existant), route `storno.show`.
- Produces: `App\Mail\AppointmentRescheduledMail(Appointment $appointment, string $cabinetName, string $cancelUrl, CarbonImmutable $oldStart, string $oldPractitionerName)` ; vue `emails.rescheduled`.

- [ ] **Step 1: Write the failing tests**

Créer `backend/tests/Feature/TenantSchema/AppointmentRescheduleNotificationTest.php` :

```php
<?php

use App\Mail\AppointmentRescheduledMail;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\AppointmentFactory;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function rescheduleStaff(): User
{
    return User::factory()->create(['two_factor_confirmed_at' => now()]);
}

// A future, conflict-free appointment with a parent email.
function makeAppointment(array $overrides = []): Appointment
{
    $start = CarbonImmutable::now('Europe/Berlin')->addDays(3)->setTime(10, 0);

    return AppointmentFactory::new()->create(array_merge([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'status' => 'confirmed',
        'parent_email' => 'eltern@example.com',
    ], $overrides));
}

it('emails the parent when the appointment time changes', function () {
    $appt = makeAppointment();
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertSent(AppointmentRescheduledMail::class, fn ($m) => $m->hasTo('eltern@example.com'));
});

it('emails the parent when the practitioner changes (same time)', function () {
    $appt = makeAppointment();
    $other = Practitioner::factory()->create();

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'practitioner_id' => $other->id,
        ])
        ->assertOk();

    Mail::assertSent(AppointmentRescheduledMail::class);
});

it('does not email when only attendance or internal notes change', function () {
    $appt = makeAppointment();

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'attendance' => 'arrived',
            'notes_internal' => 'Kind war ruhig',
        ])
        ->assertOk();

    Mail::assertNothingSent();
});

it('does not email when the appointment has no parent email', function () {
    $appt = makeAppointment(['parent_email' => null]);
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertNothingSent();
});

it('carries the old start, the new start and the storno link to the mailable', function () {
    $appt = makeAppointment();
    $oldStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin');
    $newStart = $oldStart->addDays(2);

    $this->actingAs(rescheduleStaff())
        ->patchJson("/termine/{$appt->id}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])
        ->assertOk();

    Mail::assertSent(AppointmentRescheduledMail::class, function ($m) use ($appt, $oldStart, $newStart) {
        expect($m->oldStart->format('Y-m-d H:i'))->toBe($oldStart->format('Y-m-d H:i'));
        expect($m->appointment->clinicStartsAt()->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'));
        expect($m->cancelUrl)->toContain($appt->cancellation_token);

        return true;
    });
});

it('renders the rescheduled email without error (old -> new, Berlin times, storno button)', function () {
    $appt = makeAppointment();
    $oldStart = $appt->clinicStartsAt();
    $newStart = CarbonImmutable::parse($appt->starts_at, 'Europe/Berlin')->addDays(1);
    $appt->update(['starts_at' => $newStart, 'ends_at' => $newStart->addMinutes(30)]);

    $mail = new AppointmentRescheduledMail(
        $appt->fresh(['service', 'practitioner']),
        'Kids Club',
        'https://example.test/storno/abc',
        $oldStart,
        'Dr. Anna Müller',
    );

    $rendered = $mail->render();

    expect($rendered)
        ->toContain('verschoben')
        ->toContain($oldStart->format('H:i'))
        ->toContain($newStart->format('H:i'))
        ->toContain('https://example.test/storno/abc');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=AppointmentRescheduleNotificationTest`
Expected: FAIL (classe `AppointmentRescheduledMail` inexistante / aucun e-mail envoyé).

- [ ] **Step 3: Create the mailable**

`backend/app/Mail/AppointmentRescheduledMail.php` :

```php
<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,        // state AFTER reschedule (new values)
        public string $cabinetName,
        public string $cancelUrl,
        public CarbonImmutable $oldStart,        // old clinicStartsAt() (Berlin), captured before reschedule
        public string $oldPractitionerName,      // old practitioner full name, captured before
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ihr Termin bei {$this->cabinetName} wurde verschoben",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.rescheduled');
    }
}
```

- [ ] **Step 4: Create the markdown view**

`backend/resources/views/emails/rescheduled.blade.php` :

```blade
<x-mail::message>
# Ihr Termin wurde verschoben

Hallo {{ $appointment->parent_first_name }},

der Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** wurde verschoben.

**Bisher:** {{ $oldStart->locale('de')->translatedFormat('l, d. F Y') }}, {{ $oldStart->format('H:i') }} Uhr — bei {{ $oldPractitionerName }}

**Neu:**

- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Falls der neue Termin nicht passt, stornieren Sie ihn bitte über den Button oben.

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
```

- [ ] **Step 5: Trigger the email in `update()`**

Dans `backend/app/Http/Controllers/Tenant/AppointmentController.php`, méthode `update()`. D'abord, s'assurer que les imports `use App\Mail\AppointmentRescheduledMail;` et `use Illuminate\Support\Facades\Mail;` sont présents en tête (ajouter ceux qui manquent — `Mail` est déjà importé car `store()` l'utilise ; ajouter `AppointmentRescheduledMail`).

Capturer l'état AVANT le reschedule — insérer juste **avant** la ligne `$appointment = $scheduler->reschedule($appointment, $data);` :

```php
        // Capture pre-reschedule state for the parent notification. Raw starts_at
        // for change detection (instant vs instant); clinicStartsAt() (Berlin) for
        // the email display; practitioner name for the "Bisher" line.
        $oldStartsAt = $appointment->starts_at;
        $oldStartDisplay = $appointment->clinicStartsAt();
        $oldPractitionerId = $appointment->practitioner_id;
        $oldPractitionerName = $appointment->practitioner?->fullName() ?? '—';
```

Puis, juste **avant** le `return response()->json(...)` final (après l'application de notes_internal/attendance), ajouter :

```php
        // Notify the parent only when the time (instant) or the practitioner actually
        // changed — never on a notes/attendance-only edit. Post-commit + rescue so a
        // mail failure never fails the already-persisted reschedule (like store()).
        $appointment->loadMissing('practitioner');
        $timeChanged = $appointment->starts_at->ne($oldStartsAt);
        $practitionerChanged = $appointment->practitioner_id !== $oldPractitionerId;

        if (($timeChanged || $practitionerChanged) && filled($appointment->parent_email)) {
            $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
            rescue(fn () => Mail::to($appointment->parent_email)->queue(
                new AppointmentRescheduledMail(
                    $appointment,
                    config('app.name'),
                    $cancelUrl,
                    $oldStartDisplay,
                    $oldPractitionerName,
                )
            ));
        }
```

> Le `return response()->json($this->toDto($appointment->load(['service', 'practitioner'])));` existant reste la dernière instruction.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=AppointmentRescheduleNotificationTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Run the appointment suite (no regression)**

Run: `cd backend && php artisan test --filter=Appointment`
Expected: PASS — les tests existants de reschedule/conflit (409), pointage, liste restent verts.

- [ ] **Step 8: Pint + commit**

```bash
cd backend && vendor/bin/pint app/Mail/AppointmentRescheduledMail.php app/Http/Controllers/Tenant/AppointmentController.php tests/Feature/TenantSchema/AppointmentRescheduleNotificationTest.php
cd .. && git add backend/app/Mail/AppointmentRescheduledMail.php backend/resources/views/emails/rescheduled.blade.php backend/app/Http/Controllers/Tenant/AppointmentController.php backend/tests/Feature/TenantSchema/AppointmentRescheduleNotificationTest.php
git commit -m "feat(appointments): notify parent by email when a RDV is rescheduled"
```

---

### Task 2: Vérification du rendu e-mail

**Files:** aucun (vérification).

- [ ] **Step 1: Rendre l'e-mail pour contrôle visuel**

Le rendu HTML est déjà asserté par le test #6, mais pour un contrôle visuel humain, rendre l'e-mail (par ex. via `php artisan tinker` en construisant un `AppointmentRescheduledMail` sur un RDV seedé et en appelant `->render()`, ou via Mailpit/mail log si configuré en local). Vérifier :
- Le bloc « Bisher » (ancienne date/heure + ancien praticien) puis « Neu » (nouvelle date/heure, Leistung, Behandler:in).
- Les heures sont en `Europe/Berlin` (cohérentes avec ce que le cabinet a saisi — pas de décalage +2h).
- Le bouton « Termin stornieren » pointe vers `/storno/{token}`.

- [ ] **Step 2: Confirmer (pas de commit)**

Noter le résultat ; aucune modification de code attendue si tout est correct.

---

## Self-Review

**Spec coverage :**
- Déclencheur heure OU praticien → Task 1 (trigger Step 5 + tests #1,#2) ✓
- Pas d'envoi sur notes/attendance seul → Task 1 (test #3) ✓
- Pas d'envoi si pas d'e-mail → Task 1 (test #4) ✓
- Contenu ancien→nouveau + storno → Task 1 (vue Step 4 + tests #5,#6) ✓
- Heures Berlin (clinicStartsAt) → Task 1 (vue + capture `$oldStartDisplay`) ✓
- Robustesse rescue/post-commit/sync → Task 1 (Step 5, pattern store()) ✓
- Comparaison brut↔brut (pas de faux positif) → Task 1 (Global Constraints + Step 5 `$oldStartsAt`) ✓
- Aucune migration → respecté ✓
- Vérif rendu → Task 2 ✓

**Placeholder scan :** aucun TODO/TBD ; code complet à chaque étape.

**Type consistency :** mailable `AppointmentRescheduledMail($appointment, $cabinetName, $cancelUrl, $oldStart: CarbonImmutable, $oldPractitionerName: string)` — identique entre la création (Step 3), l'instanciation dans `update()` (Step 5) et les tests (#5,#6). `clinicStartsAt(): CarbonImmutable` (confirmé sur le modèle). Détection : `starts_at->ne($oldStartsAt)` (Carbon brut vs brut), `practitioner_id !==` (int). Vue consomme `$appointment`, `$cabinetName`, `$cancelUrl`, `$oldStart`, `$oldPractitionerName` — tous fournis par le mailable.
