# Spec — Refonte du flow de réservation widget (3 étapes)

**Date :** 2026-06-09
**Statut :** Validée

---

## Contexte

Le widget actuel a 4 étapes séquentielles :
`service → termin → form → success`

Le cabinet souhaite un flow **date-first en 3 étapes** plus intuitif et moderne, avec un
stepper visuel ligne indigo. Le service se sélectionne dans l'étape 1 car il conditionne
le calcul des créneaux (durée variable : 30 / 45 / 60 min).

---

## Nouveau flow validé

```
Step 1 — Termin    : Service pills → Calendrier → Slots
Step 2 — Angaben   : Formulaire enfant + parent (sans submit)
Step 3 — Bestätigen: Récapitulatif + consent + bouton "Termin buchen"
          → success screen (inchangé)
```

---

## 1. Stepper visuel

Nouveau composant `StepIndicator.vue` (style C — ligne indigo, validé).

```
Props : currentStep: 'termin' | 'form' | 'confirm'
```

Rendu : 3 nœuds reliés par une ligne continue. Nœud actif = point indigo avec halo.
Nœud complété = ✓ plein indigo. Nœud futur = cercle vide gris.
La ligne se remplit progressivement entre les nœuds complétés.

Labels : **Termin** · **Angaben** · **Bestätigen**

Intégré en haut de `App.vue`, visible sur les steps `termin`, `form`, `confirm`.
Masqué sur `success`.

---

## 2. Step 1 — TerminStep refondu

`ServiceStep.vue` est **supprimé**. Son contenu est absorbé dans `TerminStep.vue`.

### Sous-états internes (réactifs Vue, pas dans le wizard)

```
serviceChosen : Service | null   — null = aucun service sélectionné
dateChosen    : string | null    — null = aucune date sélectionnée
```

### Rendu séquentiel dans le step

**Section A — Service pills** (toujours visible)

Trois boutons pill verticaux (liste, pas inline) :
- Affiche `service.name` + durée (`service.duration_minutes Min.`)
- Pill active : fond `#0f172a` / texte blanc
- Pills inactives : fond gris clair / bordure légère
- Un seul service sélectionnable à la fois

**Section B — Calendrier** (apparaît après service choisi)

`transition fade-in` (opacity 0→1, translateY +4px→0, 200ms).
Identique au `BookingCalendar` actuel.
Émet `month-change` (déjà géré dans `App.vue`) à chaque navigation de mois.

**Section C — Slots** (apparaît après date choisie)

Apparaît sous le calendrier avec le même `fade-in`.
Affiche les créneaux disponibles comme aujourd'hui (pills horaires avec point coloré praticien).
Filtre praticien conservé si plusieurs praticiens disponibles.
Clic sur un slot → `emit('select', slot)` → `App.vue` appelle `w.chooseSlot()` → avance à `form`.

### Événements émis

| Événement | Payload | Déclencheur |
|---|---|---|
| `service-select` | `{ service, monthWindow }` | Clic sur une pill |
| `month-change` | `{ from, to }` | Navigation calendrier |
| `pick-date` | `date: string` | Clic sur une date dispo |
| `select` | `slot: Slot` | Clic sur un créneau |

### Gestion dans App.vue

