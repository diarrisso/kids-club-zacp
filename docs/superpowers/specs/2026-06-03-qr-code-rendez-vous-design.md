# Spec — QR Code de prise de rendez-vous

**Date :** 2026-06-03
**Statut :** Validé (brainstorming) — prêt pour le plan d'implémentation
**Auteur :** Mamadi Diarrisso (+ Claude)

## 1. Objectif

Permettre à un parent de **scanner un QR code** (affiché en salle d'attente, sur un
flyer, une carte de visite, ou intégré dans un email/newsletter) pour ouvrir
**directement sur son téléphone la page de prise de rendez-vous** et réserver via le
widget existant.

Le QR doit être **généré côté serveur** afin d'être **réutilisable partout** via une URL
d'image publique stable (`<img src>` dans un email, téléchargement PNG/SVG pour
l'impression).

### Hors scope (décidé explicitement)
- ❌ Aucune injection automatique du QR dans les emails existants (confirmation,
  rappel, annulation). Le QR reste une image publique que le cabinet colle où il veut.
- ❌ Pas de QR « dynamique » par service / praticien / créneau. Le QR encode **une URL
  fixe** (la page de réservation). YAGNI.
- ❌ Pas de logo central / personnalisation graphique avancée en v1 (extension possible
  plus tard, `endroid` le supporte).

## 2. Contexte & contraintes du codebase

