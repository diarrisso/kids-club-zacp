# Flow de réservation « date-first » style Doctolib — Design

> Spec — 2026-06-04. Widget public de prise de rendez-vous (Kids Club by zacp).

## Objectif

Refondre le parcours de réservation du **widget public** en un flow
**date-first, user-friendly, calqué sur Doctolib** : le parent choisit le soin,
puis voit un **calendrier** des disponibilités, clique un jour, et voit **tous
les créneaux de ce jour tous médecins confondus** — chaque créneau étiqueté avec
son médecin — avec un **filtre par médecin**. Tout ce qui définit l'offre reste
**configurable par le cabinet**.

## Contexte & contraintes (état actuel du code)

- API widget publique, anonyme, throttlée par IP (`routes/api.php`,
  préfixe `/api/v1/widget`). Lectures = `throttle:widget-read` (20/min).
- Le moteur `App\Services\Tenant\AvailabilityCalculator` calcule les créneaux
  **par praticien + service** (`forPractitionerService`), aligné sur la durée du
  service, fuseau `Europe/Berlin`, délai mini 120 min, horizon 60 j. `isBookable()`
  re-valide un créneau précis côté serveur.
- `Slot` (`App\Services\Tenant\Slot`) est un DTO `readonly` ne portant que
  `starts_at` / `ends_at`. **On ne le modifie pas** : l'enrichissement praticien
  se fait dans le contrôleur.
- Pivot **`practitioners() belongsToMany`** sur `Service` = « quel médecin offre
  quel soin » (déjà piloté par le CRUD staff). `ServiceController::practitioners`
  l'expose déjà.
- Réservation : `POST /widget/appointments` (`StoreAppointmentRequest`) exige
  `practitioner_id` + `service_id` + `starts_at`. Lock pessimiste sur la ligne
  praticien, 409 si conflit, honeypot `website`, consent. **Contrat inchangé.**
- `Setting` (`App\Models\Setting`) = store clé/valeur caché (`get`/`put`).

### Dépendance de séquencement (à lire avant l'implémentation)

La **PR #20 (widget redesign)** est ouverte et retravaille `App.vue` + tous les
`steps/*.vue` (style pastel KidsClub : header, barre de progression, cartes
d'options, avatars colorés, pills de créneaux). **Ce flow restructure les mêmes
fichiers.** Recommandation : **merger la PR #20 d'abord**, puis implémenter ce
flow par-dessus, afin de **préserver le style visuel** et d'éviter un conflit de
merge. La présente spec décrit la **logique de flow + backend + composant
calendrier** ; elle réutilise et conserve le style existant des steps.

## Architecture cible

### Parcours (4 étapes au lieu de 5)

```text
[ ● Leistung ]──[ ○ Termin ]──[ ○ Ihre Daten ]──[ ○ Fertig ]
```

L'étape **praticien autonome est supprimée** (le médecin est choisi implicitement
en cliquant un créneau). **Date + créneaux fusionnent** dans l'étape « Termin ».

### Étape « Termin » (cœur du flow)

1. **Calendrier mensuel compact** : nav mois précédent/suivant. Les jours ayant
   ≥1 créneau libre (tous médecins confondus, pour le service choisi) sont
   **cliquables et mis en avant** ; les autres sont **grisés/désactivés**. Les
   jours hors horizon (passé, > horizon) sont désactivés.
2. **Clic sur un jour** → chargement des créneaux de ce jour → **liste des
   créneaux** sous le calendrier, chaque créneau affichant **heure + médecin**
   (avatar coloré + nom abrégé).
3. **Filtre médecin** : chips au-dessus de la liste — `Alle Behandler` (défaut)
   + un chip par médecin ayant des créneaux **ce jour-là**. Filtrage **côté
   client** sur les créneaux déjà chargés (aucun appel réseau au changement de
   filtre). Si un seul médecin offre le service → **pas de chips** (liste seule).
4. **Clic sur un créneau** = sélection **(date+heure + médecin)** → passe à
   « Ihre Daten ».

## Backend

### Endpoint 1 — Jours disponibles (nouveau)

`GET /api/v1/widget/availability/days?service_id&from&to`

- Validé : `service_id` (required, exists:services,id), `from` (required, date),
  `to` (required, date, after_or_equal:from). On **clampe** `to` à `from + horizon`
  pour borner le coût.
- Calcule, pour les **médecins actifs offrant le service** (pivot), l'ensemble des
  **dates distinctes** (YYYY-MM-DD, fuseau clinique) ayant ≥1 créneau libre dans
  `[from, to]`.
