# Masinga Booking — Phase 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the multi-tenant Laravel foundation with auth and CRUD entities so a cabinet admin can configure practitioners, services, availabilities, and exceptions before any booking logic is wired up.

**Architecture:** Laravel 11 + Inertia + Vue 3 + Tailwind + shadcn-vue + stancl/tenancy v4 (schema-per-tenant PostgreSQL). Two route files (central + tenant), tenant identified by subdomain. No booking widget yet — that's Phase 2.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia.js 2, Vue 3 (Composition API), Tailwind CSS 3, shadcn-vue, stancl/tenancy v4, Laravel Fortify, PostgreSQL 16, Pest 3 (testing).

**Reference spec:** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md`

---

## Scope

This plan covers **roadmap weeks S1–S4** of the master roadmap:

- **S1**: Laravel project setup, Inertia/Vue/Tailwind/shadcn-vue install, stancl/tenancy install + central migrations
- **S2**: Routes (central + tenant), tenant identification middleware, Fortify auth, login/logout pages
- **S3**: CRUD `Practitioner` and `Service` entities (models, migrations, controllers, Inertia pages, tests)
- **S4**: CRUD `Availability` and `AvailabilityException` entities + smoke test

**Out of scope for this plan** (deferred to Plan 2):
- AvailabilityCalculator (slot computation engine)
- Public widget API
- Vue 3 standalone widget
- WordPress plugin
- FullCalendar dashboard

**End-of-plan acceptance criteria**: A super-admin can create a tenant `kidsclub`. The Kids Club admin can log into `kidsclub.masinga-booking.test`, create 2 practitioners, 3 services, weekly availability schedules, and add vacation exceptions. All persisted in PostgreSQL schema `tenant_kidsclub`. Feature tests cover happy path + cross-tenant isolation.

---

## File Structure

```
app/
├── Models/
│   ├── Tenant.php                       # central — extends stancl Tenant
│   ├── Domain.php                       # central — extends stancl Domain
│   ├── User.php                         # central — admin SaaS + cabinet owner
│   ├── Plan.php                         # central — Starter/Pro/Business
│   └── Tenant/
│       ├── Practitioner.php             # tenant — dentists
│       ├── Service.php                  # tenant — appointment types
│       ├── Availability.php             # tenant — recurring schedule
│       └── AvailabilityException.php    # tenant — vacations/blocks
├── Http/
│   ├── Controllers/
│   │   ├── Central/
│   │   │   └── DashboardController.php  # SaaS admin dashboard
│   │   └── Tenant/
│   │       ├── DashboardController.php
│   │       ├── PractitionerController.php
│   │       ├── ServiceController.php
│   │       ├── AvailabilityController.php
│   │       └── AvailabilityExceptionController.php
│   ├── Middleware/
│   │   └── (stancl middlewares auto-registered)
│   └── Requests/
│       └── Tenant/
│           ├── StorePractitionerRequest.php
│           ├── StoreServiceRequest.php
│           ├── StoreAvailabilityRequest.php
│           └── StoreAvailabilityExceptionRequest.php
├── Providers/
│   └── TenancyServiceProvider.php       # stancl provider
│
config/
├── tenancy.php                          # stancl config
└── fortify.php                          # auth config
│
database/
├── migrations/                          # CENTRAL migrations
│   ├── 2026_06_01_000001_create_tenants_table.php
│   ├── 2026_06_01_000002_create_domains_table.php
│   ├── 2026_06_01_000003_create_users_table.php
│   └── 2026_06_01_000004_create_plans_table.php
├── migrations/tenant/                   # TENANT migrations (per-schema)
│   ├── 2026_06_01_000010_create_practitioners_table.php
│   ├── 2026_06_01_000011_create_services_table.php
│   ├── 2026_06_01_000012_create_practitioner_service_table.php
│   ├── 2026_06_01_000013_create_availabilities_table.php
│   └── 2026_06_01_000014_create_availability_exceptions_table.php
├── factories/
│   ├── TenantFactory.php
│   ├── UserFactory.php
│   └── Tenant/
│       ├── PractitionerFactory.php
│       ├── ServiceFactory.php
│       └── AvailabilityFactory.php
└── seeders/
    └── KidsClubTenantSeeder.php
│
resources/
├── js/
│   ├── app.ts                           # Inertia entry
│   ├── ssr.ts                           # SSR entry (optional)
│   ├── Pages/
│   │   ├── Auth/
│   │   │   └── Login.vue
│   │   ├── Central/
│   │   │   └── Dashboard.vue
│   │   └── Tenant/
│   │       ├── Dashboard.vue
│   │       ├── Practitioners/Index.vue
│   │       ├── Practitioners/Form.vue
│   │       ├── Services/Index.vue
│   │       ├── Services/Form.vue
│   │       ├── Availabilities/Index.vue
│   │       ├── Availabilities/Form.vue
│   │       └── Exceptions/Index.vue
│   ├── Layouts/
│   │   ├── AuthLayout.vue
│   │   └── TenantLayout.vue             # sidebar Behandler/Leistungen/etc.
│   └── components/ui/                   # shadcn-vue components installed here
├── views/
│   └── app.blade.php                    # Inertia root template
│
routes/
├── web.php                              # central routes
├── tenant.php                           # tenant routes (subdomain-resolved)
└── auth.php                             # Fortify auth routes
│
tests/
├── Pest.php
├── TestCase.php                         # base
├── TenantTestCase.php                   # for tests needing a tenant context
└── Feature/
    ├── Central/
    │   └── TenantManagementTest.php
    └── Tenant/
        ├── AuthTest.php
        ├── PractitionerTest.php
        ├── ServiceTest.php
        ├── AvailabilityTest.php
        ├── ExceptionTest.php
        └── CrossTenantIsolationTest.php # CRITICAL DSGVO test
```

---

## Setup Prerequisites

Before Task 1, the developer needs locally:
- PHP 8.3+ with extensions: pdo_pgsql, mbstring, openssl, tokenizer
- Composer 2.x
- Node 20+ and npm 10+
- PostgreSQL 16 running locally
- A local domain wildcarded to 127.0.0.1 (e.g. `*.masinga-booking.test` via `dnsmasq` or `/etc/hosts` entries for `central.masinga-booking.test` and `kidsclub.masinga-booking.test`)

---

### Task 1: Initialize Laravel 11 project

**Files:**
- Create: `composer.json`, `package.json`, `.env`, etc. (via installer)

- [ ] **Step 1: Create Laravel project**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
composer create-project laravel/laravel:^11.0 backend
cd backend
```

- [ ] **Step 2: Configure PostgreSQL in `.env`**

Edit `backend/.env`:

```ini
APP_NAME="Masinga Booking"
APP_URL=http://central.masinga-booking.test

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=masinga_booking
DB_USERNAME=postgres
DB_PASSWORD=postgres

SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
```

- [ ] **Step 3: Create the database**

```bash
psql -U postgres -c "CREATE DATABASE masinga_booking;"
php artisan migrate
```

Expected: default Laravel tables created (users, sessions, cache, jobs).

- [ ] **Step 4: Commit**

```bash
cd .. && git add backend/
git commit -m "feat: scaffold Laravel 11 project with PostgreSQL"
```

---

### Task 2: Install Inertia + Vue 3 + Tailwind + shadcn-vue

**Files:**
- Modify: `backend/composer.json`, `backend/package.json`, `backend/resources/views/app.blade.php`
- Create: `backend/resources/js/app.ts`, `backend/tailwind.config.js`, `backend/components.json`

- [ ] **Step 1: Install Inertia server-side**

```bash
cd backend
composer require inertiajs/inertia-laravel
php artisan inertia:middleware
```

Then register `HandleInertiaRequests` in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
})
```

- [ ] **Step 2: Install Vue 3 + Inertia client + TypeScript**

```bash
npm install vue@^3.4 @inertiajs/vue3 @vitejs/plugin-vue typescript --save-dev
npm install -D @vue/tsconfig
```

- [ ] **Step 3: Configure Vite for Vue**

Edit `backend/vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
    ],
    resolve: {
        alias: { '@': path.resolve(__dirname, 'resources/js') },
    },
});
```

- [ ] **Step 4: Create the Inertia entry point**

Create `backend/resources/js/app.ts`:

```ts
import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, DefineComponent } from 'vue';
import '../css/app.css';

