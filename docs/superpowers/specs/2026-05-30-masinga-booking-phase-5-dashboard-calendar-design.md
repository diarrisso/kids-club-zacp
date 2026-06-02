# Spec — Masinga Booking · Phase 5 : Calendrier dashboard (gestion des RDV)

**Date :** 2026-05-30 · **Révisé :** 2026-06-02 (adaptation single-tenant / Laravel 13)
**Statut :** Validé (brainstorming)
**Dépend de :** Phase 2 (modèle `appointments`, `AvailabilityCalculator`, verrou ligne-praticien) et Phase 4 (mailables, `CabinetNotifier`, route `/storno`) — toutes mergées dans `main`.
**Spec parente :** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md` (roadmap — dashboard cabinet).

> **⚠️ Révision single-tenant.** Cette spec a été écrite avant le retrait de la multi-tenancy (commit `9eea4c3`) et la montée Laravel 13 (`f65e381`). Elle est ici adaptée à la réalité actuelle : **une seule base PostgreSQL, un seul cabinet, pas de `stancl/tenancy`, pas de résolution de tenant par domaine, une seule suite de tests `RefreshDatabase`**. Les namespaces `App\…\Tenant\…`, le dossier de tests `tests/Feature/TenantSchema/` et `TenantLayout.vue` sont **conservés tels quels** (renommage cosmétique différé — cf. `CLAUDE.md`). Le nom du cabinet vient de `config('app.name')` ; aucun appel à `tenant()`.

---

## 1. Résumé

Console d'agenda **côté cabinet** : la première vue interne sur les rendez-vous (jusqu'ici visibles uniquement via l'API publique du widget). Le staff peut **voir, créer (RDV téléphone), déplacer (drag&drop) et annuler** les RDV dans un calendrier FullCalendar, color-codé par praticien.

**Inclus :** page calendrier Inertia (`Tenant/Appointments/Calendar.vue`) avec FullCalendar Vue 3 · feed JSON des événements (plage + filtre praticien) · création manuelle · reschedule drag&drop · annulation · validation « override cabinet » (chevauchement seulement) · migration rendant `parent_email`/`parent_consent_at` nullable · réutilisation de l'email de confirmation (si email présent).

**Exclus (plans ultérieurs) :** vue colonnes-par-praticien (FullCalendar `resource`, premium) · email parent lors d'une annulation **par le cabinet** (MVP : pas d'envoi) · récurrence · liste d'attente · statistiques · export iCal · DSGVO/anonymisation.

## 2. Décisions figées (brainstorming)

| Décision | Choix |
|---|---|
| Interactivité | **Gestion complète** : voir + créer + déplacer (drag&drop) + annuler |
| Base technique | **FullCalendar Vue 3**, plugins MIT (`daygrid`, `timegrid`, `interaction`). Color-coding par praticien (champ `color` existant), **pas** de vue `resource` premium |
| Validation cabinet | **Override** : on bloque **uniquement** le chevauchement même-praticien (verrou ligne + check). Heures d'ouverture, grille 30 min, délai 2 h, horizon : **non imposés** (le staff a autorité). On n'utilise PAS `isBookable()` |
| RDV manuel | Champs requis : enfant (prénom/nom/naissance), parent (prénom/nom/**téléphone**), praticien, prestation, créneau. **Email optionnel**, **consentement implicite** → migration `parent_email` + `parent_consent_at` nullable |
| Vues | **Woche par défaut** + Tag + Monat. Filtre praticien (cases color-codées) |
| Notif. confirmation | Création manuelle **avec** email → email de confirmation (réutilise Phase 4) ; **sans** email → aucun envoi |
| Notif. annulation cabinet | **MVP : pas d'email parent** (le cabinet gère par téléphone). Marque `cancelled` + libère le créneau |
| Transport | Page = Inertia ; feed + mutations du calendrier = **JSON via axios** (XSRF auto), pour gérer `revert()` proprement sur conflit drag&drop |
| Fuseau | `Europe/Berlin` (sérialisation + `timeZone` FullCalendar), locale `de` |

## 3. Architecture & composants

```text
backend/app/Http/Controllers/Tenant/AppointmentController.php   ← index + events + store + update + destroy
backend/app/Http/Requests/Tenant/StoreManualAppointmentRequest.php   ← validation RDV manuel
backend/app/Http/Requests/Tenant/UpdateAppointmentRequest.php        ← validation reschedule/édition
backend/app/Services/Tenant/AppointmentScheduler.php   ← create/reschedule avec verrou ligne + check chevauchement (override cabinet)

