# QR Code de prise de rendez-vous — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Générer côté serveur un QR code pointant vers la page de réservation, exposé comme image publique réutilisable (PNG/SVG) et configurable depuis l'admin staff.

**Architecture :** Une table `settings` clé/valeur (cachée) stocke l'URL cible. Un endpoint public `GET /termin-qrcode.{png|svg}` rend le QR de cette URL via `endroid/qr-code`. Une page Inertia/Vue authentifiée permet au staff de saisir l'URL, prévisualiser et télécharger le QR.

**Tech Stack :** Laravel 13 · Inertia 2 · Vue 3 (`<script setup lang="ts">`) · `endroid/qr-code` v6 (GD présent) · Pest 4 · PostgreSQL.

**Conventions du repo (rappel) :**
- URLs en allemand, **noms de routes en anglais**, jamais d'URL hardcodée → `route('name')`.
- Validation dans des Form Requests.
- Tests Pest dans `tests/Feature/TenantSchema/` (feature, `RefreshDatabase` via `tests/Pest.php`) et `tests/Unit/`.
- Auth dans les tests : `$this->actingAs(\App\Models\User::factory()->create())`.
- Les noms `Tenant\*` sont vestigiaux (mono-tenant) — le modèle `Setting` est une préférence applicative, **hors** namespace `Tenant\*`.
- Lancer la suite : `composer test` (depuis `backend/`). Style : `vendor/bin/pint`.

**Note docs de référence :** wireframe / database-diagram / ProjectProgressService **n'existent pas** dans ce repo — aucune mise à jour de ces fichiers n'est requise (contrairement au workflow global qui ne s'applique que s'ils existent).

---

## Structure des fichiers

| Fichier | Responsabilité |
|---|---|
| `backend/database/migrations/2026_06_03_000001_create_settings_table.php` | Table `settings(key unique, value)` |
| `backend/app/Models/Setting.php` | Accès clé/valeur caché (`get`/`put`) |
| `backend/app/Support/QrCodeRenderer.php` | Rend une URL en image QR (PNG ou SVG) — wrappe endroid |
| `backend/app/Http/Controllers/QrCodeController.php` | Endpoint **public** image `GET /termin-qrcode.{format}` |
| `backend/app/Http/Controllers/Tenant/QrCodeSettingController.php` | Page admin (`index`) + enregistrement (`update`) |
| `backend/app/Http/Requests/Tenant/StoreQrSettingRequest.php` | Validation `booking_url` |
| `backend/resources/js/Pages/Tenant/QrCode.vue` | UI admin : champ URL, aperçu, downloads, copier |
| `backend/resources/js/Layouts/TenantLayout.vue` (modif) | Ajout entrée nav QR-Code |
| `backend/routes/web.php` (modif) | Routes image publique + admin |
| `backend/app/Providers/AppServiceProvider.php` (modif) | Limiteur `qr` |
| Tests Pest (plusieurs) | Voir tâches |

---

## Task 0 : Installer endroid/qr-code

**Files :**
- Modify: `backend/composer.json` (via composer)

- [ ] **Step 1 : Installer la dépendance**

Run (depuis `backend/`) :
```bash
composer require endroid/qr-code:^6
```
Expected : `endroid/qr-code` ajouté à `composer.json` / `composer.lock`, installation OK.

- [ ] **Step 2 : Vérifier l'extension GD (writer PNG)**

Run :
```bash
php -m | grep -i gd
```
Expected : affiche `gd` (déjà confirmé sur cet environnement).

- [ ] **Step 3 : Commit**

```bash
git add backend/composer.json backend/composer.lock
git commit -m "build: add endroid/qr-code v6 for server-side QR generation"
```

---

## Task 1 : Stockage clé/valeur `Setting` (caché)

**Files :**
- Create: `backend/database/migrations/2026_06_03_000001_create_settings_table.php`
- Create: `backend/app/Models/Setting.php`
- Test: `backend/tests/Unit/SettingTest.php`

- [ ] **Step 1 : Écrire le test (échoue)**

