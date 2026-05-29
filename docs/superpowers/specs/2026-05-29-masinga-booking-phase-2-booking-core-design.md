# Spec — Masinga Booking · Phase 2 : Cœur Booking

**Date :** 2026-05-29
**Statut :** Validé (brainstorming)
**Dépend de :** Phase 1 Foundation (tenancy, auth, CRUD Behandler/Leistungen/Sprechzeiten/Abwesenheiten) — mergée dans `main`.
**Spec parente :** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md` (§5.2, §6.1, §6.3, §9).

---

## 1. Résumé

Le moteur de réservation côté **backend uniquement**, testé headless. Permet à un parent/tuteur de réserver un rendez-vous pour son enfant via une API publique, sans compte, avec garantie anti-double-booking et annulation par lien.

**Inclus :** calcul de créneaux (`AvailabilityCalculator`), modèle `appointments` (schéma tenant), API publique `/api/v1/widget/{slug}/*`, réservation avec verrou pessimiste, annulation par token.

**Exclus (plans ultérieurs) :** widget Vue embarquable + plugin WordPress · calendrier dans le dashboard admin · notifications email (confirmation/rappel/annulation) · anonymisation 90j + audit log (plan Production).

## 2. Décisions figées (brainstorming)

| Décision | Choix |
|---|---|
| Grille de créneaux | **Alignée sur la durée du service** (service 30 min → 09:00, 09:30, 10:00…). Pas de chevauchement entre options. |
| Statut à la réservation | **Confirmé automatiquement** (`status = confirmed`). Pas de workflow d'approbation cabinet. |
| Choix du praticien | **Praticien précis** (parent choisit service → praticien → créneau). Pas de « sans préférence ». |
| Fenêtre de réservation | **Délai minimum 2h** avant le créneau, **horizon 60 jours**. Valeurs **codées en dur** (constantes), pas de config par cabinet. |
| Reschedule | **Hors scope** — annuler puis re-réserver. |
| Cardinalité | **1 RDV = 1 enfant** (re-réserver pour la fratrie). |
| `patient_birthdate` | **Requis**. |
| Anti-spam | Rate-limit par IP + **honeypot** (pas de captcha). |

## 3. Architecture & composants

Cinq unités à responsabilité unique :

1. **`App\Services\Tenant\AvailabilityCalculator`** — service pur : `(Practitioner, Service, CarbonPeriod) → Collection<Slot>`. Aucune dépendance HTTP. Testable isolément. C'est le risque #1 → couverture lourde.
2. **`App\Models\Tenant\Appointment`** + migration tenant `create_appointments_table`.
3. **API publique** sous `routes/api.php`, groupe `/api/v1/widget/{slug}/*`, identifiée tenant **par chemin** (≠ admin par sous-domaine). Controllers fins :
   - `WidgetServiceController` (services, praticiens d'un service)
   - `WidgetSlotController` (créneaux)
   - `WidgetAppointmentController` (réservation)
   - `AppointmentCancellationController` (consultation + annulation par token)
4. **Middleware d'identification tenant par chemin** — `InitializeTenancyByPath` (stancl) ou identification par segment `{slug}`. CORS activé pour le domaine du cabinet.
5. **`StoreAppointmentRequest`** — validation (champs enfant/parent, consentement, honeypot).

### Identification tenant par chemin (point d'architecture)
Le widget tourne sur le site WordPress du cabinet (ex. `kidsclub-zacp.de`) et appelle notre API en **cross-origin**. Le tenant ne peut donc pas venir du sous-domaine ; il vient du **chemin** (`/api/v1/widget/kidsclub/...`). On ajoute le bootstrapper/middleware d'identification par path. Le `SearchPathBootstrapper` de la Phase 1 reste le mécanisme de bascule de schéma (runtime `SET search_path`), inchangé.

## 4. Modèle de données — `appointments` (schéma tenant)

```
appointments
├── id                    UUID (primary)
├── practitioner_id       FK practitioners, cascadeOnDelete, index
├── service_id            FK services, cascadeOnDelete
├── starts_at             timestamp
├── ends_at               timestamp
├── status                string  -- pending | confirmed | cancelled | completed | no_show ; défaut 'confirmed'
├── patient_first_name    string  (enfant)
├── patient_last_name     string  (enfant)
├── patient_birthdate     date    (enfant — donnée médicale sensible, requis)
├── parent_first_name     string
├── parent_last_name      string
├── parent_email          string
├── parent_phone          string  nullable
├── parent_consent_at     timestamp (consentement parental DSGVO, requis)
├── notes_parent          text    nullable
├── notes_internal        text    nullable (jamais exposé au parent)
├── cancellation_token    UUID    unique
└── timestamps
```
Index composite `(practitioner_id, starts_at)` pour accélérer les requêtes de chevauchement.

## 5. `AvailabilityCalculator` — algorithme

**Entrées :** `Practitioner $practitioner`, `Service $service` (→ `duration_minutes`), plage `[from, to]`.
**Bornage :** `from = max(now + 2h, from)` ; `to = min(now + 60 jours, to)`.

Pour chaque jour de la plage :
1. Récupérer les `availabilities` du praticien pour ce `day_of_week`, valides ce jour (`valid_from`/`valid_to` nullables).
2. Générer les créneaux candidats par pas de `duration_minutes` dans `[start_time, end_time]` tant que `slot_start + duration ≤ end_time`.
3. Retirer les créneaux chevauchant une `availability_exception` (`vacation|sick|block`) du praticien.
4. Retirer les créneaux chevauchant un `appointment` existant du praticien (statuts `pending|confirmed`).
5. Retirer les créneaux commençant avant `now + 2h`.

**Sortie :** créneaux libres groupés par date (DTO `Slot { starts_at, ends_at }`).

**Cas limites à tester :** bornes de journée (créneau qui finit pile à `end_time`) · exception partielle vs totale sur la journée · RDV existants dos-à-dos · coupure du délai 2h en milieu de journée · horizon 60j respecté · jour sans dispo · plage vide · praticien sans dispo.

## 6. API publique

| Méthode & route (préfixe `/api/v1/widget/{slug}`) | Rôle |
|---|---|
| `GET /services` | services actifs du cabinet |
| `GET /services/{service}/practitioners` | praticiens actifs faisant ce service |
| `GET /slots?practitioner_id&service_id&from&to` | créneaux libres (`AvailabilityCalculator`) |
| `POST /appointments` | réservation (verrou pessimiste) |
| `GET /appointments/{token}` | détail RDV (pour page d'annulation) |
| `POST /appointments/{token}/cancel` | annulation |

- **Tenant** résolu via `{slug}` (middleware path).
- **CORS** restreint au domaine déclaré du cabinet (config tenant ; en MVP, ouvert au domaine principal du tenant).
- **Rate-limit** : ~20 lectures/min/IP ; 5 réservations/min/IP.
- **Honeypot** : champ caché sur le POST ; si rempli → rejet silencieux (200 sans création).
- **Pas d'auth** (réservation anonyme). Aucune donnée patient retournée par les endpoints de lecture publics (services/praticiens/slots ne contiennent pas de PII).
- **Format = JSON** sur toute l'API (consommée cross-origin par le widget) : `POST /appointments` renvoie `201` avec `{ cancellation_token, starts_at, ... }` ; conflit `409` ; validation `422` ; rate-limit `429` ; slug inconnu `404`.
- **Consentement** : l'entrée est un booléen `consent` (case cochée), validé `accepted` ; le backend stocke `parent_consent_at = now()`. Le champ DB est donc toujours un timestamp, jamais un booléen.

## 7. Réservation — concurrence

```php
DB::transaction(function () use ($data) {
    $conflict = Appointment::query()
        ->where('practitioner_id', $data['practitioner_id'])
        ->where('starts_at', '<', $data['ends_at'])
        ->where('ends_at', '>', $data['starts_at'])
        ->whereIn('status', ['pending', 'confirmed'])
        ->lockForUpdate()      // sur des LIGNES, jamais un agrégat (règle PostgreSQL)
        ->exists();

    abort_if($conflict, 409); // SlotTakenException

    // Re-validation défensive : le créneau est dans une dispo et hors exception.
    return Appointment::create($data + [
        'status' => 'confirmed',
        'cancellation_token' => (string) Str::uuid(),
    ]);
});
```
Conflit → `409` (le widget redemandera les créneaux). Le `lockForUpdate()` porte sur les lignes de RDV existantes, conformément à la règle PostgreSQL héritée.

## 8. Annulation

`cancellation_token` (UUID) généré à la création. Route publique scopée tenant :
- `GET /appointments/{token}` → renvoie date/heure/service (pour confirmation).
- `POST /appointments/{token}/cancel` → `status = cancelled`.
Le créneau redevient libre automatiquement (l'`AvailabilityCalculator` ignore les `cancelled`). L'email d'information au cabinet relève du plan Notifications.

## 9. DSGVO (dans le cœur)

- `parent_consent_at` **requis** (validé `true`, stocké en timestamp) — trace du consentement parental éclairé.
- `cancellation_token` → annulation sans login (moins de friction, moins de données).
- Endpoints de lecture publics **sans PII**.
- Le schéma supporte l'anonymisation et l'audit log, **implémentés au plan Production** (job 90j, `audit_logs`).

## 10. Stratégie de tests

- **Unitaires `AvailabilityCalculator` (≥30)** : tous les cas limites du §5.
- **Feature (suite `tenant`, via `TenantTestCase`, schémas réels)** :
  - réservation réussie (`POST` → 201 JSON, RDV créé, token généré, `status=confirmed`)
  - **double-booking bloqué** : deux réservations sur le même créneau → la 2ᵉ reçoit 409
  - consentement manquant → 422
  - honeypot rempli → pas de création
  - rate-limit dépassé → 429
  - annulation par token → `cancelled`, créneau redevient proposé par `slots`
  - **isolation cross-tenant** : un RDV créé sur le cabinet A n'apparaît pas dans les slots/lectures du cabinet B ; réserver via `/widget/cabinet-b` n'affecte jamais A.
- Identification tenant par chemin vérifiée (slug inconnu → 404).

## 11. Non-objectifs (rappel)

Widget Vue/Shadow DOM, plugin WordPress, calendrier FullCalendar, emails Postmark, anonymisation/audit DSGVO, statistiques, SMS — **tous hors Phase 2**, traités dans les plans suivants.

## 12. Critères d'acceptation

Un parent peut, via l'API publique d'un cabinet, lister les services → choisir un praticien → voir les créneaux libres → réserver pour son enfant (avec consentement) → l'annuler par token ; le tout avec isolation stricte entre cabinets et zéro double-booking sous concurrence. Suite `tenant` + `central` vertes.
