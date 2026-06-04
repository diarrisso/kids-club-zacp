# Spec — Dashboard moderne, rôles & système de couleurs KidsClub

**Date :** 2026-06-04
**Statut :** Validé (brainstorming) — prêt pour le plan d'implémentation
**Auteur :** Mamadi Diarrisso (+ Claude)

## 1. Objectif

Donner au cabinet dentaire pédiatrique **KidsClub** une interface staff **moderne et
distinctive**, articulée autour de trois apports liés :

1. **🎨 Dashboard moderne** — aujourd'hui la page est un simple titre. On la reconstruit
   avec une vraie qualité de design (skill `frontend-design`), des **icônes Lucide**, des
   KPIs et la liste des rendez-vous du jour, le tout décliné dans la **charte KidsClub**.
2. **🔐 Rôles** — distinguer **médecin** et **secrétaire** pour personnaliser l'affichage
   (sans aucune restriction d'accès : les deux restent « admin »).
3. **🌈 Système de couleurs ludique** — l'enfant/parent **choisit sa salle/couleur** lors
   de la prise de rendez-vous (widget), et cette couleur **pilote le calendrier** côté
   staff. Cohérent avec les 5 salles colorées du cabinet et les supports print à venir.

### Hors scope (décidé explicitement — YAGNI)
- ❌ **Pas d'entité relationnelle « Zimmer / Salle »** avec détection de conflit (« 2
  enfants dans la salle verte à la même heure »). La couleur est une **préférence
  ludique**, pas une réservation de ressource stricte. Upgrade possible plus tard.
- ❌ **Aucun mur de permissions** : pas de Policies, pas de middleware de rôle, pas de
  gates. Les deux rôles voient tous les écrans.
- ❌ Pas de coloration du calendrier par praticien **en fond** (le praticien passe en
  **bordure** ; le fond porte la couleur de salle).
- ❌ Pas de nouvelle bibliothèque d'icônes : `lucide-vue-next` est **déjà installé**.

## 2. Contexte & contraintes du codebase

- **Mono-tenant** : un seul cabinet, une seule base. Les noms `Tenant\*` sont vestigiaux
  (cf. `CLAUDE.md`).
- **`lucide-vue-next` ^1.0.0 est déjà dans `package.json`** → zéro dépendance à ajouter.
- **`Service.color` existe déjà** mais on ne s'en sert plus pour le calendrier (la couleur
  vient désormais du **choix de salle** stocké sur le rendez-vous).
- **Le calendrier staff** (`Pages/Tenant/Appointments/Calendar.vue`, FullCalendar) colore
  aujourd'hui par `practitioner.color` via `lib/calendar.ts → toCalendarEvent`.
- **Le flux events** : `Tenant\AppointmentController@events` construit les DTO via une
  méthode `toDto($a)` (eager-load `service`, `practitioner`). C'est là qu'on exposera
  `room`.
- **Le widget public** (anonyme) : étapes dans `resources/js/widget/steps/`
  (`ServiceStep`, `PractitionerStep`, `SlotStep`, **`FormStep`**, `SuccessStep`), pilotées
  par `useWizard.ts` ; types dans `types.ts` ; appels dans `api.ts`. La validation de
  réservation est dans `app/Http/Requests/Widget/StoreAppointmentRequest.php`, la création
  dans `app/Http/Controllers/Widget/AppointmentController.php`.
- **`Appointment::$fillable`** exclut délibérément `notes_internal` et `reminder_sent_at`
  (staff/système-only). On ajoutera `room` au `$fillable` (champ non sensible).
- **Conventions** : URLs en allemand, noms de routes en anglais ; jamais d'URL hardcodée ;
  validation en Form Requests ; PostgreSQL ; tests Pest 4 + Vitest (widget) ; composants
  réutilisables obligatoires.

## 3. La charte KidsClub (design tokens)

Les 5 couleurs fournies par le client (numéros RVB → hex web) :

| Salle (client)   | RVB             | Hex        | Rôle visuel        |
|------------------|-----------------|------------|--------------------|
| **Grün** (vert)  | 189 / 204 / 194 | `#BDCCC2`  | fond pastel        |
| **Gelb** (jaune) | 247 / 226 / 157 | `#F7E29D`  | fond pastel        |
| **Orange/Rosa**  | 252 / 232 / 225 | `#FCE8E1`  | fond pastel        |
| **Blau** (bleu)  | 152 / 172 / 186 | `#98ACBA`  | fond pastel + **signature** |
| **Lila** (violet)| 204 / 200 / 206 | `#CCC8CE`  | fond pastel        |

```js
// tailwind.config.js → theme.extend.colors
kids: {
  green:  '#BDCCC2',
  yellow: '#F7E29D',
  peach:  '#FCE8E1',
  blue:   '#98ACBA',
  purple: '#CCC8CE',
}
```

