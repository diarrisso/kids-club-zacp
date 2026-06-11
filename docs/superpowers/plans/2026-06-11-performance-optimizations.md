# PR-D — Optimisations de performance · Plan d'implémentation

> **Pour les workers agentiques :** SOUS-SKILL REQUISE : `superpowers:subagent-driven-development`. Étapes par checkbox (`- [ ]`).

**Objectif :** 5 optimisations backend (N+1 calculateur, index partiel + composites, cache listes widget, timeout SMTP) — **sémantique du calculateur strictement préservée**.

**Stack :** Laravel 13 · Pest 4 · PostgreSQL. Commandes depuis `backend/`.

**Branche :** `feature/perf-optimizations` (créée depuis origin/main ; spec commitée). Indépendante de PR-C.
Spec : `docs/superpowers/specs/2026-06-11-performance-optimizations-design.md`.

**Conventions :** TDD (test rouge LANCÉ d'abord), `vendor/bin/pint --dirty` avant chaque commit, un commit par tâche. Baseline suite : **190 passed**. Ne jamais stager les dirty non-liés (CLAUDE.md, .DS_Store, backend/tz_dst_check.php, wordpress-plugin/*, backend/public/widget-preview.html).

**Ordre :** du plus isolé (config, schéma) au plus intriqué (cache + invalidation).

---

### Task 1 : Timeout SMTP

**Fichiers :**
- Modifier : `backend/config/mail.php`
- Test : `backend/tests/Feature/MailTimeoutTest.php` (nouveau)

- [ ] **Étape 1 : test rouge** — créer `backend/tests/Feature/MailTimeoutTest.php` :

```php
<?php

it('bounds the smtp timeout so a dead MX fails fast', function () {
    expect(config('mail.mailers.smtp.timeout'))->toBe(5);
});
```

Lancer `php artisan test --filter=MailTimeoutTest` → ÉCHEC (timeout actuel = null). Observer le rouge.

- [ ] **Étape 2 : implémenter** — `backend/config/mail.php`, mailer `smtp`, remplacer `'timeout' => null,` par :

```php
            'timeout' => env('MAIL_TIMEOUT', 5),
```

Ajouter dans `backend/.env.example` (près du bloc MAIL) :

```
# MAIL_TIMEOUT=5
```

- [ ] **Étape 3 : lancer** — `php artisan test --filter=MailTimeoutTest` → PASS. `composer test` → vert.
⚠️ `phpunit.xml` ne fixe pas `MAIL_TIMEOUT` → le défaut 5 s'applique en test. Si un autre test fixait le timeout, vérifier. (Le mailer de test est `array`, le timeout du `smtp` n'affecte pas les tests d'envoi.)

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/config/mail.php backend/.env.example backend/tests/Feature/MailTimeoutTest.php
git commit -m "perf(mail): bound smtp timeout to 5s so a dead MX fails fast into rescue()"
```

---

### Task 2 : Index partiel + composites (migration)

**Fichiers :**
- Créer : `backend/database/migrations/2026_06_11_000001_add_performance_indexes.php`
- Test : `backend/tests/Feature/TenantSchema/PerformanceIndexesTest.php` (nouveau)

- [ ] **Étape 1 : test rouge** — créer `backend/tests/Feature/TenantSchema/PerformanceIndexesTest.php` :

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function indexNames(string $table): array
{
    return collect(DB::select(
        'SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]
    ))->pluck('indexname')->all();
}

it('creates the partial overlap index on appointments', function () {
    expect(indexNames('appointments'))->toContain('appointments_overlap_idx');
});

it('creates the composite availabilities index and drops the redundant standalone', function () {
    $idx = indexNames('availabilities');
    expect($idx)->toContain('availabilities_practitioner_id_day_of_week_index');
    expect($idx)->not->toContain('availabilities_practitioner_id_index');
});

it('creates the composite exceptions index and drops the redundant standalones', function () {
    $idx = indexNames('availability_exceptions');
    expect($idx)->toContain('availability_exceptions_practitioner_id_starts_at_ends_at_index');
    expect($idx)->not->toContain('availability_exceptions_starts_at_index');
    expect($idx)->not->toContain('availability_exceptions_practitioner_id_index');
});
```

⚠️ L'implémenteur vérifie les **noms d'index réels** avant d'écrire les assertions : lancer un `RefreshDatabase` puis `SELECT indexname FROM pg_indexes` sur les 3 tables pour lire les noms auto-générés Laravel exacts, et ajuster les chaînes attendues. Les noms ci-dessus suivent la convention par défaut Laravel mais DOIVENT être confirmés (le composite peut être tronqué si > 63 chars Postgres — vérifier `availability_exceptions_practitioner_id_starts_at_ends_at_index` fait 61 chars, OK).

Lancer → ÉCHEC (index absents). Observer le rouge.

- [ ] **Étape 2 : implémenter** — créer la migration `backend/database/migrations/2026_06_11_000001_add_performance_indexes.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partial composite: serves the overlap query (status-filtered) that runs
        // inside the booking lock + the calculator. status IN (...) is constant →
        // legal partial-index predicate (no now()).
        DB::statement(
            'CREATE INDEX appointments_overlap_idx ON appointments '.
            '(practitioner_id, starts_at, ends_at) '.
            "WHERE status IN ('pending', 'confirmed')"
        );

        Schema::table('availabilities', function (Blueprint $t) {
            $t->index(['practitioner_id', 'day_of_week']);
            $t->dropIndex(['practitioner_id']); // redundant: left-prefix of the composite
        });

        Schema::table('availability_exceptions', function (Blueprint $t) {
            $t->index(['practitioner_id', 'starts_at', 'ends_at']);
            $t->dropIndex(['practitioner_id']); // redundant: left-prefix of the composite
            $t->dropIndex(['starts_at']);        // redundant standalone (audit)
        });
    }

    public function down(): void
    {
        Schema::table('availability_exceptions', function (Blueprint $t) {
            $t->dropIndex(['practitioner_id', 'starts_at', 'ends_at']);
            $t->index('practitioner_id');
            $t->index('starts_at');
        });

        Schema::table('availabilities', function (Blueprint $t) {
            $t->dropIndex(['practitioner_id', 'day_of_week']);
            $t->index('practitioner_id');
        });

        DB::statement('DROP INDEX IF EXISTS appointments_overlap_idx');
    }
};
```

⚠️ Vérifier que `dropIndex(['practitioner_id'])` cible le bon nom auto-généré ; si Laravel ne retrouve pas le nom, utiliser le nom explicite (`dropIndex('availabilities_practitioner_id_index')`). Tester `migrate` ET `migrate:rollback` localement pour prouver la réversibilité.

- [ ] **Étape 3 : lancer** — `php artisan test --filter=PerformanceIndexesTest` → PASS (3). `composer test` → vert (les requêtes existantes ne cassent pas ; les drops ne retirent que des index redondants). Tester aussi `php artisan migrate:rollback --step=1` puis `migrate` sur la base locale pour confirmer up/down.

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/database/migrations/2026_06_11_000001_add_performance_indexes.php backend/tests/Feature/TenantSchema/PerformanceIndexesTest.php
git commit -m "perf(db): partial overlap index + composite availability indexes, drop redundant standalones"
```

---

### Task 3 : Corriger le N+1 (`AvailabilityCalculator`)

**Fichiers :**
- Modifier : `backend/app/Services/Tenant/AvailabilityCalculator.php`
- Test : `backend/tests/Feature/TenantSchema/AvailabilityCalculatorQueryCountTest.php` (nouveau)

- [ ] **Étape 1 : test rouge** — d'abord lire un test calculateur existant (`tests/Feature/TenantSchema/` — chercher WidgetBooking/Slot) pour le setup factory (praticien + service + availability + lien pivot). Créer `backend/tests/Feature/TenantSchema/AvailabilityCalculatorQueryCountTest.php` :

```php
<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Services\Tenant\AvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('queries the availabilities table once regardless of the window width', function () {
    // ... seed: 1 active practitioner offering 1 active service, with a weekly
    //     availability (e.g. Mon 09:00-17:00, slot 30min). Use the existing
    //     factory setup from WidgetBookingTest. ...

    $calc = app(AvailabilityCalculator::class);
    $from = CarbonImmutable::now()->addDays(3)->startOfDay();
    $to = $from->addDays(40); // wide window — would be ~40 queries under the N+1

    $count = 0;
    DB::listen(function ($q) use (&$count) {
        if (str_contains($q->sql, '"availabilities"')) {
            $count++;
        }
    });

    $calc->forPractitionerService($practitioner, $service, $from, $to);

    expect($count)->toBe(1); // hoisted out of the day-loop
});
```

⚠️ **Live-revert obligatoire** : avant d'implémenter le fix, lancer ce test → il doit échouer avec un compte ≈ nombre de jours ouvrés dans la fenêtre (preuve du N+1). Documenter le compte observé (rouge) dans le rapport. Le `str_contains` sur `"availabilities"` doit ne matcher QUE la table availabilities (attention à ne pas matcher `availability_exceptions` — vérifier : la requête exceptions cible `"availability_exceptions"`, distincte ; affiner le match si besoin, ex. regex `/from "availabilities"/i`).

- [ ] **Étape 2 : implémenter** — dans `forPractitionerService`, AVANT la boucle `for ($day...)`, charger une fois ; DANS la boucle, remplacer la requête (lignes 57-61) par un filtre en mémoire :

```php
        $allAvailabilities = $practitioner->availabilities()->get();
```

```php
            $availabilities = $allAvailabilities->filter(function ($a) use ($day) {
                if ((int) $a->day_of_week !== $day->dayOfWeekIso) {
                    return false;
                }
                if ($a->valid_from !== null && $a->valid_from->startOfDay()->greaterThan($day->startOfDay())) {
                    return false;
                }
                if ($a->valid_to !== null && $a->valid_to->startOfDay()->lessThan($day->startOfDay())) {
                    return false;
                }
                return true;
            });
```

⚠️ **Vérifier le cast réel** de `valid_from`/`valid_to` dans le modèle `Availability` (`$casts`). S'ils sont `date`/`datetime` → Carbon, le `.startOfDay()` ci-dessus reproduit la sémantique **jour** de `whereDate`. S'ils sont nullable et castés autrement, adapter pour matcher EXACTEMENT les 3 conditions SQL d'origine (`day_of_week =`, `valid_from <= day OR null`, `valid_to >= day OR null`). Reproduire la sémantique, ne pas la « corriger ».

- [ ] **Étape 3 : lancer** — `php artisan test --filter=AvailabilityCalculatorQueryCountTest` → PASS (compte = 1). **Puis lancer toute la suite des tests calculateur/booking existants** (`php artisan test --filter=Widget` + `--filter=Availability` + `--filter=Slot`) → ils prouvent que **les mêmes slots sont produits** (sémantique préservée). `composer test` → vert.
⚠️ Si un test calculateur existant casse, c'est que le filtre PHP ne reproduit pas exactement le SQL — corriger le filtre, PAS le test. Documenter.

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Services/Tenant/AvailabilityCalculator.php backend/tests/Feature/TenantSchema/AvailabilityCalculatorQueryCountTest.php
git commit -m "perf(calculator): hoist availabilities out of the per-day loop (~60x fewer queries)"
```

---

### Task 4 : Cache des listes widget + invalidation

**Fichiers :**
- Créer : `backend/app/Support/CatalogCache.php`
- Créer : `backend/app/Observers/CatalogObserver.php` (ou hooks dans les modèles)
- Modifier : `backend/app/Http/Controllers/Widget/ServiceController.php`
- Modifier : `backend/app/Http/Controllers/Tenant/ServiceController.php` (flush après `sync`)
- Modifier : `backend/app/Models/Tenant/Service.php` + `Practitioner.php` (enregistrer l'observer) OU `AppServiceProvider::boot`
- Test : `backend/tests/Feature/TenantSchema/WidgetCatalogCacheTest.php` (nouveau)

- [ ] **Étape 1 : test rouge** — créer `backend/tests/Feature/TenantSchema/WidgetCatalogCacheTest.php` :

```php
<?php

use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('serves the services list from cache on the second call', function () {
    Service::factory()->create(['is_active' => true]);

    $this->getJson('/api/v1/widget/services')->assertOk(); // warms cache

    $count = 0;
    DB::listen(function ($q) use (&$count) {
        if (str_contains($q->sql, 'from "services"')) {
            $count++;
        }
    });
    $this->getJson('/api/v1/widget/services')->assertOk();

    expect($count)->toBe(0); // served from cache
});

it('invalidates the services cache when a service is created via the staff side', function () {
    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(0);

    Service::factory()->create(['is_active' => true, 'name' => 'Neue Leistung']);

    $this->getJson('/api/v1/widget/services')->assertOk()->assertJsonCount(1);
});

it('invalidates the practitioners cache when the pivot is synced', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(0);

    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->sync([$p->id]);
    \App\Support\CatalogCache::flush(); // mirrors what the staff ServiceController does after sync

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(1);
});

it('invalidates when a practitioner is toggled inactive', function () {
    $service = Service::factory()->create(['is_active' => true]);
    $p = Practitioner::factory()->create(['is_active' => true]);
    $service->practitioners()->sync([$p->id]);
    \App\Support\CatalogCache::flush();

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(1);

    $p->update(['is_active' => false]); // Practitioner saved → observer flushes

    $this->getJson("/api/v1/widget/services/{$service->id}/practitioners")
        ->assertOk()->assertJsonCount(0);
});
```

⚠️ Notes : le 3e test appelle `CatalogCache::flush()` manuellement APRÈS le `sync()` direct sur la relation — c'est ce que fait le staff `ServiceController` en prod. Le test prouve que le flush fonctionne ; le test « via le vrai endpoint staff » est optionnel (nécessite auth + 2FA). Le 4e test prouve l'observer sur `Practitioner::saved`. Adapter le `str_contains` SQL au dialecte réel (Postgres : `from "services"`).

Lancer → le 1er test échoue (compte = 1, pas de cache aujourd'hui). Observer le rouge.

- [ ] **Étape 2 : implémenter**

`backend/app/Support/CatalogCache.php` :

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CatalogCache
{
    private const VERSION_KEY = 'widget:catalog:version';

    public static function version(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }

    public static function flush(): void
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
    }

    public static function servicesKey(): string
    {
        return 'widget:services:v'.self::version();
    }

    public static function practitionersKey(int $serviceId): string
    {
        return "widget:practitioners:{$serviceId}:v".self::version();
    }
}
```

`backend/app/Http/Controllers/Widget/ServiceController.php` — envelopper les 2 requêtes dans `Cache::rememberForever(CatalogCache::servicesKey(), ...)` / `practitionersKey($service->id)`.

`backend/app/Observers/CatalogObserver.php` — un observer générique :

```php
<?php

namespace App\Observers;

use App\Support\CatalogCache;

class CatalogObserver
{
    public function saved(mixed $model): void { CatalogCache::flush(); }
    public function deleted(mixed $model): void { CatalogCache::flush(); }
}
```

Enregistrer dans `AppServiceProvider::boot` :

```php
        \App\Models\Tenant\Service::observe(\App\Observers\CatalogObserver::class);
        \App\Models\Tenant\Practitioner::observe(\App\Observers\CatalogObserver::class);
```

`backend/app/Http/Controllers/Tenant/ServiceController.php` — après CHAQUE `->sync($practitionerIds)` (lignes ~36, ~56), ajouter `CatalogCache::flush();` (le sync ne déclenche aucun event modèle).

- [ ] **Étape 3 : lancer** — `php artisan test --filter=WidgetCatalogCacheTest` → PASS (4). `composer test` → vert.
⚠️ Régression possible : un test existant qui crée un service puis lit l'endpoint widget dans le même process pourrait voir du cache périmé — mais l'observer flush sur `saved`, donc create→flush→version bump→nouvelle clé. Vérifier `WidgetBookingTest`/`ServiceControllerTest` existants restent verts. Si un test staff assertait un nombre de requêtes, l'observer ajoute un `Cache::forever` (pas une requête DB sous store array). Rapporter tout test touché.

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Support/CatalogCache.php backend/app/Observers/CatalogObserver.php backend/app/Http/Controllers/Widget/ServiceController.php backend/app/Http/Controllers/Tenant/ServiceController.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/TenantSchema/WidgetCatalogCacheTest.php
git commit -m "perf(widget): forever-cache services/practitioners lists with version-counter invalidation"
```

---

### Task 5 : Vérification finale, push, PR

- [ ] **Étape 1 : suites complètes**

```bash
composer test            # 190 + ~12 nouveaux, tout vert
npm run test:widget      # 102 intouché
vendor/bin/pint --test   # nos fichiers clean (la dette pré-existante de main peut apparaître)
```

- [ ] **Étape 2 : revue anti-régression du diff complet** (`git diff origin/main...HEAD`) :
- N+1 : `availabilities()` chargé hors boucle, filtre PHP reproduit les 3 conditions SQL exactement.
- Migration réversible (up/down testés), index partiel légal (pas de `now()`), standalone redondants droppés et recréés en `down()`.
- Cache : invalidation sur create/update/delete (observer) ET sur `sync()` (flush explicite) ET toggle is_active.
- `config('mail.mailers.smtp.timeout')` = 5.
- Sémantique calculateur inchangée (tests booking/slot existants verts).

- [ ] **Étape 3 : push + PR**

```bash
git push -u origin feature/perf-optimizations
gh pr create --title "perf: N+1 fix, db indexes, widget list caching, smtp timeout [PR-D]" --body "<résumé : tableau des 5 optims, gains, tests query-count, lien spec ; note ops : index créés en prod>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Puis agent code-reviewer final + boucle CodeRabbit (autofix Major+Minor). **Pas de merge, pas de deploy sans instruction explicite.**

---

## Notes d'auto-revue du plan

- Couverture spec : §5→Task 1, §2+§3→Task 2, §1→Task 3, §4→Task 4. Ordre du plus isolé au plus intriqué.
- Le N+1 (Task 3) est le gain principal — test query-count + live-revert obligatoire pour prouver la régression d'origine.
- Pièges anticipés : noms d'index Laravel auto-générés (vérifier via pg_indexes avant d'asserter) ; cast date de `valid_from/to` (reproduire `whereDate` jour, pas datetime) ; `sync()` ne déclenche pas d'event → flush explicite ; réversibilité migration (down recrée les standalone).
- Tests query-count via `DB::listen` — affiner le `str_contains` pour ne pas matcher `availability_exceptions` quand on compte `availabilities`.
