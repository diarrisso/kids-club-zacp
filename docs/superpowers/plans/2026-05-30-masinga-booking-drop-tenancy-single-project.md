# Drop Tenancy → Single-Project Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the multi-tenant Masinga Booking SaaS into a single-tenant (single-project) app for Kids Club by zacp, by removing `stancl/tenancy` in place, on Laravel 11 — preserving all booking/widget/email business logic.

**Architecture:** This is a **removal refactor**, not greenfield feature work. The existing test suite is the safety net, but the tests reference tenancy and cannot stay green at every micro-step. Ordering is deliberate: (1) rewrite all app code to stop calling `tenant()`/`tenancy()`; (2) rewrite routes; (3) delete the tenancy classes + central SaaS surface; (4) fix `bootstrap`; (5) convert the seeder; (6) collapse the two test suites into one `RefreshDatabase` suite, convert/delete tests, and **`composer remove stancl/tenancy` LAST**; (7) de-tenant the widget JS + WordPress plugin. Each task ends in a commit with a concrete verification command; the test-collapse task proves the full suite green.

**Tech Stack:** Laravel 11.54 · Inertia 2 · Vue 3 (`<script setup lang="ts">`) · Tailwind 3 · PostgreSQL 16 · Pest 3 · Fortify. Removing: `stancl/tenancy ^3.10`.

**Working directory:** All `php`/`composer`/`artisan` commands run from `backend/`. `npm` commands too. Git commands run from the repo root `/Users/mdiarrisso/PhpstormProjects/kids-club-zacp/` (the `backend/` paths below are relative to repo root in `git add`). Branch: `refactor/drop-tenancy` (already created).

**Key constraint — do NOT run `composer remove stancl/tenancy` until Task 7.** Until then the package supplies `tenant()`, `tenancy()`, and every `Stancl\…` class, so intermediate commits still boot. The moment the package leaves, any remaining `Stancl\…` import or `tenant()` call fatals — Task 7 removes it only after every reference is gone.

---

## File Structure (what changes and why)

**Rewritten (stop using tenancy):**
- `app/Models/User.php` — drop `CentralConnection`, `role`, `tenant_id`, `tenant()`, `isSuperAdmin()`.
- `database/factories/UserFactory.php` — drop `role`/`tenant_id`.
- `app/Actions/Fortify/CreateNewUser.php` — global-unique email, no tenant.
- `app/Actions/Fortify/AuthenticateUser.php` — plain email+password lookup.
- `app/Support/CabinetNotifier.php` — recipients from `config('mail.practice_notification_address')`.
- `app/Console/Commands/SendAppointmentReminders.php` — direct `Appointment` query, no tenant loop.
- `app/Http/Middleware/HandleInertiaRequests.php` — drop `tenant` prop, share `app_name`.
- `app/Providers/AppServiceProvider.php` — rate-limiter keys by IP only.
- `app/Http/Controllers/Widget/AppointmentController.php`, `Widget/CancellationController.php`, `Public/CancellationPageController.php` — `route('storno.show', ['token' => …])`, `config('app.name')`.
- `config/mail.php` — add `practice_notification_address`.
- `routes/web.php`, `routes/api.php`, `config/fortify.php` — single domain, German URLs, no `{tenant}`.
- `bootstrap/providers.php`, `bootstrap/app.php` — unregister provider, drop exception renderers.
- `database/seeders/DatabaseSeeder.php` + new `KidsClubSeeder.php`.
- `resources/js/widget/api.ts`, `resources/js/widget/main.ts` — no tenant slug.
- `wordpress-plugin/masinga-booking/includes/class-shortcode.php`, `class-settings.php` — no tenant slug.
- `phpunit.xml`, `tests/Pest.php`, `composer.json` (`scripts.test`) — one suite.
- `.env.example` — drop tenancy vars; add `APP_NAME`, `PRACTICE_NOTIFICATION_EMAIL`.

**Deleted:**
- Models: `app/Models/Tenant.php`, `Domain.php`, `Plan.php`; `database/factories/TenantFactory.php`.
- `app/Providers/TenancyServiceProvider.php`, `app/Tenancy/SearchPathBootstrapper.php`, `app/Listeners/SwitchSearchPathForMigration.php`, `ResetSearchPathAfterMigration.php`, `NarrowSearchPathForTenantMigration.php`.
- `config/tenancy.php`, `routes/tenant.php`.
- `app/Http/Controllers/Central/DashboardController.php`; `resources/js/Pages/Central/Dashboard.vue` (keep `Central/Landing.vue` — reused as the public landing).
- `database/seeders/KidsClubTenantSeeder.php`.
- Migrations: `2019_09_15_000005_create_plans_table.php`, `2019_09_15_000010_create_tenants_table.php`, `2019_09_15_000020_create_domains_table.php`, `2026_06_01_000004_modify_users_table.php`.
- `tests/TenantTestCase.php`; `tests/Feature/Central/*`; `tests/Feature/Tenant/AuthTest.php`; `tests/Feature/TenantSchema/{CrossTenantIsolationTest,WidgetCrossTenantTest,WidgetTenantIdentificationTest}.php`.

