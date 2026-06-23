# Export CSV des statistiques no-show — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un export CSV (ventilation par praticien + ligne Gesamt) à la page `/statistiken`, réutilisant la logique d'agrégation existante.

**Architecture:** Extraire la logique d'agrégation de `StatisticsController::index()` dans une méthode privée partagée `computeStats()`, puis ajouter une action `export()` qui la réutilise et streame un CSV via `fputcsv`. Bouton-lien sur la page frontend réutilisant la période. Aucune migration.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 (`<script setup lang="ts">`), Tailwind 3, Pest 4, PostgreSQL.

## Global Constraints

- **PostgreSQL** est la cible réelle (tests forcent `DB_CONNECTION=pgsql`).
- **URLs allemandes, noms de route anglais** — URL `/statistiken/export`, route `tenant.statistics.export`. Jamais de chemin hardcodé côté serveur.
- **CSV standard international** : séparateur virgule `,` (défaut `fputcsv`), **UTF-8 sans BOM**, **point décimal**. Taux = nombre brut (PHP cast), **cellule vide** si `null`.
- **`fputcsv`** obligatoire (échappement natif → un nom de praticien avec `,`/`"` ne casse pas les colonnes, pas d'injection de séparateur). Jamais de concaténation manuelle de CSV.
- **Scope par rôle fail-closed identique à la page** : un médecin n'exporte que ses chiffres ; médecin non lié → aucune ligne praticien (`?? -1`). Réception/admin → tout.
- **Anti-N+1** : `computeStats()` conserve la requête agrégée unique + 1 `whereIn`. L'export ne rajoute aucune requête.
- **No-show sur RDV passés uniquement** (borne haute `min(to, now)`), `null` exclus du dénominateur, annulés exclus, timezone `Europe/Berlin` — tout ça vit déjà dans `computeStats()` (extrait sans changement de comportement).
- **Aucune migration.**
- Tests style Pest (`it(...)`) ; `composer test` doit rester vert (dont `StatisticsTest`, qui prouve la non-régression de `index()`).

---

### Task 1: Backend — extraire `computeStats()`, ajouter `export()`, route, tests

**Files:**
- Modify: `backend/app/Http/Controllers/Tenant/StatisticsController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/StatisticsExportTest.php` (créer)

**Interfaces:**
- Consumes: `App\Http\Requests\Tenant\StatisticsRequest` (existant : valide `from`/`to` nullable date + `from <= to`, 422 JSON sur échec) ; `App\Models\Tenant\Appointment`, `App\Models\Tenant\Practitioner` (`fullName()`, `color`), `App\Models\User::isMedecin()` + `practitioner_id`.
- Produces:
  - `private computeStats(StatisticsRequest $request): array` → `['kpis'=>['arrived'=>int,'noShow'=>int,'notRecorded'=>int,'rate'=>float|null], 'perPractitioner'=>array<{id,name,color,arrived,noShow,rate}>, 'filters'=>['from'=>string,'to'=>string], 'scoped'=>bool]` (exactement la structure rendue aujourd'hui).
  - `GET /statistiken/export` (route `tenant.statistics.export`) → `StreamedResponse` CSV (`text/csv`, `Content-Disposition: attachment; filename="noshow-statistik_<from>_<to>.csv"`).

- [ ] **Step 1: Write the failing tests**

Créer `backend/tests/Feature/TenantSchema/StatisticsExportTest.php` :

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
});

// Distinct helper names — StatisticsTest.php already declares statsStaff()/pastAt()
// at top level in the same Pest suite; re-declaring them would fatal.
function csvStaff(): User
{
    return User::factory()->create([
        'role' => 'secretaire',
        'two_factor_confirmed_at' => now(),
    ]);
}

function csvPast(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Berlin')->subDays(10)->setTime(9, 0);
}

it('exports a CSV with text/csv content type and a dated attachment filename', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);

    $from = CarbonImmutable::now('Europe/Berlin')->subDays(30)->toDateString();
    $to = CarbonImmutable::now('Europe/Berlin')->toDateString();

    $res = $this->actingAs(csvStaff())->get("/statistiken/export?from={$from}&to={$to}");

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain("noshow-statistik_{$from}_{$to}.csv");
});

