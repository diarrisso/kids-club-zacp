# Mail d'annulation au parent — Design

**Date** : 2026-06-08
**Statut** : Validé (à implémenter)
**Auteur** : Mamadi Diarrisso (+ Claude)
**Contexte** : Kids Club by zacp — single-tenant booking (Laravel 13 · PostgreSQL · prod sur `kidsclub.masingatech.com`)

---

## 1. Problème

Quand un rendez-vous est annulé, **seul le cabinet** reçoit un e-mail (`AppointmentCancelledMail` via `CabinetNotifier::notifyCancelled` → `PRACTICE_NOTIFICATION_EMAIL`). **Le parent ne reçoit rien** — ni confirmation quand c'est lui qui annule, ni avis quand c'est le cabinet qui annule.

Comportement actuel (vérifié dans le code) :

| Qui annule | Cabinet notifié | Parent notifié |
|------------|:---:|:---:|
| Parent (lien `/storno/{token}`) | ✅ | ❌ |
| Widget (API `POST /appointments/{token}/cancel`) | ✅ | ❌ |
| Staff (`DELETE /termine/{appointment}`) | ❌¹ | ❌ |

¹ *Le staff EST le cabinet : il ne se notifie pas lui-même. Code MVP : « le cabinet gère la communication directement ».*

Le cas le plus critique est l'annulation **par le cabinet** : si la praticienne est absente, le parent **doit** savoir que son RDV n'aura pas lieu — or aujourd'hui il n'est jamais prévenu.

---

## 2. Décision

**Le parent est notifié par e-mail sur TOUS les chemins d'annulation** (parent, widget, staff). L'alerte cabinet existante reste inchangée.

État cible :

| Chemin | Cabinet (alerte interne) | Parent (confirmation) |
|--------|:---:|:---:|
| Parent / widget | ✅ inchangé | ✅ **nouveau** |
| Staff (`/termine`) | ❌ inchangé | ✅ **nouveau** |

---

## 3. Composants

### 3.1 Mailable `AppointmentCancelledParentMail`

