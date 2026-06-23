# Notification de re-planification (Termin verschoben)

**Date :** 2026-06-23
**Statut :** Design validé, prêt pour le plan d'implémentation
**Périmètre :** une seule PR
**Lot :** C (premier sous-projet ; liste d'attente + SMS reportés en sous-projets distincts)

## Contexte

Le staff peut **déjà** déplacer un RDV : `AppointmentController::update()` → `AppointmentScheduler::reschedule()`
applique le changement d'heure/praticien sous transaction avec protection anti-chevauchement
(verrou pessimiste sur la ligne praticien, 409 en cas de conflit). **Mais aucun e-mail n'est
envoyé au parent** quand son RDV change : il découvre le changement au cabinet.

Cette feature ajoute l'e-mail « Ihr Termin wurde verschoben » au parent. Aucun changement de la
mécanique de reschedule, aucune migration.

## Décisions de design (validées)

- **Déclencheur** : envoyer si `starts_at` **OU** `practitioner_id` a changé. Ne PAS envoyer si seuls
  `notes_internal` / `attendance` / nom patient / salle changent.
- **Contenu** : ancienne date/heure → nouvelle date/heure, praticien (nouveau), service, + le lien
  d'annulation existant (`/storno/{token}`) pour que le parent puisse annuler si la nouvelle heure
  ne convient pas.
- **Heures en `Europe/Berlin`** via `Appointment::clinicStartsAt()` (le helper d'affichage déjà
  utilisé par les autres e-mails — on ne réintroduit pas le bug +2h).
- **Robustesse** : envoi post-commit, `rescue()`, `->queue()` (sync via `QUEUE_CONNECTION=sync`),
  saut silencieux si `parent_email` vide — **identique au pattern de `store()`/confirmation**.
- **Pas d'alerte cabinet** (c'est le cabinet qui a déclenché le déplacement).
- **Aucune migration.**

## Architecture

### 1. Mailable + vue (calqués sur `AppointmentConfirmationMail`)

**`App\Mail\AppointmentRescheduledMail`** (suffixe `Mail`, convention du projet) :
```php
class AppointmentRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,      // état APRÈS reschedule (nouvelles valeurs)
        public string $cabinetName,
        public string $cancelUrl,
        public Carbon $oldStart,               // ancien clinicStartsAt() (Berlin) — utiliser le type EXACT retourné par clinicStartsAt() (Carbon ou CarbonImmutable, à vérifier)
        public string $oldPractitionerName,    // ancien praticien (nom complet), capturé avant
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

**Vue** `resources/views/emails/rescheduled.blade.php` (markdown, comme `confirmation.blade.php`) :
- Titre « Ihr Termin wurde verschoben ».
- **Bisher** : `{{ $oldStart->format('d.m.Y') }}, {{ $oldStart->format('H:i') }} Uhr` chez `{{ $oldPractitionerName }}`.
- **Neu** : `{{ $appointment->clinicStartsAt()->format('d.m.Y') }}, {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr` chez `{{ $appointment->practitioner->fullName() }}` — Leistung `{{ $appointment->service->name }}`.
- Bouton d'annulation vers `$cancelUrl` (« Termin stornieren »).
- Toutes les heures via `clinicStartsAt()` / l'instance `$oldStart` déjà en Berlin (jamais `starts_at` brut).

### 2. Déclenchement dans `AppointmentController::update()`

Juste **avant** `$scheduler->reschedule($appointment, $data)` :
```php
$oldStart = $appointment->clinicStartsAt();                 // CarbonImmutable Berlin
$oldPractitionerId = $appointment->practitioner_id;
$oldPractitionerName = $appointment->practitioner?->fullName() ?? '—';
```
Juste **après** le reschedule (et après application notes/attendance) :
```php
$appointment->loadMissing('practitioner');
$timeChanged = $appointment->starts_at->ne($oldStart);          // Carbon comparison
$practitionerChanged = $appointment->practitioner_id !== $oldPractitionerId;

if (($timeChanged || $practitionerChanged) && filled($appointment->parent_email)) {
    $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
    rescue(fn () => Mail::to($appointment->parent_email)->queue(
        new AppointmentRescheduledMail(
            $appointment, config('app.name'), $cancelUrl, $oldStart, $oldPractitionerName,
        )
    ));
}
```

> **Comparaison d'heure :** `$appointment->starts_at` (nouveau) vs `$oldStart` (ancien `clinicStartsAt`,
> Berlin). Les deux sont des Carbon ; `->ne()` compare l'instant. Capturer `$oldStart` via
> `clinicStartsAt()` garantit la même base tz que l'affichage e-mail.

### 3. Gestion des erreurs

- `parent_email` vide → aucun envoi, pas d'erreur (saut silencieux).
- Échec SMTP → absorbé par `rescue()` ; la re-planification (déjà committée par le scheduler) n'échoue jamais.
- Aucun changement d'heure/praticien (ex. pointage attendance seul) → aucun e-mail.

## Tests (Pest — `Mail::fake()`)

1. Changement d'heure (`starts_at`) → `AppointmentRescheduledMail` envoyé au `parent_email`.
2. Changement de praticien à heure égale → e-mail envoyé.
3. Changement de `notes_internal` / `attendance` / nom patient seul → **aucun** e-mail (`assertNothingSent`/`assertNotSent`).
4. `parent_email` null → aucun envoi, réponse 200 (pas d'erreur).
5. Contenu : le mailable porte l'ancien `starts_at` ET le nouveau (Berlin), + l'`cancelUrl` storno
   (assert sur les propriétés du mailable et/ou `assertSeeInHtml` via rendu, heures en Berlin).
6. Robustesse : si l'envoi e-mail lève, la requête reste 200 et la nouvelle heure est bien persistée
   (rescue) — simulable via un transport mail qui throw, ou en vérifiant que `reschedule` est committé
   indépendamment.

> Les tests existants de double-booking / conflit (`409`) et de reschedule restent inchangés et verts.

## Hors périmètre

- Re-planification initiée par le **parent** (depuis le lien e-mail) — autre sous-projet.
- **Liste d'attente** et **SMS** (autres sous-projets du Lot C).
- UX staff de déplacement (slot picker / drag&drop dédié) — le modal d'édition actuel suffit.

## Données de référence

Aucune migration. Pas de `database-diagram.html` / `wireframe.html` / `ProjectProgressService` (sans objet).
