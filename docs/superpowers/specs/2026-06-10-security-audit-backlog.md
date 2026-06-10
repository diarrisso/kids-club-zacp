# Security + Performance Audit — Backlog (PR-B / PR-C / PR-D + Ops)

**Date:** 2026-06-10
**Source:** 5-dimension parallel audit (auth, public API, injection/XSS/secrets, performance, config/infra).
**Decisions:** TOTP mandatory (→ PR-A); anti-abuse = throttles only (no booking-UX change); one PR per domain in order.
**Dependency scan:** `composer audit` + `npm audit` = **0 vulnerabilities**.

## Clean bill (verified safe — no action)

SQL injection (only parameterised Eloquent; the single `DB::raw` is a static column ref in
a migration) · XSS (no `{!! !!}`, no `v-html`/`innerHTML`; all PII via escaped `{{ }}`;
Shadow-DOM CSS via `textContent`) · committed secrets (none; `.env*` gitignored, blank
example) · mass-assignment (`Appointment::$fillable` excludes `notes_internal` /
`reminder_sent_at`; controllers build `create()` field-by-field) · booking TOCTOU (lock on
a real practitioner row, not an aggregate; `isBookable` before the tx) · cancellation token
(UUIDv4, unique-indexed, unguessable) · email header injection (validated `email`, Symfony
Mailer) · PII logging (none in request path) · dev tools (`telescope`/`debugbar` absent;
faker/pint/pest in `require-dev`).

---

## PR-A — Mandatory 2FA + login hardening
See `2026-06-10-2fa-mandatory-design.md`. Folds in M1 (password policy) and M3 (per-IP
login limiter) since they serve the same "harden staff accounts" goal.

---

## PR-B — Infra / transport / headers (mostly code; some VPS ops)

- **[HIGH] TrustProxies missing → per-IP rate limiting broken behind Cloudflare.**
  `bootstrap/app.php` has no `trustProxies()`. `$r->ip()` returns the Cloudflare/nginx IP,
  so every per-IP limiter (`widget-read`/`widget-book`/`storno`/`qr`) collapses to one
  shared bucket — the booking engine's whole IP-throttle defence is void. Fix: trust the
  proxy + `HEADER_X_FORWARDED_*`. **MUST be paired with the ops items below** (firewall +
  nginx `real_ip`) or `at: '*'` lets a direct-to-origin caller spoof `X-Forwarded-For`.
- **[HIGH] No security response headers.** Add a `SecureHeaders` middleware on the `web`
  group: `X-Frame-Options: DENY` (staff app must not be framed — clickjacking),
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` (deny camera/mic/geo/payment), HSTS when secure, and a CSP with
  `frame-ancestors 'none'`, `style-src 'self' 'unsafe-inline'` (Inertia/Tailwind). The
  **widget** is served as a static asset (host page's CSP governs it) — its embeddability
  is unaffected by the app CSP. API group gets only `nosniff` + `Referrer-Policy`.
- **[MED] Session cookie not hardened.** `SESSION_SECURE_COOKIE` unset → cookie can ride
  HTTP; sessions unencrypted at rest (DB driver, medical context). Fix in config:
  default `secure` to true off-local; set `SESSION_ENCRYPT=true`.
- **[MED] No forced HTTPS scheme.** Generated URLs (storno/reset links) can come out
  `http://` behind Cloudflare. Trusting `X-Forwarded-Proto` (TrustProxies) fixes it;
  belt-and-braces `URL::forceScheme('https')` in production `boot()`.
- **[MED] CORS `*` on `api/*`.** Acceptable (anonymous, `supports_credentials:false`) but
  tighten to the practice origin via `env('WIDGET_ALLOWED_ORIGIN')` once the WP embed
  domain is final.
- **[LOW] Storno token referrer leak.** Add `<meta name="referrer" content="no-referrer">`
  to the storno Blade `<head>` so the token-in-URL can't leak via `Referer`.
- **[LOW] Branded error pages** (`resources/views/errors/{404,500}.blade.php`) — optional.

### Ops items (NOT code — apply on the Hostinger VPS / Cloudflare; cannot be done from the repo)
- Set `.env` (prod): `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`,
  `SESSION_ENCRYPT=true`, real SMTP mailer. **Verify** — if `.env` was copied from the
  `local`/`debug=true` example, Ignition would leak DB creds + source.
