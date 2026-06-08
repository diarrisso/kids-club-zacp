# Fiabilité de l'envoi des e-mails en production — Design

**Date** : 2026-06-08
**Statut** : Validé (à implémenter)
**Auteur** : Mamadi Diarrisso (+ Claude)
**Contexte** : Kids Club by zacp — single-tenant booking (Laravel 13 · PostgreSQL · prod sur `kidsclub.masingatech.com`)

---

## 1. Problème

Les trois e-mails transactionnels du cabinet sont **mis en file** mais **rien ne vide la file** en production :

| E-mail | Déclencheur | Destinataire | Mailable |
|--------|-------------|--------------|----------|
| Confirmation | Réservation widget (POST) | Parent | `AppointmentConfirmationMail` |
| Annulation (alerte) | Annulation (token / page storno) | **Cabinet** (`PRACTICE_NOTIFICATION_EMAIL`) | `AppointmentCancelledMail` |
| Rappel | Cron horaire, fenêtre `[24h, 25h)` | Parent | `AppointmentReminderMail` |

Les trois Mailables implémentent `ShouldQueue`, et les call-sites utilisent `Mail::to(...)->queue(...)`. La config par défaut est `QUEUE_CONNECTION=database` (`config/queue.php` ligne 16, `.env.example` ligne 43).

**Conséquences observées / suspectées en prod :**

1. **`QUEUE_CONNECTION=database` sans worker** → les jobs s'empilent dans la table `jobs` et **ne partent jamais** (aucun `queue:work` ni Horizon supervisé sur le VPS).
2. **Aucun cron `schedule:run`** → la commande horaire `appointments:send-reminders` (planifiée dans `routes/console.php` via `Schedule::command(...)->hourly()`) **ne s'exécute jamais** ; les rappels ne partent pas, même avec un worker.

Ce n'est **pas** une régression du parcours date-first (PR #21) : le bug d'infra préexiste. Le code applicatif est correct.

