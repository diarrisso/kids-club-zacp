# Spec — Bibliothèque de composants UI réutilisables (frontend staff)

**Date :** 2026-06-03
**Statut :** En attente de validation (brainstorming)
**Branche :** `refactor/staff-ui-components` (issue de `main`)

## 1. Objectif

Éliminer la duplication massive du frontend staff (Inertia/Vue) en extrayant une
**bibliothèque de composants UI réutilisables**, puis refactorer les pages existantes
pour les consommer. Gains : DRY, cohérence visuelle, maintenabilité, et un design system
réutilisable pour toute page future.

C'est un **refactor sans changement fonctionnel** : l'apparence et le comportement
restent identiques (vérification visuelle Chrome avant/après obligatoire).

## 2. Constat (duplication actuelle)

Inventaire des 12 pages `resources/js/Pages/Tenant/**` (~1050 lignes). Patterns dupliqués
quasi à l'identique :

- **Champ de formulaire** : `<div><label class="block text-sm font-medium mb-1">…</label>
  <input class="w-full p-2 border rounded"><div v-if="errors" class="text-red-600 text-sm">…</div></div>`
  — répété pour chaque champ de chaque formulaire (Services, Practitioners, Availabilities,
  Exceptions, Appointments).
- **Bouton submit** : `<button type="submit" :disabled="form.processing"
  class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800">Speichern</button>` — identique partout.
- **Carte blanche** : `bg-white p-6 rounded shadow space-y-4` (formulaires) / `bg-white rounded shadow` (tables).
- **En-tête de page** : `<div class="flex justify-between items-center mb-6"><h1 class="text-3xl font-bold">…</h1>
  <Link class="bg-blue-700 …">+ Neue …</Link></div>`.
- **Table de liste** : `<table class="w-full bg-white rounded shadow"><thead class="bg-slate-100">…` —
  `Services/Index` et `Practitioners/Index` sont identiques à ~95 %.
- **Pastille couleur** : `<span class="inline-block w-6 h-6 rounded-full" :style="{ background }">`.
- **Badge statut** : `Aktiv` (vert) / `Inaktiv` (slate).
- **Actions de ligne** : `Bearbeiten` (Link) + `Löschen` (button + `confirm('Wirklich löschen?')`).

## 3. Architecture — emplacement

Nouveau dossier **`resources/js/components/ui/`** (cohérent avec la convention shadcn-vue
mentionnée dans `CLAUDE.md`, bien qu'aucun dossier de composants n'existe encore). Import
via l'alias `@` : `import FormField from '@/components/ui/FormField.vue'`.

## 4. Composants à créer

### Primitives de formulaire
| Composant | Rôle | API (props / slots / emits) |
|---|---|---|
| `FormField.vue` | Label + contrôle (slot) + message d'erreur | props: `label: string`, `error?: string`, `required?: boolean`, `hint?: string` ; slot par défaut = le contrôle |
| `TextInput.vue` | `<input>` stylé avec `v-model` | `v-model` (`modelValue: string\|number`), passe-plat `type`/attrs ; classe `w-full p-2 border rounded` ; émet `update:modelValue` |
| `PrimaryButton.vue` | Bouton d'action principal | props: `type?: 'submit'\|'button'` (défaut `submit`), `disabled?: boolean` ; slot = libellé ; classe `bg-blue-700 … hover:bg-blue-800 disabled:opacity-50` |
| `ButtonLink.vue` | `<Link>` Inertia stylé comme bouton (ex. « + Neue X ») | props: `href: string` ; slot = libellé |
| `Card.vue` | Conteneur blanc générique | props: `as?: 'div'\|'form'` (défaut `div`) ; slot ; classe `bg-white rounded shadow` (+ `p-6 space-y-4` via prop `padded`) |

### Primitives de liste / page
| Composant | Rôle | API |
|---|---|---|
| `PageHeader.vue` | Titre H1 + action optionnelle | props: `title: string` ; slot nommé `action` (optionnel) |
| `DataTable.vue` | Coquille `<table>` (thead/tbody par slots) | slots: `head` (les `<th>`), défaut (les `<tr>`) ; classe `w-full bg-white rounded shadow`, thead `bg-slate-100` |
| `StatusBadge.vue` | Badge Aktiv/Inaktiv | props: `active: boolean` ; rend vert/slate avec libellé allemand |
| `ColorDot.vue` | Pastille couleur | props: `color: string` |
| `RowActions.vue` | Bearbeiten + Löschen | props: `editHref: string`, `confirmMessage?: string` (défaut `'Wirklich löschen?'`) ; emit `delete` (le parent appelle `router.delete`) |

