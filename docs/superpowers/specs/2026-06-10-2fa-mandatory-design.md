# PR-A — Mandatory TOTP Two-Factor Authentication + Login Hardening

**Date:** 2026-06-10
**Status:** Approved design — ready for implementation plan
**Scope:** Backend (Fortify) + Inertia/Vue frontend + tests. Single PR.
**Context:** First PR of a 4-PR security batch (A: 2FA+auth · B: infra/headers · C: anti-abuse · D: perf). See `2026-06-10-security-audit-backlog.md` for B/C/D.

## Problem

The staff app (dental practice / "cabinet" accounts) protects appointment data for
minors. Today authentication is **password-only**: the `two_factor_*` DB columns exist
(migration `2026_05_29_101702`) but 2FA is **completely unwired** — the Fortify feature
flag is off, `User` lacks the `TwoFactorAuthenticatable` trait, there is no challenge
view, no enrolment UI, and no enforcement. A custom `AuthenticateUser` action also
short-circuits Fortify's login pipeline, which would silently defeat 2FA even if enabled.

The practice owner requires **mandatory** 2FA: no staff account may operate without it.

## Goal

Every authenticated staff user MUST have a confirmed TOTP second factor. A user without
one is hard-blocked: on login they are redirected to the security page and cannot reach
any other staff route until they enrol and confirm. Subsequent logins require a TOTP code
(or a single-use recovery code) after the password step.

Method: **TOTP only** (authenticator apps — Google/Microsoft Authenticator, FreeOTP).
No SMS (cost/SIM-swap), no passkeys in this PR.

## Non-goals (this PR)

- Passkeys / WebAuthn (deferred).
- Security HTTP headers, TrustProxies, `SESSION_SECURE_COOKIE`/`SESSION_ENCRYPT`,
  forced HTTPS → **PR-B**.
- Public-widget anti-abuse throttles → **PR-C**.
- Performance (N+1, indexes) → **PR-D**.
- Self-service 2FA reset (deliberately omitted — see Recovery).

## Design

### Backend — Fortify wiring

1. **`config/fortify.php`** — add to the `features` array:
   - `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`
     — `confirm` forces the user to verify a TOTP code before 2FA is marked active;
     `confirmPassword` requires a fresh `password.confirm` to enable/disable.
   - `Features::updatePasswords()` — enables the in-app password-change endpoint used by
     the security page.

