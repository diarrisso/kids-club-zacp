# Lot B (V1) — Statistiques no-show

**Date :** 2026-06-22
**Statut :** Design validé, prêt pour le plan d'implémentation
**Périmètre :** une seule PR

## Contexte

Kids Club by zacp (réservation mono-cabinet, dentisterie pédiatrique). Le **Lot A**
(déployé en prod le 2026-06-22) a ajouté le **pointage de présence** : colonne
`appointments.attendance` (enum `App\Support\Attendance` : `arrived` / `no_show` / `null`),
posée par le staff via le calendrier et la liste `/termine/liste`.

Ce lot exploite cette donnée pour donner au cabinet une **vue de pilotage** : combien de
patients ne viennent pas, et chez quel praticien. C'est la V1 du Lot B (statistiques) ;
le par-service et l'évolution mensuelle sont explicitement reportés en V2.

## Décisions de design (validées)

- **Page dédiée** `/statistiken` (URL allemande, cohérente avec `/behandler`,
  `/leistungen`, `/sprechzeiten`), route name anglais `tenant.statistics.index`.
- **V1 = KPI globaux + ventilation par praticien.** Par-service et évolution mensuelle
  reportés en V2.
- **Période sélectionnable** Von/Bis, défaut = 30 derniers jours, en `Europe/Berlin`.
  Réutilise le mécanisme date de la Terminliste.
- **No-show calculé sur les RDV passés uniquement** (`starts_at < maintenant`) : un RDV
  futur a `attendance = null` pour une raison normale (pas encore eu lieu) et ne doit pas
  entrer dans le calcul.
- **Scope par rôle, fail-closed** : un médecin est **toujours** scopé à son
  `practitioner_id` ; un médecin **non lié** (`practitioner_id` null) ne voit **rien**
  (jamais tout le cabinet — données de santé). Réception/admin (non-médecin) voient tout
  le cabinet, avec le détail par praticien. Pattern `isMedecin()` + `practitioner_id`.
  > Décision 2026-06-23 (revue CodeRabbit) : on durcit ici en fail-closed. Le
  > `DashboardController` reste en fail-open (`when($practitionerId, …)`) — suivi à
  > prévoir pour l'aligner (et envisager une contrainte sur `practitioner_id`).
- **Aucune migration** : on lit la colonne `attendance` existante.

## Architecture

### 1. Calcul & métriques

**Population de base (dénominateur)** : RDV avec
- `status != 'cancelled'`,
- `starts_at < now()` (passés),
- `starts_at` dans la période `[from 00:00, to 23:59]` Berlin,
- `+ when(scoped, where practitioner_id = …)` pour un médecin.

**KPI globaux :**
- **Erschienen** = count `attendance = 'arrived'`
- **Nicht erschienen** = count `attendance = 'no_show'`
- **Nicht erfasst** = count `attendance IS NULL` (RDV passés non pointés — indicateur de
  qualité de saisie, affiché à part, ton neutre)
- **No-Show-Quote** = `no_show / (arrived + no_show)`, en %. **Les `null` sont exclus du
  dénominateur.** Si `arrived + no_show = 0` → taux `null`, rendu « — ».

**Par praticien :** même ventilation (erschienen / nicht erschienen / quote) pour chaque
Behandler ayant au moins un RDV dans la population. Trié par No-Show-Quote décroissante.

> **Pourquoi exclure les `null` du dénominateur :** les compter fausserait le taux (un
> cabinet qui oublie de pointer la moitié de ses RDV aurait un taux artificiellement
> écrasé ou gonflé). Le taux ne reflète que les RDV réellement constatés ; le compteur
> « Nicht erfasst » séparé incite à pointer.

**Efficacité (anti-N+1) :** une **seule requête agrégée**
`selectRaw('practitioner_id, attendance, COUNT(*) as total')->groupBy('practitioner_id','attendance')`,
puis assemblage en PHP. Les noms/couleurs des praticiens sont chargés en une requête
séparée (pas par praticien). `selectRaw` ne contient **aucune** saisie utilisateur (noms
de colonnes en dur) ; la seule entrée (`from`/`to`) passe par bindings `whereBetween`.