`backend/tests/Unit/SettingTest.php` :
```php
<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the default when the key is absent', function () {
    expect(Setting::get('booking_url', 'fallback'))->toBe('fallback');
});

it('persists and reads a value', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    expect(Setting::get('booking_url'))->toBe('https://cabinet.de/rendez-vous');
});

it('overwrites an existing value and invalidates the cache', function () {
    Setting::put('booking_url', 'https://old.de');
    Setting::put('booking_url', 'https://new.de');

    expect(Setting::get('booking_url'))->toBe('https://new.de');
});
```

- [ ] **Step 2 : Lancer le test (doit échouer)**

Run : `php artisan test --filter=SettingTest`
Expected : FAIL (`Class "App\Models\Setting" not found`).

- [ ] **Step 3 : Écrire la migration**

`backend/database/migrations/2026_06_03_000001_create_settings_table.php` :
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

- [ ] **Step 4 : Écrire le modèle**

`backend/app/Models/Setting.php` :
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value')
        );

        return $value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }
}
```

- [ ] **Step 5 : Lancer le test (doit passer)**

Run : `php artisan test --filter=SettingTest`
Expected : PASS (3 tests).

- [ ] **Step 6 : Commit**

```bash
git add backend/database/migrations/2026_06_03_000001_create_settings_table.php backend/app/Models/Setting.php backend/tests/Unit/SettingTest.php
git commit -m "feat(settings): cached key/value Setting store + migration"
```

---

## Task 2 : Service de rendu `QrCodeRenderer`

**Files :**
- Create: `backend/app/Support/QrCodeRenderer.php`
- Test: `backend/tests/Unit/QrCodeRendererTest.php`

- [ ] **Step 1 : Écrire le test (échoue)**

`backend/tests/Unit/QrCodeRendererTest.php` :
```php
<?php

use App\Support\QrCodeRenderer;

it('renders a PNG with the image/png mime type', function () {
    $result = (new QrCodeRenderer())->render('https://cabinet.de/rendez-vous', 'png');

    expect($result['mime'])->toBe('image/png')
        ->and($result['body'])->toStartWith("\x89PNG");
});

it('renders an SVG with the image/svg+xml mime type', function () {
    $result = (new QrCodeRenderer())->render('https://cabinet.de/rendez-vous', 'svg');

    expect($result['mime'])->toBe('image/svg+xml')
        ->and($result['body'])->toContain('<svg');
});
```

- [ ] **Step 2 : Lancer le test (doit échouer)**

Run : `php artisan test --filter=QrCodeRendererTest`
Expected : FAIL (`Class "App\Support\QrCodeRenderer" not found`).

- [ ] **Step 3 : Écrire le service**

`backend/app/Support/QrCodeRenderer.php` :
```php
<?php

namespace App\Support;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;

class QrCodeRenderer
{
    /**
     * @return array{body: string, mime: string}
     */
    public function render(string $data, string $format): array
    {
        $writer = $this->writerFor($format);

        $qrCode = new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 400,
            margin: 16,
        );

        $result = $writer->write($qrCode);

        return ['body' => $result->getString(), 'mime' => $result->getMimeType()];
    }

    private function writerFor(string $format): WriterInterface
    {
        return $format === 'svg' ? new SvgWriter() : new PngWriter();
    }
}
```

- [ ] **Step 4 : Lancer le test (doit passer)**

Run : `php artisan test --filter=QrCodeRendererTest`
Expected : PASS (2 tests).

- [ ] **Step 5 : Commit**

```bash
git add backend/app/Support/QrCodeRenderer.php backend/tests/Unit/QrCodeRendererTest.php
git commit -m "feat(qr): QrCodeRenderer wrapping endroid (PNG + SVG)"
```

---

## Task 3 : Endpoint image public + limiteur

**Files :**
- Create: `backend/app/Http/Controllers/QrCodeController.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/QrCodeImageTest.php`

- [ ] **Step 1 : Écrire le test (échoue)**

`backend/tests/Feature/TenantSchema/QrCodeImageTest.php` :
```php
<?php

use App\Models\Setting;

it('renders a PNG QR when booking_url is configured (no auth needed)', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $res = $this->get('/termin-qrcode.png');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('image/png');
});

it('renders an SVG QR when booking_url is configured', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $res = $this->get('/termin-qrcode.svg');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('image/svg+xml');
});

