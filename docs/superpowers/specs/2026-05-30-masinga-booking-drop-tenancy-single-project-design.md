# Spec — Masinga Booking · PR1 : Retrait de la multi-tenancy (single-project)

**Date :** 2026-05-30
**Statut :** Validé (brainstorming)
**Dépend de :** Phases 1-4 mergées dans `main`.
**Spec parente :** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md`.
**Branche :** `refactor/drop-tenancy`

---

## 1. Résumé

Convertir le SaaS multi-tenant en **application single-tenant** dédiée à Kids Club by zacp (cabinet dentaire pédiatrique), par refactor **en place** du dépôt existant, **sur Laravel 11.54**. On **conserve l'intégralité du code métier** (moteur de réservation, widget embarquable, mailables Phase 4, `AvailabilityCalculator`, CRUD praticiens/prestations/horaires/absences, page d'annulation `/storno`). On retire **uniquement l'infrastructure de multi-tenancy** (stancl/tenancy et toute la mécanique de bascule de schéma).

C'est le **PR1 d'une séquence de 3** :

1. **PR1 (cette spec)** — retrait tenancy, sur Laravel 11.
2. **PR2** — montée de version Laravel 13 + dépendances (`tinker ^3`, `pest ^4`, `phpunit ^13`).
3. **PR3** — Phase 5 calendrier dashboard (révision de la spec existante pour le single-project).

**Pourquoi séquencer ainsi :** isoler les sources de régression. Retirer la tenancy ET monter une version majeure en même temps rendrait tout échec de test ambigu (« est-ce le retrait tenancy ou Laravel 13 ? »). Une chose à la fois, suite verte entre chaque.

## 2. Décisions figées (brainstorming)

| Décision | Choix |
|---|---|
| Architecture cible | **Single-project** (single-tenant), refactor en place du dépôt actuel |
| Séquencement | Retrait tenancy (Laravel 11) → upgrade Laravel 13 → Phase 5 calendrier |
| Notifications cabinet | **Email cabinet configuré** via `PRACTICE_NOTIFICATION_EMAIL` ; **plus de rôles** — tout utilisateur authentifié = staff |
| Colonnes `users` | Supprimer `tenant_id` et `role` |
| Widget | Reste embarquable mais **sans segment `{tenant}`** dans l'URL (`/api/v1/widget/*`) |
| Suite de tests | **Une seule suite** `RefreshDatabase` (suppression du split central/tenant) |
| Namespace modèles métier | **Conservé** (`App\Models\Tenant\*`) en PR1 — renommage cosmétique = follow-up trivial, hors scope |
| Layout Inertia | **Conservé** (`TenantLayout.vue`) en PR1 — renommage cosmétique = follow-up trivial |
| Nom du cabinet (emails, UI) | Via `config('app.name')` (env `APP_NAME`) au lieu de `tenant()->name` |

## 3. Suppressions

**Composer :** `composer remove stancl/tenancy`.

**Fichiers/dossiers à supprimer :**

```text
backend/app/Models/Tenant.php
backend/app/Models/Domain.php
backend/app/Models/Plan.php
backend/app/Providers/TenancyServiceProvider.php
backend/app/Tenancy/SearchPathBootstrapper.php
backend/app/Listeners/SwitchSearchPathForMigration.php
backend/app/Listeners/ResetSearchPathAfterMigration.php
backend/app/Listeners/NarrowSearchPathForTenantMigration.php   ← (fichier orphelin non suivi)
backend/config/tenancy.php
backend/routes/tenant.php                                       ← fusionné dans web.php (§6)
backend/tests/TenantTestCase.php
backend/database/migrations/*_create_plans_table.php
backend/database/migrations/*_create_tenants_table.php
backend/database/migrations/*_create_domains_table.php
```

**Modifications de retrait :**

- `backend/bootstrap/providers.php` — retirer l'enregistrement de `TenancyServiceProvider`.
- `backend/bootstrap/app.php` — retirer les renderers d'exceptions `TenantCouldNotBeIdentified*`.
- `backend/composer.json` — retrait de la dépendance + de toute config `extra`/scripts liée à la tenancy.

> Note : `CentralConnection` est le **trait fourni par le paquet** (`Stancl\Tenancy\Database\Concerns\CentralConnection`), pas un fichier à nous. Il disparaît avec `composer remove` ; il suffit de retirer son `use` de `User` et `Plan`.

## 4. Bascule vers une base unique

- **Migrations métier** : déplacer `backend/database/migrations/tenant/*` → `backend/database/migrations/` (migrations normales sur la base unique). Conserver l'ordre chronologique des timestamps. Le dossier `tenant/` disparaît.
- **Modèles `App\Models\Tenant\*`** (`Practitioner`, `Service`, `Appointment`, `Availability`, `AvailabilityException`) : retirer toute référence à une connexion `tenant` / au trait de connexion ; ce sont désormais de simples modèles Eloquent sur la connexion par défaut. **Namespace et emplacement inchangés** en PR1.
- **`User`** (`backend/app/Models/User.php`) : retirer le trait `CentralConnection`, retirer `tenant_id` et `role` de `$fillable`/casts et de toute logique. Migration `drop columns` :

```php
// backend/database/migrations/<ts>_drop_tenant_columns_from_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn(['tenant_id', 'role']);
});
```

(Dans `down()`, les recréer pour réversibilité, sans contrainte FK vers `tenants`.)

## 5. Auth / Fortify / Inertia / layout

- `backend/app/Actions/Fortify/CreateNewUser.php` — retirer l'assignation `tenant_id`/`role` ; créer un utilisateur staff simple.
- `backend/app/Actions/Fortify/*` (AuthenticateUser le cas échéant) — retirer toute logique tenant.
- `backend/config/fortify.php` — retirer les références tenant (domaine, garde tenant).
- `backend/app/Http/Middleware/HandleInertiaRequests.php` — retirer le prop partagé `tenant` ; exposer à la place `app_name => config('app.name')` (si l'UI en a besoin).
- `backend/resources/js/Layouts/TenantLayout.vue` — remplacer `page.props.tenant.name` par le nom d'app partagé (`config('app.name')`). Nom de fichier conservé en PR1.

## 6. Routes (domaine unique)

- **Widget API** (`backend/routes/api.php`) : préfixe `v1/widget/{tenant}` → `v1/widget` ; retirer le middleware `InitializeTenancyByPath` ; retirer le paramètre `{tenant}` des signatures de contrôleurs.
- **`routes/tenant.php`** (identifié par domaine, groupe `auth` : `/dashboard`, resources `behandler`/`leistungen`/`sprechzeiten`/`abwesenheiten`/`termine`) → **fusionné dans `web.php`** derrière `auth`, **sans** `Route::domain(...)` ni boucle `config('tenancy.central_domains')`. URLs allemandes **conservées à l'identique**.
- **`web.php`** : retirer la boucle `foreach (config('tenancy.central_domains'))` et les `Route::domain`. L'app sert sur un domaine unique : landing + auth (login/logout/register Fortify) + dashboard + CRUD sur le même hôte.
- **Page d'annulation** : `/storno/{tenant}/{token}` → **`/storno/{token}`** (groupe `['web', 'throttle:storno']`, sans `InitializeTenancyByPath`). Le rate-limiter `storno` passe d'une clé `tenant|ip` à une clé `ip`.

## 7. Notifications (config-driven, sans rôles)

- **`backend/app/Support/CabinetNotifier.php`** :

```php
public static function recipients(): array
{
    $email = config('mail.practice_notification_address');
    return $email ? [$email] : [];
}
```

`config/mail.php` ajoute `'practice_notification_address' => env('PRACTICE_NOTIFICATION_EMAIL')`. On retire la requête `User::where('tenant_id', …)->where('role','tenant_owner')`.

- **`backend/app/Console/Commands/SendAppointmentReminders.php`** : retirer `Tenant::all()->each(fn ($t) => $t->run(...))` ; requête `Appointment` directe sur la base unique :

```php
public function handle(): int
{
    Appointment::query()
        ->where('status', 'confirmed')
        ->whereNull('reminder_sent_at')
        ->whereBetween('starts_at', [now()->addHours(24), now()->addHours(25)])
        ->with(['service', 'practitioner'])
        ->get()
        ->each(function (Appointment $a) {
            try {
                $cancelUrl = route('storno.show', ['token' => $a->cancellation_token]);
                Mail::to($a->parent_email)->queue(
                    new AppointmentReminderMail($a, config('app.name'), $cancelUrl)
                );
                $a->update(['reminder_sent_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
            }
        });

    return self::SUCCESS;
}
```

La planification `->hourly()->withoutOverlapping()` (routes/console.php) reste inchangée.

- **`AppointmentController::store`** (widget) + **`CancellationController::cancel`** (API) + **`CancellationPageController`** (page web) : `cancelUrl = route('storno.show', ['token' => …])` (sans tenant) ; le nom passé aux mailables (`tenant()->name`) devient `config('app.name')`. Les patterns post-commit + `rescue()`/`lockForUpdate` de la Phase 4 sont **conservés tels quels**.

## 8. Seeders & configuration

- **`backend/database/seeders/KidsClubTenantSeeder.php`** → **`KidsClubSeeder.php`** : seed des praticiens / prestations / horaires (et données d'exemple) directement sur la base unique ; **suppression** de toute création de `Tenant`/`Domain`/`Plan`. Référencé depuis `DatabaseSeeder`.
- **`.env` / `.env.example`** : retirer `TENANCY_*` / `central_domains` ; ajouter `PRACTICE_NOTIFICATION_EMAIL=praxis@kidsclub.de` et fixer `APP_NAME="Kids Club by zacp"`.

## 9. Tests (une seule suite, RefreshDatabase)

- Suppression de `TenantTestCase` et du split 2-suites. **Tous les tests Feature → `RefreshDatabase`** sur la base unique. `composer test` → **un seul run** (`phpunit` / `pest` standard).
- **`backend/tests/Pest.php`** : appliquer `RefreshDatabase` aux tests Feature ; retirer toute liaison à `TenantTestCase`.
- **`backend/phpunit.xml`** : fusionner les testsuites `central`/`tenant` en une seule `Feature`+`Unit` standard.
- **Conversions de fichiers existants :**
  - `tests/Feature/TenantSchema/*` (~15 fichiers : booking, availability, widget API, cancellation, mail confirmation/reminder/cancelled, CabinetNotifier, page storno) → `RefreshDatabase`, URLs sans `{tenant}`/`testtenant`, retrait de `tenancy()->initialize(...)`, `Mail::fake()` conservé.
  - `tests/Feature/Central/*` (TenantManagement, Routing central, CentralDashboard) → **supprimés** (concepts disparus) ou convertis en équivalents single-app (ex. un test « dashboard accessible après login »).
- **Cible :** suite complète verte en une seule passe, couverture métier (booking lock, isBookable 422/409, fenêtre rappel demi-ouverte, emails queued, page storno) **préservée**.

## 10. Plugin WordPress & widget JS

- **`backend/resources/js/widget/api.ts`** : base URL sans `{tenant}` → `/api/v1/widget/*`. Retirer le slug tenant de la config de boot du widget.
- **`wordpress-plugin/masinga-booking/`** : retirer l'attribut shortcode du slug tenant ; le widget pointe directement sur `/api/v1/widget/*`. Rebuild `npm run build:widget`.

## 11. Critères d'acceptation

- `composer show` ne liste plus `stancl/tenancy` ; aucun import `Stancl\Tenancy\*` ni appel `tenant()`/`tenancy()` ne subsiste dans le code applicatif.
- L'app démarre sur **un domaine unique** : `/connexion` → login → `/dashboard` ; CRUD behandler/leistungen/sprechzeiten/abwesenheiten/termine accessibles (URLs allemandes inchangées).
- Le **widget réserve sans tenant** dans l'URL (`POST /api/v1/widget/appointments`) ; rendez-vous persisté ; isolation Shadow DOM intacte.
- **`/storno/{token}`** affiche le RDV et l'annule ; le cabinet est notifié à l'adresse `PRACTICE_NOTIFICATION_EMAIL`.
- **Suite complète verte en UNE seule suite** (`composer test`), couverture métier préservée.
- `migrate:fresh --seed` reconstruit une base unique cohérente avec données Kids Club.