- nginx: `real_ip_header CF-Connecting-IP;` + `set_real_ip_from <Cloudflare ranges>;` so
  Laravel sees the genuine client IP.
- Firewall: VPS accepts 80/443 **only** from Cloudflare IP ranges (makes TrustProxies `*`
  safe and blocks direct-to-origin `X-Forwarded-For` spoofing).
- Enable Cloudflare HSTS toggle (edge as single source of truth for HSTS).

---

## PR-C — Public-widget anti-abuse (throttles only; no booking-UX change)

- **[HIGH] Email-bombing + DB pollution.** A booking queues a confirmation mail to the
  attacker-supplied `parent_email`, throttled only 5/min/IP → ~7,200 mails/day to a victim
  from one IP, each writing a permanent `appointments` row. Fix: per-recipient throttle
  before queueing, e.g. `RateLimiter::attempt('confirm-mail:'.sha1(lower(email)), 3, …,
  3600)` (3/hour/email) — still return 201, just skip the mail.
- **[HIGH] Global circuit-breaker on `widget-book`.** Add an un-keyed
  `Limit::perMinute(30)` alongside per-IP so a rotating-proxy botnet can't fill every
  practitioner's calendar (slot-squatting) or spam unboundedly. (TrustProxies from PR-B is
  the prerequisite for the per-IP part to mean anything.)
- **[MED] Unbounded `from`/`to` span = calculator DoS amplifier.** `SlotController` /
  `AvailabilityController` accept any range; `from=2020&to=2099` loops ~28k days. Cap the
  span (reject > 62 days; horizon is 60). Add `after:now` + horizon bound to
  `StoreAppointmentRequest.starts_at` as defence-in-depth (today `isBookable` saves us).
- **[LOW] Malformed `cancellation_token`** → Postgres uuid cast error → 500 instead of 404.
  Validate the token format (uuid) on the route or before the query.

**Explicitly deferred** (user decision): email-verification/pending bookings and CAPTCHA —
both change the parent booking UX. Revisit if throttles prove insufficient.

---

## PR-D — Performance

- **[HIGH] N+1 in `AvailabilityCalculator` — biggest single win.** `availabilities()` is
  re-queried **inside the per-day loop** (`:57-61`) → ~60 queries/practitioner/calendar
  load, ×N practitioners on every widget interaction. Load once before the loop, filter
  `day_of_week` + `valid_from/to` in PHP. ~60× query reduction, pure PHP, no migration.
- **[HIGH] Partial composite index for the appointment-overlap query** (runs inside the
  booking lock + in the calculator):
  `CREATE INDEX appointments_overlap_idx ON appointments (practitioner_id, starts_at, ends_at) WHERE status IN ('pending','confirmed')`.
  Shortens lock-hold time. (`now()` not in predicate → legal partial index.)
- **[MED] Composite indexes:** `availabilities (practitioner_id, day_of_week)`;
  `availability_exceptions (practitioner_id, starts_at, ends_at)` (drop the redundant
  standalone `starts_at` index).
- **[MED] Cache widget `services` / `practitioners` lists** (served on every widget mount,
  change ~monthly) — `rememberForever` invalidated on the staff CRUD save (mirror the
  `Setting` pattern). Slot/availability endpoints are NOT cacheable (depend on `now()`).
- **[LOW] SMTP timeout under sync queue:** `Mail::queue()` on `sync` still blocks the
  booking HTTP response on SMTP. Set `config/mail.php 'timeout' => 5` so a dead MX fails
  fast into `rescue()` instead of stalling the parent's "booking" spinner.
- **[INFO] Widget IIFE bundle ~172 KB** (ships Vue) — inherent to an embeddable IIFE; not
  worth splitting. Main app already per-page code-split (FullCalendar loads only on the
  calendar page).

---

## Suggested sequencing rationale

PR-B (TrustProxies) is a **prerequisite** for PR-C's per-IP throttles to mean anything, so
B before C. PR-D is independent and can land any time. PR-A is first per the owner's
priority ("renforcer la sécurité").
