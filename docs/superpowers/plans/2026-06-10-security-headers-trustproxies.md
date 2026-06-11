# PR-B — TrustProxies + Security Headers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix per-IP rate limiting behind Cloudflare (TrustProxies), add security response headers (XFO/nosniff/Referrer/Permissions/HSTS/CSP) on web + a slim API profile, harden session/transport (secure cookie default, forced HTTPS, env-driven CORS, storno no-referrer).

**Architecture:** `trustProxies(at: '*')` in `bootstrap/app.php` (paired ops: CF-only firewall). One `SecureHeaders` middleware class with two profiles (`web` default, `api` via middleware parameter), appended to both groups. Config-level defaults for session.secure; `URL::forceScheme` in production; CORS origins from `WIDGET_ALLOWED_ORIGINS`.

**Tech Stack:** Laravel 13 · Pest 4 · PostgreSQL. All commands from `backend/`.

**Branch:** `feature/security-headers-trustproxies` (created from origin/main; spec committed).
Spec: `docs/superpowers/specs/2026-06-10-security-headers-trustproxies-design.md`.

**Conventions:** TDD (failing test RUN first), `vendor/bin/pint --dirty` before each commit, commit per task. Current suite baseline: **176 passed**. Never include unrelated dirty root files in commits.

---

### Task 1: TrustProxies

**Files:**
- Modify: `backend/bootstrap/app.php` (withMiddleware block)
- Test: `backend/tests/Feature/TrustProxiesTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TrustProxiesTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

it('resolves the real client ip from x-forwarded-for', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertSee('203.0.113.7');
});

it('treats x-forwarded-proto https as a secure request', function () {
    Route::get('/_test/secure', fn () => request()->isSecure() ? 'secure' : 'insecure');

    $this->get('/_test/secure', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertContent('secure'); // exact match — assertSee('secure') would substring-match 'insecure'
});

it('ignores x-forwarded-host so the host cannot be poisoned', function () {
    Route::get('/_test/host', fn () => request()->getHost());

    $baseline = $this->get('/_test/host')->content();

    $this->get('/_test/host', [
        'X-Forwarded-For' => '203.0.113.7',     // proves proxy trust is active
        'X-Forwarded-Host' => 'evil.example',   // must have zero effect
    ])->assertOk()->assertContent($baseline);
});
```

> **As-built corrections** (post-review): `assertSee('secure')` was a latent false-green (substring of `insecure`) → exact `assertContent`. A third guard test pins that `X-Forwarded-Host` is ignored — see the bitmask note below.

- [ ] **Step 2: Run — must fail**

Run: `php artisan test --filter=TrustProxiesTest`
Expected: FAIL — first test sees `127.0.0.1`, second sees `insecure` (no proxies trusted).

- [ ] **Step 3: Implement**

In `backend/bootstrap/app.php`: add `use Illuminate\Http\Request;` and inside `->withMiddleware(function (Middleware $middleware) {` add (before the existing `web(append:)`):

```php
        // Production sits behind Cloudflare -> nginx. Trusting the proxy is what
        // makes $request->ip() return the real client (all per-IP rate limiters
        // depend on it) and X-Forwarded-Proto produce https URLs. `at: '*'` is
        // only safe because the VPS firewall accepts 80/443 exclusively from
        // Cloudflare ranges (ops checklist in the PR-B spec) — a direct-to-origin
        // caller could otherwise spoof X-Forwarded-For.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );
```

> **As-built correction** (post-review, commit `c2ab9d8`): the original plan also trusted `HEADER_X_FORWARDED_HOST | HEADER_X_FORWARDED_PORT`. Review caught that Cloudflare passes `X-Forwarded-Host`/`-Port` through **untouched** (it only appends XFF and overwrites XFP), so the firewall argument doesn't cover them — a client could poison `$request->getHost()` and thus the absolute URLs in booking-confirmation emails. The bitmask is XFF|XFP **only**; nginx forwards the true `Host` header and proto=https defaults the port to 443.

- [ ] **Step 4: Run — must pass**

`php artisan test --filter=TrustProxiesTest` → PASS (3, incl. the as-built host-poisoning guard). Then `composer test` → full suite green (the throttle-dependent tests must not regress).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/bootstrap/app.php backend/tests/Feature/TrustProxiesTest.php
git commit -m "feat(security): trust proxy headers so per-IP rate limiting works behind Cloudflare"
```

---

### Task 2: SecureHeaders middleware — web profile

**Files:**
- Create: `backend/app/Http/Middleware/SecureHeaders.php`
- Modify: `backend/bootstrap/app.php` (append to web group)
- Test: `backend/tests/Feature/SecureHeadersTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SecureHeadersTest.php`:

```php
<?php

