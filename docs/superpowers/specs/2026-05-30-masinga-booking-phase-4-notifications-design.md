# Spec — Masinga Booking · Phase 4 : Notifications email

**Date :** 2026-05-30
**Statut :** Validé (brainstorming)
**Dépend de :** Phase 2 (modèle `appointments`, API booking/annulation) et Phase 3 (widget) — toutes deux mergées dans `main`.
**Spec parente :** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md` (§6.3, §9, roadmap S9).

---

## 1. Résumé

Trois emails transactionnels (allemand, mis en file via Redis) autour du cycle de vie d'un rendez-vous, plus une page web d'annulation pour que le lien d'email fonctionne.

**Inclus :** email de **confirmation** (au parent à la réservation) · email de **rappel 24h** (au parent, via commande planifiée horaire) · email d'**annulation** (au cabinet quand un parent annule) · **page d'annulation** Blade publique (`/storno/{tenant}/{token}`) · colonne `reminder_sent_at` · commande `appointments:send-reminders`.

**Exclus (plans ultérieurs) :** calendrier dashboard · DSGVO/anonymisation/audit · SMS · table de réglages de notification dédiée · alerte temps-réel au cabinet à chaque réservation.

## 2. Décisions figées (brainstorming)

| Décision | Choix |
|---|---|
| Emails | Confirmation (parent), Rappel 24h (parent), Annulation (cabinet) |
| Rappel | **Scan programmé horaire** : RDV `confirmed`, `starts_at` ∈ [now+24h, now+25h), `reminder_sent_at` null → envoi + marquage |
| Infra mail | **Laravel Mail driver-agnostique** : dev `log`, prod `postmark` (config), tests `Mail::fake()`. Pas de dépendance dure Postmark dans le code. |
| Envoi | **Queued** (Redis) — jamais synchrone, ne bloque pas la réservation |
| Destinataire cabinet | les `tenant_owner` du tenant (table `users` centrale) — pas de table de réglages dédiée pour le MVP |
| Page d'annulation | Blade autonome (pas Inertia), tenant-par-chemin `/storno/{tenant}/{token}` |
| From | `noreply` générique, **from-name = nom du cabinet** (tenant), sujets allemands incluant le nom du cabinet |

## 3. Architecture & composants

```
backend/app/Mail/
├── AppointmentConfirmationMail.php   ← au parent, à la réservation (Markdown, ShouldQueue)
├── AppointmentReminderMail.php       ← au parent, 24h avant (ShouldQueue)
└── AppointmentCancelledMail.php      ← au cabinet, à l'annulation (ShouldQueue)

backend/resources/views/emails/      ← templates Markdown allemands
├── confirmation.blade.php
├── reminder.blade.php
└── cancelled.blade.php

backend/app/Console/Commands/SendAppointmentReminders.php   ← appointments:send-reminders
backend/routes/console.php           ← planification ->hourly()

backend/database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php

backend/app/Http/Controllers/Public/CancellationPageController.php  ← page web Blade (show + cancel)
backend/resources/views/storno/{show,done}.blade.php
backend/routes/web.php  ← GET/POST /storno/{tenant}/{token}, middleware ['web', InitializeTenancyByPath]
                          (groupe SÉPARÉ des routes Route::domain centrales ; 'web' fournit session+CSRF)

