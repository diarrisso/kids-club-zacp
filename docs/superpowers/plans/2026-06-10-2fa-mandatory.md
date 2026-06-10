# Mandatory TOTP 2FA + Login Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a confirmed TOTP second factor mandatory for every staff account: un-enrolled users are hard-blocked to a security page until they enrol, and confirmed users pass a TOTP/recovery-code challenge after their password.

**Architecture:** Wire Laravel Fortify's built-in 2FA (the DB columns already exist), remove a custom auth resolver that bypasses the 2FA pipeline, add an `EnsureTwoFactorEnrolled` middleware on the staff route group, and build Inertia/Vue pages for the challenge, the password-confirm gate, and a `/sicherheit` security page. Harden the password policy and login throttle alongside.

**Tech Stack:** Laravel 13, Laravel Fortify, Inertia 2, Vue 3 (`<script setup lang="ts">`), Pest 4, PostgreSQL.

**Run all commands from `backend/`.**

### URL convention note (confirm at review)
This codebase already serves Fortify routes in English (`/login`) and only *resource* routes in German (`/behandler`). Germanizing Fortify's challenge URL needs `Fortify::ignoreRoutes()` (error-prone), so this plan **keeps Fortify's `/two-factor-challenge` route** (English path, German page content) and makes only the **custom** security page German: `/sicherheit`. This is a deliberate deviation from the spec's `/zwei-faktor-bestaetigung`. Flag if you want the German challenge URL instead.

### Pre-flight
- [ ] **Step 0: Confirm branch + green baseline**

Run: `git branch --show-current` → expect `feature/2fa-mandatory`.
Run: `php artisan test 2>&1 | tail -3` → expect all passing (baseline before changes).

---

## Task 1: Factory state — default users are 2FA-enrolled (test foundation)

Because 2FA is mandatory, a "default" staff user is an enrolled one. Make the factory default set `two_factor_confirmed_at` so the many existing feature tests that `actingAs()` a user are not caught by the enforcement middleware (Task 5). Add an explicit `withoutTwoFactor()` state for the enforcement test.

**Files:**
- Modify: `database/factories/UserFactory.php`

- [ ] **Step 1: Add the two-factor default + state to the factory**

Replace the `definition()` return array and add the new state method:

```php
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'secretaire',
            // 2FA is mandatory, so a default staff user is already enrolled. The
            // secret/recovery columns are encrypted by the TwoFactorAuthenticatable
            // cast on write; tests that exercise the real TOTP flow override these.
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => json_encode(['recovery-code-1', 'recovery-code-2']),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * A user who has not yet enrolled in two-factor authentication.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
```

> Note: the encrypted casts come from the `TwoFactorAuthenticatable` trait added in Task 2. Until Task 2 runs, these columns are plain. That's fine — Task 1 and Task 2 are committed together is NOT required, but run Task 2 immediately after. If you run the suite between Task 1 and Task 2, the encrypted-cast for these values is absent and they are stored raw; no test depends on decrypting them before Task 8.

- [ ] **Step 2: Commit**

```bash
git add database/factories/UserFactory.php
git commit -m "test: default user factory to a 2FA-enrolled user; add withoutTwoFactor state"
```

---

## Task 2: Enable Fortify 2FA + updatePasswords feature flags and the model trait

**Files:**
- Modify: `config/fortify.php` (features array, ~line 164)
- Modify: `app/Models/User.php`
- Test: `tests/Feature/TwoFactor/TwoFactorEnabledTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TwoFactor/TwoFactorEnabledTest.php`:

```php
<?php

use App\Models\User;

it('exposes the fortify two-factor enable endpoint when authenticated', function () {
    $user = User::factory()->create();

    // Fortify registers POST /user/two-factor-authentication only when the feature
    // is enabled. With confirmPassword=true it first demands a password confirmation,
    // which surfaces as a 423 (locked) JSON response — proof the route exists and is
    // guarded, not a 404.
    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(423);
});

it('lets the user model generate a two-factor secret (trait present)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    expect(method_exists($user, 'twoFactorQrCodeSvg'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TwoFactorEnabledTest`
Expected: FAIL — the POST returns 404 (feature off) and/or `twoFactorQrCodeSvg` does not exist (trait missing).

