# Warteliste (Liste d'attente) — V1

**Date :** 2026-06-23
**Statut :** Design validé, prêt pour le plan d'implémentation
**Périmètre :** une seule PR
**Lot :** C — sous-projet 2 (notification re-planif = sous-projet 1, SMS = sous-projet 3)

## Contexte

Kids Club by zacp (dentisterie pédiatrique, mono-cabinet). Quand tous les créneaux de l'horizon 60 jours sont pris, un parent qui cherche via le widget n'a aucune issue. Cette feature ajoute une **liste d'attente** : le parent laisse ses coordonnées + service souhaité, le cabinet reçoit un e-mail et consulte la liste dans l'admin pour le rappeler dès qu'un créneau se libère.

V1 = inscription manuelle + gestion admin. La proposition automatique (annulation → offre au 1er en attente) est reportée en V2.

## Décisions de design (validées)

- **Qui s'inscrit :** le parent via le widget public (pas le staff).
- **Déclencheur widget :** le bouton « Auf die Warteliste » apparaît dans `TerminStep.vue` quand `visibleSlots.length === 0` (aucun créneau visible au filtre courant). C'est une **nouvelle étape `waitlist`** ajoutée au wizard (après `termin`, avant rien — elle n'est pas dans le flux normal `termin→kind→form→confirm→success`).
- **Formulaire :** prénom/nom enfant, prénom/nom parent, téléphone (obligatoire), e-mail (optionnel), service souhaité (select des services actifs — défaut = service déjà sélectionné dans le wizard), notes (optionnel).
- **Notification cabinet :** e-mail via `CabinetNotifier` pattern (queued + rescue) → sujet « Neue Warteliste-Anfrage ».
- **Page admin `/warteliste`** : tableau paginé des entrées, filtre par statut, changement de statut en ligne.
- **Badge nav :** compteur d'entrées `pending` sur le lien « Warteliste » dans `TenantLayout.vue`.
- **Aucune migration inverse** (nouvelle table uniquement).

## Architecture

### 1. Base de données — `waitlist_entries`

```sql
id                   uuid PK
patient_first_name   string
patient_last_name    string
parent_first_name    string
parent_last_name     string
parent_phone         string
parent_email         string nullable
service_id           FK → services(id) ON DELETE SET NULL nullable
notes                text nullable
status               enum('pending','contacted','booked','cancelled') default 'pending'
created_at, updated_at
```

