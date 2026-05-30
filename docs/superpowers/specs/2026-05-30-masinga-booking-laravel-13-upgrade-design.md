# Masinga Booking — Laravel 11 → 13 Upgrade (PR2/3) — Design

**Date:** 2026-05-30
**Status:** Approved (strategy + PHP ^8.3 constraint confirmed by user)
**Author:** Claude (Opus 4.8)

---

## 1. Goal

Upgrade the Masinga Booking backend (Laravel **11.54.0**) to Laravel **13.x** (latest stable
13.12.0), with the dependency chain this forces (PHP ^8.3, PHPUnit ^12, Pest ^4,
pest-plugin-laravel ^4, tinker ^3), while keeping the full test suite green and the embedded
booking widget functional cross-origin.

This is **PR2 of 3** in the single-project pivot roadmap:
1. ✅ PR1 — drop multi-tenancy (merged, `main`)
2. **PR2 — upgrade to Laravel 13 (this spec)**
3. PR3 — Phase 5 dashboard calendar (single-project)

## 2. Context & Why It's Low-Risk

Laravel 13 is officially a **"zero breaking changes" framework release**. The real risk in any
L13 upgrade is (a) infrastructure/config defaults, (b) third-party packages, and (c) the test
tooling jump. Cross-referencing the official upgrade guide against this codebase:

| Breaking change (guide) | Impact here | Reason |
|---|---|---|
| Dependency bumps (framework, tinker, phpunit, pest) | **Applies** | Core of this PR |
| CSRF rename `VerifyCsrfToken` → `PreventRequestForgery` + `Sec-Fetch-Site` origin check | **None** | No CSRF references in app/bootstrap/tests; widget API routes (`api.php`) carry only `throttle` middleware — no web/CSRF middleware → new origin check does not apply |
| Carbon 2 → 3 | **None** | Already on `nesbot/carbon` 3.11.4 |
| `serializable_classes` cache default = `false` | **Trivial** | App stores no PHP objects in cache (database driver); adopt the new default |
| Cache/session prefix now hyphenated | **Cosmetic** | Pin `SESSION_COOKIE` / `CACHE_PREFIX` in `.env.example` to avoid a one-time session reset on deploy |
| `upsert` `uniqueBy` validation | **None** | No `upsert()` usage |
| Queue event property renames (`JobAttempted`, `QueueBusy`) | **None** | No custom queue event listeners |
| Model nested instantiation in `boot()` throws | **None** | No such pattern |
| Pagination Bootstrap view rename | **None** | No Bootstrap pagination views referenced |
| Password reset subject string change | **None** | No test asserts the subject |
| PHPUnit 11 → 12, Pest 3 → 4 | **Main work** | Possible assertion-signature / deprecation breakages — caught by the 50-test suite |

**Already ahead of the curve** (pulled forward by L11.54 patches): Carbon 3.11.4, PHPUnit 11.5.50,
collision 8.9.4 (already declares `^13.5` support). The major jump therefore changes *constraints*,
not *code*.

## 3. Target Dependency Matrix

### `composer.json` — `require`
| Package | From | To | Note |
|---|---|---|---|
| `php` | `^8.2` | `^8.3` | L13 floor; local PHP is 8.4.11 |
| `laravel/framework` | `^11.31` | `^13.0` | resolves to 13.12.x |
| `laravel/tinker` | `^2.9` | `^3.0` | required by L13 |
| `inertiajs/inertia-laravel` | `^3.1` | `^3.1` | unchanged — already `^11\|^12\|^13` |
| `laravel/fortify` | `^1.37` | `^1.37` | unchanged — already `^11\|^12\|^13` |
| `tightenco/ziggy` | `^2.6` | `^2.6` | unchanged — `laravel >=9` |
| `predis/predis` | `^3.4` | `^3.4` | unchanged — framework-agnostic |

