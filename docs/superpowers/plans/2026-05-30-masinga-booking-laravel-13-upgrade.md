# Laravel 13 Upgrade Implementation Plan (PR2/3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the `backend/` app from Laravel 11.54 to Laravel 13.x (PHP ^8.3, PHPUnit ^12, Pest ^4, tinker ^3) with all existing tests green and the embedded widget working cross-origin.

**Architecture:** Constraint-only upgrade. Edit `composer.json` → `composer update` → fix test-tooling breakages → adopt two safe L13 config defaults → verify widget cross-origin. No feature code changes; the existing 50 PHP + 17 JS tests are the correctness oracle.

**Tech Stack:** Laravel 13, PHP 8.3+ (8.4.11 local), Inertia 2 (Vue 3), Fortify, Pest 4 / PHPUnit 12, PostgreSQL 16.

**Working dir for all backend commands:** `/Users/mdiarrisso/PhpstormProjects/kids-club-zacp/backend`

---

## Task 1: Bump composer constraints

**Files:**
- Modify: `backend/composer.json`

- [ ] **Step 1: Edit `require` block**

Set these exact constraints in `"require"`:

```json
"require": {
    "php": "^8.3",
    "inertiajs/inertia-laravel": "^3.1",
    "laravel/fortify": "^1.37",
    "laravel/framework": "^13.0",
    "laravel/tinker": "^3.0",
    "predis/predis": "^3.4",
    "tightenco/ziggy": "^2.6"
},
```

- [ ] **Step 2: Edit `require-dev` block**

Set these exact constraints in `"require-dev"`:

```json
"require-dev": {
    "fakerphp/faker": "^1.23",
    "laravel/pail": "^1.1",
    "laravel/pint": "^1.13",
    "laravel/sail": "^1.26",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.9",
    "pestphp/pest": "^4.0",
    "pestphp/pest-plugin-laravel": "^4.0",
    "phpunit/phpunit": "^12.0"
},
```

- [ ] **Step 3: Validate JSON**

Run: `composer validate --no-check-publish`
Expected: `./composer.json is valid` (lock-out-of-date warning is fine — fixed in Task 2).

- [ ] **Step 4: Commit**

```bash
git add backend/composer.json
git commit -m "chore(deps): bump composer constraints to Laravel 13 stack"
```

---

## Task 2: Resolve dependencies

**Files:**
- Modify: `backend/composer.lock` (generated)

- [ ] **Step 1: Dry-run the resolution**

Run: `composer update --dry-run "laravel/framework" "laravel/tinker" "phpunit/phpunit" "pestphp/pest" "pestphp/pest-plugin-laravel" "nunomaduro/collision" --with-all-dependencies`
Expected: a plan that upgrades laravel/framework to v13.12.x, tinker to v3.x, phpunit to 12.x, pest to 4.7.x, pest-plugin-laravel to 4.x. No "your requirements could not be resolved" error.

- [ ] **Step 2: Run the real update**

Run: `composer update "laravel/framework" "laravel/tinker" "phpunit/phpunit" "pestphp/pest" "pestphp/pest-plugin-laravel" "nunomaduro/collision" --with-all-dependencies`
Expected: packages installed; no errors. If resolution fails, fall back to `composer update --with-all-dependencies` (full update within constraints).

- [ ] **Step 3: Confirm installed majors**

Run: `php artisan --version && ./vendor/bin/pest --version`
Expected: `Laravel Framework 13.x.x` and `Pest 4.x.x`.

- [ ] **Step 4: Commit**

```bash
git add backend/composer.lock
git commit -m "chore(deps): composer update to Laravel 13 / Pest 4 / PHPUnit 12"
```

---

## Task 3: Get the PHP test suite green on the new stack

**Files:**
- Modify (only if breakages surface): `backend/tests/**`, `backend/phpunit.xml`, `backend/tests/Pest.php`

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected (success): `Tests: 50 passed`. If green, skip to Step 4.

- [ ] **Step 2: Triage failures**

For each failure, classify:
- **Pest 4 API change** (e.g., a renamed/removed expectation or helper) → update the call site to the Pest 4 equivalent.
- **PHPUnit 12 deprecation-as-error** (e.g., doc-comment metadata removed, data-provider visibility) → migrate to the supported form (PHP attributes / `static` providers). Pest tests rarely hit these.
- **Behavioral L13 change** → only the items flagged "applies" in the spec should ever appear; none are expected for this app.

Do NOT change application code to make a test pass unless the failure is a genuine L13 behavioral change documented in the spec — in that case, fix the app code and note why.

- [ ] **Step 3: Re-run until green**

Run: `composer test`
Expected: `Tests: 50 passed`. Loop Steps 2–3 until green.

- [ ] **Step 4: Run the JS suite (regression check)**

Run: `npm run test:widget`
Expected: `17 passed`. (Front-end untouched; this only confirms nothing regressed.)

- [ ] **Step 5: Commit (only if test files changed)**

```bash
git add backend/tests backend/phpunit.xml
git commit -m "test: adapt suite to Pest 4 / PHPUnit 12"
```