createInertiaApp({
    title: (title) => `${title} — Masinga Booking`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#0a6cb3' },
});

function resolvePageComponent(path: string, pages: Record<string, () => Promise<DefineComponent>>) {
    return pages[path]() ?? Promise.reject(new Error(`Page not found: ${path}`));
}
```

- [ ] **Step 5: Replace the root view**

Edit `backend/resources/views/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title inertia>Masinga Booking</title>
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
```

- [ ] **Step 6: Install Tailwind + shadcn-vue**

```bash
npm install -D tailwindcss postcss autoprefixer
npx tailwindcss init -p
npm install class-variance-authority clsx tailwind-merge lucide-vue-next
npx shadcn-vue@latest init
```

When prompted by `shadcn-vue init`, accept defaults except: Style=`default`, Base color=`slate`, CSS variables=`yes`.

- [ ] **Step 7: Smoke-test the stack**

Create a temporary `backend/resources/js/Pages/Hello.vue`:

```vue
<script setup lang="ts">
const message = 'Hello from Inertia + Vue 3'
</script>
<template>
    <div class="p-8 bg-slate-50 min-h-screen">
        <h1 class="text-3xl font-bold text-blue-700">{{ message }}</h1>
    </div>
</template>
```

Edit `backend/routes/web.php`:

```php
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Hello'));
```

```bash
npm run dev &
php artisan serve
```

Visit `http://localhost:8000` → should see blue "Hello from Inertia + Vue 3".

- [ ] **Step 8: Commit**

```bash
git add . && git commit -m "feat: add Inertia + Vue 3 + Tailwind + shadcn-vue stack"
```

---

### Task 3: Install and configure stancl/tenancy v4

**Files:**
- Create: `backend/config/tenancy.php`, `backend/app/Providers/TenancyServiceProvider.php`
- Modify: `backend/bootstrap/providers.php`, `backend/routes/web.php`
- Create: `backend/routes/tenant.php`

- [ ] **Step 1: Install the package**

```bash
cd backend
composer require stancl/tenancy
php artisan tenancy:install
```

This publishes `config/tenancy.php`, `routes/tenant.php`, `app/Providers/TenancyServiceProvider.php`, and creates `database/migrations/tenant/` folder.

- [ ] **Step 2: Configure PostgreSQL schema mode**

Edit `backend/config/tenancy.php` — set `bootstrappers` and `database`:

```php
'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\PostgreSQLSchemaTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],

'database' => [
    'central_connection' => env('DB_CONNECTION', 'pgsql'),
    'template_tenant_connection' => null,
    'prefix' => 'tenant_',
    'suffix' => '',
    'managers' => [
        'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
    ],
],

'identification_middleware' => [
    Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
],

'central_domains' => [
    'central.masinga-booking.test',
    'masinga-booking.test',
],
```

- [ ] **Step 3: Register tenancy routes correctly**

Edit `backend/app/Providers/TenancyServiceProvider.php`:

```php
public function mapRoutes(): void
{
    if (file_exists(base_path('routes/tenant.php'))) {
        Route::middleware('web')
            ->namespace($this->app->getNamespace().'Http\Controllers')
            ->group(base_path('routes/tenant.php'));
    }
}

public function boot(): void
{
    $this->bootEvents();
    $this->mapRoutes();
    $this->makeTenancyMiddlewareHighestPriority();
}
```

- [ ] **Step 4: Commit**

```bash
git add . && git commit -m "feat: install and configure stancl/tenancy v4 with PostgreSQL schemas"
```

---

### Task 4: Create central migrations (tenants, domains, users, plans)

**Files:**
- Create: 4 migrations in `backend/database/migrations/`
- Test: `backend/tests/Feature/Central/TenantManagementTest.php`

- [ ] **Step 1: Write failing test**

Create `backend/tests/Feature/Central/TenantManagementTest.php`:

```php
<?php
use App\Models\Tenant;
use App\Models\Plan;

beforeEach(function () {
    $this->plan = Plan::factory()->create(['name' => 'Starter']);
});

it('creates a tenant with a primary domain', function () {
    $tenant = Tenant::create([
        'id' => 'kidsclub',
        'name' => 'Kids Club by zacp',
        'slug' => 'kidsclub',
        'status' => 'active',
        'plan_id' => $this->plan->id,
    ]);

    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    expect($tenant->fresh()->domains)->toHaveCount(1)
        ->and($tenant->primaryDomain->domain)->toBe('kidsclub.masinga-booking.test');
});
```

- [ ] **Step 2: Run test, expect failure**

```bash
cd backend && php artisan test --filter=TenantManagementTest
```

Expected: FAIL ("Class App\\Models\\Plan not found" or similar).

- [ ] **Step 3: Generate migrations**

```bash
php artisan make:migration create_plans_table
php artisan make:migration create_tenants_table
php artisan make:migration create_domains_table
php artisan make:migration modify_users_table
```

- [ ] **Step 4: Plans migration**

```php
// database/migrations/2026_06_01_000001_create_plans_table.php
Schema::create('plans', function (Blueprint $table) {
    $table->id();
    $table->string('name');                   // Starter, Pro, Business
    $table->unsignedInteger('price_monthly'); // cents
    $table->jsonb('features');                // {"max_practitioners":5,"sms":false}
    $table->timestamps();
});
```

- [ ] **Step 5: Tenants migration (using stancl schema)**

```php
// database/migrations/2026_06_01_000002_create_tenants_table.php
Schema::create('tenants', function (Blueprint $table) {
    $table->string('id')->primary();                        // slug-based id
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('status')->default('trialing');          // trialing|active|suspended
    $table->foreignId('plan_id')->nullable()->constrained();
    $table->timestamp('trial_ends_at')->nullable();
    $table->jsonb('data')->nullable();                       // stancl-required column
    $table->timestamps();
});
```

- [ ] **Step 6: Domains migration**

```php
// database/migrations/2026_06_01_000003_create_domains_table.php
Schema::create('domains', function (Blueprint $table) {
    $table->id();
    $table->string('domain', 191)->unique();
    $table->string('tenant_id');
    $table->boolean('is_primary')->default(false);
    $table->timestamps();
    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
});
```

- [ ] **Step 7: Modify users table**

```php
// database/migrations/2026_06_01_000004_modify_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->string('role')->default('tenant_owner')->after('email'); // super_admin|tenant_owner
    $table->string('tenant_id')->nullable()->after('role');
    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
});
```

- [ ] **Step 8: Run migrations**

```bash
php artisan migrate
```

Expected: 4 new migrations executed.

- [ ] **Step 9: Run test, still expects fail (models not yet created)**

```bash
php artisan test --filter=TenantManagementTest
```

- [ ] **Step 10: Commit**

```bash
git add . && git commit -m "feat: add central migrations for tenants/domains/users/plans"
```

---

### Task 5: Create Tenant and Domain models

**Files:**
- Create: `backend/app/Models/Tenant.php`, `backend/app/Models/Domain.php`, `backend/app/Models/Plan.php`
- Create: `backend/database/factories/TenantFactory.php`, `backend/database/factories/PlanFactory.php`

- [ ] **Step 1: Create Tenant model**

```php
<?php
// app/Models/Tenant.php
namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains, HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'slug', 'status', 'plan_id', 'trial_ends_at'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
```

- [ ] **Step 2: Create Domain model**

```php
<?php
// app/Models/Domain.php
namespace App\Models;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

class Domain extends BaseDomain
{
    protected $fillable = ['domain', 'tenant_id', 'is_primary'];
}
```

- [ ] **Step 3: Create Plan model**

```php
<?php
// app/Models/Plan.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'price_monthly', 'features'];

    protected $casts = ['features' => 'array'];
}
```

- [ ] **Step 4: Create factories**

```php
<?php
// database/factories/PlanFactory.php
namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Starter',
            'price_monthly' => 2900,
            'features' => ['max_practitioners' => 1, 'sms' => false],
        ];
    }
}
```

```php
<?php
// database/factories/TenantFactory.php
namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);
        return [
            'id' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
            'plan_id' => Plan::factory(),
        ];
    }
}
```

- [ ] **Step 5: Update Tenant model — add `primaryDomain` relation**

Add to `Tenant.php`:

```php
public function primaryDomain()
{
    return $this->hasOne(Domain::class)->where('is_primary', true);
}
```