backend/app/Support/CabinetNotifier.php  ← helper : emails des tenant_owner du tenant courant
```

Chaque Mailable est une unité isolée (données injectées au constructeur, rendu Blade, `ShouldQueue`). La commande, les contrôleurs et le helper sont testables indépendamment (`Mail::fake()`).

## 4. Migration — `reminder_sent_at`

Nouvelle migration tenant `add_reminder_sent_at_to_appointments` : `$table->timestamp('reminder_sent_at')->nullable();`. Idempotence du rappel : une fois marqué, plus jamais renvoyé.

## 5. Déclencheurs

- **Confirmation** : `AppointmentController::store` (Phase 2), après `Appointment::create(...)` et hors transaction de verrou → `Mail::to($appointment->parent_email)->queue(new AppointmentConfirmationMail($appointment, tenant()->name, $cancelUrl))`. `$cancelUrl` = `{app_url}/storno/{tenant}/{token}`.
- **Annulation** : dans le flux d'annulation — **API** `CancellationController::cancel` (Phase 2) **et** la **page web** — après passage en `cancelled` → `Mail::to($cabinetEmails)->queue(new AppointmentCancelledMail($appointment, tenant()->name))`. `$cabinetEmails` via `CabinetNotifier`.
- **Rappel** : la commande (§6).

`CabinetNotifier::recipients()` : `User::where('tenant_id', tenant()->getTenantKey())->where('role','tenant_owner')->pluck('email')->all()` (modèle central User épinglé `CentralConnection`). Si vide → on ne fait rien (pas d'erreur).

## 6. Commande `appointments:send-reminders` (multi-tenant)

```php
public function handle(): int
{
    Tenant::all()->each(function (Tenant $tenant) {
        $tenant->run(function () {
            Appointment::query()
                ->where('status', 'confirmed')
                ->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [now()->addHours(24), now()->addHours(25)])
                ->get()
                ->each(function (Appointment $a) {
                    try {
                        Mail::to($a->parent_email)->queue(new AppointmentReminderMail($a, tenant()->name));
                        $a->update(['reminder_sent_at' => now()]);
                    } catch (\Throwable $e) {
                        report($e); // un échec n'arrête pas le lot
                    }
                });
        });
    });
    return self::SUCCESS;
}
```
Planification : `routes/console.php` → `Schedule::command('appointments:send-reminders')->hourly()->withoutOverlapping();`.
Fenêtre [24h, 25h) + run horaire ⇒ chaque RDV reçoit exactement un rappel ~24h avant. Les `cancelled` sont exclus (filtre `status`).

## 7. Page d'annulation (lien email)

- `GET /storno/{tenant}/{token}` → `CancellationPageController@show` : middleware `InitializeTenancyByPath`, résout l'`Appointment` par `cancellation_token` (404 sinon), rend `storno/show` (date, heure, prestation, bouton « Termin stornieren » qui POST). Si déjà `cancelled`, afficher l'état annulé.
- `POST /storno/{tenant}/{token}` → `@cancel` : passe en `cancelled`, queue `AppointmentCancelledMail` au cabinet, rend `storno/done` (« Ihr Termin wurde storniert »). CSRF : la page Blade inclut `@csrf` (route web).
- Blade autonome, allemand, style minimal inline (pas de dépendance au widget).

## 8. Infra mail & contenu

- Mailables `ShouldQueue` (file Redis). En tests, `Mail::fake()` capture sans envoyer.
- From : `config('mail.from.address')` (noreply), name = nom du cabinet via l'`Envelope` (`from: new Address($addr, $tenantName)`).
- **Confirmation** : « Ihr Termin bei {Kabinett} ist bestätigt » — date/heure, prestation, praticien, prénom de l'enfant, lien d'annulation.
- **Rappel** : « Erinnerung: Ihr Termin morgen bei {Kabinett} » — date/heure, prestation, lien d'annulation.
- **Annulation (cabinet)** : « Ein Termin wurde storniert » — date/heure, prestation, enfant, parent.
- Prod : `MAIL_MAILER=postmark` + `POSTMARK_TOKEN` (config/services). Dev : `log`.

## 9. DSGVO

Le parent reçoit les données de son propre enfant (licite). Le cabinet (responsable de traitement) reçoit les détails du RDV. Aucun token d'annulation exposé au-delà du parent. Pas de stockage email supplémentaire. (Anonymisation/audit = plan Production.)

## 10. Tests

- **Confirmation** : réservation via l'API → `Mail::assertQueued(AppointmentConfirmationMail)` adressé au `parent_email` (Mail::fake).
- **Annulation** : POST cancel (API) **et** POST `/storno/...` → `Mail::assertQueued(AppointmentCancelledMail)` vers l'email d'un `tenant_owner` ; statut `cancelled`.
- **Rappel** (commande, `Mail::fake`) : RDV à +24h30 → après `artisan appointments:send-reminders` : `assertQueued(ReminderMail)` + `reminder_sent_at` non null ; RDV à +26h → `assertNotQueued` ; RDV déjà rappelé → pas de second envoi ; RDV `cancelled` → ignoré.
- **Isolation multi-tenant** : un RDV dans le cabinet A n'envoie pas de rappel via le contexte du cabinet B.
- **Page d'annulation** : `GET /storno/{tenant}/{token}` 200 + montre la prestation ; token inconnu → 404 ; POST → `cancelled` + email cabinet.
- Suites : `tenant` (`TenantTestCase`, schémas réels) ; `Mail::fake()` partout.

## 11. Critères d'acceptation

À la réservation, le parent reçoit (file) un email de confirmation allemand avec lien d'annulation fonctionnel ; ~24h avant, un rappel unique ; quand un parent annule (widget, API ou page `/storno`), le cabinet est notifié et le créneau se libère. Tout est multi-tenant isolé, queued, et testé via `Mail::fake()`. Aucune donnée patient ne fuit hors du destinataire légitime.