- Réponse : `["2026-06-05", "2026-06-06", ...]` (tableau de dates triées).
- Throttle : `widget-read`.
- Implémentation : nouvelle méthode `AvailabilityCalculator::availableDates(
  Service $service, CarbonImmutable $from, CarbonImmutable $to): Collection`
  qui boucle sur `$service->practitioners()->active()->get()`, réutilise la
  logique jour-par-jour existante, et **court-circuite par (médecin, jour) dès le
  premier créneau trouvé** (pas besoin d'énumérer tous les créneaux pour savoir
  qu'un jour est dispo). Retourne les dates distinctes.

### Endpoint 2 — Créneaux d'un jour, multi-médecins (extension de l'existant)

`GET /api/v1/widget/slots?service_id&from&to[&practitioner_id]`

- `practitioner_id` devient **optionnel**. Présent → ce médecin seul (compat
  ascendante). Absent → **fusion de tous les médecins actifs offrant le service**.
- Chaque créneau de la réponse est **enrichi de son médecin** :
  ```json
  [
    { "starts_at": "2026-06-05T09:00:00+02:00", "ends_at": "...",
      "practitioner": { "id": 3, "first_name": "Anna", "last_name": "Müller",
                        "title": "Dr.", "color": "#98ACBA" } },
    ...
  ]
  ```
- Tri par `starts_at` croissant (puis par nom de médecin pour un ordre stable).
- Throttle : `widget-read`.
- Implémentation : le **contrôleur** boucle sur les médecins (1 si
  `practitioner_id`, sinon tous via le pivot), appelle `forPractitionerService`,
  et mappe chaque `Slot` en `slot.toArray() + ['practitioner' => [...]]`. La
  classe `Slot` n'est pas modifiée.

### Lead time & horizon configurables par le cabinet

- `AvailabilityCalculator` lit `Setting::get('booking.lead_minutes',
  (string) self::LEAD_MINUTES)` et `Setting::get('booking.horizon_days',
  (string) self::HORIZON_DAYS)`, castés en `int`, via deux helpers privés
  (`leadMinutes()`, `horizonDays()`) utilisés **partout** où les constantes
  l'étaient (`forPractitionerService`, `isBookable`, `availableDates`).
- Les **constantes restent les valeurs par défaut** → aucune row Setting =
  comportement identique à aujourd'hui → **aucune régression de tests**.
- **Hors scope de cette spec** : l'écran de réglages staff pour éditer ces deux
  valeurs (configurable au niveau données dès maintenant ; UI staff = follow-up).

### Configurabilité cabinet — récapitulatif

| Levier | Mécanisme | État |
|---|---|---|
| Quel médecin offre quel soin | pivot `practitioner_service` | déjà CRUD staff |
| Horaires d'ouverture | `availabilities` (Sprechzeiten) | déjà CRUD staff |
| Absences | `availability_exceptions` (Abwesenheiten) | déjà CRUD staff |
| Délai mini / horizon | `Setting` (data-layer) | **ajouté ici** (UI = follow-up) |

## Frontend (widget)

### `useWizard.ts`

- `Step = 'service' | 'termin' | 'form' | 'success'`,
  `ORDER = ['service','termin','form','success']`.
- `chooseService(s)` → `'termin'`. `chooseSlot(slot)` (slot porte le praticien)
  → `'form'`. `back()` linéaire inchangé. **Suppression** de `choosePractitioner`.
- `selection` : `{ service?, slot? }` (le praticien vit désormais dans
  `slot.practitioner`).

### `types.ts`

- `Slot` gagne `practitioner: { id: number; first_name: string; last_name: string;
  title?: string; color?: string }`.
- `BookingPayload.practitioner_id` inchangé (alimenté par `slot.practitioner.id`).

### `api.ts`

- `slots(serviceId, from, to, practitionerId?)` → `practitioner_id` ajouté à la
  query **seulement s'il est fourni**.
- Nouveau `availabilityDays(serviceId, from, to): Promise<string[]>` →
  `GET /availability/days`.

### Composants

- **`steps/TerminStep.vue`** (nouveau) — orchestration : calendrier + chips
  filtre + liste de créneaux. Props : `availableDates: string[]`,
  `slots: Slot[]`, `loadingSlots: boolean`. Émet `pick-date(date)` et
  `select(slot)`.
- **`components/BookingCalendar.vue`** (nouveau) — grille mensuelle (lun→dim,
  locale `de-DE`), nav mois ±, jours dispo cliquables, autres désactivés. Props :
  `availableDates: string[]`, `selectedDate?: string`. Émet `select(date)` et
  `month-change({ from, to })` (pour recharger les jours dispo au changement de
  mois). **Aucune dépendance externe** (calendrier maison, bundle IIFE léger).
- **`steps/PractitionerStep.vue`** — **supprimé** (et retiré de `App.vue` + de la
  barre de progression).
- Style : réutiliser les primitives visuelles des steps existants (cartes
  pastel, avatars colorés, pills). Le filtre médecin = chips arrondis avec la
  couleur du médecin ; le créneau = pill avec mini-avatar.

### `App.vue` (orchestration)

- `onService(s)` : `chooseService`, puis charge `availabilityDays(s.id, moisFrom,
  moisTo)` pour le mois courant (borne `from = max(aujourd'hui, début mois)`).
- `TerminStep` :
  - `@month-change` → recharge `availabilityDays` pour le nouveau mois.
  - `@pick-date(date)` → charge `slots(service.id, date, date)` (multi-médecins,
    pas de `practitioner_id`), `loadingSlots` pendant l'appel.
  - `@select(slot)` → `w.chooseSlot(slot)`.
- `onSubmit` : `practitioner_id: w.selection.slot!.practitioner.id`,
  `service_id`, `starts_at: w.selection.slot!.starts_at`. Reste inchangé
  (honeypot, room, consent, gestion 409/422/429).
- Barre de progression : **4 étapes**.

## Edge cases

- **Aucune dispo dans l'horizon** : calendrier sans jour cliquable + message
  `Kein freier Termin verfügbar.` ; nav mois reste possible jusqu'à l'horizon.
- **Service mono-médecin** : pas de chips filtre (liste de créneaux seule).
- **Jour sélectionné puis vidé** (dernier créneau pris entre-temps) : liste
  vide + message ; le parent peut re-cliquer un autre jour. Le 409 à la
  réservation reste le filet de sécurité final (`w.back()` → re-choix).
- **Changement de mois** au-delà de l'horizon : jours désactivés.
- **Fuseau** : tout en `Europe/Berlin` (inchangé). Les dates du calendrier sont
  des chaînes `YYYY-MM-DD` en heure clinique (pas de dérive UTC).

## Sécurité (inchangée, à préserver)

- Endpoints lecture anonymes throttlés `widget-read` ; réservation `widget-book`.
- `isBookable()` re-valide chaque créneau au POST ; lock pessimiste + 409.
- `Appointment::$fillable` exclut toujours `notes_internal` / `reminder_sent_at`.
- Honeypot `website` + `parent_consent_at` conservés.
- Validation : `availability/days` et `slots` valident `service_id` via
  `exists:services,id`, bornent la fenêtre temporelle (clamp horizon) pour éviter
  un calcul abusif déclenché par un `to` lointain.

## Tests

### Pest (Feature) — `tests/Feature/TenantSchema/`

- **`availableDates`** : un service offert par 2 médecins → renvoie l'union des
  dates dispo ; un jour sans dispo (absence couvrant les 2) absent ; respecte
  lead/horizon ; court-circuit n'omet aucune date.
- **`slots` multi-médecins** : sans `practitioner_id` → créneaux des 2 médecins
  fusionnés et triés ; chaque créneau porte le bon `practitioner`. Avec
  `practitioner_id` → compat ascendante (ce médecin seul).
- **Service mono-médecin** : `slots` renvoie un seul médecin sur chaque créneau.
- **Settings** : poser `booking.lead_minutes` / `booking.horizon_days` modifie la
  fenêtre calculée ; absence de row = constantes (régression).
- **Réservation** : POST avec un créneau issu du flow multi-médecins se réserve
  (isBookable OK) ; 409 conservé.

### Vitest — `tests/widget/`

- **`BookingCalendar`** : jours dispo cliquables / indispo désactivés ; nav mois
  émet `month-change` avec la bonne fenêtre ; `select` émet la date cliquée.
- **`TerminStep`** : affiche les chips médecins présents ce jour ; le filtre
  client masque les créneaux des autres médecins ; mono-médecin = pas de chips.
- **`useWizard`** : `service → termin → form → success`, `back()` linéaire ;
  `chooseSlot` stocke le praticien dans la sélection.

## YAGNI / hors scope

- Écran de réglages staff pour délai/horizon (data-layer suffit pour l'instant).
- Choix « n'importe quel médecin » explicite à la réservation (le créneau porte
  déjà un médecin précis ; le filtre suffit).
- Vue semaine / agenda horizontal (le calendrier mensuel + liste suffit au
  besoin « user-friendly »).