- [ ] **Step 6: Configure stancl to use our Tenant/Domain models**

Edit `config/tenancy.php`:

```php
'tenant_model' => \App\Models\Tenant::class,
'domain_model' => \App\Models\Domain::class,
```

- [ ] **Step 7: Run test, expect pass**

```bash
php artisan test --filter=TenantManagementTest
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add . && git commit -m "feat: add Tenant, Domain, Plan models with factories"
```

---

### Task 6: Create User model with role + tenant scoping

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/database/factories/UserFactory.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/Central/TenantManagementTest.php`:

```php
it('attaches users to tenants', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['role' => 'tenant_owner']);

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->role)->toBe('tenant_owner');
});

it('marks super admins without a tenant', function () {
    $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null]);
    expect($admin->isSuperAdmin())->toBeTrue();
});
```

- [ ] **Step 2: Run test, expect fail**

```bash
php artisan test --filter=TenantManagementTest
```

- [ ] **Step 3: Update User model**

```php
<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'tenant_id'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
}
```

- [ ] **Step 4: Run test, expect pass**

- [ ] **Step 5: Commit**

```bash
git add . && git commit -m "feat: add role and tenant relation to User model"
```

---

### Task 7: Set up central + tenant routes

**Files:**
- Modify: `backend/routes/web.php`, `backend/routes/tenant.php`
- Create: `backend/app/Http/Controllers/Central/DashboardController.php`
- Create: `backend/app/Http/Controllers/Tenant/DashboardController.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Central/RoutingTest.php`:

```php
<?php
use App\Models\Tenant;

it('central domain shows marketing landing', function () {
    $response = $this->get('http://central.masinga-booking.test/');
    $response->assertOk()->assertInertia(fn ($page) => $page->component('Central/Landing'));
});

it('unknown subdomain returns 404', function () {
    $response = $this->get('http://unknown.masinga-booking.test/');
    $response->assertNotFound();
});

it('tenant domain resolves to tenant dashboard', function () {
    $tenant = Tenant::factory()->create(['id' => 'kidsclub']);
    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    $response = $this->get('http://kidsclub.masinga-booking.test/');
    $response->assertRedirect();  // redirect to /login (not authenticated yet)
});
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Configure central routes**

```php
<?php
// routes/web.php
use App\Http\Controllers\Central\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', fn () => Inertia::render('Central/Landing'))->name('central.landing');
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('auth')->name('central.dashboard');
    });
}
```

- [ ] **Step 4: Configure tenant routes**

```php
<?php
// routes/tenant.php
use App\Http\Controllers\Tenant\DashboardController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', fn () => redirect()->route('tenant.dashboard'));

    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');
    });
});
```

- [ ] **Step 5: Create controllers**

```php
<?php
// app/Http/Controllers/Central/DashboardController.php
namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('Central/Dashboard', [
            'tenants' => Tenant::with('plan')->latest()->get(),
        ]);
    }
}
```

```php
<?php
// app/Http/Controllers/Tenant/DashboardController.php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Dashboard');
    }
}
```

- [ ] **Step 6: Create matching Vue pages**

Create `resources/js/Pages/Central/Landing.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
</script>
<template>
    <Head title="Masinga Booking — SaaS für Arztpraxen" />
    <div class="min-h-screen bg-slate-50 flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-5xl font-bold text-blue-700">Masinga Booking</h1>
            <p class="mt-4 text-slate-600">Online-Terminbuchung für moderne Arztpraxen</p>
        </div>
    </div>
</template>
```

Create `resources/js/Pages/Tenant/Dashboard.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
</script>
<template>
    <Head title="Dashboard" />
    <div class="p-8"><h1 class="text-3xl font-bold">Cabinet Dashboard</h1></div>
</template>
```

- [ ] **Step 7: Add hosts entries**

```bash
sudo sh -c 'echo "127.0.0.1 central.masinga-booking.test" >> /etc/hosts'
sudo sh -c 'echo "127.0.0.1 kidsclub.masinga-booking.test" >> /etc/hosts'
sudo sh -c 'echo "127.0.0.1 masinga-booking.test" >> /etc/hosts'
```

- [ ] **Step 8: Run tests, expect pass**

```bash
php artisan test --filter=RoutingTest
```

- [ ] **Step 9: Commit**

```bash
git add . && git commit -m "feat: configure central + tenant routes with subdomain identification"
```

---

### Task 8: Install Fortify auth (tenant-aware)

**Files:**
- Modify: `backend/composer.json`, `backend/config/fortify.php`
- Create: `backend/app/Http/Controllers/Auth/LoginController.php`
- Create: `backend/resources/js/Pages/Auth/Login.vue`

- [ ] **Step 1: Install Fortify**

```bash
cd backend
composer require laravel/fortify
php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"
php artisan migrate
```

- [ ] **Step 2: Write failing test**

Create `tests/Feature/Tenant/AuthTest.php`:

```php
<?php
use App\Models\Tenant;
use App\Models\User;

it('a tenant owner can log into their tenant', function () {
    $tenant = Tenant::factory()->create(['id' => 'kidsclub']);
    $tenant->domains()->create(['domain' => 'kidsclub.masinga-booking.test', 'is_primary' => true]);

    $user = User::factory()->create([
        'email' => 'michael@kidsclub.de',
        'password' => bcrypt('secret123'),
        'role' => 'tenant_owner',
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->post('http://kidsclub.masinga-booking.test/login', [
        'email' => 'michael@kidsclub.de',
        'password' => 'secret123',
    ]);

    $response->assertRedirect('/dashboard');
});

it('a user from another tenant cannot log in', function () {
    $tenant_a = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenant_a->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenant_b = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenant_b->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    $user_a = User::factory()->create([
        'email' => 'a@a.de', 'password' => bcrypt('x'),
        'role' => 'tenant_owner', 'tenant_id' => 'cabinet-a',
    ]);

    $response = $this->post('http://cabinet-b.masinga-booking.test/login', [
        'email' => 'a@a.de', 'password' => 'x',
    ]);

    $response->assertSessionHasErrors('email');
});
```

- [ ] **Step 3: Configure Fortify for tenants**

Edit `config/fortify.php`:

```php
'home' => '/dashboard',
'middleware' => ['web', \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class],
'features' => [
    Features::registration(),  // disabled later, enabled for early testing
    Features::resetPasswords(),
],
'views' => false,  // we render Vue pages via Inertia
```

- [ ] **Step 4: Customize the user authentication callback**

Create `app/Actions/Fortify/AuthenticateUser.php`:

```php
<?php
namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateUser
{
    public function __invoke(Request $request)
    {
        $tenant = tenant();

        $user = User::where('email', $request->email)
            ->where('tenant_id', $tenant?->id)
            ->first();

        if ($user && Hash::check($request->password, $user->password)) {
            return $user;
        }

        return null;
    }
}
```

Register in `app/Providers/FortifyServiceProvider.php`:

```php
use App\Actions\Fortify\AuthenticateUser;
use Laravel\Fortify\Fortify;

public function boot(): void
{
    Fortify::authenticateUsing(app(AuthenticateUser::class));
    Fortify::loginView(fn () => Inertia::render('Auth/Login'));
}
```

- [ ] **Step 5: Create Login Vue page**

```vue
<!-- resources/js/Pages/Auth/Login.vue -->
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '', remember: false })

const submit = () => form.post('/login')
</script>
<template>
    <Head title="Anmelden" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-6">Anmelden</h1>
            <input v-model="form.email" type="email" placeholder="E-Mail"
                   class="w-full p-3 border rounded mb-3">
            <div v-if="form.errors.email" class="text-red-600 text-sm mb-3">{{ form.errors.email }}</div>
            <input v-model="form.password" type="password" placeholder="Passwort"
                   class="w-full p-3 border rounded mb-4">
            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Anmelden
            </button>
        </form>
    </div>
</template>
```

- [ ] **Step 6: Run tests, expect pass**

```bash
php artisan test --filter=AuthTest
```

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: tenant-aware Fortify auth with cross-tenant isolation"
```

---

### Task 9: Tenant migrations — practitioners

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000010_create_practitioners_table.php`

- [ ] **Step 1: Generate**

```bash
cd backend
php artisan make:migration create_practitioners_table --path=database/migrations/tenant
```

- [ ] **Step 2: Write migration**

