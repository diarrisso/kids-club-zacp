# Finalisation du redesign staff-app — design

> **Objet** : rendre déployable la branche `feature/warteliste-hifi-redesign` en supprimant une feature redondante, corrigeant deux bugs bloquants, et câblant réellement les réglages (Einstellungen) qui aujourd'hui n'agissent sur rien.
> **Date** : 2026-06-25 · **Statut** : design validé, avant plan d'implémentation
> **Base** : code review de la branche (2 CRITICAL, 3 IMPORTANT confirmés au caractère près)

---

## 1. Contexte & problème

La branche embarque le redesign visuel de l'app staff (sain) **plus** trois nouveautés dont deux sont des **coquilles qui affichent un faux succès** :

- **Serientermine** (`BulkAppointmentController::store`) valide puis affiche « X Termine eingeplant » **sans créer aucun rendez-vous**.
- **Einstellungen** (`PracticeSettings`) sauvegarde des réglages que **rien ne lit** (grep vide hors `SettingsController`).
- Bug **C1** : `Practitioner->name` n'existe pas (que `fullName()`) → noms vides dans les toasts.
- Bug **I1** : `AvailabilityController::batchUpdate` peut écrire `NULL` dans `start_time`/`end_time` (colonnes NOT NULL) → **500 Postgres**.

Découverte d'architecture déterminante : `AvailabilityCalculator` **calcule déjà les créneaux libres à la volée** depuis les Sprechzeiten et **exclut déjà** fériés (Yasumi), week-ends et absences. Serientermine est donc **techniquement redondant** — le mockup React de référence était autonome et ignorait ce moteur.

## 2. Décisions

| # | Décision | Raison |
|---|---|---|
| D1 | **Supprimer Serientermine** entièrement | Redondant avec le moteur ; aucune cible réelle à « générer » |
| D2 | Garder `practice_settings` (singleton typé) | Domaine distinct de `Setting` (clé-valeur, moteur booking) → pas de vrai doublon |
| D3 | Canal de rappel **e-mail uniquement** | SMS non implémenté (reporté) → on ne montre pas un canal qui ne marche pas |
| D4 | **Créer** la notif cabinet « nouvelle réservation » | `CabinetNotifier` n'a aujourd'hui que `notifyCancelled`/`notifyWaitlist` |

## 3. Périmètre détaillé

### 3.1 Suppression Serientermine (D1)
Retirer :
- Routes `tenant.bulk-appointments.*` (`routes/web.php`)
- `app/Http/Controllers/Tenant/BulkAppointmentController.php`
- `resources/js/Pages/Tenant/BulkAppointments/Index.vue`
- L'entrée de navigation « Serientermine » dans `Layouts/TenantLayout.vue`

### 3.2 Bugs bloquants
- **C1** — `app/Models/Tenant/Practitioner.php` : ajouter
  ```php
  public function getNameAttribute(): string { return $this->fullName(); }
  ```
  Corrige `$practitioner->name` dans `AvailabilityController::batchUpdate` (et tout usage futur). Le mapping `index()` de BulkAppointment disparaît avec D1.
- **I1** — `AvailabilityController::batchUpdate` : valider qu'un jour **ouvert** porte ses heures, et que `end_time > start_time`. Via `withValidator`/`after` (les wildcards `required_if` sur tableau étant fragiles) :
  - si `open === true` → `start_time` et `end_time` requis (format `H:i`)
  - `end_time` strictement après `start_time`
  - rejeter (422) sinon ; ne jamais créer de ligne avec une heure nulle.

### 3.3 Câblage Einstellungen
Lecture centralisée via `PracticeSettings::current()`.

- **Rappel** — `app/Console/Commands/SendAppointmentReminders.php` :
  - lire `current()` ; si `reminder_enabled === false` → ne rien envoyer (sortir proprement).
  - remplacer la fenêtre `[now+24h, now+25h)` par `[now+lead, now+lead+1h)` où `lead = reminder_lead_hours` (∈ {2,24,48}).
  - passer `reminder_message` à `AppointmentReminderMail`, qui substitue `{Datum}` et `{Uhrzeit}` (format clinique `Europe/Berlin`, locale de). Conserver `reminder_sent_at` comme garde anti-doublon.
- **Confirmation parent** — `app/Http/Controllers/Widget/AppointmentController.php` (~l.83) : n'envoyer `AppointmentConfirmationMail` que si `booking_confirmation_enabled`.
- **Notif cabinet annulation** — `app/Http/Controllers/Widget/CancellationController.php` (~l.48) : `notifyCancelled` seulement si `notify_on_cancellation`.
- **Notif cabinet réservation (nouveau)** — `app/Mail/AppointmentBookedMail.php` + `CabinetNotifier::notifyBooked(Appointment)` (même structure que `notifyCancelled` : queue + `rescue()` pour ne jamais faire échouer la réservation). Déclenché dans `AppointmentController` si `notify_on_booking`.
- **UI** — `resources/js/Pages/Tenant/Settings/Index.vue` : retirer le choix de canal SMS/E-Mail (e-mail implicite). Le reste des contrôles reste, désormais effectif.

### 3.4 Validation serveur (`UpdateSettingsRequest`)
- `reminder_enabled`, `booking_confirmation_enabled`, `notify_on_booking`, `notify_on_cancellation` : `boolean`.
- `reminder_lead_hours` : `in:2,24,48`.
- `reminder_channel` : forcé/validé à `email` (SMS exclu).
- `reminder_message` : `string`, longueur bornée (ex. `max:500`).

## 4. Tests (Pest, `tests/Feature/TenantSchema/`)

- **Rappel** : (a) `reminder_enabled=false` → 0 mail ; (b) `lead_hours=48` → seul un RDV à ~48h est rappelé, pas celui à 24h ; (c) le corps du mail contient le `reminder_message` avec date/heure substituées ; (d) `reminder_sent_at` empêche le doublon.
- **Confirmation** : `booking_confirmation_enabled=false` → pas de `AppointmentConfirmationMail` à la réservation ; `true` → envoyé.
- **Notif cabinet** : `notify_on_booking=true` → `AppointmentBookedMail` aux destinataires cabinet ; `false` → aucun. Idem `notify_on_cancellation`.
- **batchUpdate** : un jour `open:true` sans heures → 422, aucune ligne créée ; jour `open:false` → ignoré ; `end <= start` → 422.
- **SettingsController** : `update` persiste les champs validés ; `reminder_lead_hours` hors {2,24,48} → 422 ; `current()` crée le singleton avec les défauts.

## 5. Hors-scope (explicite)
- SMS (rappel/confirmation) — reste reporté.
- `reminder_channel` autre que `email`.
- Toute notion de « génération de créneaux » (le moteur s'en charge).
- Migration `practice_settings` → `Setting` (on garde les deux, domaines séparés).

## 6. Critères de succès
- `composer test` vert, build vert.
- Aucune page staff n'affiche un succès sans effet réel.
- Code review re-passée : 0 CRITICAL / 0 IMPORTANT.
- Déploiement = merge `main` → CI verte → Deploy auto (migrate `practice_settings` incluse).
