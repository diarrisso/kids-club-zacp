# PR-C — Anti-abus du widget public · Plan d'implémentation

> **Pour les workers agentiques :** SOUS-SKILL REQUISE : `superpowers:subagent-driven-development` (recommandé) ou `superpowers:executing-plans`. Étapes suivies par checkbox (`- [ ]`).

**Objectif :** 4 corrections anti-abus côté backend (throttle mail par destinataire, circuit-breaker global `widget-book`, plafond de plage `from`/`to` + bornes `starts_at`, contrainte UUID sur les routes `{token}`) — **sans changer l'UX de réservation**.

**Architecture :** `RateLimiter::attempt` autour de la queue du mail de confirmation ; tableau de `Limit` sur le limiter `widget-book` ; `abort_if` de plage dans les 2 contrôleurs read + règles `after:now`/`before:` sur `StoreAppointmentRequest` ; `->whereUuid('token')` sur 4 routes.

**Stack :** Laravel 13 · Pest 4 · PostgreSQL. Toutes les commandes depuis `backend/`.

**Branche :** `feature/widget-anti-abuse` (créée depuis origin/main ; spec commitée).
Spec : `docs/superpowers/specs/2026-06-11-widget-anti-abuse-design.md`.

**Conventions :** TDD (test rouge LANCÉ d'abord), `vendor/bin/pint --dirty` avant chaque commit, un commit par tâche. Baseline suite : **190 passed**. Ne jamais stager les fichiers dirty non-liés (CLAUDE.md, .DS_Store, backend/tz_dst_check.php, wordpress-plugin/*, backend/public/widget-preview.html).

---

### Task 1 : Contrainte UUID sur les routes `{token}` (la plus simple, isole un fix net)

**Fichiers :**
- Modifier : `backend/routes/api.php`, `backend/routes/web.php`
- Test : `backend/tests/Feature/TenantSchema/TokenRouteConstraintTest.php` (nouveau)

- [ ] **Étape 1 : test rouge**

Créer `backend/tests/Feature/TenantSchema/TokenRouteConstraintTest.php` :

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 (not 500) for a malformed token on the storno page', function () {
    $this->get('/storno/not-a-uuid')->assertNotFound();
});

it('returns 404 for a malformed token on the api show endpoint', function () {
    $this->getJson('/api/v1/widget/appointments/not-a-uuid')->assertNotFound();
});

it('returns 404 for a malformed token on the api cancel endpoint', function () {
    $this->postJson('/api/v1/widget/appointments/not-a-uuid/cancel')->assertNotFound();
});

it('still 404s a well-formed but unknown uuid token', function () {
    $unknown = '00000000-0000-4000-8000-000000000000';
    $this->get("/storno/{$unknown}")->assertNotFound();
    $this->getJson("/api/v1/widget/appointments/{$unknown}")->assertNotFound();
});
```

- [ ] **Étape 2 : lancer — doit échouer**

`php artisan test --filter=TokenRouteConstraintTest`. Attendu : les 3 premiers tests **échouent** (500 `PDOException` invalid uuid syntax au lieu de 404). Le 4e (uuid valide inconnu) passe déjà (`firstOrFail` → 404). **Observer le rouge** avant d'implémenter.

⚠️ Note implémenteur : si le test 500 remonte comme une exception non-catchée plutôt qu'une réponse 500 (selon la config de gestion d'exceptions Pest), vérifier via `->assertStatus(500)` d'abord pour prouver le bug, puis basculer sur l'assertion finale `->assertNotFound()`. Rapporter le mécanisme observé.

- [ ] **Étape 3 : implémenter**

`backend/routes/api.php` — ajouter `->whereUuid('token')` aux 2 routes token :

```php
        Route::get('/appointments/{token}', [CancellationController::class, 'show'])->whereUuid('token');
```

```php
        Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel'])->whereUuid('token');