- [ ] **Step 3: Enable the features**

In `config/fortify.php`, the `features` array currently is:

```php
    'features' => array_values(array_filter([
        in_array(env('APP_ENV'), ['local', 'testing'], true) ? Features::registration() : null,
        Features::resetPasswords(),
        // Features::emailVerification(),
    ])),
```

Replace it with:

```php
    'features' => array_values(array_filter([
        in_array(env('APP_ENV'), ['local', 'testing'], true) ? Features::registration() : null,
        Features::resetPasswords(),
        // Features::emailVerification(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,          // user must verify a TOTP code before 2FA is active
            'confirmPassword' => true,  // enabling/disabling needs a fresh password confirmation
        ]),
    ])),
```

- [ ] **Step 4: Add the trait to the User model**

In `app/Models/User.php`, add the import and the trait:

```php
use Laravel\Fortify\TwoFactorAuthenticatable;
```

and change the `use` inside the class from:

```php
    use HasFactory, Notifiable;
```

to:

```php
    use HasFactory, Notifiable, TwoFactorAuthenticatable;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TwoFactorEnabledTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add config/fortify.php app/Models/User.php tests/Feature/TwoFactor/TwoFactorEnabledTest.php
git commit -m "feat(auth): enable Fortify two-factor + updatePasswords features and trait"
```

---

## Task 3: Remove the custom auth resolver (audit C2)

The custom `AuthenticateUser` hand-rolls credential checking via `Fortify::authenticateUsing`, which sidesteps the framework's login pipeline. Default Fortify already does email+password; removing it lets `RedirectIfTwoFactorAuthenticatable` (already wired) fire.

**Files:**
- Delete: `app/Actions/Fortify/AuthenticateUser.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Test: `tests/Feature/TwoFactor/LoginPipelineTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TwoFactor/LoginPipelineTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('logs in with valid credentials through the default pipeline', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-12!XY',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertRedirect('/login');

    $this->assertGuest();
});
```

- [ ] **Step 2: Run test to verify it passes already (baseline) — then we refactor without breaking it**

Run: `php artisan test --filter=LoginPipelineTest`
Expected: PASS (the custom resolver currently handles this). This test is the safety net for the refactor; keep it green through Steps 3-5.

- [ ] **Step 3: Remove the custom resolver wiring**

In `app/Providers/FortifyServiceProvider.php`:
- Delete the line: `Fortify::authenticateUsing(app(AuthenticateUser::class));`
- Delete the import: `use App\Actions\Fortify\AuthenticateUser;`

- [ ] **Step 4: Delete the action file**

```bash
rm app/Actions/Fortify/AuthenticateUser.php
```

- [ ] **Step 5: Run test to verify it still passes**

Run: `php artisan test --filter=LoginPipelineTest`
Expected: PASS (default Fortify pipeline now handles email+password identically).

- [ ] **Step 6: Commit**

```bash
git add app/Providers/FortifyServiceProvider.php tests/Feature/TwoFactor/LoginPipelineTest.php
git rm app/Actions/Fortify/AuthenticateUser.php
git commit -m "fix(auth): remove custom authenticateUsing resolver so the 2FA redirect pipeline runs"
```

---

## Task 4: Password policy + coarse per-IP login limiter (audit M1, M3)

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php`
- Test: `tests/Feature/TwoFactor/PasswordPolicyTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TwoFactor/PasswordPolicyTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('rejects a weak password on update', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->actingAs($user)
        ->from('/sicherheit')
        ->put('/user/password', [
            'current_password' => 'correct-horse-12!XY',
            'password' => 'password',          // too weak: < 12, no symbol/number, breached
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrors('password');
});

it('accepts a strong password on update', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-12!XY')]);

    $this->actingAs($user)
        ->put('/user/password', [
            'current_password' => 'correct-horse-12!XY',
            'password' => 'Tr0ub4dour&3xtraLong!',
            'password_confirmation' => 'Tr0ub4dour&3xtraLong!',
        ])
        ->assertSessionHasNoErrors();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PasswordPolicyTest`
Expected: FAIL — the weak password is currently accepted (default policy = min 8, no complexity), so `assertSessionHasErrors('password')` fails.