- `service_id` nullable (SET NULL si le service est supprimé) — le nom du service est dénormalisé dans l'e-mail cabinet au moment de l'inscription.
- Pas de `practitioner_id` (aucune préférence demandée — V1).
- Pas de `cancellation_token` (la liste d'attente n'a pas de flux d'annulation parent en V1).

Modèle : `App\Models\WaitlistEntry`, cast `status` → enum `App\Support\WaitlistStatus`.

### 2. Enum — `App\Support\WaitlistStatus`

```php
enum WaitlistStatus: string {
    case Pending    = 'pending';
    case Contacted  = 'contacted';
    case Booked     = 'booked';
    case Cancelled  = 'cancelled';

    public function label(): string { /* Ausstehend / Kontaktiert / Gebucht / Storniert */ }
}
```

### 3. API widget — `POST /api/v1/widget/warteliste`

- Groupe `throttle:widget-book` (5/min par IP + 30/min global — limiteurs existants).
- **`App\Http\Requests\Widget\StoreWaitlistRequest`** :
  ```
  patient_first_name: required string max:255
  patient_last_name:  required string max:255
  parent_first_name:  required string max:255
  parent_last_name:   required string max:255
  parent_phone:       required string max:255
  parent_email:       nullable email max:255
  service_id:         nullable integer exists:services,id
  notes:              nullable string max:1000
  ```
- **`App\Http\Controllers\Widget\WaitlistController@store`** :
  1. Valide → `WaitlistEntry::create(...)`.
  2. Post-commit : `rescue(fn () => CabinetNotifier::notifyWaitlist($entry))`.
  3. Retourne `201 + { message: 'Auf der Warteliste eingetragen.' }`.

- **`CabinetNotifier::notifyWaitlist(WaitlistEntry $entry)`** (nouvelle méthode statique) :
  ```php
  rescue(fn () => Mail::to(self::recipients())->queue(
      new WaitlistEntryMail($entry, config('app.name'))
  ));
  ```

- **`App\Mail\WaitlistEntryMail`** : sujet « Neue Warteliste-Anfrage bei {cabinet} », vue markdown `emails/waitlist-entry.blade.php` — nom enfant, contact parent, service souhaité (nom ou « Kein Präferenz »), notes.

### 4. Widget frontend — nouvelle étape `waitlist`

**`useWizard.ts`** : ajouter `'waitlist'` comme étape latérale (pas dans `ORDER` du flux normal — pas de `next()`/`prev()` dans le flux standard ; on y va via `go('waitlist')` depuis `TerminStep`).

Type : `export type Step = 'termin' | 'kind' | 'form' | 'confirm' | 'success' | 'waitlist'`

**`TerminStep.vue`** : quand `visibleSlots.length === 0` (et qu'il ne s'agit pas juste d'un chargement), afficher un bouton :
```html
<button @click="$emit('waitlist')">Auf die Warteliste →</button>
```
L'event `waitlist` est capturé par `App.vue` → `w.go('waitlist')`.

**`resources/js/widget/steps/WaitlistStep.vue`** (nouveau) :
- Formulaire : prénom/nom enfant, prénom/nom parent, téléphone, e-mail optionnel, service (select pré-rempli si déjà sélectionné dans `w.selection.service`), notes.
- Soumission via `POST /api/v1/widget/warteliste` (axios).
- Sur succès : écran de confirmation inline « ✓ Wir haben Ihre Anfrage erhalten und melden uns baldmöglichst. ».
- Erreurs 422 : affichées sous les champs.
- Bouton retour « ← Zurück » → `w.go('termin')`.

**`App.vue`** : ajouter le case `waitlist` dans le `v-if`/`v-else` des étapes, écouter `@waitlist="w.go('waitlist')"` sur `TerminStep`.

### 5. Admin staff — `/warteliste`

**Route** (groupe auth+2FA) :
```
GET  /warteliste          → WaitlistController@index   (tenant.waitlist.index)
PATCH /warteliste/{entry} → WaitlistController@update  (tenant.waitlist.update)
```

**`App\Http\Requests\Tenant\UpdateWaitlistRequest`** : `status` required, enum `WaitlistStatus`.

**`App\Http\Controllers\Tenant\WaitlistController`** :
- `index()` : paginate(25), filtre `?status=pending` (défaut), eager-load `service`, render Inertia `Tenant/Waitlist/Index`.
- `update()` : valide → `$entry->update(['status' => $data['status']])` → JSON `200`.

**`resources/js/Pages/Tenant/Waitlist/Index.vue`** :
- Barre de filtre statut (Ausstehend / Kontaktiert / Gebucht / Storniert / Alle).
- Tableau : date, enfant, parent, téléphone, e-mail, service souhaité, notes, statut (select inline pour changer), pagination.
- Lien nav « Warteliste » dans `TenantLayout.vue` (icône `ClipboardList` — déjà importée pour la Terminliste) avec badge `(N)` si `pendingCount > 0`.
- `pendingCount` passé comme prop Inertia partagée dans `HandleInertiaRequests::share()` :
  `'waitlist_pending_count' => fn () => \App\Models\WaitlistEntry::where('status','pending')->count()`
  (requête légère sur chaque page staff — le badge est affiché dans `TenantLayout` via `(page.props as any).waitlist_pending_count`).

### 6. Gestion des erreurs

- `service_id` inexistant → 422 (validation `exists:`).
- `status` invalide → 422.
- Échec e-mail cabinet → absorbé par `rescue()` ; l'inscription est persistée.
- Pas de `parent_email` → saut de l'envoi de confirmation parent (V1 : pas d'e-mail parent à l'inscription — le cabinet rappelle par téléphone).
- Pagination : aucune entrée → état vide propre « Keine Einträge ».

### 7. Tests (Pest)

**Widget API :**
1. POST valide → 201 + entrée en DB.
2. POST sans téléphone → 422.
3. POST service_id invalide → 422.
4. POST rate-limit : throttle `widget-book`.
5. Notification cabinet envoyée (`Mail::fake()`, `assertQueued(WaitlistEntryMail::class)`).
6. Pas de notification si aucun `PRACTICE_NOTIFICATION_EMAIL` configuré.

**Admin :**
7. `GET /warteliste` → 200, Inertia `Tenant/Waitlist/Index`, filtre défaut `pending`.
8. `PATCH /warteliste/{id}` statut valide → 200 + statut mis à jour.
9. `PATCH /warteliste/{id}` statut invalide → 422.
10. Scope fail-closed : médecin lié voit la liste (waitlist n'est pas scopée par praticien — c'est une vue cabinet globale).

## Hors périmètre (V2)

- Offre automatique quand un créneau se libère (annulation/no-show → e-mail au 1er en liste).
- Expiration automatique des vieilles demandes.
- Suppression par le parent (lien dans un e-mail de confirmation d'inscription).
- Filtrage par service dans l'admin.
- Export CSV de la liste d'attente.

## Données de référence

Aucun `database-diagram.html` / `wireframe.html` / `ProjectProgressService` (sans objet dans ce projet).