```

`backend/routes/web.php` — groupe storno (lignes ~79-80), ajouter `->whereUuid('token')` AVANT `->name(...)` :

```php
        Route::get('/{token}', [CancellationPageController::class, 'show'])->whereUuid('token')->name('storno.show');
        Route::post('/{token}', [CancellationPageController::class, 'cancel'])->whereUuid('token')->name('storno.cancel');
```

- [ ] **Étape 4 : lancer — doit passer**

`php artisan test --filter=TokenRouteConstraintTest` → PASS (4). Puis `composer test` → suite verte (les tests storno/cancellation existants utilisent de vrais UUID, donc non régressés — vérifier).

- [ ] **Étape 5 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/routes/api.php backend/routes/web.php backend/tests/Feature/TenantSchema/TokenRouteConstraintTest.php
git commit -m "fix(widget): constrain {token} routes to uuid — malformed token now 404 not 500"
```

---

### Task 2 : Plafond de plage `from`/`to` + bornes `starts_at`

**Fichiers :**
- Modifier : `backend/app/Http/Controllers/Widget/SlotController.php`, `backend/app/Http/Controllers/Widget/AvailabilityController.php`, `backend/app/Http/Requests/Widget/StoreAppointmentRequest.php`
- Test : `backend/tests/Feature/TenantSchema/WidgetRangeBoundsTest.php` (nouveau)

- [ ] **Étape 1 : test rouge**

Créer `backend/tests/Feature/TenantSchema/WidgetRangeBoundsTest.php`. AVANT de l'écrire, lire `tests/Feature/TenantSchema/WidgetBookingTest.php` (ou le test slots existant) pour copier la mise en place factory (un `Service` actif est requis pour passer la validation `exists:services,id`).

```php
<?php

use App\Models\Tenant\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = Service::factory()->create();
});

it('rejects a slots range wider than 62 days', function () {
    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-03-20', // 78 days
    ]))->assertStatus(422);
});

it('accepts a slots range of exactly 62 days', function () {
    $this->getJson('/api/v1/widget/slots?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-03-04', // 62 days
    ]))->assertOk();
});

it('rejects an availability/days range wider than 62 days', function () {
    $this->getJson('/api/v1/widget/availability/days?'.http_build_query([
        'service_id' => $this->service->id,
        'from' => '2026-01-01',
        'to' => '2026-12-31',
    ]))->assertStatus(422);
});

it('rejects a booking whose starts_at is in the past', function () {
    $payload = bookingPayload($this->service, now()->subDay()->toIso8601String());
    $this->postJson('/api/v1/widget/appointments', $payload)->assertStatus(422);
});

it('rejects a booking whose starts_at is beyond the horizon', function () {
    $payload = bookingPayload($this->service, now()->addDays(90)->toIso8601String());
    $this->postJson('/api/v1/widget/appointments', $payload)->assertStatus(422);
});
```

⚠️ Note implémenteur : `bookingPayload(...)` est un helper à créer (ou inliner) qui produit un payload `StoreAppointmentRequest` valide SAUF `starts_at`. Copier les champs requis depuis un test booking existant (`practitioner_id`, `service_id`, noms patient/parent, `patient_birthdate` before:today, `parent_email`, `consent` accepted). Il faut un practitioner offrant le service. Le but de ces 2 tests est de prouver que la **validation** (422) coupe avant le calculateur — pas besoin d'un slot réellement bookable.

Préciser `diffInDays` : `$from->diffInDays($to)` où `from`/`to` sont les dates brutes validées (avant `startOfDay`/`endOfDay`). 62 jours = `from` + 62. Vérifier la frontière exacte en TDD (le test « exactly 62 » doit passer, « 78 » échouer) et ajuster `> 62` si la sémantique `diffInDays` décale d'un jour — rapporter la valeur retenue.

- [ ] **Étape 2 : lancer — doit échouer**