```php
public function up(): void
{
    Schema::create('practitioners', function (Blueprint $table) {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('title')->nullable();      // "Dr.", "Zahnärztin"
        $table->string('email')->nullable();
        $table->string('avatar_url')->nullable();
        $table->string('color', 7)->default('#0a6cb3');  // hex
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}
```

- [ ] **Step 3: Commit**

```bash
git add . && git commit -m "feat: add tenant migration for practitioners"
```

---

### Task 10: Practitioner model + factory + tests

**Files:**
- Create: `backend/app/Models/Tenant/Practitioner.php`
- Create: `backend/database/factories/Tenant/PractitionerFactory.php`
- Create: `backend/tests/Feature/Tenant/PractitionerTest.php`
- Create: `backend/tests/TenantTestCase.php`

- [ ] **Step 1: Create tenant test base class**

```php
<?php
// tests/TenantTestCase.php
namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['id' => 'test-tenant']);
        $this->tenant->domains()->create([
            'domain' => 'test-tenant.masinga-booking.test',
            'is_primary' => true,
        ]);
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Write failing test**

```php
<?php
// tests/Feature/Tenant/PractitionerTest.php
use App\Models\Tenant\Practitioner;
use Tests\TenantTestCase;

uses(TenantTestCase::class);

it('creates a practitioner in the tenant schema', function () {
    $p = Practitioner::create([
        'first_name' => 'Anna',
        'last_name' => 'Müller',
        'title' => 'Dr.',
        'email' => 'anna@kidsclub.de',
        'color' => '#FF6B6B',
    ]);

    expect($p->fresh()->last_name)->toBe('Müller')
        ->and($p->is_active)->toBeTrue();
});

it('lists active practitioners only via scope', function () {
    Practitioner::factory()->create(['is_active' => true]);
    Practitioner::factory()->create(['is_active' => false]);

    expect(Practitioner::active()->count())->toBe(1);
});
```

- [ ] **Step 3: Run, expect fail**

```bash
php artisan test --filter=PractitionerTest
```

- [ ] **Step 4: Create model**

```php
<?php
// app/Models/Tenant/Practitioner.php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class Practitioner extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'title', 'email', 'avatar_url', 'color', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function fullName(): string
    {
        return trim("{$this->title} {$this->first_name} {$this->last_name}");
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\PractitionerFactory::new();
    }
}
```

- [ ] **Step 5: Create factory**

```php
<?php
// database/factories/Tenant/PractitionerFactory.php
namespace Database\Factories\Tenant;

use App\Models\Tenant\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

class PractitionerFactory extends Factory
{
    protected $model = Practitioner::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'title' => $this->faker->randomElement(['Dr.', 'Zahnärztin', 'Zahnarzt']),
            'email' => $this->faker->safeEmail(),
            'color' => $this->faker->hexColor(),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run, expect pass**

```bash
php artisan test --filter=PractitionerTest
```

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: add Practitioner model with factory and tests"
```

---

### Task 11: PractitionerController + Inertia pages

**Files:**
- Create: `backend/app/Http/Controllers/Tenant/PractitionerController.php`
- Create: `backend/app/Http/Requests/Tenant/StorePractitionerRequest.php`
- Create: `backend/resources/js/Pages/Tenant/Practitioners/Index.vue`
- Create: `backend/resources/js/Pages/Tenant/Practitioners/Form.vue`
- Modify: `backend/routes/tenant.php`

- [ ] **Step 1: Write failing CRUD test**

Add to `tests/Feature/Tenant/PractitionerTest.php`:

```php
it('lists practitioners on the index page', function () {
    Practitioner::factory()->count(3)->create();

    $this->actingAs($this->makeTenantUser())
        ->get('http://test-tenant.masinga-booking.test/behandler')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Practitioners/Index')
            ->has('practitioners', 3)
        );
});

it('creates a practitioner via POST', function () {
    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/behandler', [
            'first_name' => 'Anna', 'last_name' => 'Müller', 'title' => 'Dr.',
            'email' => 'anna@kidsclub.de', 'color' => '#FF6B6B', 'is_active' => true,
        ])
        ->assertRedirect();

    expect(Practitioner::where('last_name', 'Müller')->exists())->toBeTrue();
});
```

Add helper to `TenantTestCase.php`:

```php
protected function makeTenantUser(): \App\Models\User
{
    return \App\Models\User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'tenant_owner',
    ]);
}
```

- [ ] **Step 2: Run, expect fail**

- [ ] **Step 3: Create FormRequest**

```php
<?php
// app/Http/Requests/Tenant/StorePractitionerRequest.php
namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StorePractitionerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'title'      => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
            'color'      => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active'  => ['boolean'],
        ];
    }
}
```

- [ ] **Step 4: Create Controller**

```php
<?php
// app/Http/Controllers/Tenant/PractitionerController.php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StorePractitionerRequest;
use App\Models\Tenant\Practitioner;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class PractitionerController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Practitioners/Index', [
            'practitioners' => Practitioner::orderBy('last_name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Practitioners/Form', ['practitioner' => null]);
    }

    public function store(StorePractitionerRequest $request): RedirectResponse
    {
        Practitioner::create($request->validated());
        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde angelegt.');
    }

    public function edit(Practitioner $practitioner)
    {
        return Inertia::render('Tenant/Practitioners/Form', ['practitioner' => $practitioner]);
    }

    public function update(StorePractitionerRequest $request, Practitioner $practitioner): RedirectResponse
    {
        $practitioner->update($request->validated());
        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde aktualisiert.');
    }

    public function destroy(Practitioner $practitioner): RedirectResponse
    {
        $practitioner->delete();
        return redirect()->route('tenant.practitioners.index')
            ->with('success', 'Behandler wurde gelöscht.');
    }
}
```

- [ ] **Step 5: Register routes**

Add to `routes/tenant.php` inside the auth-protected group:

```php
use App\Http\Controllers\Tenant\PractitionerController;

Route::resource('behandler', PractitionerController::class)
    ->names('tenant.practitioners')
    ->parameters(['behandler' => 'practitioner']);
```

- [ ] **Step 6: Create Index Vue page**

```vue
<!-- resources/js/Pages/Tenant/Practitioners/Index.vue -->
<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'