it('sends the security headers on web responses', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

it('omits hsts on insecure requests but sends it on https', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('omits the csp outside production', function () {
    $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
});

it('enforces a strict csp in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self'")            // regression guard: never 'unsafe-inline' scripts
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("img-src 'self' data: blob:")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'");
});
```

(NOTE for the implementer: if `app()->detectEnvironment` proves unreliable mid-test in Laravel 13, the alternative is `$this->app['env'] = 'production';` — use whichever actually flips `app()->environment('production')` and state which in the report. `/login` exists via Fortify and renders for guests.)

- [ ] **Step 2: Run — must fail**

`php artisan test --filter=SecureHeadersTest` → FAIL (headers absent).

- [ ] **Step 3: Implement the middleware**

Create `backend/app/Http/Middleware/SecureHeaders.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    /**
     * Security response headers. Two profiles:
     *  - web (default): full set incl. clickjacking protection and, in
     *    production, a strict CSP. The embeddable widget is NOT affected —
     *    it runs on the practice's site under the HOST page's CSP; this
     *    header governs only our own pages (staff app, storno, landing).
     *  - api: slim set for JSON/font responses (CSP/XFO are meaningless there
     *    and Referrer-Policy is stricter since API URLs never need a referrer).
     */
    public function handle(Request $request, Closure $next, string $profile = 'web'): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($profile === 'api') {
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (app()->environment('production')) {
            // 'unsafe-inline' styles: Inertia/Tailwind inline style attributes +
            // the storno page's <style> block. Scripts stay strict 'self' — the
            // Vite build emits only hashed external files and Inertia passes page
            // data via a data-page attribute, not inline <script>. blob:/data:
            // images: Appearance logo preview (createObjectURL) and QR previews.
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
```

In `backend/bootstrap/app.php`: add `use App\Http\Middleware\SecureHeaders;` and extend the web append:

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecureHeaders::class,
        ]);
```

- [ ] **Step 4: Run — must pass**

`php artisan test --filter=SecureHeadersTest` → PASS (4). `composer test` → green.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Http/Middleware/SecureHeaders.php backend/bootstrap/app.php backend/tests/Feature/SecureHeadersTest.php
git commit -m "feat(security): SecureHeaders middleware — XFO, nosniff, referrer/permissions policy, HSTS, production CSP"
```

---

### Task 3: API slim profile

**Files:**
- Modify: `backend/bootstrap/app.php` (api group)
- Test: extend `backend/tests/Feature/SecureHeadersTest.php`

- [ ] **Step 1: Failing test** — append to SecureHeadersTest.php:

```php
it('sends only the slim profile on api responses', function () {
    $response = $this->getJson('/api/v1/widget/config');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeaderMissing('X-Frame-Options');
    $response->assertHeaderMissing('Content-Security-Policy');
});
```

Run → FAIL (no headers on api).

- [ ] **Step 2: Implement** — in `backend/bootstrap/app.php` add inside withMiddleware:

```php
        $middleware->api(append: [
            SecureHeaders::class.':api',
        ]);
```

- [ ] **Step 3: Run** — `php artisan test --filter=SecureHeadersTest` → PASS (5). `composer test` green (WidgetFontTest's CORS/Cache-Control assertions must still pass — HandleCors runs globally before this append; verify no header conflicts).

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/bootstrap/app.php backend/tests/Feature/SecureHeadersTest.php
git commit -m "feat(security): slim security-header profile on api responses"
```

---

### Task 4: Session secure default + forced HTTPS

**Files:**
- Modify: `backend/config/session.php:172`
- Modify: `backend/app/Providers/AppServiceProvider.php` (boot)
- Test: `backend/tests/Feature/TransportHardeningTest.php` (new)

- [ ] **Step 1: Failing test**

Create `backend/tests/Feature/TransportHardeningTest.php`:

```php
<?php

it('defaults the session cookie to secure outside local and testing', function () {
    // The config default is computed from APP_ENV at config load; in the test
    // env it must be false (http test client), and the default expression must
    // yield true for production. We assert the production branch directly:
    $default = (require base_path('config/session.php'))['secure'];

    expect($default)->toBeFalse(); // APP_ENV=testing here

    // Simulate the production read of the same expression:
    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $prod = (require base_path('config/session.php'))['secure'];
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';

    expect($prod)->toBeTrue();
});

it('forces https urls in production', function () {
    app()->detectEnvironment(fn () => 'production');
    (new App\Providers\AppServiceProvider(app()))->boot();

    expect(url('/storno/abc'))->toStartWith('https://');
});
```

(NOTE: the first test re-`require`s the config file to exercise the default expression without booting a second app. If `env()` reads cached values making putenv ineffective, fall back to asserting the expression inline: read the config file source and skip the putenv dance — implementer judges and reports. The second test re-runs boot() manually after flipping the environment.)

Run → FAIL (secure default currently null; urls http).

- [ ] **Step 2: Implement**

`backend/config/session.php` line ~172:

```php
    // Secure-by-default everywhere except local dev / CI (http test client).
    // Overridable via SESSION_SECURE_COOKIE either way.
    'secure' => env('SESSION_SECURE_COOKIE', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
```

`backend/app/Providers/AppServiceProvider.php` boot() — first lines + import `Illuminate\Support\Facades\URL`:

```php
        if ($this->app->environment('production')) {
            // Belt-and-braces with TrustProxies' X-Forwarded-Proto: emails and
            // storno/reset links must never go out as http://.
            URL::forceScheme('https');
        }
```

- [ ] **Step 3: Run** — `php artisan test --filter=TransportHardeningTest` → PASS. `composer test` green (the http test client must still work: APP_ENV=testing → secure=false).

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/config/session.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/TransportHardeningTest.php
git commit -m "feat(security): secure session cookie by default off-local + forced https urls in production"
```

---

### Task 5: Env-driven CORS + storno no-referrer meta

**Files:**
- Modify: `backend/config/cors.php:22`
- Modify: `backend/resources/views/storno/show.blade.php` (head)
- Test: extend `backend/tests/Feature/TransportHardeningTest.php` + storno page test

- [ ] **Step 1: Failing tests** — append to TransportHardeningTest.php:

```php
it('drives cors origins from the env with a wildcard fallback', function () {
    expect(config('cors.allowed_origins'))->toBe(['*']); // default

    config(['cors.allowed_origins' => ['https://praxis.example']]);
    $this->getJson('/api/v1/widget/config', ['Origin' => 'https://praxis.example'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://praxis.example');
    $this->getJson('/api/v1/widget/config', ['Origin' => 'https://evil.example'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
```

And the storno meta test — check how existing storno tests create an appointment (read `tests/Feature/TenantSchema/CancellationPageTest.php` and copy its factory setup):

```php
it('declares no-referrer on the storno page so the token cannot leak', function () {
    $appointment = \App\Models\Tenant\Appointment::factory()->create();

    $this->get(route('storno.show', ['token' => $appointment->cancellation_token]))
        ->assertOk()
        ->assertSee('<meta name="referrer" content="no-referrer">', false);
});
```

(Use `uses(RefreshDatabase::class)` at the top of the file if not already — required by the factory. Check CancellationPageTest for the exact factory invocation incl. required relations.)

Run → storno meta test FAILS; CORS default already `['*']` via the new expression — the env-driven part is config-level, the assertion on `['*']` default passes pre-change too, that's fine (pin).

- [ ] **Step 2: Implement**

`backend/config/cors.php`:

```php
    // Tightenable per-deployment without a code change: set
    // WIDGET_ALLOWED_ORIGINS="https://praxis-domain.de" (comma-separated for
    // several) in the prod .env once the WP embed domain is final. Wildcard
    // default is acceptable: anonymous read API, supports_credentials=false.
    'allowed_origins' => array_map('trim', explode(',', env('WIDGET_ALLOWED_ORIGINS', '*'))),
```

`backend/resources/views/storno/show.blade.php` — inside `<head>` after the viewport meta:

```html
    {{-- The cancellation token sits in this page's URL: no outbound request may carry it in Referer. --}}
    <meta name="referrer" content="no-referrer">
```

> **As-built correction** (post-review, commit `10555da`): the same meta also goes into **`storno/done.blade.php`** — it renders at the same token-bearing URL (GET when already cancelled + as the POST response), so the invariant must hold for both views. The test covers both: it asserts the meta on the show view, then flips the appointment to `cancelled`, re-GETs the same token URL (which routes to the done view) and asserts the meta there too. Also hardened: `cors.php` wraps the expression in `array_values(array_filter(...))` (empty env value or trailing comma can no longer emit a malformed empty ACAO header), and `phpunit.xml` pins `WIDGET_ALLOWED_ORIGINS=*` against local `.env` flake.

- [ ] **Step 3: Run** — `php artisan test --filter=TransportHardeningTest` → PASS. `composer test` green.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/config/cors.php backend/resources/views/storno/show.blade.php backend/tests/Feature/TransportHardeningTest.php
git commit -m "feat(security): env-driven cors origins + no-referrer meta on the storno page"
```

---

### Task 6: Final verification — suites, prod-build CSP walkthrough, push + PR

- [ ] **Step 1: Full suites**

```bash
composer test            # as-built: 176 baseline + 14 new = 190, all green
                         # (TrustProxiesTest 3 + SecureHeadersTest 7 + TransportHardeningTest 4)
npm run test:widget      # 102 untouched
vendor/bin/pint --test   # only pre-existing main debt may appear; OUR files clean
npm run build && npm run build:widget
```

- [ ] **Step 2: CSP walkthrough on a production build (Chrome)** — the highest-risk item:

```bash
npm run build   # real assets, no Vite dev server
APP_ENV=production php artisan serve --port=8012   # serves with CSP enforced
```

In Chrome (browser automation): login (2FA challenge!), dashboard, Termine calendar, Behandler list, **Erscheinungsbild** (color pickers, pick a logo file → blob: preview must render), QR-Code page (SVG/PNG images), logout; storno page for a seeded appointment. Watch `read_console_messages` with pattern `Content-Security-Policy|CSP|Refused` — ZERO violations allowed. If a violation appears: fix the CSP directive list (e.g. missing `blob:`), re-run, document the addition.
⚠️ Revert any temporary .env/APP_ENV changes afterwards; kill the temp server.

- [ ] **Step 3: Push + PR**

```bash
git push -u origin feature/security-headers-trustproxies
gh pr create --title "feat(security): TrustProxies + security headers + transport hardening [PR-B]" --body "<summary: problem table, headers table, ops checklist from the spec, CSP walkthrough result>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Then final code-reviewer agent on the branch diff + CodeRabbit loop. **No deploy without explicit "deploy".**

---

## As-built deviations (post-review, all test-pinned)

- **TrustProxies bitmask = XFF|XFP only** (`c2ab9d8`) — see the correction note in Task 1.
- **Ziggy removed entirely** (`7a8c3c2`): `@routes` rendered an inline non-nonced `<script>` → CSP violation on every production page. Zero client-side usage existed → dropped from `app.blade.php`, `package.json` (`ziggy-js`), `composer.json` (`tightenco/ziggy`) and `tsconfig.json` paths.
- **SecureHeaders is `prepend:`ed (not appended) on both groups** (`7a8c3c2`, `733db7f`): prepended = outermost in the group, so error responses rendered by inner middleware (419 CSRF, 429 throttle, binding 404s) carry the headers too. Unmatched-route 404s remain uncovered — accepted, documented in `bootstrap/app.php`.
- **HSTS applies to both profiles** (CodeRabbit, PR #31): moved before the api early-return — HSTS is host-wide, widget-only visitors should learn it from API responses too.
- **storno `done.blade.php` also carries the no-referrer meta** (`10555da`) — see the correction note in Task 5.
- **CORS test uses two origins** (`945cd38`): fruitcake/php-cors has a single-origin shortcut that emits the ACAO header unconditionally (browser enforces the mismatch), so the disallowed-origin `assertHeaderMissing` leg requires ≥2 configured origins to exercise the dynamic echo-if-allowed branch.

## Plan self-review notes

- Spec coverage: §1→Task 1, §2→Tasks 2-3, §3→Task 4, §4→Task 4, §5→Task 5, §6→Task 5, ops checklist→PR description (Task 6). CSP `img-src blob:` pre-added (spec's watch-out) and pinned by test.
- The two environment-flipping tests (production CSP, forceScheme) carry explicit fallback instructions if `detectEnvironment` misbehaves — implementer reports which mechanism worked.
- Known seam: HandleCors (global) + SecureHeaders:api both touch api responses — Task 3 explicitly re-runs WidgetFontTest to prove no clobbering.