`php artisan test --filter=WidgetRangeBoundsTest` → les tests de plage échouent (200 au lieu de 422), les tests `starts_at` échouent (le booking passe la validation puis 422 `isBookable` OU 201 — selon le slot ; ce qui compte : ce n'est PAS un 422 de **validation**). Observer le rouge.

⚠️ Subtilité : un `starts_at` passé pourrait déjà donner 422 via `isBookable`. Pour prouver que c'est bien la *validation* qui coupe (défense en profondeur), le test peut asserter que la réponse 422 contient l'erreur de validation sur `starts_at` (`->assertJsonValidationErrors('starts_at')`). Utiliser cette assertion plus précise dans les 2 tests `starts_at`.

- [ ] **Étape 3 : implémenter**

`SlotController::index` et `AvailabilityController::days` — après le `$data = $request->validate([...])`, ajouter cette vérification (qui parse elle-même les dates pour calculer l'écart, indépendamment du parse de plage utilisé plus bas) :

```php
        abort_if(
            \Carbon\CarbonImmutable::parse($data['from'])->diffInDays(\Carbon\CarbonImmutable::parse($data['to'])) > 62,
            422,
            'Date range too large.'
        );
```

(Ou factoriser dans une méthode privée partagée si l'implémenteur juge pertinent — mais les 2 contrôleurs sont distincts, dupliquer 3 lignes est acceptable ; ne pas créer d'abstraction prématurée. Rapporter le choix.)

> **As-built** (commit `1612947`, suite à la revue qualité) : la mesure du cap a été déplacée sur des **jours Berlin entiers** — chaque borne est parsée avec `CLINIC_TIMEZONE` puis `->startOfDay()` avant le `diffInDays`. Cela ferme la fuite fractionnaire (`from=…T23:00&to=…T01:00` = 63 jours calendaires marquait 62.08 et passait) et le sous-comptage DST, tout en restant un garde grossier (la source de vérité reste `isBookable`). Le parse de plage existant en dessous (`startOfDay`/`endOfDay`) est inchangé.

`StoreAppointmentRequest::rules` — `starts_at` :

```php
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(61)->toDateString()],
```

- [ ] **Étape 4 : lancer — doit passer**

`php artisan test --filter=WidgetRangeBoundsTest` → PASS. `composer test` → suite verte (les tests slots/days/booking existants utilisent des plages courtes et des dates futures proches — vérifier qu'aucun n'utilise une fenêtre > 62j ni une date hors `(now, +61j)`).

⚠️ Si un test existant casse parce qu'il bookait à une date hors bornes ou demandait une grande fenêtre, c'est un signal légitime : ajuster le test existant vers une date/fenêtre réaliste (le widget réel ne dépasse jamais l'horizon), pas contourner la règle. Rapporter tout test existant modifié.

- [ ] **Étape 5 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Http/Controllers/Widget/SlotController.php backend/app/Http/Controllers/Widget/AvailabilityController.php backend/app/Http/Requests/Widget/StoreAppointmentRequest.php backend/tests/Feature/TenantSchema/WidgetRangeBoundsTest.php
git commit -m "feat(widget): cap from/to span at 62 days + bound starts_at (calculator DoS defence-in-depth)"
```

---

### Task 3 : Circuit-breaker global sur `widget-book`

**Fichiers :**
- Modifier : `backend/app/Providers/AppServiceProvider.php`
- Test : `backend/tests/Feature/TenantSchema/WidgetBookCircuitBreakerTest.php` (nouveau)

- [ ] **Étape 1 : test rouge**

Lire d'abord `AppServiceProvider::boot` (le limiter `widget-book` actuel) et un test booking existant pour le payload + le pattern d'IP spoofée (`X-Forwarded-For`). Créer `backend/tests/Feature/TenantSchema/WidgetBookCircuitBreakerTest.php` :

```php
<?php

use App\Models\Tenant\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('widget-book-global'); // shared global bucket — isolate the test
    $this->service = Service::factory()->create();
});

it('trips the global circuit-breaker after 30 bookings across distinct ips', function () {
    // 30 requests from 30 different IPs all pass the per-IP limit (5/min each)
    // but together exhaust the 30/min global bucket; the 31st is 429.
    for ($i = 1; $i <= 30; $i++) {
        $res = $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->postJson('/api/v1/widget/appointments', invalidButRoutablePayload());
        expect($res->status())->not->toBe(429); // may be 422 (validation) — just NOT throttled
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->postJson('/api/v1/widget/appointments', invalidButRoutablePayload())
        ->assertStatus(429);
});

it('still enforces the per-ip limit of 5 per minute', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/widget/appointments', invalidButRoutablePayload());
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/api/v1/widget/appointments', invalidButRoutablePayload())
        ->assertStatus(429);
});
```

⚠️ Notes implémenteur cruciales :
1. **Le throttle s'évalue AVANT la validation** (middleware). Donc le payload peut être invalide (`invalidButRoutablePayload()` = `[]` ou minimal) — la requête est comptée par le limiter qu'elle soit 422 ou non. C'est l'approche la plus simple et robuste : on teste le throttle, pas le booking. Vérifier que le middleware `throttle:widget-book` tourne bien avant la validation du FormRequest (c'est le cas en Laravel : middleware de route > résolution du FormRequest).
2. **IP en test :** `withServerVariables(['REMOTE_ADDR' => ...])` est le mécanisme fiable pour faire varier `$request->ip()` en test (TrustProxies + `X-Forwarded-For` marche aussi mais REMOTE_ADDR est plus direct ici). L'implémenteur choisit et rapporte ce qui fait effectivement varier `$r->ip()`.
3. **Isolation :** le bucket global `widget-book-global` est partagé entre tests → `RateLimiter::clear('widget-book-global')` en `beforeEach`. Vérifier aussi que le cache de test (`array`) ne persiste pas entre tests (défaut Pest : app neuve par test, donc cache array vidé — mais la clé nommée mérite le clear explicite par sécurité). Le `decay` par minute peut nécessiter `RateLimiter::clear()` ciblé plutôt qu'un voyage dans le temps.
4. Si 30 requêtes réelles sont trop lentes/flaky, c'est acceptable ici (POST simples, pas de mail car payload invalide → coupe avant). Garder tel quel sauf flakiness avérée.

Lancer → le 1er test échoue (la 31e n'est pas 429 — pas de breaker global aujourd'hui). Le 2e (per-IP) passe déjà. Observer le rouge sur le breaker global.

- [ ] **Étape 2 : implémenter**

`AppServiceProvider::boot` — remplacer la ligne `widget-book` :

```php
        RateLimiter::for('widget-book', fn (Request $r) => [
            // Global circuit-breaker FIRST: a rotating-proxy botnet bypasses any
            // per-IP limit, so cap total booking traffic at 30/min — far above a
            // single practice's legitimate pace, low enough to blunt calendar-
            // squatting. The per-IP 5/min still stops a single noisy client.
            Limit::perMinute(30)->by('widget-book-global'),
            Limit::perMinute(5)->by($r->ip()),
        ]);
```

- [ ] **Étape 3 : lancer — doit passer**

`php artisan test --filter=WidgetBookCircuitBreakerTest` → PASS (2). `composer test` → suite verte.

⚠️ Régression probable : les tests booking existants qui font plusieurs POST `widget-book` dans la même minute pourraient maintenant taper le bucket global 30/min s'ils tournent dans le même process sans clear. Vérifier ; si un test booking existant fait > 30 POST cumulés, ajouter `RateLimiter::clear('widget-book-global')` dans son setup. Rapporter tout test touché.

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Providers/AppServiceProvider.php backend/tests/Feature/TenantSchema/WidgetBookCircuitBreakerTest.php
git commit -m "feat(widget): global 30/min circuit-breaker on widget-book (anti slot-squatting botnet)"
```

---

### Task 4 : Throttle du mail de confirmation par destinataire

**Fichiers :**
- Modifier : `backend/app/Http/Controllers/Widget/AppointmentController.php`
- Test : `backend/tests/Feature/TenantSchema/ConfirmationMailThrottleTest.php` (nouveau)

- [ ] **Étape 1 : test rouge**

Lire `tests/Feature/TenantSchema/WidgetBookingTest.php` pour le pattern exact d'un booking RÉUSSI (factory practitioner+service+availability pour que `isBookable` passe et qu'un mail soit effectivement mis en file). Créer `backend/tests/Feature/TenantSchema/ConfirmationMailThrottleTest.php` :