### `composer.json` — `require-dev`
| Package | From | To | Note |
|---|---|---|---|
| `phpunit/phpunit` | `^11.0.1` | `^12.0` | Pest 4 requires `^12.5.24` |
| `pestphp/pest` | `^3.8` | `^4.0` | resolves to 4.7.x |
| `pestphp/pest-plugin-laravel` | `^3.2` | `^4.0` | **3.x has no L13 support** — the forcing constraint |
| `nunomaduro/collision` | `^8.1` | `^8.9` | tighten floor (8.9.4 supports `^13.5`) |
| `fakerphp/faker` | `^1.23` | `^1.23` | unchanged |
| `laravel/pail` | `^1.1` | `^1.1` | unchanged |
| `laravel/pint` | `^1.13` | `^1.13` | unchanged |
| `laravel/sail` | `^1.26` | `^1.26` | unchanged |
| `mockery/mockery` | `^1.6` | `^1.6` | unchanged |

**Out of scope:** `laravel/boost` (`^2.0` in the guide) — not installed; we do not add it.
JS/front-end (`@inertiajs/vue3 ^3.3`, Vite 6, Vitest 4, Vue 3.5, Tailwind 3) is **untouched** — it
has no coupling to the PHP framework major.

## 4. Approach

1. **Branch:** `chore/upgrade-laravel-13` (created from fresh `main`).
2. **Edit `composer.json`** to the target matrix above (one commit).
3. **`composer update`** for the bumped packages + their transitive deps (one commit for the lock).
4. **Run the PHP suite** (`composer test`). Fix any Pest 4 / PHPUnit 12 breakages (assertion
   signatures, deprecations) until **50 passed**.
5. **Run the JS suite** (`npm run test:widget`) — expected unaffected (17 passed) since the
   front-end is untouched; run it to confirm the upgrade didn't disturb the build.
6. **Adopt safe L13 config defaults** (small, optional-but-recommended hardening):
   - `config/cache.php` → add `'serializable_classes' => false` to match the new default.
   - `.env.example` → pin `SESSION_COOKIE` and `CACHE_PREFIX` so a future prod deploy does not
     silently rotate the session cookie name.
7. **Chrome verification:** login → dashboard, `/behandler`, and the **widget cross-origin booking
   flow** (the one place where an L13 request-forgery change *could* bite — confirm `/api/v1/widget`
   GET + POST still return 200 with no console/CORS errors).
8. **Code review** (obligatory): security + correctness on the full branch diff.
9. **PR → CodeRabbit** autofix loop (Major+Minor) → merge on user OK.

## 5. Testing Strategy

- **Existing suites are the safety net** — no new feature code, so no new feature tests. The
  upgrade is "correct" iff the existing 50 PHP + 17 JS tests stay green on the new stack.
- **Pest 4 sanity:** confirm Pest 4 discovers both `Unit` and `Feature` suites under the
  collapsed single-suite `phpunit.xml` from PR1 (`RefreshDatabase` on `Feature`).
- **Cross-origin widget regression:** manual Chrome check is the explicit guard for the
  `Sec-Fetch-Site` origin-verification change, since automated tests hit the API same-origin.
- **No deploy** in this PR — strictly upgrade + merge. Deployment (with the mandatory
  `optimize:clear && optimize && systemctl restart php8.x-fpm`) happens only on explicit user
  instruction, and must target a PHP **8.3+** server.

## 6. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Pest 4 changed an assertion signature used in tests | Medium | Fix per-test as the suite reports; Pest 4 upgrade notes consulted on failure |
| PHPUnit 12 removes a deprecated API the suite uses | Low | Suite already runs on 11.5.50; fix surfaced deprecations-as-errors |
| Widget cross-origin POST blocked by new origin check | Low | api.php has no web/CSRF middleware; verified in Chrome before merge |
| Prod server still on PHP < 8.3 at deploy time | Deferred | Out of scope for this PR; flagged for the deploy step (not done here) |
| Composer cannot resolve (hidden transitive conflict) | Low | `composer update` dry-run first; all top-level deps verified L13-compatible above |

## 7. Out of Scope

- PR3 dashboard calendar work.
- Adopting new L13 features (eventStream, etc.).
- Front-end dependency upgrades.
- Cosmetic rename of the intentionally-kept `App\Models\Tenant\*` namespace / `TenantLayout.vue`
  (tracked as a separate later follow-up from PR1).
- Production deployment.