it('writes per-practitioner rows sorted by rate desc plus a Gesamt total row', function () {
    $a = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Anna', 'last_name' => 'M']);
    $b = Practitioner::factory()->create(['title' => 'Dr.', 'first_name' => 'Bo', 'last_name' => 'S']);
    Appointment::factory()->count(5)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);
    Appointment::factory()->count(1)->create(['practitioner_id' => $a->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);

    $csv = $this->actingAs(csvStaff())->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('Behandler,Erschienen,Nicht erschienen,Nicht erfasst,No-Show-Quote (%)');
    // b first (100%), then a (1/6 = 16.7%)
    expect($lines[1])->toBe('Dr. Bo S,0,2,,100');
    expect($lines[2])->toBe('Dr. Anna M,5,1,,16.7');
    // Gesamt last: arrived 5, no_show 3, notRecorded 0, rate 3/8 = 37.5
    expect($lines[3])->toBe('Gesamt,5,3,0,37.5');
});

it('scopes a linked medecin export to their own single row', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);
    Appointment::factory()->count(4)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => csvPast()]);

    $medecin = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => $a->id, 'two_factor_confirmed_at' => now(),
    ]);

    $csv = $this->actingAs($medecin)->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // header + exactly 1 practitioner row + Gesamt
    expect($lines)->toHaveCount(3);
    // Gesamt = a's figures only (3 arrived, 0 no_show, rate 0)
    expect($lines[2])->toBe('Gesamt,3,0,0,0');
});

it('fails closed: an unlinked medecin export has no practitioner rows', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(5)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => csvPast()]);

    $unlinked = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => null, 'two_factor_confirmed_at' => now(),
    ]);

    $csv = $this->actingAs($unlinked)->get('/statistiken/export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // header + Gesamt only
    expect($lines)->toHaveCount(2);
    expect($lines[1])->toBe('Gesamt,0,0,0,');
});