defineProps<{ practitioners: Array<{
    id: number; first_name: string; last_name: string; title: string;
    email: string; color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
    if (confirm('Wirklich löschen?')) router.delete(`/behandler/${id}`)
}
</script>
<template>
    <Head title="Behandler" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Behandler</h1>
            <Link href="/behandler/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                + Neuer Behandler
            </Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Name</th>
                    <th class="p-3 text-left">E-Mail</th>
                    <th class="p-3">Farbe</th>
                    <th class="p-3">Status</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in practitioners" :key="p.id" class="border-t">
                    <td class="p-3">{{ p.title }} {{ p.first_name }} {{ p.last_name }}</td>
                    <td class="p-3">{{ p.email }}</td>
                    <td class="p-3 text-center">
                        <span class="inline-block w-6 h-6 rounded-full"
                              :style="{ background: p.color }"></span>
                    </td>
                    <td class="p-3 text-center">
                        <span v-if="p.is_active" class="text-green-600">Aktiv</span>
                        <span v-else class="text-slate-400">Inaktiv</span>
                    </td>
                    <td class="p-3 text-right">
                        <Link :href="`/behandler/${p.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="destroy(p.id)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 7: Create Form Vue page**

```vue
<!-- resources/js/Pages/Tenant/Practitioners/Form.vue -->
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const props = defineProps<{
    practitioner: null | {
        id: number; first_name: string; last_name: string; title: string;
        email: string; color: string; is_active: boolean;
    }
}>()

const form = useForm({
    first_name: props.practitioner?.first_name ?? '',
    last_name: props.practitioner?.last_name ?? '',
    title: props.practitioner?.title ?? '',
    email: props.practitioner?.email ?? '',
    color: props.practitioner?.color ?? '#0a6cb3',
    is_active: props.practitioner?.is_active ?? true,
})

const submit = () => {
    if (props.practitioner) form.put(`/behandler/${props.practitioner.id}`)
    else form.post('/behandler')
}
</script>
<template>
    <Head :title="practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler'" />
    <div class="p-8 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">
            {{ practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler' }}
        </h1>
        <form @submit.prevent="submit" class="bg-white p-6 rounded shadow space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Anrede</label>
                <input v-model="form.title" type="text" class="w-full p-2 border rounded"
                       placeholder="Dr., Zahnärztin, ...">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Vorname *</label>
                    <input v-model="form.first_name" required class="w-full p-2 border rounded">
                    <div v-if="form.errors.first_name" class="text-red-600 text-sm">{{ form.errors.first_name }}</div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Nachname *</label>
                    <input v-model="form.last_name" required class="w-full p-2 border rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">E-Mail</label>
                <input v-model="form.email" type="email" class="w-full p-2 border rounded">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Farbe im Kalender</label>
                <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
            </div>
            <label class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox"> Aktiv
            </label>
            <button type="submit" :disabled="form.processing"
                    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800">
                Speichern
            </button>
        </form>
    </div>
</template>
```

- [ ] **Step 8: Run all tests, expect pass**

```bash
php artisan test
```

- [ ] **Step 9: Commit**

```bash
git add . && git commit -m "feat: Practitioner CRUD with Inertia pages and tests"
```

---

### Task 12: Service entity (migration + model + controller + pages)

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000011_create_services_table.php`
- Create: `backend/app/Models/Tenant/Service.php`
- Create: `backend/database/factories/Tenant/ServiceFactory.php`
- Create: `backend/app/Http/Controllers/Tenant/ServiceController.php`
- Create: `backend/app/Http/Requests/Tenant/StoreServiceRequest.php`
- Create: `backend/resources/js/Pages/Tenant/Services/Index.vue`, `Form.vue`
- Create: `backend/tests/Feature/Tenant/ServiceTest.php`

- [ ] **Step 1: Migration**

```php
Schema::create('services', function (Blueprint $table) {
    $table->id();
    $table->string('name');                     // "Erstuntersuchung Kind"
    $table->unsignedSmallInteger('duration_minutes')->default(30);
    $table->string('color', 7)->default('#0a6cb3');
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

- [ ] **Step 2: Write failing test**

```php
<?php
// tests/Feature/Tenant/ServiceTest.php
use App\Models\Tenant\Service;
use Tests\TenantTestCase;

uses(TenantTestCase::class);

it('creates a service via POST', function () {
    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/leistungen', [
            'name' => 'Erstuntersuchung Kind',
            'duration_minutes' => 45,
            'color' => '#FF6B6B',
            'description' => 'Erste Untersuchung bis 6 Jahre',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Service::where('name', 'Erstuntersuchung Kind')->exists())->toBeTrue();
});

it('rejects negative durations', function () {
    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/leistungen', [
            'name' => 'Test',
            'duration_minutes' => -5,
            'color' => '#000000',
        ])
        ->assertSessionHasErrors('duration_minutes');
});
```

- [ ] **Step 3: Model**

```php
<?php
// app/Models/Tenant/Service.php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Service extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'duration_minutes', 'color', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\ServiceFactory::new();
    }
}
```

- [ ] **Step 4: Factory**

```php
<?php
// database/factories/Tenant/ServiceFactory.php
namespace Database\Factories\Tenant;

use App\Models\Tenant\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Erstuntersuchung', 'Prophylaxe', 'Kontrolle', 'Versiegelung',
            ]),
            'duration_minutes' => $this->faker->randomElement([15, 30, 45, 60]),
            'color' => $this->faker->hexColor(),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 5: FormRequest**

```php
<?php
// app/Http/Requests/Tenant/StoreServiceRequest.php
namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'color'            => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description'      => ['nullable', 'string'],
            'is_active'        => ['boolean'],
        ];
    }
}
```

- [ ] **Step 6: Controller (identical pattern to Practitioner)**

```php
<?php
// app/Http/Controllers/Tenant/ServiceController.php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreServiceRequest;
use App\Models\Tenant\Service;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class ServiceController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Services/Index', [
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Services/Form', ['service' => null]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        Service::create($request->validated());
        return redirect()->route('tenant.services.index')->with('success', 'Leistung angelegt.');
    }

    public function edit(Service $service)
    {
        return Inertia::render('Tenant/Services/Form', ['service' => $service]);
    }

    public function update(StoreServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());
        return redirect()->route('tenant.services.index')->with('success', 'Leistung aktualisiert.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();
        return redirect()->route('tenant.services.index')->with('success', 'Leistung gelöscht.');
    }
}
```

- [ ] **Step 7: Routes**

Add to `routes/tenant.php`:

```php
use App\Http\Controllers\Tenant\ServiceController;

Route::resource('leistungen', ServiceController::class)
    ->names('tenant.services')
    ->parameters(['leistungen' => 'service']);
```

- [ ] **Step 8: Vue pages**

Index and Form pages follow the same structure as Practitioner pages — adapt field names (name, duration_minutes in number input with min=5, description as textarea).

```vue
<!-- resources/js/Pages/Tenant/Services/Form.vue -->
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const props = defineProps<{
    service: null | {
        id: number; name: string; duration_minutes: number;
        color: string; description: string; is_active: boolean;
    }
}>()

const form = useForm({
    name: props.service?.name ?? '',
    duration_minutes: props.service?.duration_minutes ?? 30,
    color: props.service?.color ?? '#0a6cb3',
    description: props.service?.description ?? '',
    is_active: props.service?.is_active ?? true,
})