`onServiceSelect({ service, monthWindow })` :
1. Appelle `w.chooseService(service)` (ne change plus d'étape, juste stocke)
2. Appelle `onMonthChange(monthWindow)` pour charger les dates disponibles immédiatement

`useWizard` : `chooseService()` ne fait plus `go('termin')` — il stocke uniquement le service
dans `selection.service` et reste sur l'étape courante.

---

## 3. Step 2 — FormStep (allégé)

`FormStep.vue` est modifié : le bouton `Speichern` / submit est **retiré**.
Remplacé par un bouton `Weiter →` qui émet `advance` avec les données du formulaire.

En haut du formulaire : **carte récap compacte** (non éditable) :
```
Leistung : Erstuntersuchung · 45 Min.
Datum    : Mi, 10. Juni 2026 · 10:30 Uhr
```
Cette carte est passée en prop depuis App.vue (`selection`).

À la soumission du formulaire (clic Weiter + validation front) :
- `emit('advance', formData)`
- App.vue stocke les données dans `pendingForm` (ref)
- `w.advance()` → passe à `confirm`

Les données du formulaire sont **conservées si l'utilisateur fait Zurück** depuis step 3 :
App.vue passe `pendingForm` comme prop `initialValues` à FormStep — les champs sont pré-remplis.

---

## 4. Step 3 — ConfirmStep (nouveau)

Nouveau fichier `steps/ConfirmStep.vue`.

### Contenu

**Récapitulatif complet** (lecture seule, card gris clair) :
- Leistung + durée
- Date + heure + praticien (prénom + nom)
- Prénom/nom de l'enfant
- Email parent

**Notification email** (bandeau jaune pâle) :
> "📧 Bestätigungsmail wird an {email} gesendet"

**Checkbox consent RGPD** (obligatoire, valider avant activation du bouton) :
> "Ich stimme der Verarbeitung meiner Daten gemäß der Datenschutzerklärung zu."

**Bouton principal** : `Termin buchen ✓`
- Désactivé tant que consent non coché
- État loading (spinner inline) pendant la requête API

### Soumission

`emit('submit')` → App.vue récupère `pendingForm` + `w.selection` → construit le payload → `api.book()`.

### Gestion erreur slot_taken depuis ConfirmStep

Si `slot_taken` : `w.backToTermin()` (nouvelle méthode sautant directement à `termin`)
+ rafraîchissement des slots pour la date sélectionnée + banner d'erreur.

---

## 5. useWizard.ts — modifications

```typescript
export type Step = 'termin' | 'form' | 'confirm' | 'success'
const ORDER: Step[] = ['termin', 'form', 'confirm', 'success']
```

Méthodes modifiées / ajoutées :

| Méthode | Comportement |
|---|---|
| `chooseService(s)` | Stocke `selection.service`, **ne change plus d'étape** |
| `chooseSlot(slot)` | Stocke `selection.slot`, avance à `form` (inchangé) |
| `advance()` | Avance d'une étape (`form` → `confirm`) |
| `backToTermin()` | Force retour à `termin` depuis n'importe quelle étape |
| `back()` | `confirm` → `form`, `form` → `termin` (ORDER inverse) |

---

## 6. App.vue — modifications

```typescript
const pendingForm = ref<Record<string, unknown> | null>(null)

// Nouveau handler
function onFormAdvance(data: Record<string, unknown>) {
    pendingForm.value = data
    w.advance()
}

// onSubmit appelé depuis ConfirmStep (pas FormStep)
// Utilise pendingForm.value au lieu des données passées en argument
```

Import/usage de `ServiceStep` supprimé.
`StepIndicator` importé et rendu en haut du template.

---

## 7. Fichiers modifiés / créés / supprimés

| Fichier | Action |
|---|---|
| `widget/useWizard.ts` | Modifier (nouveau type Step, nouvelles méthodes) |
| `widget/App.vue` | Modifier (pendingForm, nouveau flow, StepIndicator) |
| `widget/steps/TerminStep.vue` | Refonte complète (absorption ServiceStep) |
| `widget/steps/FormStep.vue` | Modifier (retrait submit, ajout recap card, prop initialValues) |
| `widget/steps/ConfirmStep.vue` | Créer |
| `widget/steps/ServiceStep.vue` | **Supprimer** |
| `widget/components/StepIndicator.vue` | Créer |

---

## 8. Points techniques à surveiller

**Chargement initial des dates disponibles :**
Actuellement `TerminStep` émet `month-change` à son montage (via `BookingCalendar`).
Dans le nouveau flow, `TerminStep` est monté dès le départ — le calendrier ne s'affiche
qu'après choix du service. `onMonthChange` ne doit charger les dates que si `selection.service`
est défini (déjà le cas dans `App.vue`).

**Persistance du formulaire sur Zurück :**
`pendingForm` n'est pas réinitialisé lors d'un `back()`. FormStep reçoit `initialValues`
et pré-remplit les champs via `watch(initialValues, ...)`.

**Mobile — scroll step 1 :**
Section B (calendrier) et Section C (slots) apparaissent en dessous des pills.
Sur petit écran, un `scrollIntoView` est déclenché automatiquement lors de l'affichage
de chaque section (service choisi → scroll vers calendrier ; date choisie → scroll vers slots).

---

## 9. Tests à mettre à jour

- `tests/widget/` — unit tests Vitest :
  - `useWizard` : flow `termin → form → confirm → success`, `back()`, `backToTermin()`
  - `TerminStep` : service sélectionné → calendrier visible, date sélectionnée → slots visibles
  - `ConfirmStep` : bouton désactivé sans consent, emit submit avec consent

---

## Hors scope

- Sélection du praticien comme étape séparée (reste un filtre dans step 1)
- Authentification / compte parent
- Modification d'un RDV existant