it('rejects an invalid period on export with 422', function () {
    $this->actingAs(csvStaff())
        ->get('/statistiken/export?from=not-a-date')
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=StatisticsExportTest`
Expected: FAIL (route `/statistiken/export` inexistante → 404 / méthode manquante).

- [ ] **Step 3: Refactor the controller — extract `computeStats()` and add `export()`**

Remplacer **intégralement** le corps de la classe `StatisticsController` (le fichier `backend/app/Http/Controllers/Tenant/StatisticsController.php`) par ceci. Le `computeStats()` est le corps actuel de `index()` (déplacé tel quel, AUCUN changement de logique) ; `index()` et `export()` l'appellent.

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StatisticsRequest;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController extends Controller
{
    private const TZ = 'Europe/Berlin';

    public function index(StatisticsRequest $request): Response
    {
        return Inertia::render('Tenant/Statistics/Index', $this->computeStats($request));
    }

    public function export(StatisticsRequest $request): StreamedResponse
    {
        $data = $this->computeStats($request);
        $filename = "noshow-statistik_{$data['filters']['from']}_{$data['filters']['to']}.csv";

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Behandler', 'Erschienen', 'Nicht erschienen', 'Nicht erfasst', 'No-Show-Quote (%)']);

            foreach ($data['perPractitioner'] as $row) {
                fputcsv($out, [
                    $row['name'],
                    $row['arrived'],
                    $row['noShow'],
                    '', // per-practitioner "Nicht erfasst" not tracked in V1 (see spec)
                    $row['rate'] ?? '',
                ]);
            }

            fputcsv($out, [
                'Gesamt',
                $data['kpis']['arrived'],
                $data['kpis']['noShow'],
                $data['kpis']['notRecorded'],
                $data['kpis']['rate'] ?? '',
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Aggregate no-show stats for the requested period, scoped by role
     * (fail-closed for a medecin). Single source of truth shared by index()
     * (Inertia page) and export() (CSV). Returns the exact prop structure the
     * page consumes: kpis, perPractitioner, filters, scoped.
     *
     * @return array{kpis: array{arrived:int,noShow:int,notRecorded:int,rate:float|null}, perPractitioner: array<int, array{id:mixed,name:string,color:string,arrived:int,noShow:int,rate:float|null}>, filters: array{from:string,to:string}, scoped: bool}
     */
    private function computeStats(StatisticsRequest $request): array
    {
        $user = $request->user();
        $data = $request->validated();
        $now = CarbonImmutable::now(self::TZ);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], self::TZ)->startOfDay()
            : $now->subDays(30)->startOfDay();
        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'], self::TZ)->endOfDay()
            : $now->endOfDay();

        // No-show only makes sense for appointments that already happened: cap the
        // upper bound at "now" so future slots (attendance null by nature) never count.
        $upperBound = $to->greaterThan($now) ? $now : $to;

        // A medecin is ALWAYS scoped to their own practitioner (fail-closed): an
        // unlinked medecin (practitioner_id null) sees nothing — never the whole
        // cabinet. Reception/admin (non-medecin) see everything.
        $isMedecin = $user->isMedecin();
        $practitionerId = $isMedecin ? $user->practitioner_id : null;

        // ONE aggregated query. toBase() bypasses Eloquent casting so $row->attendance
        // is the raw string ('arrived'/'no_show') or null — never the enum.
        $rows = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$from, $upperBound])
            // Linked medecin → their own rows; unlinked medecin → none (?? -1 can
            // never match a real practitioner id, so the result is empty). Bound
            // param, no raw SQL.
            ->when($isMedecin, fn ($q) => $q->where('practitioner_id', $practitionerId ?? -1))
            ->selectRaw('practitioner_id, attendance, COUNT(*) as total')
            ->groupBy('practitioner_id', 'attendance')
            ->toBase()
            ->get();

        $arrived = 0;
        $noShow = 0;
        $notRecorded = 0;
        $byPract = []; // practitioner_id => ['arrived'=>int, 'noShow'=>int]

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $pid = $row->practitioner_id;
            $byPract[$pid] ??= ['arrived' => 0, 'noShow' => 0];

            if ($row->attendance === 'arrived') {
                $arrived += $total;
                $byPract[$pid]['arrived'] += $total;
            } elseif ($row->attendance === 'no_show') {
                $noShow += $total;
                $byPract[$pid]['noShow'] += $total;
            } else {
                $notRecorded += $total;
            }
        }

        $practitioners = Practitioner::query()
            ->whereIn('id', array_keys($byPract))
            ->get()
            ->keyBy('id');

        $perPractitioner = collect($byPract)
            ->map(function (array $c, $pid) use ($practitioners) {
                $p = $practitioners->get($pid);
                $denom = $c['arrived'] + $c['noShow'];

                return [
                    'id' => $pid,
                    'name' => $p?->fullName() ?? '—',
                    'color' => $p?->color ?? '#94a3b8',
                    'arrived' => $c['arrived'],
                    'noShow' => $c['noShow'],
                    'rate' => $denom > 0 ? round($c['noShow'] / $denom * 100, 1) : null,
                ];
            })
            // Sort by no-show rate desc; null rates (nothing recorded) sink to the bottom.
            ->sortByDesc(fn (array $r) => $r['rate'] ?? -1)
            ->values()
            ->all();

        $denom = $arrived + $noShow;

        return [
            'kpis' => [
                'arrived' => $arrived,
                'noShow' => $noShow,
                'notRecorded' => $notRecorded,
                'rate' => $denom > 0 ? round($noShow / $denom * 100, 1) : null,
            ],
            'perPractitioner' => $perPractitioner,
            // `to` echoes the user's requested range for display; the query itself
            // is capped at `$upperBound` (min(to, now)). Keep these distinct on purpose.
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'scoped' => $isMedecin,
        ];
    }
}
```

- [ ] **Step 4: Register the export route**

Dans `backend/routes/web.php`, juste **après** la route `tenant.statistics.index` (lignes 73-74), ajouter :

```php
    Route::get('/statistiken/export', [StatisticsController::class, 'export'])
        ->name('tenant.statistics.export');
```

> L'import `use App\Http\Controllers\Tenant\StatisticsController;` existe déjà (ajouté au Lot B). Ne pas le redoubler.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=StatisticsExportTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the stats suite to prove no regression on `index()`**

Run: `cd backend && php artisan test --filter=Statistics`
Expected: PASS (les 12 de `StatisticsTest` + les 5 de `StatisticsExportTest` = 17). `index()` rend toujours la même structure (computeStats extrait sans changement).

- [ ] **Step 7: Pint + commit**

```bash
cd backend && vendor/bin/pint app/Http/Controllers/Tenant/StatisticsController.php tests/Feature/TenantSchema/StatisticsExportTest.php
cd .. && git add backend/app/Http/Controllers/Tenant/StatisticsController.php backend/routes/web.php backend/tests/Feature/TenantSchema/StatisticsExportTest.php
git commit -m "feat(statistics): CSV export endpoint (per-practitioner + Gesamt)"
```

---

### Task 2: Frontend — bouton « CSV exportieren » sur la page

**Files:**
- Modify: `backend/resources/js/Pages/Tenant/Statistics/Index.vue`

**Interfaces:**
- Consumes: route `GET /statistiken/export` (Task 1) ; les refs `from`/`to` existantes de la page (déjà liées aux inputs date) ; `computed` (déjà importé).
- Produces: un lien-bouton de téléchargement réutilisant la période courante.

- [ ] **Step 1: Add the `exportUrl` computed**

Dans `backend/resources/js/Pages/Tenant/Statistics/Index.vue`, après le bloc `watch(() => props.filters, …)` (ajouté au Lot B) et avant `const hasData`, insérer :

```ts
// Plain anchor download (a file response, not an Inertia visit) reusing the
// dates currently in the inputs.
const exportUrl = computed(() => {
    const params = new URLSearchParams()
    if (from.value) params.set('from', from.value)
    if (to.value) params.set('to', to.value)
    const qs = params.toString()
    return `/statistiken/export${qs ? `?${qs}` : ''}`
})
```

- [ ] **Step 2: Add the button next to « Anzeigen »**

Dans le même fichier, dans la barre de période, juste **après** le `<button … @click="applyPeriod">Anzeigen</button>`, ajouter :

```html
            <a :href="exportUrl"
               class="rounded border border-kids-blue px-4 py-2 text-sm font-semibold text-kids-blue">CSV exportieren</a>
```

- [ ] **Step 3: Build the assets**

Run: `cd backend && npm run build`
Expected: build réussi sans erreur.

- [ ] **Step 4: Run the full backend suite (no regression)**

Run: `cd backend && composer test`
Expected: PASS (toute la suite verte, dont Task 1).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/Pages/Tenant/Statistics/Index.vue
git commit -m "feat(statistics): CSV export button on the statistics page"
```

---

### Task 3: Vérification

**Files:** aucun (vérification).

- [ ] **Step 1: Vérifier l'endpoint authentifié**

L'app exige auth + 2FA et la connexion est faite par l'utilisateur (cf. préférence). Coordination :
- Démarrer le serveur (`cd backend && php artisan serve` ; si l'outil Chrome bloque sur « document_idle », relancer avec `PHP_CLI_SERVER_WORKERS=5 php artisan serve`).
- Demander à l'utilisateur de se connecter (compte secrétaire) et d'ouvrir `/statistiken`.

- [ ] **Step 2: Vérifier le bouton + le téléchargement**

- Le bouton « CSV exportieren » est visible à côté de « Anzeigen ».
- Cliquer télécharge `noshow-statistik_<from>_<to>.csv`.
- Ouvrir le fichier : en-tête `Behandler,Erschienen,Nicht erschienen,Nicht erfasst,No-Show-Quote (%)`, une ligne par praticien (triées par taux ↓, cellule « Nicht erfasst » vide), ligne `Gesamt` avec les totaux. Cohérent avec les chiffres affichés à l'écran sur la même période.
- Changer la période, ré-exporter : le fichier reflète la nouvelle période.

- [ ] **Step 3: Confirmer (pas de commit)**

Noter le résultat ; aucune modification de code attendue si tout est correct.

---

## Self-Review

**Spec coverage :**
- Contenu (par praticien + Gesamt) → Task 1 (export() + tests 2,3,4) ✓
- Format CSV standard (`,`, UTF-8 sans BOM, point décimal, taux nombre/vide) → Task 1 (fputcsv, défaut) + test 2 ✓
- Endpoint `/statistiken/export` + `StatisticsRequest` (422) → Task 1 (route + test 5) ✓
- Anti-duplication `computeStats()` partagé → Task 1 (Step 3) + non-régression test 6 ✓
- Scope fail-closed → Task 1 (tests 3,4) ✓
- `fputcsv` anti-injection → Task 1 (Global Constraints + Step 3) ✓
- Bouton réutilisant la période → Task 2 ✓
- Vérif → Task 3 ✓
- Aucune migration → respecté ✓

**Placeholder scan :** aucun TODO/TBD ; code complet à chaque étape.

**Type consistency :** `computeStats()` retourne exactement `['kpis'=>['arrived','noShow','notRecorded','rate'], 'perPractitioner'=>[{id,name,color,arrived,noShow,rate}], 'filters'=>['from','to'], 'scoped']` — identique à ce que `index()` rendait avant + consommé tel quel par `export()` (clés `kpis.arrived/noShow/notRecorded/rate`, `perPractitioner[].name/arrived/noShow/rate`, `filters.from/to`). Helpers de test `csvStaff()`/`csvPast()` distincts de `statsStaff()`/`pastAt()` (évite le fatal redeclare dans la même suite Pest).
