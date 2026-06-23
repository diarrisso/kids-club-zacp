# Export CSV des statistiques no-show

**Date :** 2026-06-23
**Statut :** Design validé, prêt pour le plan d'implémentation
**Périmètre :** une seule PR

## Contexte

Le **Lot B V1** (déployé en prod le 2026-06-23, PR #38) a ajouté la page `/statistiken` :
KPI globaux de no-show (Erschienen / Nicht erschienen / No-Show-Quote / Nicht erfasst) +
ventilation par praticien, sur une période Von/Bis sélectionnable, scope par rôle
(fail-closed : un médecin ne voit que ses propres chiffres).

Cette feature ajoute un **export CSV** de ces mêmes statistiques, pour que le cabinet
puisse les ouvrir/archiver dans un tableur. Aucun nouveau calcul métier : on réutilise
exactement la logique d'agrégation existante.

## Décisions de design (validées)

- **Contenu = ventilation par praticien + ligne `Gesamt`** (totaux cabinet). C'est ce que
  montre la page à l'écran. Pas d'export de la liste brute des RDV (reporté / hors scope).
- **Format CSV standard international** : séparateur virgule `,`, encodage **UTF-8 sans
  BOM**, **point décimal** (`40.0`). Taux exporté comme nombre (cellule vide si `null`).
- **Déclenchement** : bouton « CSV exportieren » sur la page, lien HTML classique
  (téléchargement de fichier, pas une visite Inertia) réutilisant la période courante.
- **Scope par rôle identique à la page** (fail-closed) : un médecin n'exporte que ses
  propres chiffres ; réception/admin exportent tout le cabinet.
- **Aucune migration.**

## Architecture

### 1. Endpoint

Dans le groupe `['auth', 'two-factor.enrolled']` :
```
GET /statistiken/export → StatisticsController@export (tenant.statistics.export)
```
Réutilise le **même `StatisticsRequest`** (validation `from`/`to` nullable date +
`from <= to` via `withValidator` ; période invalide → 422).

### 2. Anti-duplication

La logique d'agrégation actuellement dans `StatisticsController::index()` (résolution
période + borne `min(to, now)` + scope rôle fail-closed + requête groupée
`selectRaw`+`groupBy`+`toBase` + assemblage `kpis`/`perPractitioner` + résolution des
noms/couleurs via un `whereIn`) est **extraite dans une méthode privée partagée**
`computeStats(StatisticsRequest $request): array` qui retourne
`['kpis' => …, 'perPractitioner' => …, 'filters' => …, 'scoped' => …]`.

- `index()` appelle `computeStats()` et passe le résultat à `Inertia::render`.
- `export()` appelle `computeStats()` et génère le CSV.

Bénéfice : une seule source de vérité pour la règle métier (taux, exclusions, scope) ;
l'export ne peut pas diverger de l'affichage. Anti-N+1 préservé (même requête unique).

### 3. Génération du CSV

`export()` retourne `response()->streamDownload(...)` :
- En-têtes HTTP : `Content-Type: text/csv` + `Content-Disposition: attachment; filename="…"`.
- Nom de fichier : `noshow-statistik_<from>_<to>.csv` (dates `Y-m-d` issues de `filters`).
- Écriture via **`fputcsv`** sur `php://output` (échappement natif → un nom de praticien
  contenant `,` ou `"` ne casse pas les colonnes et n'injecte rien).
- Lignes :
  - En-tête : `Behandler,Erschienen,Nicht erschienen,Nicht erfasst,No-Show-Quote (%)`
  - Une ligne par praticien (ordre = `perPractitioner`, déjà trié par taux décroissant) :
    `name, arrived, noShow, <cellule vide>, rate` (le `notRecorded` par praticien n'existe
    pas dans la structure V1 ; la colonne « Nicht erfasst » par praticien reste **vide**
    (chaîne vide, pas de tiret), seul le total global est connu — voir note).
  - Ligne finale `Gesamt` : `Gesamt, kpis.arrived, kpis.noShow, kpis.notRecorded, kpis.rate`.
- Taux : nombre brut (`40.0`) ou **chaîne vide** si `null`. Point décimal (locale C / format
  PHP par défaut, pas de `number_format` localisé).

> **Note sur « Nicht erfasst » par praticien :** la structure `perPractitioner` de la V1 ne
> porte pas le compte `notRecorded` par praticien (cf. backlog Lot B). La colonne existe
> dans l'en-tête pour aligner avec la ligne `Gesamt`, mais les cellules par praticien sont
> vides. Si on veut le détail par praticien plus tard, il faudra l'ajouter à `computeStats`
> (et à la page). Décision V1 : colonne présente, vide par praticien, renseignée sur `Gesamt`.

### 4. Frontend

Sur `Tenant/Statistics/Index.vue`, à côté du bouton « Anzeigen » :
```html
<a :href="exportUrl" class="…(même style bouton secondaire)…">CSV exportieren</a>
```
avec `exportUrl` calculé (`computed`) à partir des `from`/`to` courants :
`/statistiken/export?from=<from>&to=<to>`. Lien natif → le navigateur télécharge le fichier.
Le bouton est visible pour tous (médecin inclus) ; le contenu respecte le scope serveur.

## Gestion des erreurs

- Période invalide → 422 (réutilise `StatisticsRequest`).
- Période sans donnée → CSV avec en-tête + ligne `Gesamt` à 0 (pas d'erreur).
- Médecin non lié → aucune ligne praticien, `Gesamt` à 0 (fail-closed).

## Tests (Pest — obligatoires)

1. `export()` renvoie `200`, `Content-Type: text/csv`, `Content-Disposition: attachment`
   avec le nom `noshow-statistik_<from>_<to>.csv`.
2. Le corps contient l'en-tête attendu + une ligne par praticien (triées par taux décroissant)
   + une ligne `Gesamt` avec les bons totaux, sur une période donnée.
3. Scope rôle : un médecin lié n'a que sa ligne ; réception/admin a toutes les lignes.
4. Médecin **non lié** → aucune ligne praticien (fail-closed).
5. Période invalide (`from=not-a-date` ou `from > to`) → 422.
6. Anti-régression : `index()` rend toujours les mêmes props après extraction de
   `computeStats` (les tests existants de `StatisticsTest` doivent rester verts).

## Hors périmètre

- Export de la **liste brute des RDV** (objet distinct, proche d'un export Terminliste).
- Export Excel natif (.xlsx), PDF, ICS.
- Format Excel-allemand (`;` + BOM + virgule décimale) — explicitement écarté au profit du
  CSV standard international.

## Données de référence

Aucune migration. Pas de `database-diagram.html` / `wireframe.html` /
`ProjectProgressService` dans ce projet (sans objet).
