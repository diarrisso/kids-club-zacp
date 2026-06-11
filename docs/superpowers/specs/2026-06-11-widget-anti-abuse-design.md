# PR-C — Anti-abus du widget public (throttles uniquement)

**Date :** 2026-06-11
**Statut :** Dérivé du backlog d'audit validé (`2026-06-10-security-audit-backlog.md`, section PR-C) — à relire.
**Périmètre :** Backend uniquement, 4 corrections. **Aucun changement d'UX de réservation** (décision utilisateur : pas de vérification e-mail, pas de CAPTCHA — à revisiter seulement si les throttles s'avèrent insuffisants).
**Contexte :** Troisième PR du batch sécurité (A: 2FA ✅ #29 · B: infra/headers ✅ #31 · **C: anti-abus** · D: perf). Le prérequis PR-B est rempli : depuis TrustProxies, `$request->ip()` est la vraie IP client — les limiters par IP ont à nouveau un sens.

## Problèmes

1. **[HIGH] Email-bombing.** Une réservation envoie un mail de confirmation à `parent_email` fourni par l'appelant, throttlé seulement 5/min/IP → ~7 200 mails/jour vers une victime depuis une seule IP (`AppointmentController::store`, ligne 75 : `Mail::to($appointment->parent_email)->queue(...)`).
2. **[HIGH] Pas de circuit-breaker global sur `widget-book`.** Le limiter est par IP uniquement (`Limit::perMinute(5)->by($r->ip())`) : un botnet à proxys tournants peut remplir le calendrier de tous les praticiens (slot-squatting) ou spammer sans borne globale.
3. **[MED] Bornes `from`/`to` non plafonnées = amplificateur DoS.** `SlotController` et `AvailabilityController` acceptent n'importe quelle plage ; `from=2020-01-01&to=2099-12-31` boucle ~29 000 jours dans le calculateur, par praticien. Et `StoreAppointmentRequest.starts_at` n'a ni `after:now` ni borne d'horizon (aujourd'hui `isBookable` nous sauve — défense en profondeur manquante).
4. **[LOW] Token non-UUID → 500 au lieu de 404.** `/storno/{token}` et les routes API `{token}` passent la valeur brute à une comparaison de colonne `uuid` PostgreSQL → `PDOException` sur `abc` (typage strict Postgres). Confirmé pendant les reviews de PR-B. Les URLs storno arrivent par e-mail et se font facilement tronquer/malformer.

## Design

### 1. Throttle par destinataire sur le mail de confirmation (`AppointmentController::store`)

```php
// Email-bombing guard: max 3 confirmation mails per recipient per hour. The
// booking itself stays untouched (201 + row committed) — only the mail is
// skipped when the cap is hit, so a victim's mailbox can't be flooded while
// a legitimate triple-booking parent still gets all three confirmations.
$emailKey = 'confirm-mail:'.sha1(mb_strtolower(trim($appointment->parent_email)));
RateLimiter::attempt($emailKey, maxAttempts: 3, callback: fn () => rescue(
    fn () => Mail::to($appointment->parent_email)->queue(
        new AppointmentConfirmationMail($appointment, config('app.name'), $cancelUrl)
    )
), decaySeconds: 3600);
```

- Remplace l'appel `rescue(Mail::queue(...))` actuel — le `rescue()` est conservé À L'INTÉRIEUR du callback (la sémantique « un échec de queue ne 500 jamais une réservation commitée » reste intacte).
- Clé = `sha1` de l'e-mail minuscule/trimé : pas de PII en clair dans le cache, normalisation suffisante (pas de canonicalisation gmail+tags — un attaquant qui varie l'adresse atteint des boîtes différentes, ce n'est plus du bombing d'une victime).
- La réponse reste **201 avec les mêmes champs** dans tous les cas : un attaquant ne peut pas sonder le cap, l'UX parent ne change pas.
- Mail de **rappel** (`appointments:send-reminders`) et mails d'**annulation** : non throttlés — déclenchés par nous (cron) ou par le détenteur du token, pas par un input attaquant répétable.

### 2. Circuit-breaker global sur `widget-book` (`AppServiceProvider::boot`)

```php
RateLimiter::for('widget-book', fn (Request $r) => [
    // Global circuit-breaker FIRST: a rotating-proxy botnet bypasses any
    // per-IP limit; 30/min total caps calendar-squatting damage while staying
    // far above the single practice's legitimate booking pace.
    Limit::perMinute(30)->by('widget-book-global'),
    Limit::perMinute(5)->by($r->ip()),
]);
```