**Règles de design** (ces tons sont *clairs*) :
- **Pastels en fond** (cartes KPI, badges, accents, événements calendrier).
- **Texte en `slate-800` (`#1E293B`)** sur les pastels (contraste suffisant — tous les
  pastels sont clairs).
- Couleur **signature** pour les éléments forts (en-têtes, bouton principal, état actif
  de nav) = le **bleu `#98ACBA`** (ou une variante légèrement assombrie pour le texte
  d'accent si besoin de contraste sur fond blanc).
- Cohérence print : ces tokens serviront de référence lorsque l'agence créera les flyers.

**Source de vérité unique des couleurs de salle** : `app/Support/Room.php` (PHP), exposée
au front via les props Inertia (dashboard/staff) et la config du widget. Une couleur ne
vit qu'à **un seul endroit**.

## 4. Rôles

### 4.1 Schéma
```
users
├── role             string  NOT NULL  default 'secretaire'   ← 'medecin' | 'secretaire'
└── practitioner_id  FK nullable → practitioners (nullOnDelete)
```
- Migration `add_role_and_practitioner_to_users`.
- `User` : `role` + `practitioner_id` dans `$fillable` ; relation `practitioner()`
  (`belongsTo`) ; helpers `isMedecin(): bool` / `isSecretaire(): bool`.

### 4.2 Sémantique (aucune restriction)
- **Les deux rôles ont un accès complet** à tous les écrans (ils sont « admin »).
- Le rôle **personnalise** l'affichage :
  - **Salutation** : « Guten Tag, Dr. {Nom} » (médecin lié) vs « Guten Tag · Empfang »
    (secrétaire).
  - **Libellé sous l'email** dans la sidebar : « Dr. {Nom} » / « Réception ».
  - **Dashboard d'un médecin lié** : la liste « Termine heute » est filtrée sur **ses**
    rendez-vous par défaut (toggle « Alle anzeigen » pour tout voir).
- **Dégradation gracieuse** : si un médecin n'a pas de `practitioner_id`, le dashboard
  retombe en mode cosmétique (affiche tout) — aucune config obligatoire, aucun blocage.

## 5. Système de couleurs / choix de salle

### 5.1 Schéma & source de vérité
```
appointments
└── room   string nullable   ← 'green' | 'yellow' | 'peach' | 'blue' | 'purple' (ou NULL)
```
- Migration `add_room_to_appointments`.
- `Appointment` : `room` ajouté au `$fillable` ; cast vers un enum PHP `App\Support\Room`.
- **`app/Support/Room.php`** — enum (backed string) :
  - cas `Green, Yellow, Peach, Blue, Purple` (valeurs `green`…`purple`) ;
  - `color(): string` → hex (`#BDCCC2`…) ;
  - `label(): string` → libellé allemand (« Grünes Zimmer »…) ;
  - `::options(): array` → liste `{value,color,label}` pour le front (props/config).

### 5.2 Choix côté parent (widget) — **optionnel**
- Le sélecteur **5 pastilles** s'intègre dans **`FormStep.vue`** (étape coordonnées) — pas
  de nouvelle étape lourde. Libellé enfant-friendly : « Welches Zimmer möchtest du? ».
- **Optionnel** : l'enfant peut ne rien choisir → `room = null` → couleur neutre au
  calendrier.
- `useWizard.ts` / `types.ts` : ajouter `room?: string | null` à l'état/au payload.
- `api.ts` : transmettre `room` dans le POST de réservation.
- `StoreAppointmentRequest` : `room` → `['nullable', 'in:green,yellow,peach,blue,purple']`.
- `Widget\AppointmentController` : persiste `room` (déjà couvert par `$fillable`).

### 5.3 Choix côté staff
- **`AppointmentForm.vue`** : même sélecteur 5 pastilles pour créer/corriger un RDV manuel
  et **changer la salle**. Le `store`/`update` staff persiste `room`.

### 5.4 Restitution au calendrier
- `Tenant\AppointmentController` : la méthode **`toDto`** expose `room` (string|null).
- `lib/calendar.ts` :
  - `AppointmentDto` reçoit `room: string | null` ;
  - mapping `roomColor(room)` (miroir front de `Room::color`, alimenté par les options
    Inertia pour éviter toute divergence) ;
  - `toCalendarEvent` : `backgroundColor = roomColor(a.room) ?? '#E2E8F0'` (slate-200
    neutre), **`textColor = '#1E293B'`**, **`borderColor = a.practitioner.color`**.

```
Fond = salle (choix enfant)   ·   Bordure = praticien (qui)   ·   Texte = slate-800
```

## 6. Dashboard moderne

### 6.1 Données — `Tenant\DashboardController@index`
Renvoie à `Inertia::render('Tenant/Dashboard', …)` :
- `role`, nom de l'utilisateur, praticien lié `{id,name,color}|null` ;
- `stats` : `todayCount`, `weekCount`, `nextAppointment {time, patient, service}|null`,
  `activePractitioners` ;
- `todayAppointments[]` : `{time, patient, service, room, practitioner{name,color}}`,
  triés par heure, **filtrés sur le praticien lié si médecin** (sinon tous) ;
- `rooms` : `Room::options()` (pour la légende).

Requêtes bornées en `Europe/Berlin`, statut `!= cancelled`, eager-load `service` +
`practitioner`. Fenêtres : « aujourd'hui » = jour courant ; « cette semaine » = semaine
ISO courante.

### 6.2 Mise en page (responsive, mobile-first)
```
┌─────────────────────────────────────────────────────────────┐
│  Guten Tag, Dr. Müller 🟦          Mittwoch, 4. Juni 2026    │  salutation perso
├───────────────┬───────────────┬───────────────┬─────────────┤
│ 📅 Heute      │ 🗓️ Diese Woche │ ⏰ Nächster    │ 🦷 Behandler │  4 × StatCard
│   7 Termine   │   28 Termine   │   14:30 Lina   │   3 aktiv    │  (icône Lucide
│  [kids.blue]  │  [kids.green]  │  [kids.yellow] │ [kids.peach] │   en pastille)
├───────────────┴───────────────┴───────────────┴─────────────┤
│  Termine heute                      │  Schnellzugriff         │
│  09:00 Max M. · Kontrolle  🟢 ●Dr.  │  ＋ Neuer Termin        │  actions (Lucide)
│  10:30 Lina B. · Füllung   🔵 ●Dr.  │  📅 Kalender öffnen     │
│  14:30 Tom K. · Beratung   🩷 ●Dr.  │  🔳 QR-Code            │
│  (médecin → SES RDV · toggle « Alle »)│  Zimmer-Legende 🟢🟡🩷🔵🟣│  RoomLegend
└─────────────────────────────────────┴─────────────────────────┘
```
- **Qualité de design** : construite avec le skill **`frontend-design`** — identité
  pédiatrique distinctive, formes douces/arrondies (`rounded-2xl`), espacements généreux,
  micro-interactions discrètes, **pas d'esthétique “template IA” générique**. Charte =
  les 5 pastels KidsClub.
- **Empty states** soignés (aucun RDV aujourd'hui → illustration/texte accueillant).
- **Accessibilité** : contraste texte slate-800 sur pastels, focus visibles, cibles
  tactiles ≥ 44 px, libellés ARIA sur les actions iconographiques.

### 6.3 Composants réutilisables (règle « composants réutilisables »)
- **`components/ui/StatCard.vue`** — `{ icon, value, label, color }` (icône Lucide en
  pastille pastel + valeur + libellé).
- **`components/ui/RoomLegend.vue`** — lecture seule des 5 salles (partagée
  dashboard + calendrier), alimentée par `Room::options()`.
- **`components/ui/RoomPicker.vue`** — 5 pastilles **cliquables** (`v-model`), partagé par
  le **widget** et le **formulaire staff**. `RoomLegend` et `RoomPicker` partagent la même
  source de couleurs.
- Réutilise les primitives existantes (`Card`, `PageHeader`, `PrimaryButton`, `ButtonLink`).

## 7. Sidebar modernisée — `TenantLayout.vue`
- Remplace les emojis du tableau `nav` par des **icônes Lucide** + libellé ; **état actif**
  (route courante) souligné par un fond `kids.blue/20` + texte slate-800.
- Mapping : `LayoutDashboard` (Dashboard), `CalendarDays` (Termine), `Stethoscope`
  (Behandler), `ClipboardList` (Leistungen), `Clock` (Sprechzeiten), `TreePalm`
  (Abwesenheiten), `QrCode` (QR-Code), `LogOut` (Abmelden). *(Icônes swappables.)*
- **Libellé de rôle** sous l'email (« Dr. {Nom} » / « Réception »), couleur signature pour
  le nom du cabinet.

## 8. Seeder — `KidsClubSeeder`
- Deux utilisateurs de démo : un **médecin** (lié à une fiche `Practitioner`) + une
  **secrétaire** (sans lien). Données **allemandes** (cabinet allemand — *pas* de données
  guinéennes ici).
- Mots de passe de démo : uniquement en contexte **local/seed** (jamais de mot de passe
  prévisible hors local/testing).
- Répartir des `room` variés sur les rendez-vous de démo (les 5 couleurs + quelques `null`)
  pour visualiser le calendrier.

## 9. Tests

### 9.1 Pest (Feature/Unit)
- **Rôles** : `role` par défaut `secretaire` ; `isMedecin()`/`isSecretaire()` ; relation
  `practitioner()`.
- **Dashboard** : invité → redirigé ; staff → `200` ; compteurs `todayCount`/`weekCount`
  corrects ; **médecin lié → liste filtrée sur ses RDV** ; médecin **non lié** → voit tout
  (dégradation) ; secrétaire → voit tout.
- **Room (widget)** : réservation acceptée avec `room` valide ; **acceptée avec `room`
  absent/null** ; rejetée avec `room` hors liste ; `room` bien persisté et **non** capable
  d'écrire un champ protégé.
- **Events** : `toDto` / l'endpoint `events` renvoie `room`.
- **Staff form** : `store`/`update` persiste `room`.

### 9.2 Vitest (widget / front)
- **`toCalendarEvent`** : fond = couleur de salle ; `room = null` → fond neutre slate-200 ;
  `textColor` foncé ; `borderColor` = couleur praticien.
- **`RoomPicker`** : émet la bonne valeur ; déselection possible (optionnel) ; rendu des 5
  pastilles depuis les options.

## 10. Sécurité
- Aucun nouveau secret ni donnée patient supplémentaire. `room` est un attribut non
  sensible, validé en liste blanche (`in:`), ajouté au `$fillable` ; `notes_internal` et
  `reminder_sent_at` **restent** hors `$fillable`.
- Pas d'élévation de privilèges introduite : le rôle ne donne **aucun** droit
  supplémentaire (tout le monde est déjà « admin »). `role` n'est pas mass-assignable
  depuis une surface publique (réservé au seed / gestion interne).
- Le widget reste anonyme, rate-limité comme aujourd'hui ; `room` n'ouvre aucune surface
  d'écriture sur d'autres entités.

## 11. Documents de référence à mettre à jour (post-implémentation)
Conformément au workflow global :
- **Wireframe** (`public/wireframe.html`) — écrans : dashboard moderne, sélecteur de salle
  (widget + form staff), sidebar modernisée.
- **Diagramme BDD** (`docs/database-diagram.html` + copie `public/`) — `users.role`,
  `users.practitioner_id`, `appointments.room`.
- **ProjectProgressService** — module « Dashboard / Rôles / Couleurs » (models /
  controllers / pages / migrations / tests).

## 12. Fichiers

**Créer**
- `database/migrations/*_add_role_and_practitioner_to_users.php`
- `database/migrations/*_add_room_to_appointments.php`
- `app/Support/Room.php` (enum + `color`/`label`/`options`)
- `resources/js/components/ui/StatCard.vue`
- `resources/js/components/ui/RoomLegend.vue`
- `resources/js/components/ui/RoomPicker.vue`

**Modifier**
- `tailwind.config.js` (palette `kids`)
- `app/Models/User.php` (role, practitioner_id, helpers, relation)
- `app/Models/Tenant/Appointment.php` (`room` fillable + cast enum)
- `app/Http/Controllers/Tenant/DashboardController.php` (agrégats + props)
- `resources/js/Pages/Tenant/Dashboard.vue` (reconstruction `frontend-design`)
- `resources/js/Layouts/TenantLayout.vue` (nav Lucide + rôle)
- `resources/js/lib/calendar.ts` (couleur par salle, texte foncé, bordure praticien)
- `resources/js/Pages/Tenant/Appointments/Calendar.vue` (légende, habillage)
- `resources/js/Pages/Tenant/Appointments/AppointmentForm.vue` (RoomPicker)
- `app/Http/Controllers/Tenant/AppointmentController.php` (`toDto` expose `room`)
- `app/Http/Requests/Widget/StoreAppointmentRequest.php` (validation `room`)
- `app/Http/Controllers/Widget/AppointmentController.php` (persiste `room`)
- `resources/js/widget/steps/FormStep.vue` (sélecteur de salle)
- `resources/js/widget/useWizard.ts` + `types.ts` + `api.ts` (`room` dans l'état/payload)
- `database/seeders/KidsClubSeeder.php` (users + rôles + rooms de démo)

## 13. Ordre d'implémentation suggéré
1. **Fondations** : charte Tailwind + `Room` enum + 2 migrations + `User`/`Appointment`.
2. **Couleurs de bout en bout** : `RoomPicker`/`RoomLegend` → widget (`FormStep`,
   `useWizard`, `api`, `StoreAppointmentRequest`, `Widget\AppointmentController`) → staff
   (`AppointmentForm`) → calendrier (`toDto`, `calendar.ts`, `Calendar.vue`).
3. **Dashboard** (skill `frontend-design`) : `DashboardController` + `StatCard` +
   `Dashboard.vue`.
4. **Sidebar** modernisée (`TenantLayout`).
5. **Seeder** + **tests** (Pest + Vitest) + maj docs de référence.