**Moved:** `database/migrations/tenant/*` → `database/migrations/` (regular).

---

## Task 1: Move business migrations onto the default connection

**Files:**
- Move: `backend/database/migrations/tenant/*.php` → `backend/database/migrations/`
- Delete: `backend/database/migrations/2019_09_15_000005_create_plans_table.php`, `…_000010_create_tenants_table.php`, `…_000020_create_domains_table.php`, `backend/database/migrations/2026_06_01_000004_modify_users_table.php`

The 7 `tenant/*` migrations are plain `Schema::create` calls with no tenant-connection binding, so on the default connection they build the same tables in the single DB. Deleting `modify_users_table` restores the clean global-`unique('email')` users table from `create_users_table` (no `role`/`tenant_id`). Do NOT run `migrate` yet — the tenancy provider's migrate-event listeners are still registered and will interfere; migration runs first in Task 6 after the provider is gone.

- [ ] **Step 1: Move the tenant migrations up one level**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp/backend
git mv database/migrations/tenant/2026_06_01_000010_create_practitioners_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000011_create_services_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000012_create_practitioner_service_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000013_create_availabilities_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000014_create_availability_exceptions_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000015_create_appointments_table.php database/migrations/
git mv database/migrations/tenant/2026_06_01_000016_add_reminder_sent_at_to_appointments.php database/migrations/
rmdir database/migrations/tenant
```

- [ ] **Step 2: Delete the tenancy infrastructure migrations**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp/backend
git rm database/migrations/2019_09_15_000005_create_plans_table.php
git rm database/migrations/2019_09_15_000010_create_tenants_table.php
git rm database/migrations/2019_09_15_000020_create_domains_table.php
git rm database/migrations/2026_06_01_000004_modify_users_table.php
```

- [ ] **Step 3: Confirm the moved migrations carry no tenant-connection coupling**

Run: `grep -rn "connection\|tenant" database/migrations/*.php`
Expected: no matches (the moved files use bare `Schema::create`). If any moved file sets `->connection(...)`, remove that call so it uses the default connection.

- [ ] **Step 4: Verify migration files lint**

Run: `for f in database/migrations/*.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for every file.

- [ ] **Step 5: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add -A backend/database/migrations
git commit -m "refactor(tenancy): move business migrations to default connection, drop tenancy + modify_users migrations"
```

---

## Task 2: De-tenant the User model, factory, and Fortify actions

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/database/factories/UserFactory.php`
- Modify: `backend/app/Actions/Fortify/CreateNewUser.php`
- Modify: `backend/app/Actions/Fortify/AuthenticateUser.php`

Every authenticated user is now staff. No roles, no tenant scoping. The `tenant()` helper still exists (package present), but these files must stop importing `CentralConnection` and stop reading `role`/`tenant_id`.

- [ ] **Step 1: Rewrite `app/Models/User.php`**

Replace the whole file with:

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

- [ ] **Step 2: Rewrite `database/factories/UserFactory.php` definition**

In `definition()`, remove the `'role'` and `'tenant_id'` keys so it returns:

```php
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
```

Leave the rest of the factory (imports, `$password`, `unverified()`) unchanged.

- [ ] **Step 3: Rewrite `app/Actions/Fortify/CreateNewUser.php`**

Replace the whole file with:

```php
<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users'),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
```

- [ ] **Step 4: Rewrite `app/Actions/Fortify/AuthenticateUser.php`**

Replace the whole file with:

```php
<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateUser
{
    /**
     * Resolve the user for a login attempt (single-tenant: email + password).
     */
    public function __invoke(Request $request): ?User
    {
        $user = User::where('email', $request->email)->first();

        if ($user && Hash::check($request->password, $user->password)) {
            return $user;
        }

        return null;
    }
}
```

- [ ] **Step 5: Verify lint + that no User code still references role/tenant**

Run: `php -l app/Models/User.php && php -l database/factories/UserFactory.php && php -l app/Actions/Fortify/CreateNewUser.php && php -l app/Actions/Fortify/AuthenticateUser.php`
Expected: `No syntax errors detected` ×4.
Run: `grep -rn "isSuperAdmin\|tenant_id\|->role\|'role'" app/Models/User.php database/factories/UserFactory.php app/Actions/Fortify/`
Expected: no matches.

- [ ] **Step 6: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/app/Models/User.php backend/database/factories/UserFactory.php backend/app/Actions/Fortify/CreateNewUser.php backend/app/Actions/Fortify/AuthenticateUser.php
git commit -m "refactor(tenancy): single-tenant User (drop role/tenant_id, CentralConnection) + Fortify actions"
```

---

## Task 3: De-tenant request-time services, controllers, and mail config