```php
<?php

use App\Mail\AppointmentConfirmationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    // shared per-email bucket — clear so counts don't bleed between tests
    RateLimiter::clear('confirm-mail:'.sha1('parent@example.de'));
});

it('queues a confirmation mail for the first three bookings to one email', function () {
    // ... 3 successful bookings, same parent_email, 3 distinct free slots ...
    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
});

it('stops queueing mail after 3 to the same email but still books (201)', function () {
    // ... 4 successful bookings, same parent_email, 4 distinct free slots ...
    // every booking returns 201 with a reference; only 3 mails go out
    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
    // the appointment rows all exist (booking not blocked)
    expect(\App\Models\Tenant\Appointment::where('parent_email', 'parent@example.de')->count())->toBe(4);
});

it('treats email case and whitespace as the same recipient', function () {
    // booking with 'Parent@Example.de ' shares the bucket of 'parent@example.de'
    // ... 3 with lowercase + 1 with mixed-case/space → still only 3 mails ...
    Mail::assertQueued(AppointmentConfirmationMail::class, 3);
});

it('does not throttle a different recipient', function () {
    // 3 to parent@example.de + 1 to other@example.de → 4 mails total
    Mail::assertQueued(AppointmentConfirmationMail::class, 4);
});
```

⚠️ Notes implémenteur :
- Le plus dur ici est de produire **4 réservations réussies** (slots libres distincts) pour le même e-mail. Réutiliser le helper/setup de `WidgetBookingTest` qui crée practitioner + service + availability couvrant plusieurs créneaux. Choisir 4 `starts_at` distincts dans la même journée ouvrée (ou jours consécutifs) tous bookables. Si c'est lourd, factoriser un helper `bookOnce($email, $startsAt)` dans le test.
- **Désactiver le circuit-breaker global pour ce test** : 4+ bookings rapides pourraient taper le 30/min global selon les autres tests du process — `RateLimiter::clear('widget-book-global')` en `beforeEach` aussi, par sécurité.
- `Mail::fake()` capture `queue()` ET `send()` ; `assertQueued` couvre `->queue(...)`. Vérifier que le contrôleur utilise bien `queue()` (oui, ligne 75).
- Le compteur d'IP : faire les 4 bookings depuis des IP **différentes** (`withServerVariables`) pour ne pas taper le per-IP 5/min — ou rester sous 5 (4 < 5, OK depuis la même IP). 4 depuis la même IP passe le per-IP. ✓

