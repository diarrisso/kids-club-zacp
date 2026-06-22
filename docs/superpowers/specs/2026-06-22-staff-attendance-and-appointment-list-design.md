# Lot A — Pointage de présence + Liste des rendez-vous (staff)

**Date :** 2026-06-22
**Statut :** Design validé, prêt pour le plan d'implémentation
**Périmètre :** une seule PR

## Contexte

Kids Club by zacp est une application de réservation mono-cabinet (dentisterie
pédiatrique). Le système couvre déjà : widget public, calendrier staff (`/termine`),
CRUD praticiens/services/disponibilités/absences, 4 mails transactionnels, 2FA,
anti-abus, cache, index DB.

Ce lot ajoute deux fonctionnalités de **gestion quotidienne côté cabinet** :

1. **Pointage de présence** — le staff marque chaque RDV comme « venu » (`arrived`)
   ou « absent » (`no_show`). C'est le socle des statistiques de no-show (Lot B futur).
2. **Liste des rendez-vous** — une page paginée avec recherche par nom (enfant/parent)
   et filtres, complémentaire du calendrier visuel (qui ne couvre que la fenêtre
   temporelle affichée).

> **Note :** la fonctionnalité « notes internes » initialement prévue dans ce lot est
> **déjà implémentée** (colonne `notes_internal`, backend, et textarea dans
> `AppointmentForm.vue`). Elle est donc hors périmètre.

## Décisions de design (validées)

- **Présence = colonne séparée**, pas une extension de `status`. Un RDV peut être
  `status=confirmed` ET `attendance=no_show`. Le moteur de créneaux, les mails et les
  throttles se basent tous sur `status` — on ne le touche pas.
- **Recherche dans une page liste dédiée** (`/termine/liste`), pas dans le calendrier
  FullCalendar (limité à la fenêtre visible). Cette page accueillera l'export CSV (Lot B).
- **Pointage accessible à deux endroits** : modal du calendrier (geste ponctuel) et
  page liste (pointage groupé en fin de journée). Les deux appellent le même `PATCH`.

## Architecture

### 1. Modèle de données

Migration unique :

```sql
ALTER TABLE appointments ADD COLUMN attendance VARCHAR NULL;
-- null = pas encore pointé · 'arrived' = venu · 'no_show' = absent
```

- Pas de contrainte CHECK SQL : validation côté Form Request (`Rule::enum`), cohérent
  avec la convention du projet (statuts validés en PHP, pas en DB).
- Nouvel enum PHP `App\Support\Attendance` (string-backed), **calqué sur `App\Support\Room`**
  (enum déjà casté sur le modèle `Appointment`) : type-safe, libellés DE centralisés,
  méthode `options()` exposée aux pages Inertia.

```php
enum Attendance: string
{
    case Arrived = 'arrived';
    case NoShow  = 'no_show';

    public function label(): string { /* 'Erschienen' | 'Nicht erschienen' */ }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array { /* ... */ }
}
```

- Casté sur le modèle : `'attendance' => Attendance::class` dans `$casts`.
- **`attendance` n'est PAS dans `$fillable`** — posé par assignation directe dans le
  contrôleur staff, exactement comme `notes_internal`. Le widget public ne peut jamais
  l'écrire (protection mass-assignment).

### 2. Backend — API & contrôleur

**a) Pointage présence — réutilise `PATCH /termine/{appointment}`**

- `UpdateAppointmentRequest` (Form Request staff) : règle
  `'attendance' => ['nullable', Rule::enum(Attendance::class)]`.
- `AppointmentController::update` : traite `attendance` par assignation directe
  (jamais via `$fillable`), au même endroit que `notes_internal`.
- Le DTO retourné (`toClinicIso` / mapping calendrier + liste) inclut `attendance`.
- Pas de nouvelle route : un RDV « pointé » est un RDV mis à jour.

**b) Liste — nouvelle action `AppointmentController::list`**

```
GET /termine/liste?q=...&from=...&to=...&attendance=...&page=...
```

- `q` : recherche sur `patient_first_name`, `patient_last_name`, `parent_last_name`,
  insensible à la casse (PostgreSQL `ILIKE`).