- Laravel accepte un tableau de `Limit` ; les deux buckets sont évalués, le plus restrictif gagne (429 + `Retry-After`).
- 30/min global = ~43 000/mois ; un cabinet mono-praticien fait quelques dizaines de réservations/jour — marge ×50, zéro impact légitime.
- S'applique aussi au POST cancel (même groupe de routes) : acceptable, l'annulation légitime est un événement rare.

### 3. Plafond de plage + défense en profondeur sur `starts_at`

**`SlotController::index` + `AvailabilityController::days`** — après la validation existante :

```php
abort_if($from->diffInDays($to) > 62, 422, 'Date range too large.');
```

(62 = horizon 60 jours + marge de bord de mois ; le widget réel demande des fenêtres d'un mois.)

**`StoreAppointmentRequest::rules`** — `starts_at` passe de `['required', 'date']` à :

```php
'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(61)->toDateString()],
```

Défense en profondeur : `isBookable` applique déjà lead 2h + horizon 60j (source de vérité, inchangée) — la règle de validation rejette juste plus tôt (422 de validation au lieu d'un passage dans le calculateur) les dates passées/lointaines.

### 4. Contrainte UUID sur `{token}` (routes)

```php
// routes/api.php
Route::get('/appointments/{token}', [CancellationController::class, 'show'])->whereUuid('token');
Route::post('/appointments/{token}/cancel', [CancellationController::class, 'cancel'])->whereUuid('token');

// routes/web.php (groupe storno)
Route::get('/{token}', ...)->whereUuid('token')->name('storno.show');
Route::post('/{token}', ...)->whereUuid('token')->name('storno.cancel');
```

Un token malformé ne matche plus la route → **404 propre** (au lieu d'un 500 `PDOException`). Aucun contrôleur modifié.

### Explicitement hors périmètre

- Vérification e-mail / réservations « pending » / CAPTCHA (décision utilisateur — UX intouchable).
- Nettoyage des lignes `appointments` créées par un abuseur (bornées par les slots disponibles grâce au verrou anti-conflit ; le staff peut annuler).
- PR-D (perf) — indépendant.

## Tests (TDD, Pest) — baseline 190

- **Mail throttle :** 3 réservations même e-mail (slots différents) → 3 mails ; la 4e → **201 quand même, 0 mail de plus** (`Mail::fake` + `assertQueued` count). E-mails différents → non affectés. Casse/espaces normalisés (`Foo@X.de` ≡ `foo@x.de`).
- **Circuit-breaker :** 31 POST `widget-book` depuis des IPs **différentes** (header `X-Forwarded-For` varié — TrustProxies de PR-B le rend trustable en test) → la 31e reçoit **429** ; vérifie que le per-IP 5/min tient toujours (6e depuis la même IP → 429).
- **Plage :** `from`/`to` espacés de 63 jours → **422** sur `/slots` ET `/availability/days` ; 62 jours → 200. `starts_at` passé → 422 ; `starts_at` à +90 jours → 422.
- **Token UUID :** `GET /storno/abc` → **404** (plus de 500) ; idem API show + cancel ; un vrai UUID inconnu → 404 aussi (comportement `firstOrFail` inchangé).
- Suite complète verte (190 + ~10 nouveaux), Pint clean sur nos fichiers.

## Critères d'acceptation

- [ ] 4e réservation/h vers le même e-mail : 201, aucun mail supplémentaire en file.
- [ ] 31e réservation/min toutes IPs confondues : 429.
- [ ] Plage > 62 jours sur slots/days : 422 ; `starts_at` hors `(now, +61j)` : 422.
- [ ] Token non-UUID sur les 4 routes token : 404.
- [ ] Aucun changement d'UX/contrat de réponse pour un parent légitime (201, mêmes champs).
- [ ] Suite verte, widget Vitest intouché.

## Risques / points d'attention

- **`RateLimiter::attempt` consomme un essai même si le mail échoue ensuite** (rescue silencieux) : acceptable — au pire un parent légitime en panne de mailer « consomme » son quota sans recevoir ; le rappel 24h reste un filet.
- **Le circuit-breaker global est un bouton DoS volontaire** : un attaquant peut le saturer et bloquer les réservations légitimes 1 min à la fois. C'est l'arbitrage assumé du backlog (mieux qu'un calendrier rempli de faux rendez-vous) ; le monitoring ops (logs 429) dira s'il faut affiner.
- **`before:` évalué à la construction des rules** (par requête) — pas de cache de date piégeux ; utiliser `now()->addDays(61)` dans `rules()` est sûr car les FormRequests sont instanciées par requête.
- Les tests de throttle doivent **vider le cache RateLimiter entre les cas** (clé globale partagée) — `RateLimiter::clear()` ou cache array par test (défaut Pest), à vérifier en TDD.