const submit = () => {
    if (props.service) form.put(`/leistungen/${props.service.id}`)
    else form.post('/leistungen')
}
</script>
<template>
    <Head :title="service ? 'Leistung bearbeiten' : 'Neue Leistung'" />
    <div class="p-8 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">
            {{ service ? 'Leistung bearbeiten' : 'Neue Leistung' }}
        </h1>
        <form @submit.prevent="submit" class="bg-white p-6 rounded shadow space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Bezeichnung *</label>
                <input v-model="form.name" required class="w-full p-2 border rounded">
                <div v-if="form.errors.name" class="text-red-600 text-sm">{{ form.errors.name }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Dauer (Minuten) *</label>
                <input v-model.number="form.duration_minutes" type="number" min="5" max="480" required
                       class="w-full p-2 border rounded">
                <div v-if="form.errors.duration_minutes" class="text-red-600 text-sm">{{ form.errors.duration_minutes }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Beschreibung</label>
                <textarea v-model="form.description" rows="3" class="w-full p-2 border rounded"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Farbe im Kalender</label>
                <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
            </div>
            <label class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox"> Aktiv
            </label>
            <button type="submit" :disabled="form.processing"
                    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800">
                Speichern
            </button>
        </form>
    </div>
</template>
```

Index page: copy the Practitioner Index and adapt columns to `name`, `duration_minutes` (display as "X min"), `color`, `is_active`.

- [ ] **Step 9: Run tests**

```bash
php artisan test --filter=ServiceTest
```

- [ ] **Step 10: Commit**

```bash
git add . && git commit -m "feat: Service CRUD with validation and Inertia pages"
```

---

### Task 13: practitioner_service pivot + attachment UI

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000012_create_practitioner_service_table.php`
- Modify: `backend/app/Models/Tenant/Practitioner.php`, `Service.php`
- Modify: `backend/resources/js/Pages/Tenant/Services/Form.vue`

- [ ] **Step 1: Migration**

```php
Schema::create('practitioner_service', function (Blueprint $table) {
    $table->id();
    $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->unique(['practitioner_id', 'service_id']);
});
```

- [ ] **Step 2: Add relations**

In `Practitioner.php`:

```php
public function services()
{
    return $this->belongsToMany(Service::class);
}
```

In `Service.php`:

```php
public function practitioners()
{
    return $this->belongsToMany(Practitioner::class);
}
```

- [ ] **Step 3: Write failing test**

Add to `tests/Feature/Tenant/ServiceTest.php`:

```php
it('attaches practitioners to a service', function () {
    $p1 = \App\Models\Tenant\Practitioner::factory()->create();
    $p2 = \App\Models\Tenant\Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/leistungen', [
            'name' => 'Prophylaxe',
            'duration_minutes' => 30,
            'color' => '#000000',
            'practitioner_ids' => [$p1->id, $p2->id],
        ])
        ->assertRedirect();

    $service = \App\Models\Tenant\Service::where('name', 'Prophylaxe')->first();
    expect($service->practitioners)->toHaveCount(2);
});
```

- [ ] **Step 4: Update FormRequest**

In `StoreServiceRequest.php`, add:

```php
'practitioner_ids'   => ['array'],
'practitioner_ids.*' => ['exists:practitioners,id'],
```

- [ ] **Step 5: Update Controller**

In `ServiceController::store` and `::update`:

```php
public function store(StoreServiceRequest $request): RedirectResponse
{
    $data = $request->validated();
    $practitionerIds = $data['practitioner_ids'] ?? [];
    unset($data['practitioner_ids']);

    $service = Service::create($data);
    $service->practitioners()->sync($practitionerIds);

    return redirect()->route('tenant.services.index')->with('success', 'Leistung angelegt.');
}
```

(Apply the same logic to `update`.)

Also pass practitioners to the form view:

```php
public function create()
{
    return Inertia::render('Tenant/Services/Form', [
        'service' => null,
        'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
    ]);
}

public function edit(Service $service)
{
    return Inertia::render('Tenant/Services/Form', [
        'service' => $service->load('practitioners'),
        'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
    ]);
}
```

- [ ] **Step 6: Update Form Vue**

Add inside the form:

```vue
<div>
    <label class="block text-sm font-medium mb-2">Wird ausgeführt von</label>
    <div class="space-y-2">
        <label v-for="p in practitioners" :key="p.id" class="flex items-center gap-2">
            <input type="checkbox" :value="p.id" v-model="form.practitioner_ids">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
        </label>
    </div>
</div>
```

Update `defineProps` and `useForm`:

```ts
const props = defineProps<{
    service: null | { /* ... */ practitioners?: Array<{ id: number }> };
    practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const form = useForm({
    // ... existing fields
    practitioner_ids: props.service?.practitioners?.map(p => p.id) ?? [],
})
```

- [ ] **Step 7: Run tests, expect pass**

- [ ] **Step 8: Commit**

```bash
git add . && git commit -m "feat: attach practitioners to services via pivot table"
```

---

### Task 14: Availability migration + model + tests

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000013_create_availabilities_table.php`
- Create: `backend/app/Models/Tenant/Availability.php`
- Create: `backend/database/factories/Tenant/AvailabilityFactory.php`
- Create: `backend/tests/Feature/Tenant/AvailabilityTest.php`

- [ ] **Step 1: Migration**

```php
Schema::create('availabilities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('day_of_week');     // 1=Mon, 7=Sun
    $table->time('start_time');
    $table->time('end_time');
    $table->date('valid_from')->nullable();
    $table->date('valid_to')->nullable();
    $table->timestamps();
});
```

- [ ] **Step 2: Write failing test**

```php
<?php
// tests/Feature/Tenant/AvailabilityTest.php
use App\Models\Tenant\{Availability, Practitioner};
use Tests\TenantTestCase;

uses(TenantTestCase::class);

it('creates a recurring availability', function () {
    $p = Practitioner::factory()->create();

    $a = Availability::create([
        'practitioner_id' => $p->id,
        'day_of_week' => 1,         // Monday
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    expect($a->fresh()->practitioner->id)->toBe($p->id)
        ->and($a->start_time->format('H:i'))->toBe('09:00');
});

it('rejects end_time before start_time', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/sprechzeiten', [
            'practitioner_id' => $p->id,
            'day_of_week' => 1,
            'start_time' => '17:00',
            'end_time' => '09:00',
        ])
        ->assertSessionHasErrors('end_time');
});
```

- [ ] **Step 3: Model**

```php
<?php
// app/Models/Tenant/Availability.php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Availability extends Model
{
    use HasFactory;

    protected $fillable = [
        'practitioner_id', 'day_of_week', 'start_time', 'end_time', 'valid_from', 'valid_to',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function practitioner()
    {
        return $this->belongsTo(Practitioner::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\AvailabilityFactory::new();
    }
}
```

- [ ] **Step 4: Factory**

```php
<?php
namespace Database\Factories\Tenant;

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'day_of_week' => $this->faker->numberBetween(1, 5),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }
}
```

- [ ] **Step 5: Run model test, expect pass**

```bash
php artisan test --filter=AvailabilityTest::it_creates_a_recurring_availability
```

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "feat: Availability model with practitioner relation"
```

---

### Task 15: AvailabilityController + pages

**Files:**
- Create: `backend/app/Http/Controllers/Tenant/AvailabilityController.php`
- Create: `backend/app/Http/Requests/Tenant/StoreAvailabilityRequest.php`
- Create: `backend/resources/js/Pages/Tenant/Availabilities/Index.vue`, `Form.vue`

- [ ] **Step 1: FormRequest with time validation**

```php
<?php
// app/Http/Requests/Tenant/StoreAvailabilityRequest.php
namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'day_of_week'     => ['required', 'integer', 'between:1,7'],
            'start_time'      => ['required', 'date_format:H:i'],
            'end_time'        => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from'      => ['nullable', 'date'],
            'valid_to'        => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php
// app/Http/Controllers/Tenant/AvailabilityController.php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAvailabilityRequest;
use App\Models\Tenant\{Availability, Practitioner};
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class AvailabilityController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenant/Availabilities/Index', [
            'availabilities' => Availability::with('practitioner')
                ->orderBy('practitioner_id')
                ->orderBy('day_of_week')
                ->get(),
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Tenant/Availabilities/Form', [
            'availability' => null,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function store(StoreAvailabilityRequest $request): RedirectResponse
    {
        Availability::create($request->validated());
        return redirect()->route('tenant.availabilities.index')
            ->with('success', 'Sprechzeit angelegt.');
    }

    public function edit(Availability $availability)
    {
        return Inertia::render('Tenant/Availabilities/Form', [
            'availability' => $availability,
            'practitioners' => Practitioner::active()->orderBy('last_name')->get(),
        ]);
    }

    public function update(StoreAvailabilityRequest $request, Availability $availability): RedirectResponse
    {
        $availability->update($request->validated());
        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit aktualisiert.');
    }

    public function destroy(Availability $availability): RedirectResponse
    {
        $availability->delete();
        return redirect()->route('tenant.availabilities.index')->with('success', 'Sprechzeit gelöscht.');
    }
}
```

- [ ] **Step 3: Routes**

Add to `routes/tenant.php`:

```php
use App\Http\Controllers\Tenant\AvailabilityController;

Route::resource('sprechzeiten', AvailabilityController::class)
    ->names('tenant.availabilities')
    ->parameters(['sprechzeiten' => 'availability']);
```

- [ ] **Step 4: Index Vue page**

```vue
<!-- resources/js/Pages/Tenant/Availabilities/Index.vue -->
<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'

const days: Record<number, string> = {
    1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So',
}

defineProps<{
    availabilities: Array<{
        id: number; day_of_week: number; start_time: string; end_time: string;
        practitioner: { first_name: string; last_name: string; color: string };
    }>;
}>()
</script>
<template>
    <Head title="Sprechzeiten" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Sprechzeiten</h1>
            <Link href="/sprechzeiten/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded">+ Neue Sprechzeit</Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Behandler</th>
                    <th class="p-3 text-left">Wochentag</th>
                    <th class="p-3 text-left">Zeit</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in availabilities" :key="a.id" class="border-t">
                    <td class="p-3">
                        <span class="inline-block w-4 h-4 rounded-full mr-2"
                              :style="{ background: a.practitioner.color }"></span>
                        {{ a.practitioner.first_name }} {{ a.practitioner.last_name }}
                    </td>
                    <td class="p-3">{{ days[a.day_of_week] }}</td>
                    <td class="p-3">{{ a.start_time }} – {{ a.end_time }}</td>
                    <td class="p-3 text-right">
                        <Link :href="`/sprechzeiten/${a.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="router.delete(`/sprechzeiten/${a.id}`)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 5: Form Vue page** — analog to Practitioner Form with day_of_week as select (1-7) and time inputs

- [ ] **Step 6: Run tests, expect pass**

```bash
php artisan test --filter=AvailabilityTest
```

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: Availability CRUD with time validation"
```

---

### Task 16: AvailabilityException entity (migration + model + controller + pages)

**Files:**
- Create: `backend/database/migrations/tenant/2026_06_01_000014_create_availability_exceptions_table.php`
- Create: `backend/app/Models/Tenant/AvailabilityException.php`
- Create: `backend/app/Http/Controllers/Tenant/AvailabilityExceptionController.php`
- Create: `backend/app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php`
- Create: `backend/resources/js/Pages/Tenant/Exceptions/Index.vue`, `Form.vue`
- Create: `backend/tests/Feature/Tenant/ExceptionTest.php`

- [ ] **Step 1: Migration**

```php
Schema::create('availability_exceptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('practitioner_id')->constrained()->cascadeOnDelete();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->string('type', 32);           // vacation | sick | block
    $table->string('reason')->nullable();
    $table->timestamps();
});
```

- [ ] **Step 2: Write failing test**

```php
<?php
// tests/Feature/Tenant/ExceptionTest.php
use App\Models\Tenant\{AvailabilityException, Practitioner};
use Tests\TenantTestCase;