> **Décision API `RowActions`** : il **émet** `delete` plutôt que d'appeler `router.delete`
> lui-même, pour ne pas coupler le composant à une URL — le parent garde la responsabilité
> de la route (respecte la frontière : le composant ne connaît pas le domaine).

> **TextInput vs FormField** : `FormField` gère le *layout* (label/erreur) et reçoit
> n'importe quel contrôle en slot (`TextInput`, `<select>`, `<textarea>`, checkbox…).
> `TextInput` standardise le style de l'input. On peut utiliser `FormField` seul avec un
> contrôle natif, ou `FormField` + `TextInput`. Pas de fusion pour rester composable.

## 5. Pages refactorées (sur cette branche, présentes sur `main`)
- **Formulaires** : `Services/Form`, `Practitioners/Form`, `Availabilities/Form`,
  `Exceptions/Form`, `Appointments/AppointmentForm` → `FormField` (+ `TextInput`),
  `PrimaryButton`, `Card`.
- **Listes** : `Services/Index`, `Practitioners/Index`, `Availabilities/Index`,
  `Exceptions/Index` → `PageHeader` + `ButtonLink`, `DataTable`, `StatusBadge`,
  `ColorDot`, `RowActions`.
- **Dashboard** : `PageHeader` (titre seul).
- **`Appointments/Calendar.vue`** : composant spécialisé (FullCalendar probable) — refactor
  **uniquement** des éléments triviaux (en-tête/boutons) s'il y en a ; ne pas toucher la
  logique calendrier.

## 6. Hors scope
- ❌ **`QrCode.vue`** : vit sur la PR QR #15 (pas encore mergée), donc absent de cette
  branche. Il adoptera la bibliothèque en **follow-up** après merge de la PR QR (mise à
  jour triviale : `FormField`, `PrimaryButton`, `CopyField`). `CopyField.vue` (champ
  readonly + copier) sera créé à ce moment-là, avec le QR comme premier consommateur.
- ❌ Aucun changement fonctionnel, aucune nouvelle route, aucun changement backend.
- ❌ Pas de migration vers une lib de composants tierce (shadcn-vue complet) — on extrait
  nos propres primitives minimales.

## 7. Tests & vérification
- **Pas de tests unitaires Vue existants** dans ce projet (les tests Pest couvrent le
  backend). On **n'introduit pas** de framework de test composant ici (hors scope / YAGNI).
- **Vérification de non-régression** :
  1. `npm run build` doit passer (compilation TS/Vue) après chaque page refactorée.
  2. **Chrome (obligatoire)** : capture avant/après de chaque écran refactoré (Dashboard,
     les 4 Index, les formulaires create+edit) — l'apparence doit être **identique** et les
     interactions (création, édition, suppression avec `confirm`, validation d'erreurs)
     inchangées.
  3. `php artisan test` doit rester vert (les feature tests backend ne doivent pas bouger,
     mais on confirme qu'on n'a rien cassé côté routes/Inertia).

## 8. Stratégie d'exécution
- Refactor **page par page**, en commençant par créer les composants (lot 1), puis en
  migrant un domaine à la fois (lot 2 = listes, lot 3 = formulaires), avec build + Chrome
  entre chaque, pour isoler toute régression visuelle.
- PR dédiée `refactor/staff-ui-components` → main (séparée de la PR QR).

## 9. Risques
- **Régression visuelle** : principal risque d'un refactor UI. Mitigation : captures Chrome
  avant/après, refactor incrémental, classes Tailwind reportées à l'identique dans les
  composants.
- **Divergence de style préexistante** : certaines pages ont de légères variations (ex.
  `Exceptions`/`Availabilities` peuvent différer) — on aligne sur le style dominant
  (`bg-blue-700`, `p-2 border rounded`) en le documentant.