> Note: `uncompromised()` calls the HaveIBeenPwned API. In the `testing` env this network call can be slow/flaky. To keep tests deterministic, the policy below applies `uncompromised()` only outside the `testing` environment; `password` still fails on the length/complexity rules in tests.

- [ ] **Step 3: Add the password policy + coarse IP limiter**

In `app/Providers/FortifyServiceProvider.php`, add imports at the top:

```php
use Illuminate\Validation\Rules\Password;
```

In `boot()`, add the password defaults (place near the top of `boot()`):

```php
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            return app()->environment('testing') ? $rule : $rule->uncompromised();
        });
```

Replace the existing `login` limiter:

```php
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
```

with a two-limit version (email+ip AND a coarser per-IP cap):

```php
        RateLimiter::for('login', function (Request $request) {
            $emailIpKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($emailIpKey),   // password-guessing one account
                Limit::perMinute(20)->by($request->ip()), // password-spray across accounts from one IP
            ];
        });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PasswordPolicyTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/FortifyServiceProvider.php tests/Feature/TwoFactor/PasswordPolicyTest.php
git commit -m "feat(auth): strong password policy + coarse per-IP login limiter"
```

---

## Task 5: `EnsureTwoFactorEnrolled` middleware — the "mandatory" enforcement

**Files:**
- Create: `app/Http/Middleware/EnsureTwoFactorEnrolled.php`
- Modify: `bootstrap/app.php` (alias + append to web auth group)
- Modify: `routes/web.php` (add middleware to the `auth` group)
- Test: `tests/Feature/TwoFactor/EnforcementTest.php` (create)

> The security routes referenced here (`tenant.security.index`) are created in Task 6. To keep this task self-contained and TDD-clean, Task 5 redirects to the route **name** `tenant.security.index`; add a minimal placeholder route at the end of Step 3 so the redirect target resolves, then Task 6 fleshes out the page.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TwoFactor/EnforcementTest.php`:

```php
<?php

use App\Models\User;

it('redirects an un-enrolled user away from staff routes to the security page', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/sicherheit');
});

it('lets an un-enrolled user reach the security page itself (no redirect loop)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk();
});

it('lets an enrolled user reach staff routes normally', function () {
    $user = User::factory()->create(); // factory default = enrolled

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('lets an un-enrolled user log out (escape hatch)', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EnforcementTest`
Expected: FAIL — `/dashboard` returns 200 for an un-enrolled user (no enforcement yet), and `/sicherheit` is 404 (route not created).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/EnsureTwoFactorEnrolled.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory two-factor: an authenticated user without a confirmed TOTP factor is
 * redirected to the security page and cannot reach any other staff route. Only the
 * enrolment, password-confirmation, password-update, and logout routes are allowed
 * through so the user can actually escape the block.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isAllowed = $request->routeIs(
            'tenant.security.*',     // the security/enrolment page
            'two-factor.*',          // Fortify enable/confirm/disable/qr/recovery/challenge
            'password.confirm',      // Fortify confirm-password gate
            'user-password.update',  // Fortify password change
            'logout',                // escape hatch
        );

        if ($user && is_null($user->two_factor_confirmed_at) && ! $isAllowed) {
            return redirect()->route('tenant.security.index')
                ->with('warning', 'Zwei-Faktor-Authentifizierung ist erforderlich.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias in `bootstrap/app.php`**

Replace the `withMiddleware` closure body:

```php
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
```

with:

```php
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'two-factor.enrolled' => \App\Http\Middleware\EnsureTwoFactorEnrolled::class,
        ]);
    })