backend/database/migrations/2026_06_01_000017_make_parent_email_consent_nullable.php

backend/resources/js/Pages/Tenant/Appointments/Calendar.vue   ← page Inertia + FullCalendar
backend/resources/js/Pages/Tenant/Appointments/AppointmentForm.vue   ← modale create/edit (DE)
backend/resources/js/lib/calendar.ts   ← helper mapping Appointment → event FullCalendar (testable isolément)

backend/routes/web.php   ← +5 routes dans le groupe auth existant (URLs allemandes)
```

> Les namespaces `Tenant\` (modèles, contrôleurs, services, requests, factories) et le dossier `tests/Feature/TenantSchema/` sont **vestigiaux** depuis le retrait de la tenancy — on les conserve par cohérence avec le reste du code. Aucun mécanisme de bascule de schéma n'est impliqué.

Le **contrôleur** est mince : il délègue create/reschedule à `AppointmentScheduler` (le seul endroit qui prend le verrou + valide le chevauchement), valide les entrées via Form Requests, et sérialise les events. Le **scheduler** est testable indépendamment. Le **helper `calendar.ts`** (Appointment→event) est pur et testé en Vitest.

## 4. Migration — `parent_email` / `parent_consent_at` nullable

`database/migrations/2026_06_01_000017_make_parent_email_consent_nullable.php`.

```php
public function up(): void
{
    Schema::table('appointments', function (Blueprint $table) {
        $table->string('parent_email')->nullable()->change();
        $table->timestamp('parent_consent_at')->nullable()->change();
    });
}
public function down(): void
{
    Schema::table('appointments', function (Blueprint $table) {
        $table->string('parent_email')->nullable(false)->change();
        $table->timestamp('parent_consent_at')->nullable(false)->change();
    });
}
```

Laravel 13 modifie les colonnes **nativement** (`->change()`), **sans `doctrine/dbal`**. Les lignes existantes ont déjà des valeurs → migration sûre. Le widget public continue d'exiger email + consentement (sa Form Request `StoreAppointmentRequest` est inchangée) ; seul le chemin cabinet autorise leur absence.

## 5. Backend — `AppointmentController` (`App\Http\Controllers\Tenant`)

- **`index()`** → `Inertia::render('Tenant/Appointments/Calendar', ['practitioners' => [...{id,name,color}], 'services' => [...{id,name,duration_minutes}]])`. `name` = `Practitioner::fullName()`.
- **`events(Request)`** → JSON. Lit `start`, `end` (ISO, fournis par FullCalendar) et un filtre optionnel `practitioner_ids[]`. Requête : `Appointment::where('status','!=','cancelled')->where('starts_at','<',$end)->where('ends_at','>',$start)` (+ `whereIn practitioner_id` si filtre), eager-load `service`+`practitioner`. Retourne un tableau de DTO (sérialisation des dates en `Europe/Berlin`, `setTimezone(...)->toIso8601String()`) :
  ```json
  {"id":"<uuid>","starts_at":"2026-06-01T09:00:00+02:00","ends_at":"2026-06-01T09:30:00+02:00",
   "status":"confirmed","patient_first_name":"Lina","patient_last_name":"Müller",
   "parent_email":"…|null","parent_phone":"…","notes_internal":"…|null",
   "practitioner":{"id":1,"name":"Dr. Anna Berg","color":"#3b82f6"},
   "service":{"id":2,"name":"Prophylaxe","duration_minutes":30}}
  ```
  Le mapping DTO→event FullCalendar (titre, couleur, `extendedProps`) est fait côté TS par `lib/calendar.ts`.
- **`store(StoreManualAppointmentRequest)`** → délègue à `AppointmentScheduler::create($data)`. Si conflit → 409 JSON `{message}`. Succès → 201 JSON de l'event créé. Si `parent_email` présent → confirmation queued (post-commit, `rescue()`, lien `/storno` **sans segment tenant** : `route('storno.show', ['token' => $appointment->cancellation_token])`, mailable `new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)` — exactement comme le widget public) ; sinon aucun envoi.
- **`update(UpdateAppointmentRequest, Appointment)`** → délègue à `AppointmentScheduler::reschedule($appointment, $data)` (couvre reschedule drag&drop ET édition de champs). Check chevauchement **excluant l'appointment courant**. 409 si conflit, sinon 200 JSON de l'event mis à jour.
- **`destroy(Appointment)`** → `update(['status' => 'cancelled'])`, 200 JSON `{status:'cancelled'}`. Pas d'email (MVP). Le créneau redevient libre (le feed exclut `cancelled`).

Toutes les actions sont derrière `auth` (groupe existant de `routes/web.php`). Le binding `{appointment}` est sûr : un id inexistant → 404 (route-model binding standard).

## 6. `AppointmentScheduler` (override cabinet)

```php
public function create(array $data): Appointment
{
    return DB::transaction(function () use ($data) {
        $this->assertNoOverlap($data['practitioner_id'], $data['starts_at'], $data['ends_at']);
        return Appointment::create($data + ['status' => 'confirmed', 'cancellation_token' => (string) Str::uuid()]);
    });
}

