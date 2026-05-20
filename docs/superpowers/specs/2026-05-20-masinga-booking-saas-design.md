# Masinga Booking — SaaS de prise de rendez-vous médical multi-tenant

**Date** : 2026-05-20
**Auteur** : Mamadi Diarrisso
**Statut** : Design approuvé, prêt pour implémentation
**Premier client** : Kids Club by zacp (cabinet de dentisterie pédiatrique, Hamburg)

---

## 1. Résumé exécutif

Masinga Booking est un **SaaS multi-tenant** de prise de rendez-vous en ligne destiné aux cabinets médicaux et dentaires en Allemagne. Le premier client est **Kids Club by zacp**, un nouveau cabinet de dentisterie pédiatrique ouvrant en septembre 2026 à Hamburg. L'architecture est conçue dès le départ pour la revente à d'autres cabinets (modèle B2B SaaS), avec une isolation forte des données patient (DSGVO Art. 9 — données de santé = catégorie spéciale).

**Le produit est composé de trois éléments distincts :**
1. **Backend SaaS Laravel 11** multi-tenant (schema-per-tenant via `stancl/tenancy` v4)
2. **Widget de booking Vue 3** standalone embarquable sur n'importe quel site (Shadow DOM)
3. **Plugin WordPress** qui emballe le widget pour les cabinets sous WP (≈80 lignes PHP)

---

## 2. Objectifs et contraintes

### 2.1 Objectifs métier