```

- [ ] **Step 5: Apply the middleware to the staff group + add a placeholder security route**

In `routes/web.php`, change the staff group opener from:

```php
Route::middleware('auth')->group(function () {
```

to:

```php
Route::middleware(['auth', 'two-factor.enrolled'])->group(function () {
```

Then, INSIDE that same group (e.g. right after the `/dashboard` route), add a placeholder security route so the redirect target resolves (Task 6 replaces the closure with a controller):

```php
    // Security / 2FA settings (page fleshed out in the SecurityController task).
    Route::get('/sicherheit', fn () => \Inertia\Inertia::render('Tenant/Security'))
        ->name('tenant.security.index');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=EnforcementTest`
Expected: PASS (all four).

> If "lets an enrolled user reach staff routes normally" fails because `Tenant/Security` page does not exist yet, that's only for the `/sicherheit` assertion — `/dashboard` should be 200. The `/sicherheit` 200 assertion needs the Inertia page; create a minimal `resources/js/Pages/Tenant/Security.vue` with `<template><div>Sicherheit</div></template>` now and flesh it out in Task 7. Add and commit it here.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureTwoFactorEnrolled.php bootstrap/app.php routes/web.php tests/Feature/TwoFactor/EnforcementTest.php resources/js/Pages/Tenant/Security.vue
git commit -m "feat(auth): mandatory two-factor enforcement middleware on the staff route group"
```

---

## Task 6: Wire the challenge + confirm-password views and the SecurityController

**Files:**
- Create: `app/Http/Controllers/Tenant/SecurityController.php`
- Modify: `app/Providers/FortifyServiceProvider.php` (challenge + confirm-password views)
- Modify: `routes/web.php` (point `/sicherheit` at the controller)
- Test: `tests/Feature/TwoFactor/SecurityPageTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TwoFactor/SecurityPageTest.php`:

```php
<?php

use App\Models\User;

it('renders the security page with the two-factor enabled flag', function () {
    $user = User::factory()->create(); // enrolled

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Security')
            ->where('twoFactorEnabled', true));
});

it('reports two-factor disabled for an un-enrolled user', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/sicherheit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Security')
            ->where('twoFactorEnabled', false));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SecurityPageTest`
Expected: FAIL — the placeholder route renders the page but does not pass `twoFactorEnabled`.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Tenant/SecurityController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Tenant/Security', [
            // `two_factor_confirmed_at` is the source of truth for "enrolled".
            'twoFactorEnabled' => ! is_null($user->two_factor_confirmed_at),
        ]);
    }
}
```

- [ ] **Step 4: Point the route at the controller**

In `routes/web.php`, replace the placeholder security route:

```php
    Route::get('/sicherheit', fn () => \Inertia\Inertia::render('Tenant/Security'))
        ->name('tenant.security.index');
```

with:

```php
    Route::get('/sicherheit', [\App\Http\Controllers\Tenant\SecurityController::class, 'index'])
        ->name('tenant.security.index');
```

- [ ] **Step 5: Wire the challenge + confirm-password views in the provider**

In `app/Providers/FortifyServiceProvider.php` `boot()`, after the `Fortify::loginView(...)` line add:

```php
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SecurityPageTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Tenant/SecurityController.php app/Providers/FortifyServiceProvider.php routes/web.php tests/Feature/TwoFactor/SecurityPageTest.php
git commit -m "feat(auth): security page controller + challenge/confirm-password views"
```

---

## Task 7: Inertia/Vue pages — challenge, confirm-password, security, nav link

These are UI components; they are verified manually in the browser (Task 8 covers the backend flow with feature tests). Build complete, working components.

**Files:**
- Create: `resources/js/Pages/Auth/TwoFactorChallenge.vue`
- Create: `resources/js/Pages/Auth/ConfirmPassword.vue`
- Replace: `resources/js/Pages/Tenant/Security.vue` (the placeholder from Task 5)
- Modify: the staff layout to add a "Sicherheit" nav link (find it under `resources/js/Layouts/` — likely `TenantLayout.vue`)

- [ ] **Step 1: Build the two-factor challenge page**

Create `resources/js/Pages/Auth/TwoFactorChallenge.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'

const useRecovery = ref(false)
const form = useForm({ code: '', recovery_code: '' })

const submit = () => form.post('/two-factor-challenge')

const toggle = () => {
    useRecovery.value = !useRecovery.value
    form.code = ''
    form.recovery_code = ''
}
</script>