uses(TenantTestCase::class);

it('creates a vacation exception spanning multiple days', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-15 23:59:59',
            'type' => 'vacation',
            'reason' => 'Sommerurlaub',
        ])
        ->assertRedirect();

    expect(AvailabilityException::count())->toBe(1);
});

it('rejects ends_at before starts_at', function () {
    $p = Practitioner::factory()->create();

    $this->actingAs($this->makeTenantUser())
        ->post('http://test-tenant.masinga-booking.test/abwesenheiten', [
            'practitioner_id' => $p->id,
            'starts_at' => '2026-08-15 00:00:00',
            'ends_at' => '2026-08-01 23:59:59',
            'type' => 'vacation',
        ])
        ->assertSessionHasErrors('ends_at');
});
```

- [ ] **Step 3: Model**

```php
<?php
// app/Models/Tenant/AvailabilityException.php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class AvailabilityException extends Model
{
    protected $fillable = ['practitioner_id', 'starts_at', 'ends_at', 'type', 'reason'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function practitioner()
    {
        return $this->belongsTo(Practitioner::class);
    }
}
```

- [ ] **Step 4: FormRequest**

```php
<?php
// app/Http/Requests/Tenant/StoreAvailabilityExceptionRequest.php
namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'starts_at'       => ['required', 'date'],
            'ends_at'         => ['required', 'date', 'after:starts_at'],
            'type'            => ['required', 'in:vacation,sick,block'],
            'reason'          => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Controller** — same pattern as `AvailabilityController`, using `AvailabilityException` model and `Tenant/Exceptions/*` pages.

- [ ] **Step 6: Routes**

Add to `routes/tenant.php`:

```php
use App\Http\Controllers\Tenant\AvailabilityExceptionController;

Route::resource('abwesenheiten', AvailabilityExceptionController::class)
    ->names('tenant.exceptions')
    ->parameters(['abwesenheiten' => 'exception']);
```

- [ ] **Step 7: Vue pages** — analog to Availability pages but with type select (`vacation`, `sick`, `block`) and full datetime inputs

- [ ] **Step 8: Run tests, expect pass**

```bash
php artisan test --filter=ExceptionTest
```

- [ ] **Step 9: Commit**

```bash
git add . && git commit -m "feat: AvailabilityException CRUD for vacations/blocks"
```

---

### Task 17: Tenant layout with sidebar navigation

**Files:**
- Create: `backend/resources/js/Layouts/TenantLayout.vue`
- Modify: all `Tenant/**/*.vue` pages to use the layout

- [ ] **Step 1: Create layout**

```vue
<!-- resources/js/Layouts/TenantLayout.vue -->
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const tenantName = computed(() => (page.props as any).tenant?.name ?? 'Cabinet')
const user = computed(() => (page.props as any).auth?.user)

const logout = () => router.post('/logout')

const nav = [
    { href: '/dashboard', label: '📅 Dashboard' },
    { href: '/behandler', label: '👨‍⚕️ Behandler' },
    { href: '/leistungen', label: '🦷 Leistungen' },
    { href: '/sprechzeiten', label: '⏰ Sprechzeiten' },
    { href: '/abwesenheiten', label: '🏖️ Abwesenheiten' },
]
</script>
<template>
    <div class="min-h-screen flex bg-slate-50">
        <aside class="w-64 bg-white border-r p-6">
            <h2 class="text-xl font-bold text-blue-700 mb-8">{{ tenantName }}</h2>
            <nav class="space-y-1">
                <Link v-for="item in nav" :key="item.href" :href="item.href"
                      class="block px-3 py-2 rounded hover:bg-blue-50">
                    {{ item.label }}
                </Link>
            </nav>
            <div class="absolute bottom-6">
                <div class="text-sm text-slate-600 mb-2">{{ user?.email }}</div>
                <button @click="logout" class="text-sm text-red-600 hover:underline">Abmelden</button>
            </div>
        </aside>
        <main class="flex-1"><slot /></main>
    </div>
</template>
```

- [ ] **Step 2: Inject tenant name via HandleInertiaRequests**

Edit `app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'tenant' => fn () => tenant() ? ['name' => tenant()->name, 'slug' => tenant()->slug] : null,
        'auth' => fn () => ['user' => $request->user()],
        'flash' => fn () => ['success' => $request->session()->get('success')],
    ]);
}
```

- [ ] **Step 3: Wrap each tenant page with the layout**

Add at top of `Tenant/Dashboard.vue`, `Tenant/Practitioners/Index.vue`, etc.:

```vue
<script setup lang="ts">
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })
// ... rest of the page
</script>
```

- [ ] **Step 4: Visual test (manual)**

Start server: `php artisan serve` + `npm run dev`. Visit `http://kidsclub.masinga-booking.test:8000/dashboard` after login. Verify sidebar appears with all links, current page is highlighted (optional bonus).

- [ ] **Step 5: Commit**

```bash
git add . && git commit -m "feat: tenant layout with sidebar navigation"
```

---

### Task 18: Cross-tenant isolation test (CRITICAL DSGVO)

**Files:**
- Create: `backend/tests/Feature/Tenant/CrossTenantIsolationTest.php`

- [ ] **Step 1: Write test**

```php
<?php
// tests/Feature/Tenant/CrossTenantIsolationTest.php
use App\Models\Tenant;
use App\Models\Tenant\Practitioner;

it('a practitioner created in tenant A is not visible from tenant B', function () {
    // Create two separate tenants with their schemas
    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    // Create a practitioner in A
    tenancy()->initialize($tenantA);
    $p_a = Practitioner::create([
        'first_name' => 'Anna', 'last_name' => 'A_Specific',
        'color' => '#000000', 'is_active' => true,
    ]);
    tenancy()->end();

    // Switch to B and verify A's data is NOT visible
    tenancy()->initialize($tenantB);
    expect(Practitioner::count())->toBe(0)
        ->and(Practitioner::where('last_name', 'A_Specific')->exists())->toBeFalse();
    tenancy()->end();
});

it('http requests are scoped to the resolved tenant', function () {
    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);

    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    $userA = \App\Models\User::factory()->create([
        'tenant_id' => 'cabinet-a', 'role' => 'tenant_owner',
    ]);

    tenancy()->initialize($tenantA);
    Practitioner::factory()->count(2)->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    Practitioner::factory()->count(5)->create();
    tenancy()->end();

    // User A logs in on cabinet-a → sees only 2 practitioners
    $response = $this->actingAs($userA)
        ->get('http://cabinet-a.masinga-booking.test/behandler');

    $response->assertInertia(fn ($page) => $page->has('practitioners', 2));
});
```

- [ ] **Step 2: Run, expect pass**

```bash
php artisan test --filter=CrossTenantIsolationTest
```

If this fails, the multi-tenant configuration has a leak — STOP and debug before continuing. This test is the linchpin of DSGVO compliance.

- [ ] **Step 3: Commit**

```bash
git add . && git commit -m "test: critical cross-tenant data isolation guarantee"
```

---

### Task 19: KidsClub seeder + end-to-end smoke test

**Files:**
- Create: `backend/database/seeders/KidsClubTenantSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Seeder**

```php
<?php
// database/seeders/KidsClubTenantSeeder.php
namespace Database\Seeders;

use App\Models\{Plan, Tenant, User};
use App\Models\Tenant\{Practitioner, Service, Availability};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class KidsClubTenantSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::firstOrCreate(['name' => 'Starter'], [
            'price_monthly' => 2900,
            'features' => ['max_practitioners' => 5, 'sms' => false],
        ]);

        $tenant = Tenant::firstOrCreate(
            ['id' => 'kidsclub'],
            ['name' => 'Kids Club by zacp', 'slug' => 'kidsclub',
             'status' => 'active', 'plan_id' => $plan->id]
        );

        $tenant->domains()->firstOrCreate([
            'domain' => 'kidsclub.masinga-booking.test',
        ], ['is_primary' => true]);

        User::firstOrCreate(['email' => 'michael@kidsclub.de'], [
            'name' => 'Michael Rohling',
            'password' => Hash::make('changeme'),
            'role' => 'tenant_owner',
            'tenant_id' => $tenant->id,
        ]);

        // Seed tenant data
        $tenant->run(function () {
            $anna = Practitioner::firstOrCreate(['email' => 'anna@kidsclub.de'], [
                'first_name' => 'Anna', 'last_name' => 'Müller',
                'title' => 'Dr.', 'color' => '#FF6B6B', 'is_active' => true,
            ]);

            $bjorn = Practitioner::firstOrCreate(['email' => 'bjorn@kidsclub.de'], [
                'first_name' => 'Björn', 'last_name' => 'Schmidt',
                'title' => 'Zahnarzt', 'color' => '#4ECDC4', 'is_active' => true,
            ]);

            $services = [
                ['name' => 'Erstuntersuchung Kind', 'duration_minutes' => 45],
                ['name' => 'Prophylaxe', 'duration_minutes' => 30],
                ['name' => 'Notfall', 'duration_minutes' => 60],
            ];
            foreach ($services as $s) {
                $service = Service::firstOrCreate(['name' => $s['name']], $s + [
                    'color' => '#0a6cb3', 'is_active' => true,
                ]);
                $service->practitioners()->syncWithoutDetaching([$anna->id, $bjorn->id]);
            }

            // Mon-Fri availability for Anna
            foreach ([1, 2, 3, 4, 5] as $day) {
                Availability::firstOrCreate([
                    'practitioner_id' => $anna->id, 'day_of_week' => $day,
                ], ['start_time' => '09:00', 'end_time' => '17:00']);
            }
        });
    }
}
```

- [ ] **Step 2: Register in DatabaseSeeder**

```php
public function run(): void
{
    $this->call([KidsClubTenantSeeder::class]);
}
```

- [ ] **Step 3: Run seeder**

```bash
php artisan migrate:fresh --seed
```

Expected: 1 tenant, 1 domain, 1 user, 2 practitioners, 3 services, 5 availabilities.

- [ ] **Step 4: Manual smoke test (browser)**

1. Visit `http://kidsclub.masinga-booking.test:8000/login`
2. Log in as `michael@kidsclub.de` / `changeme`
3. Navigate to Behandler → see Anna and Björn
4. Navigate to Leistungen → see 3 services, each linked to both practitioners
5. Navigate to Sprechzeiten → see 5 Anna entries (Mo-Fr)
6. Create a new exception (vacation) for Anna → verify it persists