**Files:**
- Modify: `backend/config/mail.php`
- Modify: `backend/app/Support/CabinetNotifier.php`
- Modify: `backend/app/Console/Commands/SendAppointmentReminders.php`
- Modify: `backend/app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/app/Http/Controllers/Widget/AppointmentController.php`
- Modify: `backend/app/Http/Controllers/Widget/CancellationController.php`
- Modify: `backend/app/Http/Controllers/Public/CancellationPageController.php`

Replace every `tenant()->name` with `config('app.name')`, every `route('storno.show', ['tenant' => …, 'token' => …])` with `route('storno.show', ['token' => …])`, and the cabinet-recipient query with a config lookup.

- [ ] **Step 1: Add the practice notification address to `config/mail.php`**

In `config/mail.php`, inside the top-level `return [ ... ]` array (e.g. right after the `'from' => [...]` block), add:

```php
    /*
    |--------------------------------------------------------------------------
    | Practice Notification Address
    |--------------------------------------------------------------------------
    | Where operational alerts (e.g. an appointment was cancelled) are sent.
    | Single-tenant: one configured cabinet inbox, no per-user roles.
    */

    'practice_notification_address' => env('PRACTICE_NOTIFICATION_EMAIL'),
```

- [ ] **Step 2: Rewrite `app/Support/CabinetNotifier.php`**

```php
<?php

namespace App\Support;

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the "an appointment was cancelled" alert to the cabinet's configured
 * inbox (PRACTICE_NOTIFICATION_EMAIL). Single-tenant: no roles, no per-user
 * recipients.
 */
class CabinetNotifier
{
    /** @return list<string> the configured cabinet recipient(s), or [] if unset */
    public static function recipients(): array
    {
        $email = config('mail.practice_notification_address');

        return $email ? [$email] : [];
    }

    /** Queue the cancellation alert to the cabinet (no-op if unconfigured). */
    public static function notifyCancelled(Appointment $appointment): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        // The appointment is already cancelled by the caller; a queue-push
        // failure (e.g. Redis down) must not fail the cancellation, so rescue().
        rescue(fn () => Mail::to($recipients)->queue(
            new AppointmentCancelledMail($appointment, config('app.name'))
        ));
    }
}
```

- [ ] **Step 3: Rewrite `app/Console/Commands/SendAppointmentReminders.php`**

```php
<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\Tenant\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Queue a 24h reminder email for each upcoming confirmed appointment.';

    public function handle(): int
    {
        Appointment::query()
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>=', now()->addHours(24))
            ->where('starts_at', '<', now()->addHours(25))
            ->with(['service', 'practitioner'])
            ->get()
            ->each(function (Appointment $appointment) {
                try {
                    $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);

                    // Mark-then-send: persist reminder_sent_at first, so if the
                    // queue push fails we roll it back and retry next run — rather
                    // than queueing first and risking a duplicate reminder if the
                    // save then failed. (Field is not $fillable → direct assignment.)
                    $appointment->reminder_sent_at = now();
                    $appointment->save();

                    try {
                        Mail::to($appointment->parent_email)->queue(
                            new AppointmentReminderMail($appointment, config('app.name'), $cancelUrl)
                        );
                    } catch (\Throwable $e) {
                        $appointment->reminder_sent_at = null;
                        $appointment->save();
                        throw $e; // surface to the outer catch for reporting
                    }
                } catch (\Throwable $e) {
                    // One bad appointment must not abort the whole batch.
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Edit `app/Http/Middleware/HandleInertiaRequests.php` `share()`**

Replace the `'tenant' => …` line with an `app_name` share:

```php
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app_name' => config('app.name'),
            'auth' => fn () => ['user' => $request->user()],
            'flash' => fn () => ['success' => $request->session()->get('success')],
        ];
    }