Lancer → le test « stops after 3 » échoue (4 mails en file aujourd'hui, pas de cap). Observer le rouge.

- [ ] **Étape 2 : implémenter**

`AppointmentController::store` — remplacer le bloc `rescue(fn () => Mail::to(...)->queue(...))` (lignes ~74-77) par :

```php
        // Notify the parent. Two guards stack here:
        //  - per-recipient throttle (3/hour/email): caps email-bombing of a
        //    victim address; the booking itself is untouched (still 201 + row
        //    committed) — only the mail is skipped past the cap.
        //  - rescue() INSIDE the callback: a queue-push failure (e.g. Redis down)
        //    must never 500 an already-committed booking.
        $cancelUrl = route('storno.show', ['token' => $appointment->cancellation_token]);
        $emailKey = 'confirm-mail:'.sha1(mb_strtolower(trim($appointment->parent_email)));
        RateLimiter::attempt(
            $emailKey,
            maxAttempts: 3,
            callback: fn () => rescue(fn () => Mail::to($appointment->parent_email)->queue(
                new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
            )),
            decaySeconds: 3600,
        );
```

Ajouter `use Illuminate\Support\Facades\RateLimiter;` en tête.

⚠️ Sémantique `RateLimiter::attempt` : exécute le callback et renvoie son retour si sous le cap, sinon `false` sans exécuter. On ignore le retour (la réservation est déjà commitée, la réponse 201 est inchangée). Vérifier que `rescue()` à l'intérieur ne fait pas compter un échec différemment — `attempt` incrémente le compteur AVANT d'exécuter le callback, donc un mail qui échoue (rescue) consomme quand même un essai : c'est le comportement voulu et documenté dans la spec (risque accepté).

- [ ] **Étape 3 : lancer — doit passer**

`php artisan test --filter=ConfirmationMailThrottleTest` → PASS (4). `composer test` → suite verte. ⚠️ `WidgetBookingTest` existant asserte sûrement qu'UN mail part sur un booking — toujours vrai (1 < 3). Vérifier qu'aucun test existant ne fait > 3 bookings au même e-mail sans clear.

- [ ] **Étape 4 : Pint + commit**

```bash
vendor/bin/pint --dirty
git add backend/app/Http/Controllers/Widget/AppointmentController.php backend/tests/Feature/TenantSchema/ConfirmationMailThrottleTest.php
git commit -m "feat(widget): throttle confirmation mail to 3/hour/recipient (anti email-bombing)"
```

---

### Task 5 : Vérification finale — suites, push, PR

- [ ] **Étape 1 : suites complètes**

```bash
composer test            # 190 + ~15 nouveaux, tout vert
npm run test:widget      # 102 intouché
vendor/bin/pint --test   # nos fichiers clean (la dette pré-existante de main peut apparaître)
```

- [ ] **Étape 2 : revue manuelle anti-régression**

Confirmer à la lecture du diff complet (`git diff origin/main...HEAD`) :
- Aucune route token sans `whereUuid`.
- Le `rescue()` est bien CONSERVÉ à l'intérieur du callback `attempt` (sémantique « queue-fail ne 500 jamais »).
- La réponse 201 du booking est inchangée (mêmes 4 champs) dans tous les chemins.
- `from`/`to` cappés sur les DEUX contrôleurs read.
- Le breaker global ET le per-IP coexistent (tableau de 2 `Limit`).

- [ ] **Étape 3 : push + PR**

```bash
git push -u origin feature/widget-anti-abuse
gh pr create --title "feat(security): widget anti-abuse throttles [PR-C]" --body "<résumé : tableau des 4 fix, tests, lien spec ; rappel : pas de changement UX booking>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Puis agent code-reviewer final sur le diff + boucle CodeRabbit (autofix Major+Minor). **Pas de merge, pas de deploy sans « deploy »/« merge » explicite.**

---

## Notes d'auto-revue du plan

- Couverture spec : §1→Task 4, §2→Task 3, §3→Task 2, §4→Task 1. Ordre choisi du plus isolé (UUID routes) au plus intriqué (mail throttle nécessitant des bookings réussis) pour stabiliser tôt.
- Pièges throttle partagés entre tests : `RateLimiter::clear()` explicite sur les clés nommées (`widget-book-global`, `confirm-mail:*`) en `beforeEach` — anticipé dans chaque tâche concernée.
- Risque de régression principal : tests booking existants tapant les nouveaux caps (global 30/min, mail 3/h, bornes `starts_at`/plage). Chaque tâche demande explicitement à l'implémenteur de vérifier et rapporter tout test existant ajusté.
- TDD honnête sur les bornes `diffInDays` et la sémantique `RateLimiter::attempt` (incrément avant callback) : l'implémenteur observe et rapporte les valeurs/mécanismes réels.