Nouveau, **distinct** de `AppointmentCancelledMail` (qui reste l'alerte interne cabinet). Orienté parent :
- `ShouldQueue` (comme les autres mailables ; en prod `QUEUE_CONNECTION=sync` → envoi synchrone, cf. [[email-queue-strategy]]).
- Reçoit l'`Appointment` + `config('app.name')`.
- Expose au template : date/heure (timezone clinique `Europe/Berlin`), praticien, service, nom du cabinet.
- **Aucune donnée interne** (`notes_internal`, etc.) — uniquement ce que le parent doit voir.

### 3.2 Template `resources/views/emails/cancelled-parent.blade.php`

Allemand, orienté parent : confirmation que le RDV est annulé.
- Sujet (via Envelope) : `{cabinet} — Ihr Termin wurde storniert`.
- Corps : « Ihr Termin am {date} um {heure} Uhr ({service}, {praticien}) wurde storniert. » + invitation à reprendre RDV si besoin.
- Un **seul** template couvre les deux cas (parent-initiated et staff-initiated) — wording neutre « wurde storniert » valable quelle que soit la source (YAGNI : pas de variante).

### 3.3 Notifier `ParentNotifier`

Nouveau, **symétrique** à `App\Support\CabinetNotifier` :
- `notifyCancelled(Appointment $appointment): void`.
- **No-op si `parent_email` est vide** — le champ est nullable (réservations manuelles staff sans e-mail parent).
- `rescue()`-wrappé : un échec de push (file/SMTP) ne doit jamais faire échouer l'annulation côté utilisateur.

```php
public static function notifyCancelled(Appointment $appointment): void
{
    $email = $appointment->parent_email;
    if (! $email) {
        return;
    }

    rescue(fn () => Mail::to($email)->queue(
        new AppointmentCancelledParentMail($appointment, config('app.name'))
    ));
}
```

### 3.4 Câblage dans les 3 chemins d'annulation

Appel **après le commit** (même position que `CabinetNotifier::notifyCancelled`, et seulement quand une **vraie** annulation a eu lieu — `$cancelled` non-null, pour ne pas renvoyer de mail sur une annulation idempotente déjà-annulée) :

1. **`Public\CancellationPageController::cancel`** (storno parent) — ajouter `ParentNotifier::notifyCancelled($cancelled)` à côté de l'appel cabinet existant.
2. **`Widget\CancellationController::cancel`** (API widget) — idem.
3. **`Tenant\AppointmentController::destroy`** (staff) — aujourd'hui ne notifie personne ; ajouter `ParentNotifier::notifyCancelled($appointment)` quand le statut bascule à `cancelled`.

> ⚠️ Dans `destroy`, ne notifier le parent **que** lors d'une transition réelle vers `cancelled` (le code actuel ne `update` que si `status !== 'cancelled'`) — pas de mail si le RDV était déjà annulé.

---

## 4. Comportement / invariants

- **Idempotence** : annuler un RDV déjà `cancelled` → aucun nouveau mail (ni parent ni cabinet).
- **`parent_email` null** → aucun mail parent (no-op silencieux), l'annulation réussit quand même.
- **Alerte cabinet** : inchangée — toujours envoyée sur parent/widget, jamais sur staff.
- **Découplage envoi/échec** : `rescue()` garantit qu'une panne mail ne casse jamais l'annulation.

---

## 5. Tests (TDD, Pest + `Mail::fake()`)

Nouveau fichier `tests/Feature/TenantSchema/ParentCancellationMailTest.php` :

1. Annulation via **storno parent** → `AppointmentCancelledParentMail` envoyé à `parent_email` **et** `AppointmentCancelledMail` au cabinet.
2. Annulation via **widget API** → `AppointmentCancelledParentMail` au parent.
3. Annulation via **staff `destroy`** → `AppointmentCancelledParentMail` au parent (et **pas** d'alerte cabinet).
4. RDV **sans `parent_email`** annulé → `assertNotQueued(AppointmentCancelledParentMail)` ; l'annulation réussit (statut `cancelled`).
5. Annulation **idempotente** (déjà `cancelled`) → `assertNotQueued(AppointmentCancelledParentMail)`.

Tests existants à préserver/ajuster : `CancellationPageTest`, `WidgetCancellationTest`, `CabinetNotifierTest`, `CancelAppointmentTest` (vérifier qu'ils n'assument pas « aucun autre mail »).

Rendu : un test léger que le template `cancelled-parent` se rend sans erreur (cf. `AppointmentMailRenderTest` existant pour le pattern).

---

## 6. Hors scope (YAGNI)

- Pas de wording différencié staff-vs-parent (un template neutre suffit).
- Pas de SMS, pas de notification push.
- Pas de notification sur **reschedule** (déplacement de RDV) — autre feature.
- Pas de refonte de `CabinetNotifier` (on ajoute `ParentNotifier` en parallèle, sans toucher l'existant).

---

## 7. Workflow de livraison

Branche `feature/parent-cancellation-email` → TDD (mailable + notifier + template, tests d'abord) → revue de code (sécurité : pas de fuite `notes_internal` dans le mail parent ; destinataires corrects) → vérif rendu → PR + CodeRabbit (autofix) → merge → **déploiement sur « deploy » explicite** (le code passe par le pipeline ; aucune étape serveur manuelle requise cette fois — pas de changement `.env`/cron).

---

## 8. Risques & mitigations

| Risque | Mitigation |
|--------|------------|
| Fuite de données internes dans le mail parent | Le mailable n'expose que date/praticien/service/cabinet ; revue de code dédiée |
| Double mail au parent sur double-clic d'annulation | Notifier appelé seulement sur transition réelle (`$cancelled` non-null) |
| Mail parent envoyé à une adresse vide → exception | Guard `if (! $email) return;` |
| Panne SMTP casse l'annulation | `rescue()` (déjà le pattern de `CabinetNotifier`) |