```

- [ ] **Step 5: Edit the three rate limiters in `app/Providers/AppServiceProvider.php`**

Replace lines 25-27 with IP-only keys (no tenant):

```php
        RateLimiter::for('widget-read', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('widget-book', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('storno', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
```

- [ ] **Step 6: Edit `app/Http/Controllers/Widget/AppointmentController.php` (the confirmation block near the end of `store()`)**

Replace the `$cancelUrl = route(...)` + `rescue(...)` block with:

```php
        // Notify the parent. Queued so it never blocks the booking response; the
        // booking is already committed here, so a queue-push failure (e.g. Redis
        // down) must not 500 an existing booking — rescue() logs and moves on.
        $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
        rescue(fn () => Mail::to($appointment->parent_email)->queue(
            new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
        ));
```

- [ ] **Step 7: Edit `app/Http/Controllers/Public/CancellationPageController.php`**

Replace the three `tenant()->name` occurrences with `config('app.name')`:
- in `show()`: `return view('storno.done', ['cabinetName' => config('app.name')]);`
- in `show()`: `'cabinetName' => config('app.name'),`
- in `cancel()`: `return view('storno.done', ['cabinetName' => config('app.name')]);`

(The controller's method signatures stay `show(string $token)` / `cancel(string $token)` — once the `/storno/{token}` route drops `{tenant}` in Task 4, `$token` is still the single route param.)

- [ ] **Step 8: Confirm `Widget/CancellationController.php` needs no change**

Run: `grep -n "tenant" app/Http/Controllers/Widget/CancellationController.php`
Expected: no matches (it already takes `string $token` and calls `CabinetNotifier::notifyCancelled` — no tenant refs). No edit needed.

- [ ] **Step 9: Verify lint + no residual `tenant()` in these files**

Run: `php -l app/Support/CabinetNotifier.php && php -l app/Console/Commands/SendAppointmentReminders.php && php -l app/Http/Middleware/HandleInertiaRequests.php && php -l app/Providers/AppServiceProvider.php && php -l app/Http/Controllers/Widget/AppointmentController.php && php -l app/Http/Controllers/Public/CancellationPageController.php`
Expected: `No syntax errors detected` ×6.
Run: `grep -rn "tenant()" app/Support app/Console app/Http/Middleware/HandleInertiaRequests.php app/Providers/AppServiceProvider.php app/Http/Controllers/Widget app/Http/Controllers/Public`
Expected: no matches.

- [ ] **Step 10: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/config/mail.php backend/app/Support/CabinetNotifier.php backend/app/Console/Commands/SendAppointmentReminders.php backend/app/Http/Middleware/HandleInertiaRequests.php backend/app/Providers/AppServiceProvider.php backend/app/Http/Controllers/Widget/AppointmentController.php backend/app/Http/Controllers/Public/CancellationPageController.php
git commit -m "refactor(tenancy): config-driven cabinet email, direct reminder query, IP rate limits, config app name"
```

---

## Task 4: Merge routes into a single domain + drop the central SaaS surface

**Files:**
- Modify: `backend/routes/web.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/config/fortify.php`
- Delete: `backend/app/Http/Controllers/Central/DashboardController.php`
- Delete: `backend/resources/js/Pages/Central/Dashboard.vue`, `backend/resources/js/Pages/Central/Landing.vue`

The app now serves on one domain: a public landing + Fortify auth + the cabinet admin (dashboard + 4 German-URL resources, moved here from `routes/tenant.php`) + the public `/storno/{token}` page. The widget API drops the `{tenant}` path segment. The central (cross-customer) dashboard disappears entirely.

- [ ] **Step 1: Rewrite `routes/web.php`**

```php
<?php

use App\Http\Controllers\Public\CancellationPageController;
use App\Http\Controllers\Tenant\AvailabilityController;
use App\Http\Controllers\Tenant\AvailabilityExceptionController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\PractitionerController;
use App\Http\Controllers\Tenant\ServiceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Public landing.
 */
Route::get('/', fn () => Inertia::render('Central/Landing'))->name('landing');

/*
 * Cabinet admin (single tenant). German URLs, route names kept stable.
 */
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

    Route::resource('behandler', PractitionerController::class)
        ->names('tenant.practitioners')
        ->parameters(['behandler' => 'practitioner']);

    Route::resource('leistungen', ServiceController::class)
        ->names('tenant.services')
        ->parameters(['leistungen' => 'service']);

    Route::resource('sprechzeiten', AvailabilityController::class)
        ->names('tenant.availabilities')
        ->parameters(['sprechzeiten' => 'availability']);

    Route::resource('abwesenheiten', AvailabilityExceptionController::class)
        ->names('tenant.exceptions')
        ->parameters(['abwesenheiten' => 'exception']);
});

/*
 * Public cancellation page — the target of the link in appointment emails.
 * 'web' supplies session + CSRF for the POST form.
 */
Route::middleware(['throttle:storno'])
    ->prefix('storno')
    ->group(function () {
        Route::get('/{token}', [CancellationPageController::class, 'show'])->name('storno.show');
        Route::post('/{token}', [CancellationPageController::class, 'cancel'])->name('storno.cancel');
    });
```

> Note: route names `tenant.dashboard`, `tenant.practitioners.*`, etc. are kept as-is to avoid touching every Inertia page + the `TenantLayout` links in this PR (cosmetic rename = later follow-up). The `Central/Landing` Inertia page is reused as the public landing; we delete only `Central/Dashboard.vue`. Keep `Central/Landing.vue`.

**Correction to the delete list:** keep `resources/js/Pages/Central/Landing.vue` (it's the landing reused above); delete only `resources/js/Pages/Central/Dashboard.vue`.

- [ ] **Step 2: Rewrite `routes/api.php`**

```php
<?php

use App\Http\Controllers\Widget\AppointmentController;
use App\Http\Controllers\Widget\CancellationController;
use App\Http\Controllers\Widget\ServiceController;
use App\Http\Controllers\Widget\SlotController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/widget')->group(function () {
    Route::middleware('throttle:widget-read')->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{service}/practitioners', [ServiceController::class, 'practitioners']);
        Route::get('/slots', [SlotController::class, 'index']);
        Route::get('/appointments/{token}', [CancellationController::class, 'show']);
    });

    Route::middleware('throttle:widget-book')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel']);
    });
});
```

- [ ] **Step 3: Edit `config/fortify.php` middleware**

Change line 104 from `'middleware' => ['web', \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class],` to:

```php
    'middleware' => ['web'],
```

- [ ] **Step 4: Delete the central dashboard controller and Vue page**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git rm backend/app/Http/Controllers/Central/DashboardController.php
git rm backend/resources/js/Pages/Central/Dashboard.vue
```

- [ ] **Step 5: Verify routes resolve and no Stancl import remains in routes/config**

Run: `php artisan route:list`
Expected: lists `landing`, `tenant.dashboard`, `behandler.*`/`tenant.practitioners.*`, widget `v1/widget/*` (no `{tenant}`), `storno.show`/`storno.cancel` (path `storno/{token}`). No exception about a missing class.
Run: `grep -rn "Stancl\|{tenant}\|central_domains\|InitializeTenancy" routes/ config/fortify.php`
Expected: no matches.

- [ ] **Step 6: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/routes/web.php backend/routes/api.php backend/config/fortify.php
git add -A backend/app/Http/Controllers/Central backend/resources/js/Pages/Central
git commit -m "refactor(tenancy): single-domain routes (merge tenant routes, drop {tenant} + central dashboard)"
```

---

## Task 5: Delete the tenancy infrastructure and clean bootstrap

**Files:**
- Modify: `backend/bootstrap/providers.php`
- Modify: `backend/bootstrap/app.php`
- Delete: `backend/app/Providers/TenancyServiceProvider.php`, `backend/app/Tenancy/SearchPathBootstrapper.php`, `backend/app/Listeners/SwitchSearchPathForMigration.php`, `backend/app/Listeners/ResetSearchPathAfterMigration.php`, `backend/app/Listeners/NarrowSearchPathForTenantMigration.php`
- Delete: `backend/config/tenancy.php`, `backend/routes/tenant.php`
- Delete: `backend/app/Models/Tenant.php`, `backend/app/Models/Domain.php`, `backend/app/Models/Plan.php`, `backend/database/factories/TenantFactory.php`

Unregister the provider **first** (it's what loads `routes/tenant.php` and binds the search-path bootstrapper), then delete the files. The package is still installed, so `bootstrap/app.php` losing its `Stancl\…` exception imports is the only Stancl reference removed here.

- [ ] **Step 1: Remove the provider from `bootstrap/providers.php`**

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
];
```

- [ ] **Step 2: Rewrite `bootstrap/app.php` without the tenancy exception renderers**

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

- [ ] **Step 3: Delete the tenancy classes, config, routes, models, factory**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git rm backend/app/Providers/TenancyServiceProvider.php
git rm backend/app/Tenancy/SearchPathBootstrapper.php
git rm backend/app/Listeners/SwitchSearchPathForMigration.php
git rm backend/app/Listeners/ResetSearchPathAfterMigration.php
git rm backend/config/tenancy.php
git rm backend/routes/tenant.php
git rm backend/app/Models/Tenant.php
git rm backend/app/Models/Domain.php
git rm backend/app/Models/Plan.php
git rm backend/database/factories/TenantFactory.php
# the orphan listener is untracked — remove from disk:
rm -f backend/app/Listeners/NarrowSearchPathForTenantMigration.php
rmdir backend/app/Tenancy 2>/dev/null || true
```

- [ ] **Step 4: Verify the app boots with the package still present but no provider**

Run: `php artisan route:list`
Expected: same route list as Task 4, no errors. (Package present → no fatal; provider gone → tenancy no longer boots.)
Run: `grep -rn "App\\\\Models\\\\Tenant\b\|App\\\\Models\\\\Domain\|App\\\\Models\\\\Plan\|TenancyServiceProvider\|SearchPathBootstrapper" app/ config/ database/ routes/ bootstrap/ | grep -v "App\\\\Models\\\\Tenant\\\\"`
Expected: no matches (nothing references the deleted classes).

- [ ] **Step 5: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/bootstrap/providers.php backend/bootstrap/app.php
git add -A backend/app backend/config backend/routes backend/database/factories
git commit -m "refactor(tenancy): delete tenancy provider/bootstrapper/listeners/models/config/routes"
```

---

## Task 6: Convert the seeder to a single-DB seeder + run migrate:fresh

**Files:**
- Create: `backend/database/seeders/KidsClubSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Delete: `backend/database/seeders/KidsClubTenantSeeder.php`

No more `Plan`/`Tenant`/`Domain`/`->run()`. Seed one staff user + practitioners/services/availabilities directly. The provider is gone (Task 5), so `migrate:fresh` now runs cleanly on the single DB.

- [ ] **Step 1: Create `database/seeders/KidsClubSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\Tenant\Availability;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class KidsClubSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'michael@kidsclub.de'], [
            'name' => 'Michael Rohling',
            'password' => Hash::make('changeme'),
        ]);

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

        foreach ([1, 2, 3, 4, 5] as $day) {
            Availability::firstOrCreate(
                ['practitioner_id' => $anna->id, 'day_of_week' => $day],
                ['start_time' => '09:00', 'end_time' => '17:00']
            );
        }
    }
}
```

- [ ] **Step 2: Point `DatabaseSeeder` at the new seeder**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            KidsClubSeeder::class,
        ]);
    }
}
```

- [ ] **Step 3: Delete the old tenant seeder**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git rm backend/database/seeders/KidsClubTenantSeeder.php
```

- [ ] **Step 4: Run a fresh migrate + seed on the dev DB**

Run: `php artisan migrate:fresh --seed`
Expected: all migrations run (users, cache, jobs, two_factor, passkeys, practitioners, services, practitioner_service, availabilities, availability_exceptions, appointments, reminder_sent_at), then `KidsClubSeeder` completes with no error. No mention of `tenants`/`plans`/`domains`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/database/seeders/KidsClubSeeder.php backend/database/seeders/DatabaseSeeder.php
git commit -m "refactor(tenancy): single-DB KidsClubSeeder (no plan/tenant/domain)"
```

---

## Task 7: Collapse the two test suites into one, convert/delete tests, remove the package

**Files:**
- Modify: `backend/phpunit.xml`
- Modify: `backend/tests/Pest.php`
- Modify: `backend/composer.json` (`scripts.test`)
- Delete: `backend/tests/TenantTestCase.php`, `backend/tests/Feature/Central/CentralDashboardTest.php`, `RoutingTest.php`, `TenantManagementTest.php`, `backend/tests/Feature/Tenant/AuthTest.php`, `backend/tests/Feature/TenantSchema/CrossTenantIsolationTest.php`, `WidgetCrossTenantTest.php`, `WidgetTenantIdentificationTest.php`
- Modify (convert): the remaining `backend/tests/Feature/TenantSchema/*.php`
- Then: `composer remove stancl/tenancy`

This is the largest task. The two-process split existed only because committed tenant schemas could not coexist with a `RefreshDatabase` transaction. With tenancy gone, **everything runs under `RefreshDatabase`** in one process. The multi-tenancy-specific tests (cross-tenant isolation, tenant identification, central tenant management/routing/dashboard, tenant-scoped auth) test concepts that no longer exist → delete them. The rest are real business tests (booking, availability, mail, cancellation, page) that just need their tenancy scaffolding stripped.

- [ ] **Step 1: Rewrite `phpunit.xml` with a single test suite**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_NAME" value="Kids Club by zacp"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="masinga_booking_test"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="PRACTICE_NOTIFICATION_EMAIL" value="praxis@kidsclub.test"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
    </php>
</phpunit>
```

- [ ] **Step 2: Rewrite `tests/Pest.php` test-case bindings**

Replace the three `pest()->extend(...)` blocks (everything from the first `pest()->extend` down to the `Unit` binding) with:

```php
// All feature tests run under RefreshDatabase on the single database.
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Unit tests.
pest()->extend(Tests\TestCase::class)->in('Unit');
```

Leave the file's header comment, `expect()->extend`, and `function something()` as they are.

- [ ] **Step 3: Update the `composer.json` test script to a single run**

Replace the `"test"` array with:

```json
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test"
        ]
```

- [ ] **Step 4: Delete the obsolete multi-tenancy tests + TenantTestCase**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git rm backend/tests/TenantTestCase.php
git rm backend/tests/Feature/Central/CentralDashboardTest.php
git rm backend/tests/Feature/Central/RoutingTest.php
git rm backend/tests/Feature/Central/TenantManagementTest.php
git rm backend/tests/Feature/Tenant/AuthTest.php
git rm backend/tests/Feature/TenantSchema/CrossTenantIsolationTest.php
git rm backend/tests/Feature/TenantSchema/WidgetCrossTenantTest.php
git rm backend/tests/Feature/TenantSchema/WidgetTenantIdentificationTest.php
```

- [ ] **Step 5: Convert each remaining `tests/Feature/TenantSchema/*.php` using this exact recipe**

Apply, in every remaining file under `tests/Feature/TenantSchema/`, these mechanical transforms:

1. **Remove tenant initialization.** Delete any line that is exactly `tenancy()->initialize($this->tenant);` or `tenancy()->end();`. The data was created in the same default-connection DB, so after a write the rows are already queryable — no re-initialize needed.
2. **Strip the tenant segment from widget URLs.** In any helper that builds a widget URL, change a value like `'http://central.masinga-booking.test/api/v1/widget/testtenant/appointments'` (or `.../testtenant/services`, `/slots`, etc.) to the relative path `'/api/v1/widget/appointments'` (resp. `/services`, `/slots`, `/appointments/{token}`, `/appointments/{token}/cancel`). Use relative paths so the test host is irrelevant.
3. **Strip the tenant segment from storno URLs.** Change `route('storno.show', ['tenant' => …, 'token' => $t])` or hardcoded `'/storno/testtenant/'.$t` to `route('storno.show', ['token' => $t])` (resp. `route('storno.cancel', ['token' => $t])`).
4. **Replace `$this->makeTenantUser()`** with `\App\Models\User::factory()->create()` (and add `use App\Models\User;` if not present). Any assertion on `role`/`tenant_id` for that user is removed.
5. **Replace cabinet-recipient assertions.** Where a test asserted the cancellation mail went to a `tenant_owner`'s email, set the config in the test body — `config()->set('mail.practice_notification_address', 'praxis@kidsclub.test');` (or rely on the `PRACTICE_NOTIFICATION_EMAIL` env from `phpunit.xml`) — and assert the mail was queued `->hasTo('praxis@kidsclub.test')`.
6. **Replace any `tenant()->name` / cabinet-name literal** in an assertion with `config('app.name')` (which is `Kids Club by zacp` via `phpunit.xml`).
7. **Remove `$this->tenant` references** that survive (there is no tenant object anymore). If a test referenced `$this->tenant->id` to scope a query, drop the scope — the single DB holds only this cabinet's data.

Worked example — `tests/Feature/TenantSchema/WidgetBookingTest.php` helper + first test become:

```php
function bookUrl(): string
{
    return '/api/v1/widget/appointments';
}

it('books an appointment for a child', function () {
    [$p, $s, $startsAt] = bookingSetup();

    $this->postJson(bookUrl(), bookingPayload([
        'practitioner_id' => $p->id, 'service_id' => $s->id,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
    ]))->assertCreated()->assertJsonStructure(['cancellation_token', 'starts_at', 'ends_at']);

    $a = Appointment::firstOrFail();
    expect($a->status)->toBe('confirmed')->and($a->parent_consent_at)->not->toBeNull();
});
```

(Note the removed `tenancy()->initialize($this->tenant);` before `Appointment::firstOrFail()`.)

- [ ] **Step 6: Remove the package**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp/backend
composer remove stancl/tenancy
```

Expected: composer removes the package and dumps the autoloader with no error. If composer reports a remaining reference, grep for it (`grep -rn "Stancl" app/ config/ routes/ bootstrap/ database/ tests/`) and remove it, then re-run.

- [ ] **Step 7: Run the full suite and fix failures until green**

Run: `composer test`
Expected: one process, all Unit + Feature tests pass. Iterate on any failure using the recipe in Step 5 (most failures will be a missed URL segment or a leftover `tenancy()`/`$this->tenant` reference). Do not proceed until green.

- [ ] **Step 8: Verify no tenancy residue anywhere**

Run: `grep -rn "stancl\|Stancl\|tenancy()\|tenant()\|InitializeTenancy\|TenantTestCase\|->run(function" backend/app backend/config backend/routes backend/bootstrap backend/database backend/tests | grep -v "App\\\\Models\\\\Tenant\\\\"`
Expected: no matches. (`App\Models\Tenant\*` model namespace is intentionally kept — that grep excludes it.)

- [ ] **Step 9: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add -A backend/phpunit.xml backend/tests backend/composer.json backend/composer.lock
git commit -m "refactor(tenancy): collapse to one RefreshDatabase suite, convert tests, remove stancl/tenancy"
```

---

## Task 8: De-tenant the widget JS and WordPress plugin

**Files:**
- Modify: `backend/resources/js/widget/api.ts`
- Modify: `backend/resources/js/widget/main.ts`
- Modify: `backend/tests/widget/api.test.ts`
- Modify: `wordpress-plugin/masinga-booking/includes/class-shortcode.php`
- Modify: `wordpress-plugin/masinga-booking/includes/class-settings.php`

The embeddable widget keeps working but no longer needs a tenant slug: it just needs the API base URL. The WP plugin drops its "Tenant-Slug" setting.

- [ ] **Step 1: Rewrite `resources/js/widget/api.ts` to drop the tenant arg**

Change the signature and root:

```ts
export function createApi(base: string) {
    const root = `${base.replace(/\/$/, '')}/api/v1/widget`
```

Leave the entire `request<T>` body and the returned methods unchanged. (Only the function signature line and the `root` line change.)

- [ ] **Step 2: Update `resources/js/widget/main.ts` boot**

Remove the `tenant` dataset requirement. The boot block becomes:

```ts
    const apiBase = el.dataset.api ?? ''
    if (!apiBase) {
        console.error('[masinga] data-api is required')
        return
    }
    // ... (keep the rest of the mount logic)
    createApp(App, { api: createApi(apiBase), apiBase }).mount(container)
```

Remove the `const tenant = el.dataset.tenant ?? ''` line and any `tenant` passed into `createApp`/`App` props. If `App.vue` declared a `tenant` prop that is unused for requests, remove that prop too; if it is only forwarded to `createApi`, removing it here is sufficient.

- [ ] **Step 3: Update `tests/widget/api.test.ts` to the new signature**

Wherever the test calls `createApi(base, tenant)` (e.g. `createApi('http://x', 'kidsclub')`), change it to `createApi('http://x')`, and update any expected URL from `…/api/v1/widget/kidsclub/services` to `…/api/v1/widget/services`.

- [ ] **Step 4: Run the widget unit tests**

Run: `npm run test:widget`
Expected: all Vitest tests pass (17 baseline; the api test now asserts tenant-less URLs).

- [ ] **Step 5: Drop the tenant slug from the WP shortcode**

In `wordpress-plugin/masinga-booking/includes/class-shortcode.php`, remove the `$tenant` option read and the `data-tenant` attribute. The guard and output become:

```php
        $api = esc_url(get_option('masinga_booking_api', ''));
        if (! $api) {
            return '<!-- masinga-booking: api not configured -->';
        }
        $src = esc_url(rtrim((string) get_option('masinga_booking_api', ''), '/') . '/widget/masinga-widget.js');

        return sprintf(
            '<div data-masinga-booking data-api="%s"></div>' .
            '<script src="%s" defer></script>',
            $api, $src
        );
```

(Adjust the exact `sprintf` placeholders to match the surrounding code; the key change is dropping `data-tenant` and the `$tenant` variable.)

- [ ] **Step 6: Drop the Tenant-Slug setting from the WP settings page**

In `wordpress-plugin/masinga-booking/includes/class-settings.php`, remove the `register_setting('masinga_booking', 'masinga_booking_tenant', …)` call and the entire table row (`<tr>…Tenant-Slug…</tr>`) for `masinga_booking_tenant`. Keep the API-URL setting and row.

- [ ] **Step 7: Rebuild the widget bundle**

Run: `npm run build:widget`
Expected: `public/widget/masinga-widget.js` is rebuilt with no error. (Per project notes, `build:widget` empties `public/widget/` — if `public/widget/test.html` is needed for manual testing, restore it afterward; it is gitignored except the `!test.html` rule.)

- [ ] **Step 8: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/resources/js/widget/api.ts backend/resources/js/widget/main.ts backend/tests/widget/api.test.ts
git add wordpress-plugin/masinga-booking/includes/class-shortcode.php wordpress-plugin/masinga-booking/includes/class-settings.php
git commit -m "refactor(tenancy): widget + WP plugin embed without tenant slug"
```

---

## Task 9: Finalize env + layout app name, full verification

**Files:**
- Modify: `backend/.env.example`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue`

- [ ] **Step 1: Update `.env.example`**

Set `APP_NAME="Kids Club by zacp"`, remove any tenancy-related vars (e.g. `TENANCY_*`, central-domain entries) if present, and add (e.g. near `MAIL_FROM_*`):

```
PRACTICE_NOTIFICATION_EMAIL=praxis@kidsclub.de
```

- [ ] **Step 2: Point `TenantLayout.vue` at the shared app name**

Change line 6 from `const tenantName = computed(() => (page.props as any).tenant?.name ?? 'Cabinet')` to:

```ts
const tenantName = computed(() => (page.props as any).app_name ?? 'Cabinet')
```

(Leave the nav array and the rest of the layout unchanged — German URLs are preserved.)

- [ ] **Step 3: Full backend suite**

Run: `composer test`
Expected: all Unit + Feature tests green in one run.

- [ ] **Step 4: Full widget suite**

Run: `npm run test:widget`
Expected: all Vitest tests green.

- [ ] **Step 5: Fresh DB rebuild sanity**

Run: `php artisan migrate:fresh --seed && php artisan route:list`
Expected: clean migrate + seed; route list shows the single-domain routes with no `{tenant}` and no central dashboard.

- [ ] **Step 6: Confirm the package is gone**

Run: `composer show stancl/tenancy`
Expected: `Package "stancl/tenancy" not found` (or non-zero exit) — it is no longer a dependency.

- [ ] **Step 7: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/.env.example backend/resources/js/Layouts/TenantLayout.vue
git commit -m "refactor(tenancy): env + layout app name; single-project finalized"
```

---

## Notes for the implementer

- **Do not rename** `App\Models\Tenant\*` model namespace or `TenantLayout.vue` / `tenant.*` route names in this PR — those are intentional cosmetic follow-ups, out of scope. Touching them now would balloon the diff across every controller, page, and test.
- **Verification cadence:** every task ends with a concrete command. Tasks 1-6 verify with `route:list` / `php -l` / `migrate:fresh` because the suite can't be green mid-refactor. Task 7 is the gate that proves the full suite green after `composer remove`.
- **If `composer test` fails after Task 7**, the failure is almost always (a) a widget/storno URL that still carries a tenant segment, (b) a leftover `tenancy()->initialize` / `$this->tenant`, or (c) a cabinet-email assertion still expecting a `tenant_owner`. Re-apply the Step-5 recipe to the failing file.
- **Chrome verification** (booking flow end-to-end, `/storno/{token}`, login → dashboard) happens after the branch is implemented, per the project workflow — not inside these tasks.