it('returns 404 when booking_url is not configured', function () {
    $this->get('/termin-qrcode.png')->assertNotFound();
});

it('returns 404 for an unsupported format', function () {
    Setting::put('booking_url', 'https://cabinet.de/rendez-vous');

    $this->get('/termin-qrcode.gif')->assertNotFound();
});
```

- [ ] **Step 2 : Lancer le test (doit échouer)**

Run : `php artisan test --filter=QrCodeImageTest`
Expected : FAIL (404 / route inexistante).

- [ ] **Step 3 : Écrire le contrôleur public**

`backend/app/Http/Controllers/QrCodeController.php` :
```php
<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\QrCodeRenderer;
use Symfony\Component\HttpFoundation\Response;

class QrCodeController extends Controller
{
    public function show(string $format, QrCodeRenderer $renderer): Response
    {
        $url = Setting::get('booking_url');

        abort_if($url === null || $url === '', 404);

        $image = $renderer->render($url, $format);

        return response($image['body'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
```

- [ ] **Step 4 : Ajouter le limiteur `qr`**

Dans `backend/app/Providers/AppServiceProvider.php`, méthode `boot()`, après la ligne `storno` :
```php
        RateLimiter::for('qr', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
```

- [ ] **Step 5 : Déclarer la route publique**

Dans `backend/routes/web.php`, ajouter (au niveau public, ex. après la route `landing`) :
```php
use App\Http\Controllers\QrCodeController;

Route::middleware('throttle:qr')
    ->get('/termin-qrcode.{format}', [QrCodeController::class, 'show'])
    ->where('format', 'png|svg')
    ->name('qr.image');
```
> Le `use App\Http\Controllers\QrCodeController;` va en tête de fichier avec les autres imports.

- [ ] **Step 6 : Lancer le test (doit passer)**

Run : `php artisan test --filter=QrCodeImageTest`
Expected : PASS (4 tests).

- [ ] **Step 7 : Commit**

```bash
git add backend/app/Http/Controllers/QrCodeController.php backend/app/Providers/AppServiceProvider.php backend/routes/web.php backend/tests/Feature/TenantSchema/QrCodeImageTest.php
git commit -m "feat(qr): public QR image endpoint /termin-qrcode.{png,svg} + qr rate limiter"
```

---

## Task 4 : Form Request de validation

**Files :**
- Create: `backend/app/Http/Requests/Tenant/StoreQrSettingRequest.php`
- Test: couvert par Task 5 (test de l'update). Pas de test isolé.

- [ ] **Step 1 : Écrire le Form Request**

`backend/app/Http/Requests/Tenant/StoreQrSettingRequest.php` :
```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreQrSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route protégée par le middleware 'auth'
    }

    public function rules(): array
    {
        return [
            'booking_url' => ['required', 'url:http,https', 'max:2048'],
        ];
    }
}
```

- [ ] **Step 2 : Commit**

```bash
git add backend/app/Http/Requests/Tenant/StoreQrSettingRequest.php
git commit -m "feat(qr): StoreQrSettingRequest validating booking_url (http/https)"
```

---

## Task 5 : Contrôleur admin (page + enregistrement) + routes

**Files :**
- Create: `backend/app/Http/Controllers/Tenant/QrCodeSettingController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/TenantSchema/QrCodeSettingTest.php`

- [ ] **Step 1 : Écrire le test (échoue)**

`backend/tests/Feature/TenantSchema/QrCodeSettingTest.php` :
```php
<?php

use App\Models\Setting;
use App\Models\User;

it('redirects guests away from the QR settings page', function () {
    $this->get('/termin-qr-code')->assertRedirect('/login');
});

it('shows the QR settings page to an authenticated staff member', function () {
    $this->actingAs(User::factory()->create())
        ->get('/termin-qr-code')
        ->assertOk();
});

it('persists a valid booking url', function () {
    $this->actingAs(User::factory()->create())
        ->post('/termin-qr-code', ['booking_url' => 'https://cabinet.de/rendez-vous'])
        ->assertRedirect();

    expect(Setting::get('booking_url'))->toBe('https://cabinet.de/rendez-vous');
});

it('rejects a non-http url', function () {
    $this->actingAs(User::factory()->create())
        ->post('/termin-qr-code', ['booking_url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('booking_url');

    expect(Setting::get('booking_url'))->toBeNull();
});
```
> Note : si la route de login du projet n'est pas `/login`, ajuster `assertRedirect('/login')` vers la valeur réelle (`route('login')`). Vérifier avec `php artisan route:list --name=login`.

- [ ] **Step 2 : Lancer le test (doit échouer)**

Run : `php artisan test --filter=QrCodeSettingTest`
Expected : FAIL (route `/termin-qr-code` inexistante).

- [ ] **Step 3 : Écrire le contrôleur admin**

`backend/app/Http/Controllers/Tenant/QrCodeSettingController.php` :
```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreQrSettingRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class QrCodeSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/QrCode', [
            'bookingUrl' => Setting::get('booking_url'),
        ]);
    }

    public function update(StoreQrSettingRequest $request): RedirectResponse
    {
        Setting::put('booking_url', $request->validated('booking_url'));

        return back()->with('success', 'QR-Code aktualisiert.');
    }
}
```

- [ ] **Step 4 : Déclarer les routes admin**

Dans `backend/routes/web.php`, **à l'intérieur** du groupe `Route::middleware('auth')->group(function () { ... })` (avec les autres routes staff) :
```php
    Route::get('/termin-qr-code', [\App\Http\Controllers\Tenant\QrCodeSettingController::class, 'index'])->name('tenant.qr.index');
    Route::post('/termin-qr-code', [\App\Http\Controllers\Tenant\QrCodeSettingController::class, 'update'])->name('tenant.qr.update');
```

- [ ] **Step 5 : Lancer le test (doit passer)**

Run : `php artisan test --filter=QrCodeSettingTest`
Expected : PASS (4 tests).

- [ ] **Step 6 : Commit**

```bash
git add backend/app/Http/Controllers/Tenant/QrCodeSettingController.php backend/routes/web.php backend/tests/Feature/TenantSchema/QrCodeSettingTest.php
git commit -m "feat(qr): authenticated QR settings page + update (persist booking_url)"
```

---

## Task 6 : Page Vue admin + entrée nav

**Files :**
- Create: `backend/resources/js/Pages/Tenant/QrCode.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue`

- [ ] **Step 1 : Créer la page Vue**

`backend/resources/js/Pages/Tenant/QrCode.vue` :
```vue
<script setup lang="ts">
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

defineOptions({ layout: TenantLayout })

const props = defineProps<{ bookingUrl: string | null }>()

const form = useForm({ booking_url: props.bookingUrl ?? '' })

// Cache-buster pour forcer le rechargement de l'aperçu après enregistrement.
const version = ref(0)
const imgUrl = computed(() => `/termin-qrcode.svg?v=${version.value}`)
const pngUrl = computed(() => `/termin-qrcode.png?v=${version.value}`)
const absolutePngUrl = computed(() => `${window.location.origin}/termin-qrcode.png`)

const copied = ref(false)

function submit() {
  form.post(route('tenant.qr.update'), {
    preserveScroll: true,
    onSuccess: () => { version.value++ },
  })
}

async function copyImageUrl() {
  await navigator.clipboard.writeText(absolutePngUrl.value)
  copied.value = true
  setTimeout(() => { copied.value = false }, 1500)
}
</script>

<template>
  <div class="max-w-2xl mx-auto p-6 space-y-6">
    <h1 class="text-2xl font-semibold">QR-Code – Terminbuchung</h1>

