# Lot B (V1) — Statistiques no-show — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner au cabinet une page `/statistiken` montrant le taux de no-show (KPI globaux + ventilation par praticien) sur une période sélectionnable.

**Architecture:** Un `StatisticsController` lit la colonne `attendance` (existante) via une seule requête agrégée groupée par `practitioner_id` + `attendance`, l'assemble en KPI + tableau par praticien (scope par rôle comme le Dashboard), et rend une page Inertia. Aucune migration.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 (`<script setup lang="ts">`), Tailwind 3, Pest 4, PostgreSQL.

## Global Constraints

- **PostgreSQL** est la cible réelle (tests forcent `DB_CONNECTION=pgsql`).
- **URLs allemandes, noms de route anglais** — URL `/statistiken`, route `tenant.statistics.index`. Jamais de chemin hardcodé.
- **No-show sur RDV passés uniquement** : borne haute de la période = `min(to_endOfDay, now)` ; les RDV futurs n'entrent jamais dans le calcul.
- **`null` (non pointés) EXCLUS du dénominateur du taux** : `rate = no_show / (arrived + no_show)`. Dénominateur 0 → `rate = null` (rendu « — »).
- **RDV `status = 'cancelled'` exclus** du calcul.
- **Scope par rôle** : `$user->isMedecin() ? $user->practitioner_id : null` (pattern du DashboardController) ; médecin lié → ses chiffres seulement.
- **Anti-N+1** : UNE requête agrégée (`selectRaw` + `groupBy`) + UNE requête `whereIn` pour les praticiens. Query-count constant.
- **`selectRaw` sans saisie utilisateur** (noms de colonnes en dur) ; `from`/`to` passent par bindings (`whereBetween`). Jamais de `whereRaw` avec input.
- **Timezone** `Europe/Berlin` pour toutes les bornes de date.
- **Aucune migration** (lecture de `attendance`).
- Tests style Pest (`it(...)`) ; `composer test` doit rester vert.

---

### Task 1: Backend — StatisticsController, StatisticsRequest, route, agrégation

**Files:**
- Create: `backend/app/Http/Requests/Tenant/StatisticsRequest.php`
- Create: `backend/app/Http/Controllers/Tenant/StatisticsController.php`
- Modify: `backend/routes/web.php` (route dans le groupe `['auth','two-factor.enrolled']`)
- Test: `backend/tests/Feature/TenantSchema/StatisticsTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant\Appointment` (colonne `attendance` : `'arrived'|'no_show'|null`), `App\Models\Tenant\Practitioner` (`fullName()`, `color`), `App\Models\User::isMedecin()` + `->practitioner_id`.
- Produces: `GET /statistiken` (route `tenant.statistics.index`) → Inertia page `Tenant/Statistics/Index` avec props :
  - `kpis`: `{arrived:int, noShow:int, notRecorded:int, rate:float|null}`
  - `perPractitioner`: `array<{id:int, name:string, color:string, arrived:int, noShow:int, rate:float|null}>` trié par `rate` décroissant
  - `filters`: `{from:string 'Y-m-d', to:string 'Y-m-d'}`
  - `scoped`: `bool` (true si médecin scopé)

- [ ] **Step 1: Write the failing tests**

Créer `backend/tests/Feature/TenantSchema/StatisticsTest.php` :

