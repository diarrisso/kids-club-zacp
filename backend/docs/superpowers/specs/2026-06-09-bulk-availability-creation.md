# Spec — Création en masse des plages d'horaires

**Date :** 2026-06-09
**Statut :** Validée

---

## Contexte

Aujourd'hui le cabinet crée chaque plage d'horaires (Sprechzeit) une par une via un formulaire
simple. Pour configurer un praticien sur 5 jours, il faut 5 soumissions séparées. Les champs
`valid_from` / `valid_to` existent en base mais ne sont pas exposés dans l'UI — toutes les
plages sont donc permanentes.

Le besoin : créer en une fois les plages d'une période configurable (3 mois, 4 mois, date libre…),
gérer les fermetures cabinet pour tous les praticiens d'un coup, et exclure automatiquement
les jours fériés allemands du calcul des créneaux.

---

## Décisions de design

**Approche :** Extension du formulaire existant (pas de nouvelle page, pas de nouveau wizard).
**Jours fériés :** Package `yasumi/yasumi` — automatique, sans table DB, configurable par Bundesland.
**Fermetures cabinet :** Toggle "tous les praticiens" sur le formulaire Abwesenheiten existant.

---

## 1. Formulaire Sprechzeiten enrichi (`/sprechzeiten/create`)

### 1a. Sélection multi-jours

Remplacement du `<select>` jour unique par 6 cases à cocher (Lun–Sam) avec affichage
en pills colorées. Au moins un jour doit être sélectionné (validation front + back).

### 1b. Mode horaires

Toggle entre deux modes :

- **Mode A — Mêmes horaires** : un seul champ `De / À` appliqué à tous les jours cochés.
- **Mode B — Horaires par jour** : une ligne `De / À` indépendante par jour coché.
  Les lignes des jours non cochés sont désactivées (grisées, non soumises).

Le mode sélectionné est conservé en état Vue local (`ref`), pas en base.

### 1c. Période de validité

| Champ | Type | Comportement |
|---|---|---|
| `valid_from` | date | Obligatoire. Valeur par défaut = aujourd'hui. |
| Durée | select | Options : 1, 2, 3, 4, 6 mois, 1 an, Personnalisé…, Sans limite |
| `valid_to` | date (conditionnel) | Affiché uniquement si "Personnalisé…" sélectionné. |

Calcul automatique affiché : "→ Valable jusqu'au JJ/MM/AAAA" dès qu'une durée prédéfinie
est choisie. "Sans limite" laisse `valid_to = NULL`.

### 1d. Intervalle de créneaux (optionnel)

Champ `slot_interval_minutes` (nullable) sur la table `availabilities`.
Radio buttons : **Par défaut** (null → le calculateur utilise `$service->duration_minutes`) /
**20 min** / **30 min**.

Affiché avec une note explicative :
> "L'intervalle détermine l'espacement entre les créneaux, indépendamment de la durée du service."

### 1e. Soumission

Le bouton affiche dynamiquement le nombre de plages qui seront créées :
`Créer 4 plages (Lu, Ma, Je, Ve)`.

Le contrôleur crée N enregistrements `Availability` en une transaction — un par jour coché,
chacun avec les mêmes `valid_from`, `valid_to`, `slot_interval_minutes` et ses propres
`start_time` / `end_time`.

---

## 2. Fermeture cabinet (`/abwesenheiten/create`)

### Toggle "Fermeture cabinet"

Nouveau toggle en haut du formulaire Abwesenheiten :

> ⬛ **Fermeture cabinet (tous les praticiens)**

Comportements quand activé :
- Le sélecteur praticien est masqué (non soumis).
- Le type est forcé à `cabinet_closure` (nouvelle valeur, label "Betriebsschließung").
- À la soumission, le contrôleur crée **une `AvailabilityException` par praticien actif**
  en une seule transaction.

Le modèle `AvailabilityException` et la migration ne changent pas — le type `cabinet_closure`
est ajouté à la liste des valeurs autorisées dans la validation et le label mapping front.

---

## 3. Jours fériés automatiques

### Package

```
composer require yasumi/yasumi
```

### Configuration

`.env.example` et `.env` reçoivent deux nouvelles variables :

```dotenv
APP_COUNTRY=Germany
APP_BUNDESLAND=BY
```

`config/booking.php` (nouveau fichier léger) expose :

```php
'country'    => env('APP_COUNTRY', 'Germany'),
'bundesland' => env('APP_BUNDESLAND', 'NW'),
```