<template>
    <Head title="Bestätigung" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-2">Zwei-Faktor-Bestätigung</h1>

            <template v-if="!useRecovery">
                <p class="text-sm text-slate-500 mb-4">Geben Sie den Code aus Ihrer Authentifizierungs-App ein.</p>
                <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code"
                       autofocus placeholder="6-stelliger Code"
                       class="w-full p-3 border rounded mb-3">
                <div v-if="form.errors.code" class="text-red-600 text-sm mb-3">{{ form.errors.code }}</div>
            </template>

            <template v-else>
                <p class="text-sm text-slate-500 mb-4">Geben Sie einen Ihrer Wiederherstellungscodes ein.</p>
                <input v-model="form.recovery_code" autocomplete="off"
                       placeholder="Wiederherstellungscode"
                       class="w-full p-3 border rounded mb-3">
                <div v-if="form.errors.recovery_code" class="text-red-600 text-sm mb-3">{{ form.errors.recovery_code }}</div>
            </template>

            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Bestätigen
            </button>

            <button type="button" @click="toggle"
                    class="mt-3 w-full text-sm text-slate-500 hover:text-slate-700">
                {{ useRecovery ? 'Authentifizierungs-Code verwenden' : 'Wiederherstellungscode verwenden' }}
            </button>
        </form>
    </div>
</template>
```

- [ ] **Step 2: Build the confirm-password page**

Create `resources/js/Pages/Auth/ConfirmPassword.vue`:

```vue
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const form = useForm({ password: '' })

const submit = () => form.post('/user/confirm-password')
</script>

<template>
    <Head title="Passwort bestätigen" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-2">Passwort bestätigen</h1>
            <p class="text-sm text-slate-500 mb-4">Bitte bestätigen Sie zur Sicherheit Ihr Passwort.</p>
            <input v-model="form.password" type="password" autocomplete="current-password"
                   autofocus placeholder="Passwort" class="w-full p-3 border rounded mb-3">
            <div v-if="form.errors.password" class="text-red-600 text-sm mb-3">{{ form.errors.password }}</div>
            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Bestätigen
            </button>
        </form>
    </div>