```php
<?php

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function statsStaff(): User
{
    return User::factory()->create([
        'role' => 'secretaire',
        'two_factor_confirmed_at' => now(),
    ]);
}

// A past Berlin datetime (10 days ago at 09:00), safely inside the default 30-day window.
function pastAt(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Berlin')->subDays(10)->setTime(9, 0);
}

it('computes the no-show rate from past recorded appointments', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(8)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Statistics/Index')
            ->where('kpis.arrived', 8)
            ->where('kpis.noShow', 2)
            ->where('kpis.rate', 20.0));
});

it('excludes not-recorded (null) appointments from the rate denominator', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(8)->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);
    Appointment::factory()->count(5)->create(['practitioner_id' => $p->id, 'attendance' => null, 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.rate', 20.0)        // 2 / (8+2), NOT 2/15
            ->where('kpis.notRecorded', 5));
});

it('excludes future appointments entirely', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    // Future appointment: tomorrow, not yet recorded — must not count anywhere.
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'attendance' => null,
        'starts_at' => CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(9, 0),
    ]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.arrived', 1)
            ->where('kpis.notRecorded', 0));
});

it('excludes cancelled appointments', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'no_show', 'status' => 'cancelled', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.arrived', 1)
            ->where('kpis.noShow', 0));
});

it('bounds the population by the from/to period', function () {
    $p = Practitioner::factory()->create();
    // Inside default window:
    Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    // 100 days ago — outside a from=60-days-ago filter:
    Appointment::factory()->create([
        'practitioner_id' => $p->id, 'attendance' => 'arrived',
        'starts_at' => CarbonImmutable::now('Europe/Berlin')->subDays(100)->setTime(9, 0),
    ]);

    $from = CarbonImmutable::now('Europe/Berlin')->subDays(60)->toDateString();
    $to = CarbonImmutable::now('Europe/Berlin')->toDateString();

    $this->actingAs(statsStaff())
        ->get("/statistiken?from={$from}&to={$to}")
        ->assertInertia(fn ($page) => $page->where('kpis.arrived', 1));
});

it('returns a null rate when no appointment is recorded', function () {
    $p = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $p->id, 'attendance' => null, 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.rate', null)
            ->where('kpis.notRecorded', 3));
});

it('breaks the figures down per practitioner', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(5)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(1)->create(['practitioner_id' => $a->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);
    Appointment::factory()->count(2)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $this->actingAs(statsStaff())
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->has('perPractitioner', 2)
            // sorted by rate desc: b (100%) before a (~16.7%)
            ->where('perPractitioner.0.id', $b->id)
            ->where('perPractitioner.0.rate', 100.0)
            ->where('perPractitioner.1.id', $a->id));
});

it('scopes a linked medecin to their own figures only', function () {
    $a = Practitioner::factory()->create();
    $b = Practitioner::factory()->create();
    Appointment::factory()->count(3)->create(['practitioner_id' => $a->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
    Appointment::factory()->count(4)->create(['practitioner_id' => $b->id, 'attendance' => 'no_show', 'starts_at' => pastAt()]);

    $medecin = User::factory()->create([
        'role' => 'medecin', 'practitioner_id' => $a->id, 'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($medecin)
        ->get('/statistiken')
        ->assertInertia(fn ($page) => $page
            ->where('scoped', true)
            ->where('kpis.arrived', 3)
            ->where('kpis.noShow', 0)          // b's no_shows excluded
            ->has('perPractitioner', 1));
});

it('keeps a constant query count regardless of practitioner count (no N+1)', function () {
    $mk = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            $p = Practitioner::factory()->create();
            Appointment::factory()->create(['practitioner_id' => $p->id, 'attendance' => 'arrived', 'starts_at' => pastAt()]);
        }
    };
    $mk(2);
    DB::enableQueryLog();
    $this->actingAs(statsStaff())->get('/statistiken')->assertOk();
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();

    $mk(8);
    $this->actingAs(statsStaff())->get('/statistiken')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($second)->toBe($first);
});

it('rejects an invalid period with 422', function () {
    $this->actingAs(statsStaff())
        ->get('/statistiken?from=not-a-date')
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=StatisticsTest`
Expected: FAIL (route `/statistiken` inexistante → 404 / classes manquantes).

- [ ] **Step 3: Create the Form Request**

`backend/app/Http/Requests/Tenant/StatisticsRequest.php` :

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * GET form: return JSON 422 on validation failure instead of the default
     * redirect, mirroring ListAppointmentsRequest (the page itself only ever
     * sends valid dates via Inertia router.get).
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => 'Ungültiger Zeitraum.', 'errors' => $validator->errors()], 422)
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`backend/app/Http/Controllers/Tenant/StatisticsController.php` :

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

class StatisticsController extends Controller
{
    private const TZ = 'Europe/Berlin';

    public function index(StatisticsRequest $request): Response
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

        // A linked medecin sees only their own figures (graceful: unlinked => all).
        $practitionerId = $user->isMedecin() ? $user->practitioner_id : null;