### Hors-cause (à confirmer au diagnostic)
- Le SMTP Hostinger (`smtp.hostinger.com:465`, `info@masingatech.com`) est supposé fonctionnel (repris d'autres projets), mais **non vérifié** pour ce projet → le diagnostic + le smoke test lèveront le doute.

---

## 2. Stratégie retenue

Deux décisions de cadrage (validées en brainstorming) :

1. **Queue = `sync`** : passer `QUEUE_CONNECTION=sync` en prod. Sous `sync`, `->queue()` exécute le Mailable **immédiatement, dans la requête HTTP** — pas de worker daemon à superviser. Pertinent ici : un seul cabinet, quelques e-mails/jour, latence SMTP acceptable dans la requête (et déjà enveloppée dans `rescue()` côté confirmation/annulation, donc une panne SMTP ne casse jamais la réservation/annulation côté utilisateur).
2. **Profondeur de vérification = Infra + test de régression + smoke** : provisionner l'infra, **plus** un test Pest `Mail::fake()` qui verrouille le câblage du dispatch, **plus** une commande artisan `mail:test` pour vérifier le transport réel en prod.

### Pourquoi pas un worker queue ?
`database` + `queue:work` (ou Horizon) est la solution « scalable » classique, mais c'est un daemon à superviser (systemd, redémarrage après deploy, monitoring des `failed_jobs`). **YAGNI** pour un cabinet à faible volume : `sync` supprime toute cette infra. Si le volume explose un jour, repasser à `database` + worker est un simple changement d'`.env` + une unit systemd — réversible, à faire **quand** le besoin existe.

### Découplage des deux préoccupations
- **« Le code dispatche-t-il le bon e-mail au bon destinataire ? »** → test Pest déterministe avec `Mail::fake()`, tourne en CI. Ne touche jamais au SMTP.
- **« Le transport SMTP délivre-t-il réellement ? »** → commande `mail:test` lancée manuellement en prod vers une vraie boîte. Dépend de l'infra, pas testable en CI.

C'est exactement ce découplage qui manquait : **le bug actuel est côté transport/infra, pas côté code applicatif.**

---

## 3. Composants

### 3.1 Diagnostic prod d'abord (lecture seule)

Avant toute modification, établir l'état réel via SSH (`root@72.62.46.55`, app dans `/var/www/kidsclub/backend`) :

- Lire `.env` prod : `QUEUE_CONNECTION`, bloc `MAIL_*`, `MAIL_FROM_ADDRESS`, `PRACTICE_NOTIFICATION_EMAIL`, `APP_URL`, `APP_ENV`.
- Vérifier l'existence d'un scheduler : `crontab -l`, `systemctl list-timers | grep -i kidsclub`, présence d'une unit `kidsclub-scheduler`.
- Inspecter la file : `SELECT count(*) FROM jobs;` et `SELECT count(*), max(failed_at) FROM failed_jobs;` (e-mails coincés ?).
- Vérifier le transport : `php artisan tinker` → tester la résolution de config mail, ou directement le smoke test (§3.5) une fois en place.

**Livrable** : un mini-rapport (3-5 lignes) consigné dans la PR, qui confirme/infirme les deux causes racines avant d'appliquer le correctif. Aucune écriture à cette étape.

### 3.2 Configuration `.env` prod

- `QUEUE_CONNECTION=sync` (s'il ne l'est pas déjà).
- Vérifier / corriger le bloc SMTP Hostinger : `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.hostinger.com`, `MAIL_PORT=465`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=ssl`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
- Vérifier `PRACTICE_NOTIFICATION_EMAIL` (destinataire des alertes d'annulation cabinet).
- Appliquer : `php artisan config:clear && php artisan config:cache`, puis `systemctl restart php8.4-fpm` (OPcache FPM, règle de déploiement projet).

> ⚠️ Les valeurs `.env` prod sont posées **manuellement sur le serveur** (jamais commitées). Le mot de passe SMTP n'est jamais saisi par l'assistant — l'utilisateur le pose lui-même si besoin.

### 3.3 Scheduler système (cron)

Installer un déclencheur qui lance `php artisan schedule:run` **chaque minute** ; Laravel décide ensuite quelle commande planifiée est due (ici, `appointments:send-reminders` à l'heure pile).

**Mécanisme : systemd timer** (cohérent avec la stack systemd du VPS — `php8.4-fpm`, observabilité `journalctl`) :

- `kidsclub-scheduler.service` (type `oneshot`) : `ExecStart=/usr/bin/php /var/www/kidsclub/backend/artisan schedule:run`
- `kidsclub-scheduler.timer` : `OnCalendar=*:0/1` (toutes les minutes), `Persistent=true`.
- Activation : `systemctl enable --now kidsclub-scheduler.timer`.

*Alternative équivalente* : une ligne crontab root
`* * * * * cd /var/www/kidsclub/backend && php artisan schedule:run >> /dev/null 2>&1`.
Le timer systemd est préféré pour les logs (`journalctl -u kidsclub-scheduler`) et la cohérence avec le reste de l'infra.

Les fichiers unit sont versionnés dans le repo (`deploy/systemd/`) pour la traçabilité, mais leur installation reste une étape serveur ponctuelle (idempotente).

### 3.4 Test de régression Pest (`Mail::fake()`)

Nouveau fichier `tests/Feature/TenantSchema/BookingNotificationsTest.php`. Il **verrouille le câblage du dispatch**, indépendamment du transport :

1. **Confirmation au parent** : `Mail::fake()`, poster une réservation valide sur l'endpoint widget → `Mail::assertQueued(AppointmentConfirmationMail::class, fn ($m) => $m->hasTo($parentEmail))`.
2. **Alerte annulation au cabinet** : créer un rendez-vous, annuler via le token → `Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo(<PRACTICE_NOTIFICATION_EMAIL>))` (et **pas** au parent).
3. *(Optionnel, si peu coûteux)* **Rappel** : un rendez-vous dans la fenêtre `[24h, 25h)`, exécuter `appointments:send-reminders` → `assertQueued(AppointmentReminderMail::class)` + `reminder_sent_at` renseigné ; un rendez-vous hors fenêtre → `assertNotQueued`.

> Note : `Mail::fake()` intercepte au niveau du façade `Mail` ; `assertQueued` fonctionne que le mailable soit `ShouldQueue` ou non, donc le test reste valide quel que soit `QUEUE_CONNECTION`. Il prouve « le code envoie au bon destinataire », jamais « le SMTP délivre ».

### 3.5 Commande smoke `mail:test`

Nouvelle commande artisan `mail:test {email}` :

- Envoie un e-mail minimal (sujet « Kids Club — test mail », corps court) via le transport configuré, **en synchrone** (`Mail::raw(...)->send()` ou un petit Mailable dédié sans `ShouldQueue`).
- Affiche succès/échec + l'exception éventuelle (utile pour diagnostiquer un refus SMTP : auth, port, TLS).
- Ne crée **aucun** rendez-vous, n'écrit rien en base.
- Usage prod : `php artisan mail:test diarrisso49@gmail.com` → confirmer la réception réelle.

Un test unitaire léger (`Mail::fake()` + `assertSent`) couvre la commande en CI.

### 3.6 Documentation

- Mettre à jour la mémoire `deployment-infra` : l'envoi e-mail dépend de `QUEUE_CONNECTION=sync` **et** du timer scheduler ; documenter la procédure `mail:test`.
- Note dans `backend/README.md` ou `CLAUDE.md` (section déploiement) : checklist e-mail post-deploy.

---

## 4. Hors scope (YAGNI)

- **Worker queue / Horizon** : pas de daemon `queue:work`, pas de supervision de `failed_jobs`. `sync` rend la file inutile.
- **Refonte des Mailables** : `ShouldQueue` + `->queue()` reste tel quel — s'exécute en synchrone sous `sync`, zéro changement de code applicatif sur les call-sites.
- **Retry / backoff / dead-letter** : en `sync`, l'échec lève l'exception (capturée par `rescue()` côté confirmation/annulation) ; pas de mécanisme de réessai automatique. Acceptable au volume actuel.
- **Monitoring / alerting e-mail** (Sentry sur échec d'envoi, dashboard) : non requis maintenant.
- **Changement du pipeline `deploy.yml`** : l'application de l'`.env` et du timer est une opération serveur ponctuelle ; pas d'automatisation CD ajoutée dans cette itération (réévaluable si on refait le serveur).

---

## 5. Plan de test / vérification

| Niveau | Quoi | Où |
|--------|------|-----|
| **Unitaire/Feature (CI)** | `BookingNotificationsTest` (dispatch + destinataires), test `mail:test` | `composer test`, CI GitHub Actions |
| **Diagnostic (prod, lecture)** | rapport `.env` / cron / file `jobs`/`failed_jobs` | SSH manuel |
| **Smoke (prod, écriture réseau)** | `php artisan mail:test <inbox>` → réception réelle | SSH manuel post-deploy |
| **Bout-en-bout (prod)** | une vraie réservation widget → réception du mail de confirmation ; tail logs | Chrome + `journalctl`/`pail` |

---

## 6. Workflow de livraison

Branche feature → TDD (test Pest d'abord, puis commande `mail:test`) → revue (code-reviewer agent : sécurité, destinataires, pas de fuite `notes_internal`) → PR + CodeRabbit (autofix) → merge.
**Déploiement uniquement sur « deploy » explicite** : application de l'`.env` (`sync` + vérif SMTP), installation du timer scheduler, `config:cache` + `restart php8.4-fpm`, puis smoke `mail:test` + réservation de bout-en-bout.

---

## 7. Risques & mitigations

| Risque | Mitigation |
|--------|------------|
| Latence SMTP ajoutée à la requête de réservation (sync) | `rescue()` déjà en place → une lenteur/panne SMTP ne fait jamais échouer la réservation ; volume faible |
| Mauvaise config SMTP en prod (auth/port/TLS) | Diagnostic + `mail:test` la détectent **avant** la première vraie réservation |
| Timer scheduler oublié après une réinstallation serveur | Unit files versionnés dans `deploy/systemd/` + documentés dans `deployment-infra` |
| Confusion destinataire annulation (parent vs cabinet) | Test de régression assertant explicitement le destinataire cabinet, pas le parent |