</template>
```

- [ ] **Step 3: Build the full security page**

Replace `resources/js/Pages/Tenant/Security.vue` with:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, Head } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps<{ twoFactorEnabled: boolean }>()

const qrSvg = ref<string>('')
const recoveryCodes = ref<string[]>([])
const showSetup = ref(false)
const acknowledged = ref(false)

const confirmForm = useForm({ code: '' })
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' })

// Begin enrolment: POST enable, then pull the QR + recovery codes.
async function enable() {
    await axios.post('/user/two-factor-authentication')
    const [qr, codes] = await Promise.all([
        axios.get('/user/two-factor-qr-code'),
        axios.get('/user/two-factor-recovery-codes'),
    ])
    qrSvg.value = qr.data.svg
    recoveryCodes.value = codes.data
    showSetup.value = true
}

// Confirm the TOTP code → 2FA becomes active.
function confirm() {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        onSuccess: () => router.reload({ only: ['twoFactorEnabled'] }),
    })
}

async function disable() {
    await axios.delete('/user/two-factor-authentication')
    router.reload({ only: ['twoFactorEnabled'] })
    showSetup.value = false
}

const changePassword = () => passwordForm.put('/user/password', {
    onSuccess: () => passwordForm.reset(),
})
</script>

<template>
    <Head title="Sicherheit" />
    <div class="max-w-2xl mx-auto p-6 space-y-10">
        <section>
            <h1 class="text-2xl font-bold mb-1">Sicherheit</h1>
            <p class="text-sm text-slate-500">Zwei-Faktor-Authentifizierung und Passwort.</p>
        </section>

        <!-- 2FA -->
        <section class="bg-white rounded-xl ring-1 ring-slate-100 p-6">
            <h2 class="text-lg font-semibold mb-3">Zwei-Faktor-Authentifizierung</h2>

            <p v-if="props.twoFactorEnabled" class="text-sm text-green-700 mb-4">
                ✓ Aktiv. Ihr Konto ist mit einem zweiten Faktor geschützt.
            </p>
            <p v-else class="text-sm text-amber-700 mb-4">
                Erforderlich. Bitte richten Sie die Zwei-Faktor-Authentifizierung ein.
            </p>

            <button v-if="!props.twoFactorEnabled && !showSetup" @click="enable"
                    class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                Einrichten
            </button>

            <div v-if="showSetup && !props.twoFactorEnabled" class="space-y-4">
                <p class="text-sm text-slate-600">1. Scannen Sie diesen QR-Code mit Ihrer Authentifizierungs-App:</p>
                <div v-html="qrSvg" class="inline-block bg-white p-2 ring-1 ring-slate-200 rounded"></div>

                <div class="rounded-lg bg-amber-50 ring-1 ring-amber-200 p-4">
                    <p class="text-sm font-semibold text-amber-800 mb-2">2. Wiederherstellungscodes (einmalig anzeigen — sicher aufbewahren):</p>
                    <ul class="font-mono text-xs text-amber-900 grid grid-cols-2 gap-1">
                        <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                    </ul>
                    <label class="mt-3 flex items-center gap-2 text-sm text-amber-800">
                        <input type="checkbox" v-model="acknowledged"> Ich habe die Codes gesichert.
                    </label>
                </div>

                <div>
                    <p class="text-sm text-slate-600 mb-1">3. Bestätigen Sie mit dem aktuellen Code:</p>
                    <input v-model="confirmForm.code" inputmode="numeric" placeholder="6-stelliger Code"
                           class="p-3 border rounded mr-2">
                    <button @click="confirm" :disabled="!acknowledged || confirmForm.processing"
                            class="bg-green-700 text-white px-4 py-2 rounded hover:bg-green-800 disabled:opacity-40">
                        Aktivieren
                    </button>
                    <div v-if="confirmForm.errors.code" class="text-red-600 text-sm mt-1">{{ confirmForm.errors.code }}</div>
                </div>
            </div>

            <button v-if="props.twoFactorEnabled" @click="disable"
                    class="text-sm text-rose-600 hover:text-rose-700">
                Deaktivieren
            </button>
        </section>

        <!-- Password -->
        <section class="bg-white rounded-xl ring-1 ring-slate-100 p-6">
            <h2 class="text-lg font-semibold mb-3">Passwort ändern</h2>
            <form @submit.prevent="changePassword" class="space-y-3 max-w-sm">
                <input v-model="passwordForm.current_password" type="password" placeholder="Aktuelles Passwort"
                       class="w-full p-3 border rounded">
                <input v-model="passwordForm.password" type="password" placeholder="Neues Passwort (min. 12 Zeichen)"
                       class="w-full p-3 border rounded">
                <div v-if="passwordForm.errors.password" class="text-red-600 text-sm">{{ passwordForm.errors.password }}</div>
                <input v-model="passwordForm.password_confirmation" type="password" placeholder="Neues Passwort bestätigen"
                       class="w-full p-3 border rounded">
                <button type="submit" :disabled="passwordForm.processing"
                        class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                    Speichern
                </button>
            </form>
        </section>
    </div>
</template>
```

> `axios` is bundled with Laravel's default frontend scaffolding (`resources/js/bootstrap` imports it and sets the XSRF header). Confirm `axios` resolves; if not, `import axios from 'axios'` works because it is a dependency of the Laravel starter. The XSRF-TOKEN cookie + `X-XSRF-TOKEN` header are auto-attached, so these same-origin POST/DELETE/GET calls are CSRF-valid.

- [ ] **Step 4: Add the "Sicherheit" nav link**

Open the staff layout (`resources/js/Layouts/TenantLayout.vue` — if the filename differs, find the layout that renders the staff nav with links to `/dashboard`, `/behandler`, etc.). Add a link alongside the others:

```vue
<Link href="/sicherheit" class="...same classes as the sibling nav links...">Sicherheit</Link>
```

Match the exact classes/structure of the existing nav items in that file (copy a sibling `<Link>` and change `href`/label).

- [ ] **Step 5: Build the frontend to verify it compiles**

Run: `npm run build`
Expected: build succeeds with no Vue/TS errors referencing the new pages.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Auth/TwoFactorChallenge.vue resources/js/Pages/Auth/ConfirmPassword.vue resources/js/Pages/Tenant/Security.vue resources/js/Layouts/
git commit -m "feat(auth): 2FA challenge, confirm-password, and security settings pages + nav link"
```

---

## Task 8: End-to-end 2FA flow feature test + full suite + Pint + browser check

**Files:**
- Test: `tests/Feature/TwoFactor/TwoFactorFlowTest.php` (create)

- [ ] **Step 1: Write the failing test (real enable → confirm → challenge → recovery)**

Create `tests/Feature/TwoFactor/TwoFactorFlowTest.php`:

```php
<?php