public function reschedule(Appointment $a, array $data): Appointment
{
    return DB::transaction(function () use ($a, $data) {
        $this->assertNoOverlap($data['practitioner_id'] ?? $a->practitioner_id,
            $data['starts_at'] ?? $a->starts_at, $data['ends_at'] ?? $a->ends_at, $a->id);
        $a->update($data);
        return $a->refresh();
    });
}

private function assertNoOverlap(int $practitionerId, $startsAt, $endsAt, ?string $exceptId = null): void
{
    // Lock the practitioner ROW (never an aggregate — PostgreSQL rule), like Phase 2.
    Practitioner::query()->whereKey($practitionerId)->lockForUpdate()->first();

    $conflict = Appointment::query()
        ->where('practitioner_id', $practitionerId)
        ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
        ->whereIn('status', ['pending', 'confirmed'])
        ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
        ->exists();

    abort_if($conflict, 409, 'Überschneidung mit einem bestehenden Termin.');
}
```

`ends_at` est calculé côté contrôleur depuis `starts_at + service.duration_minutes` (création), ou fourni par le reschedule (drag conserve la durée ; resize ajuste). **Aucun** appel à `isBookable()` — c'est le sens de l'override cabinet : seul le chevauchement est interdit.

**Champs non-`$fillable`** : `notes_internal` (et `reminder_sent_at`) sont volontairement hors `$fillable` (jamais mass-assignables depuis le public). Le chemin cabinet, lui, peut écrire `notes_internal` — mais via **assignation directe** (`$a->notes_internal = $data['notes_internal'] ?? null; $a->save();`) et non via `Appointment::create($data)`/`update($data)`, sinon il serait silencieusement ignoré. `create()`/`reschedule()` du scheduler ne reçoivent donc que les champs `$fillable` ; le contrôleur applique `notes_internal` séparément.

## 7. Form Requests

- **`StoreManualAppointmentRequest`** : `practitioner_id` (exists), `service_id` (exists), `starts_at` (date), `patient_first_name`/`patient_last_name` (required string), `patient_birthdate` (date), `parent_first_name`/`parent_last_name` (required string), `parent_phone` (required string), `parent_email` (**nullable** email), `notes_internal` (nullable). `ends_at` non soumis (calculé serveur). Pas de `consent`/honeypot (chemin staff authentifié).
- **`UpdateAppointmentRequest`** : tous champs `sometimes` (PATCH partiel) — supporte le drag&drop (`starts_at`/`ends_at` seuls) comme l'édition complète. `parent_email` nullable email.

## 8. Frontend

- **`Calendar.vue`** (`TenantLayout`) : `<FullCalendar :options>` avec `plugins:[dayGridPlugin,timeGridPlugin,interactionPlugin]`, `initialView:'timeGridWeek'`, `locale: deLocale`, `timeZone:'Europe/Berlin'`, `headerToolbar:{left:'prev,next today',center:'title',right:'timeGridDay,timeGridWeek,dayGridMonth'}`, `nowIndicator:true`, `slotMinTime`/`slotMaxTime` raisonnables (ex. 07:00–20:00).
  - `events:` = fonction qui `window.axios.get('/termine/events', {params:{start,end,practitioner_ids}})` puis `successCallback(rows.map(toCalendarEvent))`.
  - `dateClick`/`select` (créneau vide) → ouvre `AppointmentForm` en mode create, pré-rempli (date/heure cliquée ; praticien si un seul filtre actif).
  - `eventClick` → ouvre `AppointmentForm` en mode edit (depuis `extendedProps`), avec bouton « Termin stornieren » (DELETE).
  - `eventDrop`/`eventResize` → `window.axios.patch('/termine/'+id, {starts_at,ends_at})` ; sur 409 ou erreur → `info.revert()` + message ; succès → laisser le calendrier tel quel.
  - Filtre praticien : cases color-codées au-dessus ; toggle → `calendarApi.refetchEvents()`.
- **`AppointmentForm.vue`** : modale allemande (champs §7), submit via `window.axios.post`/`patch` ; sur succès → ferme + `refetchEvents()` ; sur 409/422 → affiche les erreurs. Sélecteur de prestation affiche la durée (recalcule `ends_at` à l'affichage seulement ; le serveur fait foi).
- **`lib/calendar.ts`** : `toCalendarEvent(dto): CalendarEvent` (pur) — mappe les champs + `title = "{patient_first_name} {patient_last_name[0]}. — {service.name}"` + `color`. Testé en Vitest.

## 9. Routes (`routes/web.php`, groupe `auth` existant, URLs allemandes)

```php
Route::get('/termine', [AppointmentController::class, 'index'])->name('tenant.appointments.index');
Route::get('/termine/events', [AppointmentController::class, 'events'])->name('tenant.appointments.events');
Route::post('/termine', [AppointmentController::class, 'store'])->name('tenant.appointments.store');
Route::patch('/termine/{appointment}', [AppointmentController::class, 'update'])->name('tenant.appointments.update');
Route::delete('/termine/{appointment}', [AppointmentController::class, 'destroy'])->name('tenant.appointments.destroy');
```

Lien « Termine » ajouté au tableau `nav` de `TenantLayout.vue` (`{ href: '/termine', label: '🗓️ Termine' }`).

## 10. Tests

- **Backend (Pest, `tests/Feature/TenantSchema/`, `RefreshDatabase`)** — URLs **relatives**, `$this->actingAs(User::factory()->create())`, `Mail::fake()` :
  - `events` : retourne les RDV dans la plage, exclut `cancelled`, applique le filtre praticien, sérialise en Berlin.
  - `store` : crée un RDV manuel ; **avec** `parent_email` → `Mail::assertQueued(AppointmentConfirmationMail)` ; **sans** email → `Mail::assertNothingQueued` mais RDV créé ; `notes_internal` persiste (assignation directe).
  - Override : un RDV à 20:00 (hors heures) est **accepté** (preuve qu'`isBookable` n'est pas appliqué) ; un RDV chevauchant le même praticien → **409**.
  - `update` reschedule : déplace `starts_at`/`ends_at` ; un déplacement sur un créneau chevauchant un AUTRE RDV → 409 ; déplacer sur soi-même (même plage) → OK (exclusion self).
  - `destroy` : passe `cancelled`, et le créneau libéré peut être re-réservé.
  - `auth` : invité → redirection login.
- **Frontend (Vitest)** : `toCalendarEvent` mappe correctement (title, color, ISO Berlin, extendedProps).
- Suite via `composer test` (suite unique `RefreshDatabase`). `npm run test:widget` pour le mapper TS. Pas de split central/tenant, pas de `TenantTestCase`.

## 11. Critères d'acceptation

Le staff ouvre `/termine`, voit la semaine en cours avec les RDV color-codés par praticien, filtre par praticien, crée un RDV téléphone (email facultatif), déplace un RDV en drag&drop (refus net si chevauchement, sinon persisté), et annule un RDV (créneau re-libéré). Un RDV manuel hors heures d'ouverture est accepté (override cabinet) ; un chevauchement même-praticien est toujours refusé (409). Une confirmation est envoyée seulement si un email parent est fourni. Tout est derrière `auth`, et testé.

## 12. Découpage implémentation (1 spec, 2 lots)

- **Lot A — lecture** : migration nullable · `AppointmentController@index/events` · `Calendar.vue` (vues + filtre, lecture seule) · `lib/calendar.ts` + tests feed/mapping · lien nav.
- **Lot B — écriture** : `AppointmentScheduler` · Form Requests · `store/update/destroy` · `AppointmentForm.vue` · drag&drop + revert · tests create/reschedule/cancel/override.