### Intégration dans le calculateur

`AvailabilityCalculator::slotsForDay()` reçoit une vérification supplémentaire en tête :

```php
if ($this->isPublicHoliday($day)) {
    return collect();
}
```

Méthode privée `isPublicHoliday(CarbonImmutable $day): bool` :

```php
$holidays = Yasumi::create(
    config('booking.country'),
    $day->year,
    'de_DE'
);
return $holidays->isHoliday($day->toDateTimeImmutable());
```

Le résultat de `Yasumi::create()` est mis en cache par année via un simple tableau d'instance
(`private array $holidayCache = []`) pour éviter de recalculer à chaque appel dans une même
requête.

### Gestion du Bundesland

Yasumi utilise le provider par pays. Pour les Bundesländer, le provider Allemagne accepte
une subdivision. Si `APP_BUNDESLAND` est vide, les seuls fériés fédéraux s'appliquent.

---

## 4. Utilisation de `slot_interval_minutes` dans le calculateur

`AvailabilityCalculator::slotsForDay()` utilise actuellement `$duration` (= `$service->duration_minutes`)
comme pas d'avancement. Modification :

```php
$step = $availability->slot_interval_minutes ?? $duration;
// avancement des créneaux avec $step, durée du créneau reste $duration
while ($cursor->addMinutes($step)->lessThanOrEqualTo($dayEnd)) {
    $slots->push(new Slot($cursor, $cursor->addMinutes($duration)));
    $cursor = $cursor->addMinutes($step);
}
```

L'alignement de grille (`% duration !== 0`) passe à `% $step !== 0` dans `isBookable()`.

---

## 5. Changements base de données

### Migration unique

```php
Schema::table('availabilities', function (Blueprint $table) {
    $table->unsignedSmallInteger('slot_interval_minutes')->nullable()->after('end_time');
});
```

Aucune autre migration nécessaire.

---

## 6. Validation (Form Requests)

### `StoreAvailabilityRequest`

```
days[]              required|array|min:1 — chaque valeur in:1,2,3,4,5,6,7
start_time          required_without:days_hours|date_format:H:i
end_time            required_without:days_hours|date_format:H:i|after:start_time
days_hours          array (mode B — horaires par jour)
days_hours.*.start  required|date_format:H:i
days_hours.*.end    required|date_format:H:i|after:days_hours.*.start
valid_from          required|date|after_or_equal:today
valid_to            nullable|date|after:valid_from
slot_interval_minutes nullable|integer|in:20,30
```

### `StoreAvailabilityExceptionRequest`

Ajout de `cabinet_closure` à la liste des types autorisés.
`practitioner_id` devient `nullable|required_unless:is_cabinet_closure,true`.

---

## 7. Fichiers modifiés / créés

| Fichier | Action |
|---|---|
| `database/migrations/…_add_slot_interval_to_availabilities.php` | Créer |
| `app/Models/Tenant/Availability.php` | Ajouter `slot_interval_minutes` au `$fillable` + cast |
| `app/Http/Requests/Tenant/StoreAvailabilityRequest.php` | Enrichir validation |
| `app/Http/Controllers/Tenant/AvailabilityController.php` | `store()` — boucle multi-jours |
| `resources/js/Pages/Tenant/Availabilities/Form.vue` | Refonte complète |
| `app/Services/Tenant/AvailabilityCalculator.php` | Holidays + slot_interval |
| `app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php` | cabinet_closure type |
| `app/Http/Controllers/Tenant/AvailabilityExceptionController.php` | Bulk create |
| `resources/js/Pages/Tenant/Exceptions/Form.vue` | Toggle fermeture cabinet |
| `config/booking.php` | Créer (country + bundesland) |
| `.env.example` | APP_COUNTRY + APP_BUNDESLAND |

---

## 8. Tests

- `AvailabilityCalculatorTest` — assertions : slot skippé un jour férié, `slot_interval_minutes`
  crée des créneaux plus resserrés, `valid_from`/`valid_to` respectés
- `AvailabilityTest` — store multi-jours crée N enregistrements, validation rejette 0 jours
- `ExceptionTest` — toggle cabinet_closure crée N exceptions (une par praticien actif)

---

## Hors scope

- Vue calendrier (approche C refusée)
- Gestion des jours fériés guinéens ou autres pays non supportés par Yasumi (provider custom = feature séparée)
- Modification rétroactive des plages existantes en masse
