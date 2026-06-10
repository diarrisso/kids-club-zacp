# PR-B — TrustProxies + Security Headers + Transport Hardening

**Date:** 2026-06-10
**Status:** Derived from the approved audit backlog (`2026-06-10-security-audit-backlog.md`, PR-B section) — ready for review.
**Scope:** Backend code only. The paired **ops items** (VPS firewall, nginx real_ip, prod `.env`, Cloudflare HSTS) are listed for execution at deploy time — they are NOT in this PR.
**Context:** Second PR of the security batch (A: 2FA ✅ #29 · **B: infra/headers** · C: anti-abuse · D: perf). B is the prerequisite of C: without TrustProxies, every per-IP rate limit collapses behind Cloudflare.

## Problem

1. **TrustProxies is absent** (`bootstrap/app.php` has no `trustProxies()`). Production sits behind Cloudflare → nginx; `$request->ip()` returns the proxy IP, so all per-IP limiters (`widget-read`, `widget-book`, `widget-font`, `storno`, `qr`, the login limiter from PR-A) share **one bucket for all visitors** — the throttle defence is void, and one busy parent can 429 everyone.
2. **No security response headers** anywhere: the staff app (medical data) can be framed (clickjacking), has no CSP, no HSTS, no nosniff, no referrer/permissions policy.
3. **Transport softness:** session cookie not forced `secure`, sessions unencrypted at rest (medical context), generated URLs (storno/reset links) can come out `http://` behind the proxy, CORS is `*` permanently instead of env-tightenable, and the storno page can leak its secret token via the `Referer` header.

## Design

### 1. TrustProxies (`bootstrap/app.php`)

```php
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

`at: '*'` is **only safe when the origin is reachable exclusively through the proxy**. That is exactly what the paired ops items enforce (firewall: 80/443 from Cloudflare ranges only). A code comment states this dependency. Trusting `X-Forwarded-Proto` also makes Laravel generate `https://` URLs behind the proxy (fixes the storno/reset link scheme).

### 2. `SecureHeaders` middleware (new, `app/Http/Middleware/SecureHeaders.php`)

Appended to the **web** group. Headers:

| Header | Value | Why |
|---|---|---|
| `X-Frame-Options` | `DENY` | staff app + storno must never be framed |
| `X-Content-Type-Options` | `nosniff` | MIME sniffing off |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | no path/token leakage cross-origin |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | deny powerful features |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — **only when the request is secure** | HSTS without breaking local http |
| `Content-Security-Policy` | see below — **only in production** | XSS/data-injection containment |

CSP (production only, because the Vite dev server needs inline/ws in local):

```
default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self';
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self'
```

- `'unsafe-inline'` styles: required by Inertia/Tailwind inline `style=` attributes (calendar colors, gradients). Scripts stay strict `'self'` — Inertia embeds page data in a `data-page` attribute, not inline `<script>`; the Vite build emits only hashed external files. **Verified during implementation against a production build; if any inline script surfaces (e.g. Ziggy), the fix is a nonce, not `'unsafe-inline'`.**
- The **widget is unaffected**: it's a static asset executed on the practice's site, governed by the HOST page's CSP. Our CSP applies to our own pages (staff app, storno, landing).

For the **api** group: a slim variant adds only `X-Content-Type-Options: nosniff` + `Referrer-Policy: no-referrer` (JSON + fonts; full CSP is meaningless there). Implementation: same middleware class, constructor/parameter switches the profile (`SecureHeaders:api`).

### 3. Session hardening (`config/session.php`)

- `'secure' => env('SESSION_SECURE_COOKIE', ! app()->environment('local', 'testing'))` — cookie rides HTTPS-only everywhere except local/testing, still overridable by env. (Config files can't call `app()` reliably pre-boot → implemented as `env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') !== 'local' && env('APP_ENV') !== 'testing')`.)
- `SESSION_ENCRYPT=true` stays an **ops item** for the prod `.env` (encryption-at-rest of session payloads; default in code remains `false` so local stays cheap and current prod behavior changes only at deploy time, when the ops checklist runs).

### 4. Forced HTTPS (`AppServiceProvider::boot`)

```php
if ($this->app->environment('production')) {
    URL::forceScheme('https');
}
```

Belt-and-braces with TrustProxies' `X-Forwarded-Proto` trust.

### 5. CORS tightening (`config/cors.php`)

```php
'allowed_origins' => array_map('trim', explode(',', env('WIDGET_ALLOWED_ORIGINS', '*'))),
```

Default stays `*` (the widget embeds anonymously, `supports_credentials=false` — acceptable per audit). When the practice's final WP domain is known, ops sets `WIDGET_ALLOWED_ORIGINS=https://praxis-domain.de` in the prod `.env` without a code change.