- **Mono-tenant** : un seul cabinet, une seule base. (Les noms `Tenant\*` sont vestigiaux,
  cf. `CLAUDE.md` — ne pas s'y fier comme indice de multi-tenancy.)
- **La prise de rendez-vous se fait via le widget embarqué** (`[data-masinga-booking
  data-api=...]`), en pratique sur la **page WordPress** du cabinet. Il n'existe
  aucune page de réservation publique côté Laravel — uniquement `Central/Landing`
  (vitrine). → **Le QR pointe vers l'URL de la page WordPress de réservation.**
- **Aucun mécanisme de réglages n'existe** (pas de table `settings`/`options`). Comme
  l'URL cible doit être éditable depuis l'admin sans redéploiement, elle doit être
  **persistée en base**.
- **Conventions** : URLs en allemand, noms de routes en anglais ; jamais d'URL hardcodée
  (toujours `route('name')`) ; validation dans des Form Requests ; PostgreSQL ;
  tests Pest 4.
- **Nav staff** : `resources/js/Layouts/TenantLayout.vue` contient un tableau `nav`
  (Dashboard, Termine, Behandler, Leistungen, Sprechzeiten, Abwesenheiten) — on y
  ajoutera l'entrée QR-Code.

## 3. Approche retenue

**Approche B — génération côté serveur** avec la librairie PHP **`endroid/qr-code` (v6)**
(maintenue, génère PNG via GD + SVG, supporte un logo central pour une éventuelle v2).

Alternative écartée : `simplesoftwareio/simple-qrcode` (plus ancienne, PNG nécessite
l'extension imagick). Service tiers (api.qrserver.com) écarté : fuite de l'URL vers un
tiers + dépendance réseau, contraire à l'esprit offline-first.

> **Pourquoi serveur plutôt que client** : le cabinet veut **un seul** QR réutilisable
> dans les emails ET à l'impression. Une URL d'image serveur est référençable tel quel
> partout ; une génération navigateur obligerait à régénérer/exporter à chaque usage.

## 4. Architecture

```
┌─ Admin Laravel (auth staff) ──────────────┐        ┌─ Public (parents) ─────────┐
│ Page « QR-Code » (Inertia/Vue)            │        │ GET /termin-qrcode.svg|png │  ← image publique
│  • champ « URL page de réservation »      │        │   rend le QR de l'URL       │     (réutilisable email)
│  • aperçu live  • download PNG / SVG       │───┐    │   stockée, mise en cache    │
│  • « copier l'URL image pour emails »      │   │    └────────────┬───────────────┘
└───────────────────────────────────────────┘   │                 │ encode
                  POST /termin-qr-code (save)    │                 ▼
                           ▼                      │        ┌─ URL configurée ─────────┐
                 ┌─ Setting (clé/valeur, caché) ──┴───────▶│ ex. https://cabinet.de/  │
                 │ booking_url                             │     rendez-vous          │
                 └─────────────────────────────────────────┴──────────────────────────┘
```

## 5. Composants & fichiers

### 5.1 Persistance — `Setting` (clé/valeur)
- **Migration** `create_settings_table` : `id`, `key` (string, **unique**), `value`
  (text, nullable), `timestamps`.
- **Modèle** `App\Models\Setting` avec API statique **mise en cache** :
  - `Setting::get(string $key, ?string $default = null): ?string`
  - `Setting::put(string $key, ?string $value): void` (invalide le cache de la clé).
- Clé utilisée en v1 : **`booking_url`**.
- *Note conception* : modèle placé hors du namespace `Tenant\*` car c'est une
  préférence applicative globale, pas une entité métier du domaine booking.

### 5.2 Endpoint image public — `QrCodeController@show`
- **Route** : `GET /termin-qrcode.{format}` où `format ∈ {png, svg}`
  (contrainte `->where('format', 'png|svg')`), nom `qr.image`.
- **Public** (pas d'auth) : n'expose qu'une URL non secrète (la page de réservation est
  déjà publique). Throttlé via un limiteur dédié `qr` (ex. 30/min) et mis en cache HTTP.
- Comportement :
  - Lit `Setting::get('booking_url')`.
  - Si non configurée → **404** (rien à encoder ; évite un QR cassé).
  - Sinon construit le QR avec `endroid/qr-code`, writer PNG ou SVG selon `{format}`.
  - Réponse : bon `Content-Type` (`image/png` / `image/svg+xml`) +
    `Cache-Control: public, max-age=...` (le QR change rarement).
- **Caching applicatif** : la chaîne encodée et l'image rendue peuvent être mises en
  cache (clé dérivée de `booking_url` + format) et invalidées quand le setting change.

### 5.3 Admin — page de configuration
- **Contrôleur** `App\Http\Controllers\Tenant\QrCodeSettingController` (distinct du public) :
  - `index()` → `Inertia::render('Tenant/QrCode', ['bookingUrl' => Setting::get('booking_url')])`,
    route `GET /termin-qr-code`, nom `tenant.qr.index`, middleware `auth`.
  - `update(StoreQrSettingRequest)` → `Setting::put('booking_url', ...)` + redirect
    back avec flash, route `POST /termin-qr-code`, nom `tenant.qr.update`, middleware `auth`.
- **Form Request** `App\Http\Requests\Tenant\StoreQrSettingRequest` :
  - `booking_url` → `required|url:http,https|max:2048` (bloque `javascript:`, etc.).
  - `prepareForValidation()` **trim** la valeur : une URL composée uniquement d'espaces
    devient `''` → rejetée par `required` (le contrôleur public re-garde avec `trim($url) === ''`).
- **Page Vue** `resources/js/Pages/Tenant/QrCode.vue` (layout `TenantLayout`,
  `<script setup lang="ts">`) :
  - Champ **URL de la page de réservation** (pré-rempli, `useForm`, POST vers
    `tenant.qr.update`).
  - **Aperçu live** : `<img :src="route('qr.image', { format: 'svg' })">` (rechargé après
    enregistrement).
  - Boutons **Télécharger PNG** / **Télécharger SVG** (liens vers `qr.image` avec
    `download`).
  - Champ lecture seule **« URL de l'image pour vos emails »** + bouton **copier**
    (URL absolue de `qr.image` au format png).
  - État vide : si aucune URL configurée, message d'invite + pas d'aperçu.
- **Nav** : ajouter `{ href: '/termin-qr-code', label: '🔳 QR-Code' }` au tableau `nav`
  de `TenantLayout.vue`.

### 5.4 Limiteur de débit
- Déclarer un limiteur `qr` dans `AppServiceProvider::boot` (cohérent avec les limiteurs
  `widget-read`, `widget-book`, `storno` existants), appliqué à la route image publique.

## 6. Flux de données

1. **Staff** ouvre `/termin-qr-code` → voit l'URL actuelle (ou champ vide) + aperçu.
2. Staff saisit l'URL de la page de réservation WordPress → **POST** → validation →
   `Setting::put('booking_url', …)` → cache invalidé → redirect + flash succès.
3. L'**aperçu** et les **téléchargements** pointent vers `GET /termin-qrcode.{format}`,
   qui encode l'URL stockée et renvoie l'image (cache HTTP).
4. **Parent** scanne le QR (imprimé ou en email) → son téléphone ouvre l'URL → page WP
   avec le widget → il prend rendez-vous (parcours existant inchangé).

## 7. Gestion des erreurs & cas limites
- **URL non configurée** → endpoint image renvoie **404** ; la page admin affiche un état
  vide invitant à saisir l'URL.
- **Format invalide** dans l'URL (`/termin-qrcode.gif`) → 404 via contrainte de route.
- **URL invalide soumise** (non http/https, trop longue) → rejet par le Form Request,
  message d'erreur sur le champ.
- **Extension GD absente** (PNG) → à vérifier en environnement ; SVG ne dépend pas de GD.
  Documenter le prérequis ; les tests couvriront le format effectivement disponible.

## 8. Sécurité
- Page de config + enregistrement : **auth staff** (middleware `auth`), CSRF via Inertia.
- Endpoint image **public** mais inoffensif : il n'encode qu'une URL publique non secrète.
  Throttle `qr` + cache pour éviter tout abus de génération.
- Validation stricte de l'URL (`url:http,https`) — empêche d'encoder un schéma dangereux.
- Aucune donnée patient n'entre en jeu (cohérent avec l'architecture : aucune donnée
  patient ne transite par WordPress ni par le QR).

## 9. Tests (Pest 4)
- **Image** : `GET /termin-qrcode.png` et `.svg` renvoient `200` + bon `Content-Type`
  quand `booking_url` est configurée.
- **404** : sans `booking_url`, l'endpoint renvoie `404`.
- **Format** : `GET /termin-qrcode.gif` → `404`.
- **Encodage** : le contenu encodé correspond bien à l'URL stockée (décodage ou
  vérification du payload selon writer ; au minimum, changer le setting change l'image).
- **Auth** : `GET /termin-qr-code` redirige un invité vers la connexion ; un staff
  connecté reçoit `200`.
- **Public** : `GET /termin-qrcode.svg` est accessible **sans** auth.
- **Validation** : `POST /termin-qr-code` avec une URL non-http est rejeté (erreur de
  validation) ; avec une URL valide, persiste et redirige.
- **Setting** : `Setting::put` puis `Setting::get` renvoie la valeur ; le cache est
  invalidé à l'écriture.

## 10. Documents de référence à mettre à jour (post-implémentation)
Conformément au workflow global :
- **Wireframe** (`public/wireframe.html`) — ajouter l'écran admin « QR-Code ».
- **Diagramme BDD** (`docs/database-diagram.html` + copie `public/`) — ajouter la table
  `settings`.
- **ProjectProgressService** — ajouter le module QR-Code (models / controllers / pages /
  migrations / tests).

## 11. Dépendances
- Ajout Composer : **`endroid/qr-code` ^6**.
- Vérifier la présence de l'extension **GD** (writer PNG) sur l'environnement local et
  de production.