- Filtres optionnels : période (`from`/`to`), présence (`attendance`).
- `paginate(25)`, tri `starts_at` décroissant (RDV récents en haut).
- Eager-load `practitioner` + `service` (anti N+1, contrainte stricte du projet).
- Rend la page Inertia `Tenant/Appointments/List.vue`.
- Route protégée par le même groupe `['auth', 'two-factor.enrolled']` que le reste de
  `/termine`. Nom de route : `tenant.appointments.list`.

### 3. Sécurité — Injection SQL (exigence non négociable)

La recherche `q` est la seule entrée utilisateur libre. Règles **obligatoires** :

1. **Toujours des bindings, jamais `whereRaw`/`DB::raw`** avec saisie utilisateur :
   ```php
   // ✅ valeur = binding lié ($1), jamais interprétée comme SQL
   ->where('patient_last_name', 'ILIKE', '%'.$escaped.'%')
   ```
   Même une saisie `'; DROP TABLE appointments; --` est traitée comme littéral cherché.
2. **Échapper les wildcards LIKE** dans `q` avant de construire le motif :
   `addcslashes($q, '%_\\')` — sinon un `%` saisi élargit involontairement la recherche
   (comportement incorrect, pas faille).
3. **Validation Form Request** : `q` → `['nullable', 'string', 'max:100']` ; `attendance`
   → `Rule::enum` ; `from`/`to` → `['nullable', 'date']`.

### 4. Frontend — UI

**a) Badges de présence sur le calendrier**
- Indicateur visuel par événement selon `attendance` : `null` → rien ; `arrived` →
  pastille verte ✓ ; `no_show` → pastille rouge/grisée + événement atténué.
- Injecté via `eventContent` / classe CSS, sans casser le code couleur existant
  (bordure = couleur praticien, fond = couleur salle).

**b) Modal RDV — boutons de pointage** (`AppointmentForm.vue`, mode édition uniquement)
- Ligne « Anwesenheit » : `[ ✓ Erschienen ] [ ✗ Nicht erschienen ]`.
- Toggle : recliquer l'état actif le remet à `null` (correction d'erreur de saisie).
- Envoie `PATCH {attendance: ...}` puis rafraîchit le calendrier.
- Masqués en mode création (un RDV à créer n'a pas encore eu lieu).

**c) Page liste `/termine/liste`** (`Tenant/Appointments/List.vue`)
- Barre de recherche + filtres période + filtre présence.
- Tableau : Datum/Zeit · Kind · Behandler · Leistung · boutons ✓/✗.
- Boutons rapides ✓/✗ par ligne (pointage groupé).
- Clic sur une ligne → ouvre le même modal RDV que le calendrier.

**d) Navigation**
- Lien « Liste » ajouté dans `TenantLayout.vue`, groupé avec les RDV.

## Gestion des erreurs

- **Pointage en échec réseau** : rollback optimiste de l'UI + message discret. Pas de
  perte silencieuse.
- **`attendance` invalide** : rejeté par `Rule::enum` → `422`, jamais stocké.
- **Recherche vide** : `q` absent/vide → liste complète paginée.
- **Page hors limite** : Laravel renvoie une page vide proprement.

## Tests (Pest — obligatoires avant merge)

Backend :
1. `attendance` se met à jour via `PATCH /termine/{id}` (arrived, no_show, retour à null).
2. **Sécurité mass-assignment** : `attendance` n'est pas écrivable depuis l'API widget
   publique.
3. Valeur `attendance` invalide → `422`.
4. Liste : recherche par nom enfant/parent retourne les bons résultats.
5. **Sécurité injection SQL** : `q = "'; DROP TABLE appointments; --"` ne casse rien,
   table intacte, résultats cohérents.
6. Liste : filtres période + attendance.
7. Liste : pagination.
8. Liste : N+1 — query-count constant quel que soit le nombre de RDV (eager-load).

Frontend (Vitest, si pertinent) :
9. Badge de présence rendu selon `attendance`.

## Hors périmètre (lots futurs)

- Statistiques no-show / taux de remplissage (Lot B).
- Export CSV / ICS (Lot B).
- Re-planification, liste d'attente, SMS (Lot C).
- GDT/EDV (Lot D, en attente réponse cabinet).

## Données de référence à mettre à jour (post-implémentation)

- Diagramme BDD : colonne `attendance` sur `appointments`.
- Wireframe : page `/termine/liste`.
- `ProjectProgressService` : module gestion RDV (items + checks).