    <form @submit.prevent="submit" class="space-y-3">
      <label class="block text-sm font-medium" for="booking_url">
        URL der Buchungsseite (WordPress)
      </label>
      <input
        id="booking_url"
        v-model="form.booking_url"
        type="url"
        required
        placeholder="https://praxis.de/termin"
        class="w-full rounded border px-3 py-2"
      />
      <p v-if="form.errors.booking_url" class="text-sm text-red-600">
        {{ form.errors.booking_url }}
      </p>
      <button
        type="submit"
        :disabled="form.processing"
        class="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
      >
        Speichern
      </button>
    </form>

    <div v-if="props.bookingUrl" class="space-y-4">
      <img :src="imgUrl" alt="QR-Code Terminbuchung" class="h-56 w-56 border rounded bg-white p-2" />

      <div class="flex gap-3">
        <a :href="pngUrl" download="termin-qrcode.png" class="rounded border px-3 py-2">PNG herunterladen</a>
        <a :href="imgUrl" download="termin-qrcode.svg" class="rounded border px-3 py-2">SVG herunterladen</a>
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium">Bild-URL für E-Mails</label>
        <div class="flex gap-2">
          <input :value="absolutePngUrl" readonly class="w-full rounded border px-3 py-2 bg-gray-50" />
          <button type="button" @click="copyImageUrl" class="rounded border px-3 py-2 whitespace-nowrap">
            {{ copied ? 'Kopiert ✓' : 'Kopieren' }}
          </button>
        </div>
      </div>
    </div>