### 6. Storno referrer leak (`resources/views/storno/show.blade.php`)

`<meta name="referrer" content="no-referrer">` in `<head>` — the cancellation token sits in the URL; any outbound click/asset must not carry it in `Referer`. (The `Referrer-Policy` header from §2 covers navigation away; the meta is defence-in-depth and survives header-stripping proxies.)

### Explicitly out of scope

- Branded 404/500 pages (LOW, optional in the backlog — YAGNI for this PR).
- Anti-abuse throttles (PR-C), performance (PR-D).
- The ops items themselves (firewall/nginx/.env/Cloudflare) — executed at deploy, checklist below.

## Ops checklist (deploy-time, NOT in this PR)

1. Prod `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, real SMTP, later `WIDGET_ALLOWED_ORIGINS=<wp-domain>`.
2. nginx: `real_ip_header CF-Connecting-IP;` + `set_real_ip_from <Cloudflare ranges>;`.
3. VPS firewall: 80/443 **only** from Cloudflare IP ranges (makes `at: '*'` safe).
4. Cloudflare: enable the HSTS toggle (edge mirrors the app header).
5. `php artisan storage:link` (carried over from PR #30's logo feature).

## Testing (TDD, Pest)

- **TrustProxies:** a request carrying `X-Forwarded-For: 1.2.3.4` (from any remote) → `$request->ip()` returns `1.2.3.4`; `X-Forwarded-Proto: https` → `$request->isSecure()` true and generated URLs are https.
- **Web headers:** GET a web route (login page, storno page) → all §2 headers present with exact values; HSTS absent on insecure request, present when `$request->secure()`; CSP present only when `app()->environment('production')` (tested via environment override).
- **API headers:** GET `/api/v1/widget/config` → nosniff + `Referrer-Policy: no-referrer`, NO CSP/XFO pollution.
- **CSP regression guard:** in the production-env test, assert `script-src 'self'` exactly — a future dev adding `'unsafe-inline'` scripts must consciously break this test.
- **CORS env:** with `WIDGET_ALLOWED_ORIGINS=https://praxis.example` config override → `Access-Control-Allow-Origin` echoes only that origin; default config → `*` (existing WidgetFontTest already pins `*`).
- **Session secure default:** config assertion that `session.secure` is true when env isn't local/testing (config override test).
- **Storno meta:** response body contains the `no-referrer` meta tag.
- Full suite green (176+), Pint clean. The widget Vitest suite is untouched.

## Acceptance criteria

- [ ] `$request->ip()` returns the real client IP behind a trusted proxy (test-proven).
- [ ] Every web response carries XFO/nosniff/Referrer-Policy/Permissions-Policy; HSTS on secure requests; strict CSP in production.
- [ ] API responses carry the slim profile only.
- [ ] Session cookie `secure` by default outside local/testing; HTTPS forced in production URLs.
- [ ] CORS origins env-driven with `*` fallback.
- [ ] Storno page declares `no-referrer`.
- [ ] Staff app + widget + storno fully functional under the CSP (Chrome check on a production build).
- [ ] Ops checklist documented in the PR description.

## Risks / watch-outs

- **CSP breaking the staff app**: the highest-risk item. Mitigation: production-build Chrome walkthrough (login → dashboard → calendar → Erscheinungsbild incl. color pickers + logo upload preview blob URLs → QR page images) watching the console for CSP violations. Known needs: `img-src data:` (QR inline preview?) — verify; blob: for the logo preview → add `img-src blob:` if the Appearance preview breaks.
- **`at: '*'` spoofing window** between this merge and the firewall ops item: direct-to-origin callers could spoof `X-Forwarded-For` to dodge throttles. Today's state is *worse* (throttles globally broken), so shipping first is strictly an improvement; the firewall closes the residual gap at deploy.
- **HSTS on the apex/subdomains**: `includeSubDomains` is safe here (single subdomain `kidsclub.masingatech.com`; the WP site is on a different domain entirely).