2. **`App\Models\User`** — `use Laravel\Fortify\TwoFactorAuthenticatable;`. The trait
   adds encrypted casts for `two_factor_secret` / `two_factor_recovery_codes` and the
   challenge helpers. The existing migration already provides all three columns
   (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`) — **no
   new migration**.

3. **Remove the custom auth resolver (audit C2):** delete
   `app/Actions/Fortify/AuthenticateUser.php` and the `Fortify::authenticateUsing(...)`
   line in `FortifyServiceProvider`. This restores Fortify's default
   `AttemptToAuthenticate` → `RedirectIfTwoFactorAuthenticatable` pipeline so the 2FA
   challenge redirect actually fires. Default email+password login is exactly Fortify's
   built-in behaviour, so nothing of value is lost.

4. **`FortifyServiceProvider::boot()`:**
   - `Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'))`.
   - Strong password policy (audit M1):
     `Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised())`.
     `uncompromised()` adds a HaveIBeenPwned breach check — high value for a tiny staff set.
   - Coarse per-IP login limiter alongside the existing email+ip one (audit M3) to stop
     password-spray across accounts from one IP. The login limiter returns an array of
     two `Limit`s: `Limit::perMinute(5)->by(email.ip)` **and** `Limit::perMinute(20)->by(ip)`.

5. **Mandatory enforcement middleware `EnsureTwoFactorEnrolled`:**
   - Redirects any authenticated user whose `two_factor_confirmed_at` is `null` to the
     security page, **allow-listing** only the routes needed to escape the loop: the
     security/enrolment routes, the Fortify 2FA enable/confirm/recovery endpoints,
     `password.confirm`, the 2FA challenge, and `logout`.
   - Registered as a middleware **alias** in `bootstrap/app.php` and appended to the
     staff `auth` route group in `routes/web.php` (after `auth`).
   - Carries a flash message: "Zwei-Faktor-Authentifizierung ist erforderlich."

### Frontend — Inertia + Vue 3 (`<script setup lang="ts">`)

German URLs, English route names (project convention).

1. **`Auth/TwoFactorChallenge.vue`** — URL `/zwei-faktor-bestaetigung`.
   - 6-digit TOTP input; a "Wiederherstellungscode verwenden" toggle that swaps to a
     recovery-code input. Posts to Fortify's `two-factor.login` endpoint.

2. **`Tenant/Security.vue`** — URL `/sicherheit`, behind `auth` + `password.confirm`.
   - **Enrol:** button → calls `POST /user/two-factor-authentication`; renders the QR
     code (`GET /user/two-factor-qr-code`) + the manual setup key; a TOTP confirm field
     → `POST /user/confirmed-two-factor-authentication`.
   - **Recovery codes:** shown **once** after confirmation (`GET
     /user/two-factor-recovery-codes`), with an explicit "Ich habe die Codes gesichert"
     acknowledgement; a "Codes neu generieren" action.
   - **Disable** 2FA (re-prompts `password.confirm`).
   - **Password change** (Fortify `updatePasswords`); on success, call
     `Auth::logoutOtherDevices()` server-side so a rotated password kills other sessions.

3. **Nav:** a "Sicherheit" entry in the staff layout pointing to `/sicherheit`.

### Data flow (login → enforced)

```
password OK ──> Fortify RedirectIfTwoFactorAuthenticatable
                   │ has confirmed 2FA? ── yes ──> /zwei-faktor-bestaetigung (TOTP) ──> dashboard
                   │                     ── no  ──> session login, but EnsureTwoFactorEnrolled
                   │                                 redirects every staff route ──> /sicherheit
                   └─ enrol + confirm TOTP ──> two_factor_confirmed_at set ──> unblocked
```

### Recovery (operational, documented — not a feature)

If a user loses **both** their authenticator and recovery codes, an operator resets via
`tinker`/seeder (the same channel staff are provisioned through). No self-service 2FA
reset endpoint is exposed — this keeps the pre-auth attack surface minimal. Documented in
`backend/README` / deployment notes.

## Testing (TDD, Pest)

Each behaviour written test-first (RED → GREEN):

- **Login redirect:** a user with confirmed 2FA, after a valid password, is redirected to
  the challenge route — not to `/dashboard`.
- **TOTP success:** a valid TOTP code reaches `/dashboard`.
- **Recovery code:** a valid recovery code authenticates and is **consumed** (a second use
  of the same code fails).
- **Enforcement (proves "mandatory"):** an authenticated user with
  `two_factor_confirmed_at = null` hitting any `tenant.*` route is redirected to
  `/sicherheit`; the allow-listed routes (enrol, logout, password.confirm, challenge) are
  NOT redirected (no loop).
- **Confirm-password gate:** enabling/disabling 2FA without a fresh `password.confirm`
  is rejected.
- **Password policy:** a weak password (e.g. `password`) is rejected on change; a strong
  one is accepted.
- **Login limiter:** the coarse per-IP limiter trips after the threshold (email rotation
  no longer grants unlimited attempts from one IP).

## Acceptance criteria

- [ ] No staff route is reachable by an authenticated, un-enrolled user except the
      enrolment/escape allow-list.
- [ ] A fresh seeded user is forced through enrolment on first login.
- [ ] TOTP + recovery codes work; recovery codes are single-use and shown once.
- [ ] Custom `AuthenticateUser` removed; default Fortify login still works.
- [ ] Password policy enforced (≥12, mixed, number, symbol, not breached).
- [ ] All new behaviour covered by passing Pest tests; full suite green; Pint clean.
- [ ] German URLs (`/sicherheit`, `/zwei-faktor-bestaetigung`), English route names.

## Risks / watch-outs

- **Enforcement loop:** the allow-list must be exact, or an un-enrolled user gets an
  infinite redirect. Cover with the enforcement test above.
- **Removing `authenticateUsing`** changes the login path — verify existing login tests
  (if any) still pass; the default pipeline must accept the seeded users.
- **`password.confirm` route existence:** it only registers when a view is set; ensure
  `Fortify::confirmPasswordView(...)` is wired (Inertia) so the gate works.
- **Seeder/factory:** test users need a way to be created already-2FA-confirmed (a factory
  state) so existing feature tests that log in don't all hit the enforcement redirect.
  Provide an `Authenticatable` test helper / `withTwoFactor()` factory state.