    <p v-else class="text-sm text-gray-500">
      Geben Sie zuerst die URL der Buchungsseite ein, um den QR-Code zu erzeugen.
    </p>
  </div>
</template>
```

- [ ] **Step 2 : Ajouter l'entrée de navigation**

Dans `backend/resources/js/Layouts/TenantLayout.vue`, dans le tableau `nav` (après la ligne `Abwesenheiten`) :
```js
    { href: '/termin-qr-code', label: '🔳 QR-Code' },
```

- [ ] **Step 3 : Build du front (vérifie la compilation TS/Vue)**

Run (depuis `backend/`) : `npm run build`
Expected : build réussi, pas d'erreur de compilation Vue/TS sur `QrCode.vue`.

- [ ] **Step 4 : Commit**

```bash
git add backend/resources/js/Pages/Tenant/QrCode.vue backend/resources/js/Layouts/TenantLayout.vue
git commit -m "feat(qr): admin QR-Code page (preview, downloads, copy email URL) + nav link"
```

---

## Task 7 : Vérification finale (suite complète + style + Chrome)

**Files :** aucun (vérification).

- [ ] **Step 1 : Lancer toute la suite**

Run (depuis `backend/`) : `composer test`
Expected : tous les tests passent (les anciens + les nouveaux : `SettingTest`, `QrCodeRendererTest`, `QrCodeImageTest`, `QrCodeSettingTest`).

- [ ] **Step 2 : Style PHP**

Run : `vendor/bin/pint`
Expected : aucun écart de style restant (commit si Pint modifie des fichiers).

- [ ] **Step 3 : Vérification visuelle Chrome (manuelle / browser automation)**

- Démarrer l'app (`composer dev`), se connecter, ouvrir `/termin-qr-code`.
- Saisir une URL (ex. l'URL de la page de réservation WordPress de test), enregistrer.
- Vérifier : l'aperçu s'affiche, le téléchargement PNG/SVG fonctionne, le bouton « Kopieren » copie l'URL absolue.
- **Scanner le QR avec un téléphone** → la page de réservation doit s'ouvrir.

- [ ] **Step 4 : Commit éventuel (Pint) + fin**

```bash
git add -A
git commit -m "style: pint" || echo "rien à committer"
```

---

## Self-review (couverture du spec)

- §1 Objectif (QR serveur, image publique réutilisable) → Tasks 2, 3 ✅
- §3 Approche endroid v6 → Task 0, 2 ✅
- §5.1 Setting clé/valeur caché → Task 1 ✅
- §5.2 Endpoint image public, 404 si non configuré, formats png|svg, cache → Task 3 ✅
- §5.3 Page admin (champ, aperçu, downloads, copier) + nav → Tasks 5, 6 ✅
- §5.4 Limiteur `qr` → Task 3 ✅
- §6 Flux → Tasks 3, 5, 6 ✅
- §7 Cas limites (404 non configuré, format invalide, URL invalide) → Tasks 3, 5 ✅
- §8 Sécurité (auth config, public image, validation url:http,https) → Tasks 4, 5, 3 ✅
- §9 Tests → Tasks 1, 2, 3, 5 ✅
- §10 Docs de référence → inexistants dans ce repo, aucune action (noté en tête) ✅
- §11 Dépendances (endroid, GD) → Task 0 ✅

Pas de placeholder. Signatures cohérentes : `Setting::get/put`, `QrCodeRenderer::render($data,$format): array{body,mime}`, route `qr.image`, routes `tenant.qr.index/update`.