- [ ] **Step 5: End-to-end test**

```php
<?php
// tests/Feature/Tenant/SmokeTest.php
use App\Models\Tenant;
use App\Models\User;

it('a tenant admin can navigate the full management UI after seeding', function () {
    $this->artisan('db:seed', ['--class' => 'KidsClubTenantSeeder']);

    $user = User::where('email', 'michael@kidsclub.de')->firstOrFail();

    $this->actingAs($user);

    $tenant = Tenant::findOrFail('kidsclub');
    tenancy()->initialize($tenant);

    $this->get('http://kidsclub.masinga-booking.test/behandler')
        ->assertOk()->assertInertia(fn ($p) => $p->has('practitioners', 2));

    $this->get('http://kidsclub.masinga-booking.test/leistungen')
        ->assertOk()->assertInertia(fn ($p) => $p->has('services', 3));

    $this->get('http://kidsclub.masinga-booking.test/sprechzeiten')
        ->assertOk()->assertInertia(fn ($p) => $p->has('availabilities', 5));

    tenancy()->end();
});
```

- [ ] **Step 6: Run, expect pass**

```bash
php artisan test
```

All tests must pass. Total count expected: ~20 tests.

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: KidsClub seeder + end-to-end smoke test"
```

---

### Task 20: Phase 1 README + setup docs

**Files:**
- Create: `backend/README.md`

- [ ] **Step 1: Write README**

```markdown
# Masinga Booking — Backend

Multi-tenant SaaS for medical appointment booking. Phase 1 = foundation (tenancy + auth + CRUD).

## Quick start

1. Install: `composer install && npm install`
2. Copy env: `cp .env.example .env && php artisan key:generate`
3. Configure PostgreSQL in `.env` (DB_DATABASE=masinga_booking)
4. Hosts entries:
   ```
   127.0.0.1 central.masinga-booking.test
   127.0.0.1 kidsclub.masinga-booking.test
   127.0.0.1 masinga-booking.test
   ```
5. Migrate + seed: `php artisan migrate:fresh --seed`
6. Run: `php artisan serve` + `npm run dev`
7. Login: http://kidsclub.masinga-booking.test:8000/login (michael@kidsclub.de / changeme)

## Testing

```bash
php artisan test                       # all tests
php artisan test --filter=Tenant       # tenant-specific tests
php artisan test --filter=CrossTenant  # DSGVO isolation tests
```

## Architecture

- `routes/web.php` — central domain (marketing, SaaS admin)
- `routes/tenant.php` — tenant domains (cabinet dashboards)
- `app/Models/Tenant/*` — tenant-schema models
- `database/migrations/tenant/*` — per-tenant schema migrations

See `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md` for full spec.
```

- [ ] **Step 2: Commit**

```bash
git add . && git commit -m "docs: Phase 1 setup README"
```

---

## End-of-Plan Acceptance Criteria

✅ All 20 tasks completed
✅ All tests pass (`php artisan test` green, ~20 tests)
✅ `CrossTenantIsolationTest` passes (DSGVO linchpin)
✅ Manual smoke test successful (login → CRUD all entities)
✅ Seeded data visible in `http://kidsclub.masinga-booking.test:8000`
✅ Git history clean (one commit per task)

**Next plan**: Phase 2 — Booking Engine (AvailabilityCalculator + public API + Vue widget + WordPress plugin + Dashboard calendar with FullCalendar).

---

## Self-Review

**Spec coverage check** — each spec section maps to a task:
- Spec §3 (Architecture) → Tasks 1-3 (Laravel + Inertia + stancl/tenancy)
- Spec §4 (Multi-tenancy) → Tasks 3-7 (stancl install, routes, identification)
- Spec §5 (Data model centrally) → Tasks 4-6 (tenants/domains/users/plans)
- Spec §5 (Data model tenant) → Tasks 9-16 (practitioners/services/availabilities/exceptions)
- Spec §6.2 (Dashboard flow) → Tasks 8, 17 (auth + layout)
- Spec §9 (DSGVO) → Task 18 (cross-tenant isolation test)
- Spec §10 roadmap S1-S4 → all tasks

**Out of scope (correctly deferred to Plan 2 or 3):**
- §6.1 booking flow (no public API yet) — Plan 2
- §7 WordPress plugin — Plan 2
- §9 audit log, anonymization job — Plan 3
- §3 widget Vue 3 — Plan 2

**Placeholder scan**: no "TBD", no "implement later", every test has full code, every controller has full implementation. Form Vue pages for Services and Exceptions reference the Practitioner Form pattern explicitly — engineer should copy and adapt.

**Type consistency**:
- `Practitioner` properties used consistently: `first_name`, `last_name`, `title`, `email`, `color`, `is_active`
- `Service` properties: `name`, `duration_minutes` (always integer, never `duration`), `color`, `description`, `is_active`
- Time format: `H:i` everywhere (24-hour, no seconds)
- Route names: `tenant.practitioners.*`, `tenant.services.*`, etc.
- Route URLs in German: `/behandler`, `/leistungen`, `/sprechzeiten`, `/abwesenheiten` (matches user's "URLs en français → here in German for German market")

**Open concerns flagged for execution:**
- Sudo required for /etc/hosts — engineer needs admin rights
- PostgreSQL must be running locally (Postgres.app on macOS or Homebrew)
- Stancl tenancy v4 syntax may differ slightly — check official docs if any API calls fail (especially `tenant()->run()` and bootstrappers list)
- shadcn-vue install may prompt: defaults are fine, but verify the `components/ui/` import alias `@/components/ui` works in `vite.config.js`

Plan is complete and consistent.