### 2. Backend

**Route** (groupe `['auth', 'two-factor.enrolled']`) :
```
GET /statistiken → StatisticsController@index (tenant.statistics.index)
```

**`App\Http\Requests\Tenant\StatisticsRequest`** :
```php
'from' => ['nullable', 'date'],
'to'   => ['nullable', 'date'],
```
Période invalide → 422.

**`App\Http\Controllers\Tenant\StatisticsController::index(StatisticsRequest $request)`** :
- Résout `from`/`to` (défaut : `now-30j` → `now`, Berlin ; bornés startOfDay/endOfDay).
- `$practitionerId = $user->isMedecin() ? $user->practitioner_id : null`.
- Requête de base (status, passés, période, scope) → requête agrégée groupée.
- Assemble KPI globaux + tableau par praticien (joint aux Practitioner chargés en une
  requête). Taux `null` si dénominateur 0.
- Médecin scopé : `perPractitioner` réduit à sa ligne (la page la masque, cf §3).
- Rend `Inertia::render('Tenant/Statistics/Index', [
    'kpis' => ['arrived'=>…, 'noShow'=>…, 'notRecorded'=>…, 'rate'=>float|null],
    'perPractitioner' => [['id','name','color','arrived','noShow','rate'], …],
    'filters' => ['from'=>'Y-m-d', 'to'=>'Y-m-d'],
    'scoped' => bool,
  ])`.

### 3. Frontend — `Tenant/Statistics/Index.vue`

- **Barre période** : deux `<input type="date">` Von/Bis + bouton « Anzeigen », via
  `router.get('/statistiken', {from, to}, {preserveState, replace, preserveScroll})`.
  Pré-rempli au défaut 30 jours. Bornage Inertia (pas d'axios).
- **Cartes KPI** (composant `StatCard` existant) : Erschienen, Nicht erschienen,
  No-Show-Quote (« — » si null), Nicht erfasst (ton neutre).
- **Tableau par praticien** : affiché pour réception/admin uniquement (`v-if="!scoped"`) ;
  pastille couleur praticien, colonnes Erschienen / Nicht erschienen / No-Show-Quote, trié
  par quote décroissante.
- **État vide** : 0 RDV passé dans la période → « Keine Termine im gewählten Zeitraum ».
- **Navigation** : lien « Statistiken » dans `TenantLayout.vue` (icône lucide
  `ChartColumn` ou `TrendingUp`), ajouté à l'import lucide existant (pas de second import).

## Gestion des erreurs

- Période invalide → 422 (Form Request).
- Division par zéro → taux `null` → rendu « — ».
- Aucune donnée → état vide propre.
- RDV futurs / annulés → exclus par construction.

## Tests (Pest — obligatoires)

Backend :
1. Taux correct : 8 arrived + 2 no_show → 20 %.
2. `null` exclus du dénominateur : 8 arrived + 2 no_show + 5 null → taux 20 %, notRecorded 5.
3. RDV futurs exclus (un RDV demain `null` ne compte nulle part).
4. RDV annulés exclus.
5. Filtre période Von/Bis borne la population.
6. Taux `null` quand dénominateur 0.
7. Ventilation par praticien correcte (2 praticiens).
8. Scope par rôle : médecin lié → seulement ses chiffres ; réception/admin → tout.
9. Anti-N+1 : query-count constant quel que soit le nombre de praticiens/RDV.
10. Période invalide → 422.

Frontend (Vitest, si pertinent) :
11. Rendu du taux (« 14,3 % » vs « — »).

## Hors périmètre (V2 / lots futurs)

- Statistiques **par service** (Prophylaxe vs Notfall…).
- **Évolution mensuelle** (tendance no-show dans le temps).
- Taux de **remplissage**, export CSV/ICS (reste du Lot B).
- Re-planification, liste d'attente, SMS (Lot C) ; GDT/EDV (Lot D).

## Données de référence

Pas de doc de référence à mettre à jour (le projet n'a ni `database-diagram.html`, ni
`wireframe.html`, ni `ProjectProgressService`). Aucune migration (lecture de `attendance`).