        // ONE aggregated query. toBase() bypasses Eloquent casting so $row->attendance
        // is the raw string ('arrived'/'no_show') or null — never the enum.
        $rows = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$from, $upperBound])
            ->when($practitionerId, fn ($q) => $q->where('practitioner_id', $practitionerId))
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

        return Inertia::render('Tenant/Statistics/Index', [
            'kpis' => [
                'arrived' => $arrived,
                'noShow' => $noShow,
                'notRecorded' => $notRecorded,
                'rate' => $denom > 0 ? round($noShow / $denom * 100, 1) : null,
            ],
            'perPractitioner' => $perPractitioner,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'scoped' => $practitionerId !== null,
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

Dans `backend/routes/web.php`, ajouter dans le groupe `Route::middleware(['auth', 'two-factor.enrolled'])`, après le bloc `/termine*` (et avant `/sicherheit` ou groupé près du dashboard) :

```php
    Route::get('/statistiken', [\App\Http\Controllers\Tenant\StatisticsController::class, 'index'])
        ->name('tenant.statistics.index');
```

> Vérifier l'import : soit ajouter `use App\Http\Controllers\Tenant\StatisticsController;` en tête (cohérent avec les autres `use` du fichier), soit utiliser le FQCN comme ci-dessus. Préférer l'ajout du `use` pour rester homogène avec les contrôleurs déjà importés.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=StatisticsTest`
Expected: PASS (10 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Tenant/StatisticsRequest.php backend/app/Http/Controllers/Tenant/StatisticsController.php backend/routes/web.php backend/tests/Feature/TenantSchema/StatisticsTest.php
git commit -m "feat(statistics): no-show statistics endpoint (KPIs + per-practitioner)"
```

---

### Task 2: Frontend — page `Statistics/Index.vue` + lien navigation

**Files:**
- Create: `backend/resources/js/Pages/Tenant/Statistics/Index.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue` (import lucide + entrée nav)

**Interfaces:**
- Consumes: page Inertia `Tenant/Statistics/Index` avec props `kpis` `{arrived,noShow,notRecorded,rate}`, `perPractitioner` `[{id,name,color,arrived,noShow,rate}]`, `filters` `{from,to}`, `scoped` (Task 1) ; composant `@/components/ui/StatCard.vue` (props `icon: Component`, `value`, `label`, `color: string` classe Tailwind bg).
- Produces: page fonctionnelle + lien nav « Statistiken ».

- [ ] **Step 1: Create the page**

`backend/resources/js/Pages/Tenant/Statistics/Index.vue` :

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { CheckCircle2, XCircle, Percent, HelpCircle } from 'lucide-vue-next'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import StatCard from '@/components/ui/StatCard.vue'

defineOptions({ layout: TenantLayout })

interface PractitionerRow {
    id: number
    name: string
    color: string
    arrived: number
    noShow: number
    rate: number | null
}

const props = defineProps<{
    kpis: { arrived: number; noShow: number; notRecorded: number; rate: number | null }
    perPractitioner: PractitionerRow[]
    filters: { from: string; to: string }
    scoped: boolean
}>()

const from = ref(props.filters.from)
const to = ref(props.filters.to)

// German percent formatting; "—" when there is nothing recorded (rate null).
const fmtRate = (rate: number | null) =>
    rate === null ? '—' : `${rate.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`

const applyPeriod = () => {
    router.get('/statistiken', {
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true, preserveScroll: true })
}

const hasData =
    props.kpis.arrived + props.kpis.noShow + props.kpis.notRecorded > 0
</script>

<template>
    <Head title="Statistiken" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Statistiken</h1>

        <div class="flex flex-wrap items-end gap-3 mb-6">
            <label class="text-sm">Von
                <input v-model="from" type="date" class="block border rounded px-3 py-2 text-sm" />
            </label>
            <label class="text-sm">Bis
                <input v-model="to" type="date" class="block border rounded px-3 py-2 text-sm" />
            </label>
            <button type="button" @click="applyPeriod"
                    class="rounded bg-kids-blue px-4 py-2 text-sm font-semibold text-white">Anzeigen</button>
        </div>

        <template v-if="hasData">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <StatCard :icon="CheckCircle2" :value="kpis.arrived" label="Erschienen" color="bg-kids-green" />
                <StatCard :icon="XCircle" :value="kpis.noShow" label="Nicht erschienen" color="bg-rose-100" />
                <StatCard :icon="Percent" :value="fmtRate(kpis.rate)" label="No-Show-Quote" color="bg-kids-blue" />
                <StatCard :icon="HelpCircle" :value="kpis.notRecorded" label="Nicht erfasst" color="bg-slate-100" />
            </div>

            <div v-if="!scoped">
                <h2 class="text-lg font-semibold mb-3">Nach Behandler</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-slate-500 border-b">
                        <tr>
                            <th class="py-2 pr-4">Behandler</th>
                            <th class="py-2 pr-4">Erschienen</th>
                            <th class="py-2 pr-4">Nicht erschienen</th>
                            <th class="py-2 pr-4">No-Show-Quote</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in perPractitioner" :key="row.id" class="border-b">
                            <td class="py-2 pr-4">
                                <span class="inline-block w-2.5 h-2.5 rounded-full mr-2 align-middle"
                                      :style="{ backgroundColor: row.color }"></span>
                                {{ row.name }}
                            </td>
                            <td class="py-2 pr-4">{{ row.arrived }}</td>
                            <td class="py-2 pr-4">{{ row.noShow }}</td>
                            <td class="py-2 pr-4 font-semibold">{{ fmtRate(row.rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="py-12 text-center text-slate-400">Keine Termine im gewählten Zeitraum.</p>
    </div>
</template>
```

- [ ] **Step 2: Add the nav link**

Dans `backend/resources/js/Layouts/TenantLayout.vue` :

Étendre l'import lucide existant (lignes 4-7) en ajoutant `ChartColumn` :

```ts
import {
    LayoutDashboard, CalendarDays, ListChecks, Stethoscope, ClipboardList,
    Clock, TreePalm, Palette, QrCode, ShieldCheck, LogOut, ChartColumn,
} from 'lucide-vue-next'
```

Puis ajouter une entrée dans le tableau `nav`, juste après la ligne `Terminliste` :

```ts
    { href: '/statistiken', label: 'Statistiken', icon: ChartColumn },
```

- [ ] **Step 3: Build the assets**

Run: `cd backend && npm run build`
Expected: build réussi sans erreur.

- [ ] **Step 4: Run the full backend suite (no regression)**

Run: `cd backend && composer test`
Expected: PASS (toute la suite verte, dont les 10 tests de Task 1).

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/Pages/Tenant/Statistics/Index.vue backend/resources/js/Layouts/TenantLayout.vue
git commit -m "feat(statistics): no-show statistics page (KPI cards + per-practitioner table)"
```

---

### Task 3: Vérification visuelle Chrome

**Files:** aucun (vérification).

- [ ] **Step 1: Lancer / confirmer le serveur**

Run: `cd backend && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000` (doit répondre 200 ; sinon `composer dev`).

- [ ] **Step 2: Vérifier dans Chrome (session staff déjà connectée)**

Naviguer vers `http://127.0.0.1:8000/statistiken` et vérifier :
- Les 4 cartes KPI (Erschienen, Nicht erschienen, No-Show-Quote, Nicht erfasst) avec des valeurs cohérentes vs les données seedées + le RDV pointé en Lot A.
- Le tableau « Nach Behandler » (réception/admin) trié par quote décroissante, pastilles couleur.
- Le changement de période Von/Bis recharge les chiffres (via `router.get`).
- L'état vide « Keine Termine im gewählten Zeitraum » pour une période future.
- Le lien nav « Statistiken » actif (highlight) sur cette page.

- [ ] **Step 3: Confirmer (pas de commit)**

Noter le résultat ; aucune modification de code attendue si tout est correct.

---

## Self-Review

**Spec coverage :**
- KPI globaux (arrived/noShow/notRecorded/rate) → Task 1 (controller + tests 1,2,6) ✓
- Taux excluant null + RDV passés + annulés → Task 1 (tests 2,3,4) ✓
- Filtre période Von/Bis défaut 30 j → Task 1 (test 5) + Task 2 (UI) ✓
- Par praticien trié par quote → Task 1 (test 7) + Task 2 (tableau) ✓
- Scope par rôle → Task 1 (test 8) + Task 2 (`v-if="!scoped"`) ✓
- Anti-N+1 (requête agrégée unique) → Task 1 (test 9) ✓
- Période invalide 422 → Task 1 (test 10) ✓
- Page dédiée + nav → Task 2 ✓
- État vide → Task 2 ✓
- Vérif Chrome → Task 3 ✓
- Aucune migration → respecté (lecture `attendance`) ✓

**Placeholder scan :** aucun TODO/TBD ; code complet.

**Type consistency :** `kpis {arrived,noShow,notRecorded,rate}`, `perPractitioner {id,name,color,arrived,noShow,rate}`, `filters {from,to}`, `scoped` — identiques entre le `Inertia::render` (Task 1) et les `defineProps` (Task 2). `rate: number|null` cohérent (PHP `round()|null` ↔ TS `number|null`, rendu « — » via `fmtRate`).