- **Livrer le MVP fonctionnel pour Kids Club avant le 1er septembre 2026** (13 semaines de dev)
- **Permettre à n'importe quel parent de réserver un RDV en < 60 secondes** sans créer de compte
- **Construire un produit revendable** à d'autres cabinets dentaires en Allemagne (MRR cible 5-10 cabinets à 6 mois)
- **Conformité DSGVO Art. 9** stricte (données de santé d'enfants mineurs)

### 2.2 Contraintes techniques

- Stack imposé : Laravel + Inertia + Vue 3 (expertise interne)
- Hébergement **en Allemagne** ou UE (obligation DSGVO pour données médicales)
- Le widget public doit fonctionner sur n'importe quel CMS (pas seulement WordPress)
- Aucune donnée patient ne doit transiter par le site du cabinet (réduction surface d'attaque DSGVO)

### 2.3 Non-objectifs (explicitement hors scope MVP)

- ❌ SMS reminders (v1.1, ajoute coût ~0.07€/SMS via Twilio)
- ❌ Espace patient avec login (v1.1, le booking guest suffit au lancement)
- ❌ Billing Stripe automatisé (v1.1, contrats manuels pour les 3-5 premiers cabinets)
- ❌ Intégration PVS (Charly, Dampsoft, Z1, evident) — v2.0, certains PVS sans API publique
- ❌ Application mobile native — v3.0 si demande
- ❌ Téléconsultation — hors scope, ce n'est pas un produit médical
- ❌ Paiement en ligne du RDV — hors scope (les cabinets allemands sont remboursés via Krankenkasse)

---

## 3. Architecture haut niveau

```
┌──────────────────────────────────────────────────────────────────────┐
│  SITE WORDPRESS DU CABINET (Kids Club)                                │
│  Page "Termine"  →  [masinga_booking cabinet="kidsclub"]             │
│                          ↓                                            │
│                     Plugin WP injecte le widget JS                    │
└──────────────────────────────────────────────────────────────────────┘
                          ↓
┌──────────────────────────────────────────────────────────────────────┐
│  BOOKING WIDGET (Vue 3 + Shadow DOM, bundle ~180 KB gz)              │
│  Hébergé sur cdn.masinga-booking.de/widget.js                        │
│  Communique en JSON via CORS                                          │
└──────────────────────────────────────────────────────────────────────┘
                          ↓ API JSON HTTPS
┌──────────────────────────────────────────────────────────────────────┐
│  SAAS BACKEND (Laravel 11 + Inertia + Vue 3)                         │
│                                                                       │
│  CENTRAL DOMAIN           TENANT DOMAINS                              │
│  masinga-booking.de       kidsclub.masinga-booking.de                 │
│  • Marketing site         zacp.masinga-booking.de                     │
│  • SaaS admin             • Dashboard cabinet                         │
│  • Signup cabinets        • Calendrier RDV                            │
│  • API widget publique    • Config praticiens/services                │
│                                                                       │
│  PostgreSQL 16                                                        │
│  • public.* (tenants, domains, users, plans)                          │
│  • tenant_kidsclub.* (practitioners, appointments, services, ...)     │
│  • tenant_zacp.*                                                      │
└──────────────────────────────────────────────────────────────────────┘
                          ↓ Queue jobs (Redis + Horizon)
┌──────────────────────────────────────────────────────────────────────┐
│  SERVICES EXTERNES                                                    │
│  • Postmark (email transactionnel)                                    │
│  • Sentry (error tracking)                                            │
│  • Stripe (v1.1 — billing cabinets)                                   │
└──────────────────────────────────────────────────────────────────────┘
```

### 3.1 Pourquoi trois composants découplés ?

- **Strangler-Fig pattern dès le départ** : chaque composant peut être déployé, versionné et débuggé indépendamment
- Le widget peut être réécrit en Svelte plus tard pour gagner du bundle size sans toucher au backend
- Le plugin WordPress peut évoluer sans affecter les autres CMS supportés
- L'isolation facilite l'auditabilité DSGVO (chaque composant a un périmètre clair)

---

## 4. Multi-tenancy : Schema-per-tenant

### 4.1 Choix architectural

Utilisation de **`stancl/tenancy` v4** en mode **schema-per-tenant** (PostgreSQL).

**Pourquoi ce choix :**
- **Isolement DSGVO suffisant** : chaque cabinet a son schéma PostgreSQL isolé, impossible de lire les données d'un autre cabinet même avec un bug
- **Coût infra raisonnable** : 1 seule base PostgreSQL physique pour tous les cabinets (vs N bases en model database-per-tenant)
- **Migrations propres** : `stancl/tenancy` propage automatiquement les migrations à tous les schémas
- **Pas de risque cross-tenant** : impossible d'oublier un `WHERE cabinet_id = ?` car le routage SQL est automatique via `SET search_path`

**Trade-off accepté** : si un seul gros cabinet enterprise demande une isolation totale (database-per-tenant), c'est migrable plus tard.

### 4.2 Comment ça fonctionne en pratique

- **2 niveaux de routes** :
  - `routes/web.php` (central) : site marketing, signup, admin SaaS, API publique du widget
  - `routes/tenant.php` : dashboard cabinet, auth admin/praticien, CRUD entités

- **Identification par domaine** : `kidsclub.masinga-booking.de` → middleware lit le sous-domaine → trouve le tenant → switch sur le schéma `tenant_kidsclub` automatiquement

- **Migrations séparées** :
  - `database/migrations/` → tables centrales (`tenants`, `domains`, `users` admin SaaS, `subscriptions`)
  - `database/migrations/tenant/` → tables par cabinet (`practitioners`, `appointments`, `services`, `patients`)

- **Modèles tenant** : `App\Models\Tenant\Appointment::all()` lit automatiquement le schéma du tenant courant

- **Domaine custom plus tard** : un cabinet peut connecter son propre domaine (`termine.kidsclub-zacp.de`) qui pointe vers son schéma

---

## 5. Modèle de données

### 5.1 Tables centrales (schéma `public`)

```sql
tenants
├── id (UUID)
├── name              -- "Kids Club by zacp"
├── slug              -- "kidsclub"
├── status            -- active | suspended | trialing
├── plan_id (FK)
├── trial_ends_at
└── timestamps

domains
├── id
├── tenant_id (FK)
├── domain            -- "kidsclub.masinga-booking.de"
└── is_primary

users                 -- Admins SaaS (Mamadi) + admins cabinet (Michael)
├── id
├── email
├── password
├── role              -- super_admin | tenant_owner
├── tenant_id (nullable, pour tenant_owner)
└── timestamps

plans                 -- Pricing du SaaS
├── id
├── name              -- "Starter", "Pro", "Enterprise"
├── price_monthly
└── features (jsonb)  -- {"max_practitioners": 5, "sms": false, ...}

subscriptions         -- v1.1 quand Stripe arrive
```

### 5.2 Tables tenant (schéma `tenant_*`, dupliqué par cabinet)

```sql
practitioners                       -- Les dentistes du cabinet
├── id
├── first_name, last_name
├── title             -- "Dr.", "Zahnärztin"
├── email
├── avatar_url
├── color             -- pour le calendrier #FF6B6B
├── is_active
└── timestamps

services                            -- Types de soins proposés
├── id
├── name              -- "Erstuntersuchung Kind"
├── duration_minutes  -- 30
├── color             -- pour le calendrier
├── description
├── is_active
└── timestamps

practitioner_service                -- Quels praticiens font quels soins
├── practitioner_id (FK)
└── service_id (FK)

availabilities                      -- Disponibilités récurrentes
├── id
├── practitioner_id (FK)
├── day_of_week       -- 1=lundi ... 7=dimanche
├── start_time        -- 09:00
├── end_time          -- 17:00
└── valid_from, valid_to (nullable)

availability_exceptions             -- Vacances, jours fériés, exceptions
├── id
├── practitioner_id (FK)
├── starts_at, ends_at
├── type              -- vacation | sick | block
└── reason

appointments                        -- RDV pris
├── id (UUID)
├── practitioner_id (FK)
├── service_id (FK)
├── starts_at, ends_at
├── status            -- pending | confirmed | cancelled | completed | no_show
├── patient_first_name, patient_last_name
├── patient_birthdate -- DONNÉES MÉDICALES SENSIBLES (pédiatrie)
├── parent_first_name, parent_last_name
├── parent_email, parent_phone
├── parent_consent_at -- TIMESTAMP du consentement parental DSGVO
├── notes_parent      -- notes du parent à la réservation
├── notes_internal    -- notes du cabinet (non visibles au parent)
├── cancellation_token-- UUID pour annulation via lien email
└── timestamps
```

### 5.3 Considérations DSGVO sur le modèle

- **`patient_birthdate`** : donnée médicale sensible. Combinée avec `patient_name`, elle identifie un enfant.
- **`parent_consent_at`** obligatoire : trace du consentement éclairé du parent
- **`cancellation_token`** : permet l'annulation sans login (UX optimale + moins de friction DSGVO)
- **Anonymisation automatique** : job scheduled qui anonymise les `appointments` > 90 jours après date passée
- **Audit log** : table `audit_logs` dans le schéma central qui trace tous les accès lecture/écriture aux données patient

---

## 6. Flows utilisateur

### 6.1 Flow A : Parent réserve un RDV (60 secondes max)

```
1. Parent va sur kidsclub-zacp.de/termine
                          ↓
2. Plugin WP affiche le widget JS embedded
                          ↓
3. Widget appelle GET /api/v1/widget/kidsclub/services
   → Liste : "Erstuntersuchung", "Prophylaxe", "Notfall"...
                          ↓
4. Parent choisit un service → widget appelle
   GET /api/v1/widget/kidsclub/practitioners?service_id=3
                          ↓
5. Parent choisit praticien → widget appelle
   GET /api/v1/widget/kidsclub/slots?practitioner_id=2&service_id=3&date_range=...
   (backend calcule créneaux dispo : availabilities − exceptions − existing_appointments)
                          ↓
6. Parent voit calendrier, choisit créneau
                          ↓
7. Formulaire : nom enfant, date naissance, nom parent, email, tél, notes,
   ☐ consentement DSGVO (case décochée par défaut)
                          ↓
8. POST /api/v1/widget/kidsclub/appointments
   → Backend : verrou pessimiste, vérifie slot libre, crée appointment, envoie email
                          ↓
9. Page de succès + email de confirmation reçu
```

**Verrou pessimiste critique à l'étape 8** : deux parents peuvent cliquer simultanément sur le même créneau. Sans verrou = double booking.

```php
DB::transaction(function () use ($data) {
    $conflicts = Appointment::where('practitioner_id', $data['practitioner_id'])
        ->where('starts_at', '<', $data['ends_at'])
        ->where('ends_at', '>', $data['starts_at'])
        ->whereIn('status', ['pending', 'confirmed'])
        ->lockForUpdate()
        ->exists();

    if ($conflicts) throw new SlotTakenException();

    Appointment::create($data);
});
```

Rappel : `lockForUpdate()` sur des **lignes**, jamais sur un agrégat (règle PostgreSQL héritée des projets antérieurs).

### 6.2 Flow B : Cabinet gère son agenda

```
Michael (admin Kids Club) → kidsclub.masinga-booking.de/login
                                ↓
                Stancl identifie le tenant via subdomain
                                ↓
                Auth Inertia (email + password via Fortify)
                                ↓
                Dashboard avec sidebar :
                ├── 📅 Kalender (vue jour/semaine/mois multi-praticiens)
                ├── 👨‍⚕️ Behandler (CRUD)
                ├── 🦷 Leistungen (CRUD services)
                ├── ⏰ Sprechzeiten (config availabilities)
                ├── 🏖️  Abwesenheiten (vacations/exceptions)
                ├── 📊 Statistiken (v1.1)
                └── ⚙️  Einstellungen
```

### 6.3 Flow C : Annulation par le parent

```
Parent reçoit email de confirmation avec lien :
https://kidsclub.masinga-booking.de/annulieren/{cancellation_token}
                                ↓
Page publique (pas d'auth) : "Möchten Sie Ihren Termin am ... wirklich absagen?"
                                ↓
Confirmation → UPDATE appointment SET status='cancelled'
                                ↓
Email envoyé au cabinet pour information
                                ↓
Le créneau redevient disponible automatiquement
```

---

## 7. Intégration WordPress (point critique)

Le plugin WordPress est un **micro-plugin (≈80 lignes PHP)** qui ne contient aucune logique métier. Toute la complexité vit dans le widget Vue.

### 7.1 Structure du plugin

```
masinga-booking/
├── masinga-booking.php             ← fichier principal (header, init)
├── includes/
│   ├── class-shortcode.php         ← gère [masinga_booking]
│   ├── class-block.php             ← bloc Gutenberg
│   └── class-settings.php          ← page d'admin WP
├── assets/
│   ├── admin.css
│   └── block-editor.js
├── readme.txt
└── languages/
    └── masinga-booking-de_DE.po
```

### 7.2 Le fichier principal (≈30 lignes)

```php
<?php
/**
 * Plugin Name: Masinga Booking
 * Description: Online-Terminbuchung für Arztpraxen
 * Version: 1.0.0
 * Text Domain: masinga-booking
 */

defined('ABSPATH') || exit;

define('MASINGA_BOOKING_VERSION', '1.0.0');
define('MASINGA_BOOKING_WIDGET_URL', 'https://cdn.masinga-booking.de/widget.js');
define('MASINGA_BOOKING_PATH', plugin_dir_path(__FILE__));

require_once MASINGA_BOOKING_PATH . 'includes/class-settings.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-shortcode.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-block.php';

add_action('plugins_loaded', function () {
    new Masinga_Booking_Settings();
    new Masinga_Booking_Shortcode();
    new Masinga_Booking_Block();
});
```

### 7.3 Le shortcode

```php
class Masinga_Booking_Shortcode {
    public function __construct() {
        add_shortcode('masinga_booking', [$this, 'render']);
    }

    public function render($atts) {
        $atts = shortcode_atts([
            'cabinet' => get_option('masinga_booking_slug', ''),
            'mode'    => 'inline',  // inline | button | modal
            'service' => null,
            'height'  => '720px',
        ], $atts, 'masinga_booking');

        if (empty($atts['cabinet'])) {
            return '<p>⚠️ Masinga Booking: bitte konfigurieren Sie den Cabinet-Slug.</p>';
        }

        wp_enqueue_script(
            'masinga-booking-widget',
            MASINGA_BOOKING_WIDGET_URL,
            [],
            MASINGA_BOOKING_VERSION,
            true
        );

        return sprintf(
            '<div data-masinga-booking="%s" data-mode="%s" data-service="%s" style="min-height:%s"></div>',
            esc_attr($atts['cabinet']),
            esc_attr($atts['mode']),
            esc_attr($atts['service']),
            esc_attr($atts['height'])
        );
    }
}
```

Utilisation côté éditeur WP :
```
[masinga_booking]                               -- inline simple
[masinga_booking mode="button" service="3"]    -- bouton qui ouvre modal
```

### 7.4 Distribution du plugin

| Méthode | Pour Kids Club | Pour autres clients |
|---|---|---|
| ZIP envoyé par email | ✅ OK premier client | ❌ Pas scalable |
| Auto-update via wp-admin (plugin update server custom) | ⚠️ Setup initial | ✅ Push update à tous |
| Submission au wordpress.org repository | ❌ Inutile au début | ✅ Visibilité massive |

**Décision** : commencer par ZIP, monter un plugin update server custom dès 3+ clients.

### 7.5 Point critique DSGVO

**Aucune donnée patient ne transite par WordPress.** Le `<div data-masinga-booking="kidsclub">` est juste un marker vide. Toute la conversation parent ↔ SaaS se fait directement via le widget JS → API REST sur `masinga-booking.de`.

Avantages :
1. WordPress ne stocke jamais de données de santé → réduction drastique des obligations DSGVO côté cabinet
2. Argument commercial fort : *"Vos données médicales ne quittent jamais notre infrastructure certifiée"*
3. Si le site WordPress du cabinet est compromis, les données patient restent intactes

---

## 8. Stack technique consolidé

| Couche | Choix | Justification |
|---|---|---|
| **Backend** | Laravel 11 | Maîtrise, écosystème mature |
| **Multi-tenant** | `stancl/tenancy` v4 | Standard de facto, schema-per-tenant |
| **Database** | PostgreSQL 16 | Schémas natifs, robust pour multi-tenant |
| **Frontend dashboard** | Inertia + Vue 3 + shadcn-vue + Tailwind | Stack confort + design system pro |
| **Calendar component** | FullCalendar v6 (commercial ~480€/an) | Standard pro, drag-to-reschedule natif |
| **Widget public** | Vue 3 + Shadow DOM (Vite bundle standalone) | Isolation CSS totale, ~150KB gz |
| **Plugin WordPress** | PHP simple, ~80 lignes | Wrapper minimal du widget JS |
| **Auth** | Laravel Fortify | Pas de social login au MVP |
| **Queue jobs** | Redis + Horizon | Emails async, anonymisation périodique |
| **Email** | Postmark | Délivrabilité excellente, 100 emails/mois gratuits |
| **Error tracking** | Sentry (free tier) | Indispensable production |
| **Hosting** | Hetzner Cloud CX22 (4€/mois) | Allemagne, DSGVO-friendly |
| **CDN widget** | Bunny.net ou Cloudflare R2 | Hébergement widget.js |
| **Domain** | `masinga-booking.de` (à acheter) | Domaine de marque |

---

## 9. Conformité DSGVO

### 9.1 Mesures techniques

- **Hébergement Allemagne** (Hetzner Falkenstein/Nuremberg)
- **TLS 1.3** partout, HSTS, certificats Let's Encrypt
- **Chiffrement au repos** : PostgreSQL avec disque chiffré (Hetzner ENCRYPT)
- **Audit log** de tous les accès aux données patient (table `audit_logs` centrale)
- **Anonymisation automatique** des RDV > 90 jours après date passée
- **Cancellation token** : pas de login parent requis = moins de données stockées

### 9.2 Mesures organisationnelles

- **Impressum, Datenschutzerklärung, AGB** sur masinga-booking.de
- **Auftragsverarbeitungsvertrag (AVV)** signé avec chaque cabinet client
- **Document de finalité** : "données collectées uniquement pour la gestion du RDV, durée 90 jours"
- **Procédure de demande Auskunft (Art. 15)** : endpoint d'export des données d'un patient sur demande
- **Procédure de suppression (Art. 17)** : endpoint de suppression complète

### 9.3 Consentement parental

Pour le consentement parental d'un enfant mineur :
- Case à cocher **non pré-cochée** dans le formulaire de booking
- Texte explicite : *"Ich (Erziehungsberechtigte/r) willige in die Verarbeitung der angegebenen Daten meines Kindes zum Zweck der Terminvereinbarung ein. Mehr Infos in der Datenschutzerklärung."*
- Timestamp `parent_consent_at` enregistré pour preuve

---

## 10. Roadmap d'implémentation

Hypothèse : démarrage 1er juin 2026, Kids Club ouvre 1er septembre 2026 = **13 semaines**.

```
S1-2 (juin 1-14)  : Setup projet + tenancy + auth
  ├── Laravel 11 + Inertia + Vue 3 + Tailwind + shadcn-vue
  ├── Install stancl/tenancy + config schemas
  ├── Migration central : tenants, domains, users, plans
  ├── Routes central + tenant
  └── Fortify auth tenant

S3-4 (juin 15-28) : CRUD entités tenant
  ├── Models : Practitioner, Service, Availability, Exception
  ├── Pages Vue : Behandler index/create/edit, Leistungen, Sprechzeiten
  ├── Migrations tenant
  └── Tests Feature

S5-6 (juin 29 - juil 12) : Moteur de slots + booking widget v1
  ├── Service AvailabilityCalculator (algorithme central)
  ├── API publique /api/v1/widget/{slug}/* (CORS)
  ├── Widget Vue 3 standalone avec Shadow DOM
  ├── Bundle Vite + déploiement CDN
  └── Plugin WordPress wrapper

S7-8 (juil 13-26)  : Dashboard calendar + booking flow complet
  ├── Intégration FullCalendar dans dashboard
  ├── Drag-to-reschedule, click-to-cancel
  ├── Verrou pessimiste sur création RDV
  └── Page succès + token annulation

S9 (juil 27 - août 2)  : Notifications email
  ├── Postmark setup
  ├── Mails : confirmation, rappel 24h (queue), annulation
  ├── Job Scheduled : send_reminders (toutes les heures)
  └── Templates emails (DE)

S10 (août 3-9)     : DSGVO compliance
  ├── Cookie banner, Impressum, Datenschutzerklärung
  ├── Job anonymize_old_appointments (90 jours)
  ├── Audit log des accès données patient
  └── Export DSGVO (Auskunftsrecht)

S11 (août 10-16)   : QA + tests + bugfix
  ├── Tests E2E Playwright (booking flow, dashboard)
  ├── Tests charge (50 RDV/jour simulés)
  ├── Pen test basique (OWASP top 10)
  └── Compatibilité Chrome/Safari/Firefox/iOS Safari

S12 (août 17-23)   : Deploy production + onboarding Kids Club
  ├── Hetzner setup, Nginx, PostgreSQL, Redis
  ├── Domaine + SSL Let's Encrypt
  ├── Création tenant kidsclub.masinga-booking.de
  └── Formation Michael (1h call)

S13 (août 24-31)   : Buffer + lancement soft
  └── Tests réels avec patients pilotes avant ouverture publique

🚀 1er septembre 2026 : Kids Club ouvre. Booking en ligne ACTIF.
```

---

## 11. Risques et mitigations

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Sous-estimation `AvailabilityCalculator` | Élevée | Élevé | Prévoir 1 semaine entière (S5) + 30+ tests unitaires edge cases |
| Photos/vidéos Kids Club livrées en retard | Moyenne | Faible | Le widget fonctionne sans : on lance avec placeholder |
| Conflit OPcache après deploy Laravel | Moyenne | Moyen | Checklist obligatoire post-deploy : `systemctl restart php8.3-fpm` |
| Pic de bookings simultanés (concurrence) | Faible | Élevé | Verrou pessimiste + tests de charge avec 100 concurrent users |
| Bug cross-tenant (fuite données entre cabinets) | Faible | **Critique** | Tests d'intégration multi-tenant + audit code review obligatoire avant chaque PR |
| Délai Stancl tenancy + Inertia inconnu | Moyenne | Moyen | POC d'intégration en S1 avant tout autre dev |

---

## 12. Modèle économique cible (post-MVP)

**Plans envisagés (à valider avec Kids Club + marché) :**

| Plan | Cible | Prix/mois | Praticiens | Features |
|---|---|---|---|---|
| **Starter** | Cabinet 1 médecin | 29 € | 1 | Booking, email, 1 domaine |
| **Pro** | Petit cabinet | 79 € | 5 | + SMS, domaine custom, statistiques |
| **Business** | Cabinet multi-spécialités | 149 € | 15 | + multi-langues, support prio |
| **Enterprise** | Chaîne / groupe médical | sur devis | illimité | + intégration PVS, SSO, SLA |

**Pour Kids Club** : tarif preferred-customer (early adopter) à définir avec Michael, idéalement 0€/mois la 1ère année en échange de feedback intensif + droit d'utiliser le logo Kids Club en référence commerciale.

---

## 13. Questions ouvertes

1. **Nom de domaine commercial** : `masinga-booking.de` est une hypothèse. Alternative : `terminelfen.de`, `medibook.de`, `praxistermine.de` ?
2. **Licence FullCalendar Premium** (~480€/an) ou alternative gratuite (FullCalendar Standard, Schedule-X) ?
3. **Hetzner vs Hostinger** : tu connais Hostinger sur tes autres projets. Hetzner offre des datacenters allemands certifiés ISO 27001 — meilleur pour pitch DSGVO médical.
4. **Plugin update server** : utiliser un service tiers (Freemius) ou build custom dès maintenant ?
5. **Tarification Kids Club** : 0€ en échange de référence ou prix réduit (49€/mois) ?

---

## 14. Validation

- [x] Multi-tenancy : Schema-per-tenant via stancl/tenancy v4
- [x] MVP scope : Booking widget + Praticiens + Dashboard + Emails
- [x] Frontend stack : Inertia + Vue 3 + shadcn-vue + Tailwind
- [x] Embedding : JS Widget (Shadow DOM) + WordPress Plugin
- [x] Intégration WordPress détaillée

**Statut** : design approuvé par le porteur du projet, prêt pour la phase d'implémentation plan.