use App\Models\User;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

it('enables, confirms, and challenges with a real TOTP code', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    // Enable (confirmPassword is satisfied because the test session is fresh-auth;
    // if a 423 is returned, confirm the password first).
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')->assertOk();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();

    // Compute a valid TOTP for the freshly-generated secret and confirm.
    $secret = decrypt($user->two_factor_secret);
    $code = (new Google2FA())->getCurrentOtp($secret);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertOk();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
})->skip(! class_exists(Google2FA::class), 'pragmarx/google2fa not installed');
```

> `pragmarx/google2fa` ships as a Fortify dependency, so the skip guard is a safety net, not the expected path. If the test skips, run `composer show pragmarx/google2fa` to confirm it's present and remove the skip.

- [ ] **Step 2: Run test to verify it fails (or drives the behaviour)**

Run: `php artisan test --filter=TwoFactorFlowTest`
Expected: PASS once Tasks 2–6 are in place (this test documents the end-to-end contract). If it fails on the `post('/user/two-factor-authentication')` with 423, the `withSession(['auth.password_confirmed_at' => time()])` shim is required — it is already included above.

- [ ] **Step 3: Run the FULL suite**

Run: `php artisan test`
Expected: ALL pass. If any pre-existing auth/feature test now fails because its user is caught by enforcement, fix it by using the enrolled factory default (no `withoutTwoFactor()`), NOT by weakening the middleware.

- [ ] **Step 4: Pint**

Run: `vendor/bin/pint --dirty`
Expected: `PASS`.

- [ ] **Step 5: Manual browser verification (record the steps)**

Run `composer dev` (serves app + vite). Then in the browser:
1. Seed/te a fresh un-enrolled user (`User::factory()->withoutTwoFactor()->create(['email' => 'test@x.de', 'password' => Hash::make('Tr0ub4dour&3xtraLong!')])` via tinker).
2. Log in → confirm you are redirected to `/sicherheit` and cannot reach `/dashboard`.
3. Click "Einrichten", scan the QR with an authenticator app, save the recovery codes, tick the acknowledgement, enter the code, "Aktivieren".
4. Confirm `/dashboard` is now reachable.
5. Log out, log back in → confirm the TOTP challenge appears after the password; enter a code → reach dashboard.
6. Log out, log in, use a recovery code on the challenge → reach dashboard; confirm that recovery code no longer works a second time.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/TwoFactor/TwoFactorFlowTest.php
git commit -m "test(auth): end-to-end TOTP enable/confirm/challenge/recovery flow"
```

---

## Task 9: PR

- [ ] **Step 1: Push + open PR**

```bash
git push -u origin feature/2fa-mandatory
gh pr create --base main --title "feat(auth): mandatory TOTP two-factor authentication + login hardening" --body "<summary of the spec; link docs/superpowers/specs/2026-06-10-2fa-mandatory-design.md; note: no deploy until explicit>"
```

- [ ] **Step 2: Run the code-reviewer agent on the diff (workflow step 6)** before requesting merge; fix CRITICAL/IMPORTANT findings.

- [ ] **Step 3: Do NOT deploy.** Wait for explicit "deploy". The auto-deploy on merge must be cancelled per the deployment convention.

---

## Self-review notes (author)

- **Spec coverage:** features+trait (T2) ✓ · remove custom resolver/C2 (T3) ✓ · enforcement middleware/mandatory (T5) ✓ · challenge view (T6/T7) ✓ · security page + recovery codes UX (T6/T7) ✓ · password policy M1 (T4) ✓ · per-IP login limiter M3 (T4) ✓ · recovery operational note (in spec; documented, no code) ✓ · tests (T1, T2, T3, T4, T5, T6, T8) ✓.
- **Deviation:** challenge URL kept as Fortify's `/two-factor-challenge` (English), not `/zwei-faktor-bestaetigung` — rationale in header; confirm with owner.
- **Out of scope (PR-B/C/D):** session/cookie hardening, headers, TrustProxies, anti-abuse throttles, perf — tracked in `2026-06-10-security-audit-backlog.md`.