If no test files changed (suite was already green), skip this commit.

---

## Task 4: Adopt safe L13 config defaults

**Files:**
- Modify: `backend/config/cache.php`
- Modify: `backend/.env.example`

- [ ] **Step 1: Add `serializable_classes` to cache config**

In `backend/config/cache.php`, add a top-level key (sibling of `'default'` and `'stores'`) matching the L13 default:

```php
    /*
    |--------------------------------------------------------------------------
    | Cache Serializable Classes
    |--------------------------------------------------------------------------
    |
    | Hardens cache unserialization against PHP gadget-chain attacks. This app
    | stores no PHP objects in cache, so the safe default of `false` applies.
    |
    */

    'serializable_classes' => false,
```

- [ ] **Step 2: Verify config loads**

Run: `php artisan config:show cache.serializable_classes`
Expected: prints `false` (or `cache.serializable_classes ............ false`).

- [ ] **Step 3: Pin session cookie & cache prefix in `.env.example`**

In `backend/.env.example`, under the existing `SESSION_*` block, ensure an explicit cookie name so the L13 hyphenation change cannot silently rotate it on deploy. Add after `SESSION_DOMAIN=null`:

```dotenv
# Pin explicitly so the Laravel 13 prefix-hyphenation change does not rotate
# the session cookie / cache key names on deploy.
SESSION_COOKIE=kidsclub_session
CACHE_PREFIX=kidsclub_cache
```

- [ ] **Step 4: Commit**

```bash
git add backend/config/cache.php backend/.env.example
git commit -m "chore(config): adopt L13 cache serializable_classes default; pin session/cache names"
```

---

## Task 5: Sanity-boot + lint

**Files:** none (verification only)

- [ ] **Step 1: Clear and rebuild caches**

Run: `php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan optimize:clear`
Expected: each command succeeds (the final clear leaves caches off for local dev).

- [ ] **Step 2: Fresh migrate + seed**

Run: `php artisan migrate:fresh --seed`
Expected: all migrations run; `KidsClubSeeder` seeds admin + 2 practitioners + 3 services without error.

- [ ] **Step 3: Pint (style)**

Run: `./vendor/bin/pint --test`
Expected: no style violations (or run `./vendor/bin/pint` to auto-fix, then commit).

- [ ] **Step 4: Commit (only if Pint changed files)**

```bash
git add -A
git commit -m "style: pint formatting after L13 upgrade"
```

---

## Task 6: Chrome verification (manual, by controller)

**Files:** none (verification only)

- [ ] **Step 1: Boot servers**

Backend: `php artisan serve` (port 8000). Front-end: `npm run dev`. Widget host page: `backend/public/widget/test.html` (uses `data-api="http://localhost:8000"`).

- [ ] **Step 2: Admin flow**

In Chrome: `/connexion` → log in as `michael@kidsclub.de` / `changeme` → land on dashboard. Visit `/behandler` → 2 seeded practitioners render.

- [ ] **Step 3: Widget cross-origin flow (the key L13 guard)**

Open the widget test host page. Confirm:
- `GET /api/v1/widget/services` returns **200** with 3 services (Network tab, no CORS error).
- A booking `POST /api/v1/widget/appointments` succeeds (200/201) — confirms the new
  `Sec-Fetch-Site` request-forgery check does **not** block the cross-origin widget POST.
- No console errors.

- [ ] **Step 4: Record outcome**

Note results in the PR description. If the widget POST is blocked, that is the one true L13
regression for this app → add a CORS/middleware exemption note and fix before merge.

---

## Task 7: Review, PR, merge

- [ ] **Step 1: Code review (obligatory)**

Dispatch the code-reviewer agent on the full branch diff (`git diff main...chore/upgrade-laravel-13`). Focus: did any app behavior change beyond dependency constraints? Fix CRITICAL/IMPORTANT before proceeding.

- [ ] **Step 2: Push + open PR**

```bash
git push -u origin chore/upgrade-laravel-13
gh pr create --base main --title "Upgrade: Laravel 11 → 13 (PR2/3)" --body "<summary + test results + Chrome verification>"
```

- [ ] **Step 3: CodeRabbit autofix loop**

Address all Major + Minor findings automatically; push; await re-review; repeat until 0 actionable findings.

- [ ] **Step 4: Merge on explicit user OK**

Squash-merge to `main` and delete branch. **Do not deploy** — deployment is a separate, explicitly-authorized step targeting a PHP 8.3+ server.

---

## Self-Review Checklist (completed at authoring)

- **Spec coverage:** every dependency in §3 of the spec maps to Task 1; every "applies/trivial"
  breaking change maps to a task (deps → T1/T2; test tooling → T3; cache/session defaults → T4;
  widget origin check → T6). ✓
- **No placeholders:** all constraints, commands, and config snippets are concrete. ✓
- **Type/version consistency:** constraints in Task 1 match the spec matrix exactly (php ^8.3,
  framework ^13.0, tinker ^3.0, phpunit ^12.0, pest ^4.0, pest-plugin-laravel ^4.0,
  collision ^8.9). ✓
