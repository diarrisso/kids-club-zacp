# PR-D — Optimisations de performance

**Date :** 2026-06-11
**Statut :** Dérivé du backlog d'audit validé (`2026-06-10-security-audit-backlog.md`, section PR-D) + exploration du code — à relire.
**Périmètre :** Backend uniquement. Quatrième et dernier volet du batch (A: 2FA ✅ #29 · B: headers ✅ #31 · C: anti-abus ✅ #32 · **D: perf**). Indépendant des trois autres (aucun chevauchement de fichiers avec PR-C).

## Problèmes (mesurés à la lecture du code)

1. **[HIGH] N+1 dans `AvailabilityCalculator::forPractitionerService` — le plus gros gain.** Les disponibilités (`$practitioner->availabilities()->...->get()`) sont **re-requêtées à l'intérieur de la boucle par jour** (lignes 57-61). Pour une fenêtre de 60 jours = **60 requêtes par praticien**, × N praticiens à chaque interaction du widget (calendrier, sélection de date). Or les disponibilités d'un praticien sont au plus une poignée de lignes hebdomadaires.
2. **[HIGH] Pas d'index pour la requête de chevauchement de rendez-vous.** Elle tourne **dans le verrou de réservation** (`AppointmentController:42-47`) ET dans le calculateur (`:47-49`) ET dans `AppointmentScheduler:57` : `whereIn('status', ['pending','confirmed'])->where('starts_at','<=',$to)->where('ends_at','>=',$from)`. Seul index présent : `(practitioner_id, starts_at)` — il ne couvre pas le filtre `status` ni `ends_at`. Allonge la durée de détention du verrou pessimiste.
3. **[MED] Index composites manquants** sur `availabilities` et `availability_exceptions` (interrogées par `practitioner_id` + `day_of_week` / fenêtre temporelle). Index standalone redondants à nettoyer.
4. **[MED] Listes `services` / `practitioners` re-requêtées à chaque montage du widget** alors qu'elles changent ~mensuellement. Pas de cache.
5. **[LOW] Timeout SMTP non borné.** `Mail::queue()` sur file `sync` bloque la réponse HTTP de réservation sur le SMTP (`config/mail.php` `timeout => null`). Un MX mort fait stagner le spinner "réservation" du parent avant de tomber dans `rescue()`.

## Design

### 1. Corriger le N+1 (`AvailabilityCalculator::forPractitionerService`) — pur PHP, pas de migration

Charger les disponibilités **une fois** avant la boucle, filtrer `day_of_week` + `valid_from`/`valid_to` en PHP :

```php
// AVANT la boucle for($day...) :
$allAvailabilities = $practitioner->availabilities()->get();

// DANS la boucle, remplacer la requête par un filtre en mémoire :
$availabilities = $allAvailabilities->filter(function ($a) use ($day) {
    if ((int) $a->day_of_week !== $day->dayOfWeekIso) {
        return false;
    }
    if ($a->valid_from !== null && $a->valid_from->gt($day)) {
        return false;
    }
    if ($a->valid_to !== null && $a->valid_to->lt($day)) {
        return false;
    }
    return true;
});
```

- **Sémantique strictement préservée** : le filtre PHP reproduit exactement les 3 conditions SQL (`day_of_week =`, `valid_from <= day OR null`, `valid_to >= day OR null`). ⚠️ Attention aux casts de date : `valid_from`/`valid_to` sont des `date` Eloquent (Carbon) ou null — comparer en `->startOfDay()` si besoin pour matcher `whereDate`. L'implémenteur vérifie le cast réel du modèle `Availability` et reproduit la sémantique `whereDate` (comparaison **jour**, pas datetime).
- Réduction ~60× des requêtes `availabilities`. Les exceptions et appointments sont **déjà** chargés une fois hors boucle (lignes 44-49) — modèle à suivre.
- `isBookable` (ligne 118) requête les disponibilités **une seule fois** (pas de boucle) → **non concerné**, on n'y touche pas.

### 2. Index partiel composite pour le chevauchement (nouvelle migration)

Le schema builder Laravel ne fait pas les index partiels → `DB::statement` (PostgreSQL) :

```php
public function up(): void
{
    DB::statement(
        'CREATE INDEX appointments_overlap_idx ON appointments '.
        '(practitioner_id, starts_at, ends_at) '.
        "WHERE status IN ('pending', 'confirmed')"
    );
}
public function down(): void
{
    DB::statement('DROP INDEX IF EXISTS appointments_overlap_idx');
}
```

- `status IN (...)` est une constante → **prédicat d'index partiel légal** (pas de `now()`, piège PostgreSQL connu évité).
- L'index existant `(practitioner_id, starts_at)` est **conservé** : il sert les requêtes calendrier staff qui n'ont pas le filtre `status` (affichage de tous les rendez-vous, annulés inclus). Un index partiel n'aide que les requêtes incluant son prédicat.

### 3. Index composites availabilities / exceptions (même migration ou séparée)

```php
// availabilities : requêtée par (practitioner_id, day_of_week)
Schema::table('availabilities', function (Blueprint $t) {
    $t->index(['practitioner_id', 'day_of_week']);
    $t->dropIndex(['practitioner_id']); // redondant : préfixe gauche du composite
});

// availability_exceptions : requêtée par practitioner_id + fenêtre [starts_at, ends_at]
Schema::table('availability_exceptions', function (Blueprint $t) {
    $t->index(['practitioner_id', 'starts_at', 'ends_at']);
    $t->dropIndex(['practitioner_id']);  // redondant : préfixe gauche du composite
    $t->dropIndex(['starts_at']);        // redondant : l'audit le signale
});
```

- **Règle du préfixe gauche** : `(practitioner_id, day_of_week)` couvre déjà les recherches sur `practitioner_id` seul → le standalone devient mort. Idem exceptions. On les retire pour garder un jeu d'index minimal (chaque index ralentit les écritures + occupe du disque).
- ⚠️ L'implémenteur vérifie le **nom exact** des index auto-générés par Laravel (`availabilities_practitioner_id_index`, `availability_exceptions_starts_at_index`) via `\Schema::getIndexes()` ou le naming par défaut, et utilise `dropIndex(['col'])` (Laravel reconstruit le nom) plutôt qu'un nom en dur. `down()` symétrique recrée les standalone et drop les composites.

### 4. Cache des listes widget services / practitioners (`ServiceController`)

Stratégie miroir du `Setting` (forever-cache + invalidation explicite à l'écriture), avec un **compteur de version** car les clés `practitioners` sont par-service (impossible de toutes les énumérer pour un `forget`) :

```php
// app/Support/CatalogCache.php (nouveau)
class CatalogCache
{
    public static function version(): int
    {
        return (int) Cache::rememberForever('widget:catalog:version', fn () => 1);
    }
    public static function flush(): void
    {
        Cache::forget('widget:catalog:version');
        Cache::rememberForever('widget:catalog:version', fn () => self::previous() + 1);
    }
    // ... (l'implémenteur peut simplifier en Cache::increment avec init ; détail TDD)
}
```

**Lecture** (`ServiceController`) :

```php
public function index(): JsonResponse
{
    $v = CatalogCache::version();
    return response()->json(
        Cache::rememberForever("widget:services:v{$v}", fn () => Service::where('is_active', true)
            ->orderBy('name')->get(['id','name','duration_minutes','color','description']))
    );
}
public function practitioners(Service $service): JsonResponse
{
    $v = CatalogCache::version();
    return response()->json(
        Cache::rememberForever("widget:practitioners:{$service->id}:v{$v}", fn () => $service->practitioners()
            ->where('is_active', true)->orderBy('last_name')
            ->get(['practitioners.id','first_name','last_name','title','color']))
    );
}
```

**Invalidation** — `CatalogCache::flush()` appelé depuis :
- un Observer sur `Service` (events `saved` + `deleted`) ;
- un Observer sur `Practitioner` (events `saved` + `deleted`) ;
- **explicitement après chaque `->sync($practitionerIds)`** dans `Tenant\ServiceController` (lignes 36, 56) — un `sync()` de pivot ne déclenche **aucun** event modèle.

Bumper la version **orpheline** toutes les anciennes clés (elles expirent/s'évincent seules). Robuste, sans tags (le store `database` ne supporte pas les tags), sans énumération.

⚠️ Les endpoints **slots / availability/days ne sont PAS cachables** (ils dépendent de `now()` via lead/horizon).

### 5. Timeout SMTP (`config/mail.php`)

```php
'smtp' => [
    'transport' => 'smtp',
    // ... existant ...
    'timeout' => env('MAIL_TIMEOUT', 5), // était null ; borne le blocage de la réponse HTTP sur un MX mort
],
```

5 s : un MX injoignable tombe vite dans le `rescue()` du booking au lieu de geler le spinner du parent. Overridable par env.

### Explicitement hors périmètre

- Split du bundle widget IIFE (~172 KB, embarque Vue) — inhérent à un IIFE embarquable, l'audit le classe INFO « pas worth ». L'app principale est déjà code-splittée par page.
- Toute optimisation changeant la sémantique du calculateur (la correction N+1 préserve la sémantique exacte).

## Tests (TDD, Pest) — baseline 190 (la branche part de main, pas de PR-C)

- **N+1 (le test signature)** : seeder 1 praticien + 1 service + ≥1 availability, requêter `/api/v1/widget/slots` sur une fenêtre large (ex. 40 jours). Compter via `DB::listen`/`getQueryLog` les requêtes touchant `availabilities` → doit être **constant (1)** quelle que soit la largeur de fenêtre. **Live-revert** : remettre la requête dans la boucle et prouver que le compte explose (≈ nb de jours) — documenté par l'implémenteur.
- **Index** : assertion que la migration `up`/`down` passe ; vérifier la présence de `appointments_overlap_idx` via `SELECT indexname FROM pg_indexes` ; suite complète verte (aucune requête cassée par les drops). 
- **Cache services/practitioners** : 1er appel remplit le cache ; 2e appel identique → **0 requête** sur `services`/`practitioners` (query log). Après un `Service::create/update` (ou un `sync()` pivot via le staff), le cache est invalidé → le nouvel état est servi (la version a bumpé). Tester aussi l'invalidation sur toggle `is_active` praticien.
- **SMTP timeout** : assertion `config('mail.mailers.smtp.timeout') === 5` (défaut env absent).
- Suite complète verte (190 + ~10 nouveaux), Pint clean sur nos fichiers, widget Vitest intouché.

## Critères d'acceptation

- [ ] `availabilities` requêtée 1× par appel `forPractitionerService` quelle que soit la fenêtre (test query-count + live-revert).
- [ ] `appointments_overlap_idx` partiel créé ; migration réversible ; index redondants droppés.
- [ ] Index composites availabilities/exceptions créés, standalone redondants retirés.
- [ ] Listes services/practitioners servies depuis le cache ; invalidées sur create/update/delete/toggle/sync staff.
- [ ] `config('mail.mailers.smtp.timeout')` = 5.
- [ ] Sémantique du calculateur **inchangée** (mêmes slots produits) ; suite verte ; widget Vitest intouché.

## Risques / points d'attention

- **Le filtre N+1 en PHP doit reproduire `whereDate` exactement** (comparaison au niveau **jour**, pas datetime). Piège : si `valid_from`/`valid_to` sont castés `datetime` au lieu de `date`, une comparaison naïve `->gt($day)` peut décaler d'un jour. Vérifier le cast du modèle `Availability` et tester une availability avec `valid_from = aujourd'hui` (doit être incluse).
- **Invalidation cache via `sync()`** : facile à oublier — un `sync()` ne déclenche pas d'event modèle. Le flush explicite après chaque `sync()` est non négociable, à tester.
- **Drop d'index standalone** : `down()` doit les recréer pour une migration réversible. Vérifier les noms par défaut Laravel.
- **`MAIL_TIMEOUT` env** : ne pas casser les envois lents légitimes — 5 s est large pour un MX sain ; documenter dans `.env.example`.
- **Compteur de version cache** : sous store `array` (tests), chaque test a un cache neuf → la version repart à 1, OK. Sous `database` (prod), le bump est persistant et partagé entre workers — comportement voulu.
