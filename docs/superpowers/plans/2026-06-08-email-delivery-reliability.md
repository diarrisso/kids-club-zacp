# Email Delivery Reliability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the three transactional emails (confirmation, cancellation alert, reminder) actually reach recipients in production by switching the queue to `sync` and installing a scheduler cron, plus a `mail:test` smoke command and a thin guard test.

**Architecture:** The email *dispatch* code is already correct and already covered by existing tests (`BookingConfirmationMailTest`, `CabinetNotifierTest`, `SendAppointmentRemindersTest`). The bug is purely operational: queued jobs are never drained (no worker) and `schedule:run` never executes (no cron). Fix = `QUEUE_CONNECTION=sync` (emails send in-request, no daemon) + a systemd timer running `schedule:run` every minute. The only new repo code is a `mail:test` artisan command (for verifying the real SMTP transport in prod) and versioned systemd unit files. The actual `.env`/timer application is a server-side deploy step.

**Tech Stack:** Laravel 13 · PHP 8.4 · PostgreSQL · Pest 4 · systemd · SMTP (Hostinger).

---

## Context the implementer needs (read before starting)

- **Single-tenant app.** The `App\Models\Tenant\*`, `App\Mail\*`, route names `tenant.*` etc. are *vestigial* naming from a former multi-tenant design. There is one database, one practice. Do not look for tenant resolution — there is none.
- **Run all commands from `backend/`.** Test suite is pinned to PostgreSQL (`phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=masinga_booking_test`). Full suite: `composer test`. Single file: `php artisan test tests/Feature/TenantSchema/<File>.php`.
- **`phpunit.xml` already sets `QUEUE_CONNECTION=sync` (line 31) and `MAIL_MAILER=array` (line 28)** and `PRACTICE_NOTIFICATION_EMAIL=praxis@kidsclub.test` (line 29). So tests already run under the same sync semantics we're shipping to prod, and mail goes to an in-memory transport.
- **The reminder command** is `App\Console\Commands\SendAppointmentReminders` (`signature = 'appointments:send-reminders'`), scheduled in `routes/console.php:13` via `Schedule::command('appointments:send-reminders')->hourly()->withoutOverlapping();`.
- **Existing dispatch coverage (do NOT duplicate):**
  - `tests/Feature/TenantSchema/BookingConfirmationMailTest.php` — confirmation queued to parent; honeypot → nothing.
  - `tests/Feature/TenantSchema/CabinetNotifierTest.php` — cancellation queued to cabinet (`praxis@kidsclub.test`).
  - `tests/Feature/TenantSchema/SendAppointmentRemindersTest.php` — reminder in/out of the `[24h,25h)` window + `reminder_sent_at`.
- **Pint** is the style tool: run `vendor/bin/pint` before committing PHP.
- **Production facts** (from `deployment-infra` memory): VPS `root@72.62.46.55`, app at `/var/www/kidsclub/backend`, PHP 8.4 + `php8.4-fpm`, SMTP `smtp.hostinger.com:465` (`info@masingatech.com`). Deploy is auto via GitHub Actions on merge to `main`, but **only deploy on the user's explicit "deploy"**.

---

## File Structure

| File | Responsibility | Action |
|------|----------------|--------|
| `tests/Feature/TenantSchema/ReminderScheduleTest.php` | Guard: assert the reminder command is registered to run hourly | Create |
| `app/Mail/MailTestMail.php` | Minimal self-contained Mailable for the smoke test (no Blade view, no `ShouldQueue`) | Create |
| `app/Console/Commands/MailTest.php` | `mail:test {email}` — send `MailTestMail` synchronously, report success/failure | Create |
| `tests/Feature/TenantSchema/MailTestCommandTest.php` | Cover the `mail:test` command (recipient + exit code) | Create |
| `deploy/systemd/kidsclub-scheduler.service` | systemd oneshot running `artisan schedule:run` | Create |
| `deploy/systemd/kidsclub-scheduler.timer` | systemd timer firing the service every minute | Create |
| `deploy/systemd/README.md` | Install/verify instructions for the units | Create |
| `backend/README-deployment-email.md` | Email deploy checklist (diagnose → env → timer → smoke → E2E) | Create |

---

## Task 1: Guard test — reminder command is scheduled hourly

This is the one dispatch-adjacent gap not already covered: existing tests prove the command *works*, but nothing proves it is *scheduled*. If a future refactor drops the `Schedule::command(...)` line from `routes/console.php`, reminders silently stop — this test catches that.

**Files:**
- Test: `tests/Feature/TenantSchema/ReminderScheduleTest.php` (Create)

- [ ] **Step 1: Write the test**

```php
<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the 24h reminder command to run hourly', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn (Event $e) => str_contains($e->command ?? '', 'appointments:send-reminders'));

    expect($events)->toHaveCount(1);

    // "0 * * * *" is Laravel's cron expression for ->hourly().
    expect($events->first()->expression)->toBe('0 * * * *');
});
```

- [ ] **Step 2: Run the test — expect PASS (guards existing behavior)**

Run: `php artisan test tests/Feature/TenantSchema/ReminderScheduleTest.php`
Expected: PASS (the command is already scheduled hourly in `routes/console.php:13`). This is a regression guard, not fail-first TDD — it locks current behavior so removing the schedule line later turns CI red.

- [ ] **Step 3: Sanity-check the guard actually guards**

Temporarily comment out line 13 of `routes/console.php`, re-run the test, confirm it FAILS, then restore the line. (Do not commit the commented-out version.)

Run: `php artisan test tests/Feature/TenantSchema/ReminderScheduleTest.php`
Expected after restore: PASS.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint app tests
git add tests/Feature/TenantSchema/ReminderScheduleTest.php
git commit -m "test: guard that the 24h reminder command stays scheduled hourly"
```

---

## Task 2: `MailTestMail` Mailable

A minimal, view-less Mailable used only by the smoke command. No `ShouldQueue` (so it always sends synchronously, even if prod queue ever changes). Uses `Content(htmlString: ...)` to avoid creating a Blade template for a throwaway test email.

**Files:**
- Create: `app/Mail/MailTestMail.php`

- [ ] **Step 1: Write the Mailable**

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Throwaway diagnostic email for `php artisan mail:test`.
 * Deliberately NOT ShouldQueue: the smoke test must send synchronously and
 * surface transport errors in the same process. Self-contained HTML, no view.
 */
class MailTestMail extends Mailable
{
    public function __construct(public string $appName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->appName.' — Test-E-Mail',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Diese Test-E-Mail bestätigt, dass der SMTP-Versand für '
                .e($this->appName).' funktioniert.</p>',
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
vendor/bin/pint app
git add app/Mail/MailTestMail.php
git commit -m "feat(mail): add MailTestMail diagnostic mailable"
```

---

## Task 3: `mail:test` command (TDD)

Sends `MailTestMail` to a given address using the configured transport, synchronously, and reports success or the failure reason. Used in prod to confirm SMTP actually delivers — the one thing no `Mail::fake()` test can cover.

**Files:**
- Test: `tests/Feature/TenantSchema/MailTestCommandTest.php` (Create)
- Create: `app/Console/Commands/MailTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\MailTestMail;
use Illuminate\Support\Facades\Mail;

it('sends a test mail to the given address and exits successfully', function () {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'inbox@example.de'])
        ->expectsOutputToContain('inbox@example.de')
        ->assertExitCode(0);

    Mail::assertSent(MailTestMail::class, fn ($m) => $m->hasTo('inbox@example.de'));
});

it('fails with a non-zero exit code on an invalid email', function () {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'not-an-email'])
        ->assertExitCode(1);

    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/TenantSchema/MailTestCommandTest.php`
Expected: FAIL — `Command "mail:test" is not defined.`

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Mail\MailTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MailTest extends Command
{
    protected $signature = 'mail:test {email : Recipient address for the diagnostic email}';

    protected $description = 'Send a diagnostic email via the configured transport to verify SMTP delivery.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        try {
            // ->send() (not ->queue()) forces synchronous delivery so any
            // transport error surfaces here instead of being swallowed by a queue.
            Mail::to($email)->send(new MailTestMail(config('app.name')));
        } catch (\Throwable $e) {
            $this->error("Failed to send test mail to {$email}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Test mail sent to {$email} via mailer '".config('mail.default')."'.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/TenantSchema/MailTestCommandTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Run the full suite to confirm no regressions**

Run: `composer test`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app tests
git add app/Console/Commands/MailTest.php tests/Feature/TenantSchema/MailTestCommandTest.php
git commit -m "feat(mail): add mail:test smoke command for verifying SMTP delivery"
```

---

## Task 4: systemd scheduler units (versioned)

The Laravel scheduler needs `schedule:run` invoked every minute. We ship the unit files in the repo for traceability; installation is a one-time server step (Task 6 / deploy).

**Files:**
- Create: `deploy/systemd/kidsclub-scheduler.service`
- Create: `deploy/systemd/kidsclub-scheduler.timer`
- Create: `deploy/systemd/README.md`

- [ ] **Step 1: Write the service unit**

`deploy/systemd/kidsclub-scheduler.service`:

```ini
[Unit]
Description=Kids Club Laravel scheduler (artisan schedule:run)
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/kidsclub/backend
ExecStart=/usr/bin/php /var/www/kidsclub/backend/artisan schedule:run
```

- [ ] **Step 2: Write the timer unit**

`deploy/systemd/kidsclub-scheduler.timer`:

```ini
[Unit]
Description=Run the Kids Club Laravel scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
```

- [ ] **Step 3: Write the install README**

`deploy/systemd/README.md`:

````markdown
# Laravel scheduler — systemd timer

The hourly 24h-reminder (`appointments:send-reminders`) only fires if something
runs `php artisan schedule:run` every minute. These units do that.

## Install (one-time, on the VPS as root)

```bash
cp /var/www/kidsclub/backend/deploy/systemd/kidsclub-scheduler.service /etc/systemd/system/
cp /var/www/kidsclub/backend/deploy/systemd/kidsclub-scheduler.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now kidsclub-scheduler.timer
```

## Verify

```bash
systemctl list-timers kidsclub-scheduler.timer   # NEXT/LAST columns populated
journalctl -u kidsclub-scheduler.service --since "10 min ago"
```

## Notes
- `User=www-data` must own the app files / be able to read `.env`. Adjust if the
  deploy user differs.
- Equivalent crontab alternative (if not using systemd):
  `* * * * * cd /var/www/kidsclub/backend && php artisan schedule:run >> /dev/null 2>&1`
````

- [ ] **Step 4: Commit**

```bash
git add deploy/systemd/
git commit -m "chore(deploy): versioned systemd units for the Laravel scheduler"
```

---

## Task 5: Email deploy checklist doc

Captures the operational procedure so the fix is reproducible and the next person (or a server rebuild) doesn't reintroduce the bug.

**Files:**
- Create: `backend/README-deployment-email.md`

- [ ] **Step 1: Write the checklist**

`backend/README-deployment-email.md`:

````markdown
# Email delivery — production checklist

Transactional emails (confirmation, cancellation alert, reminder) only work in
prod when BOTH are true: the queue is `sync` (emails send in-request) AND a cron
runs `schedule:run` every minute (for the hourly reminder).

## 1. Diagnose (read-only, before changing anything)

On the VPS (`root@72.62.46.55`, app at `/var/www/kidsclub/backend`):

```bash
grep -E 'QUEUE_CONNECTION|MAIL_|PRACTICE_NOTIFICATION_EMAIL|APP_URL|APP_ENV' .env
crontab -l 2>/dev/null | grep -i schedule || echo "no schedule cron"
systemctl list-timers 2>/dev/null | grep -i kidsclub || echo "no scheduler timer"
php artisan tinker --execute="echo \DB::table('jobs')->count().' queued / '.\DB::table('failed_jobs')->count().' failed';"
```

Record the result in the PR before proceeding.

## 2. Set the queue to sync + verify SMTP

In `.env`:

```dotenv
QUEUE_CONNECTION=sync
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=info@masingatech.com
MAIL_PASSWORD=********           # set on the server only — never commit
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=info@masingatech.com
MAIL_FROM_NAME="${APP_NAME}"
PRACTICE_NOTIFICATION_EMAIL=...  # cabinet inbox for cancellation alerts
```

Apply:

```bash
php artisan config:clear && php artisan config:cache
systemctl restart php8.4-fpm     # OPcache FPM holds stale config otherwise
```

## 3. Install the scheduler timer

See `deploy/systemd/README.md`.

## 4. Smoke test the transport

```bash
php artisan mail:test diarrisso49@gmail.com
```

Expect "Test mail sent ..." and confirm the email actually arrives.

## 5. End-to-end

Make a real booking through the widget → confirm the confirmation email arrives.
Tail logs: `journalctl -u php8.4-fpm` / `php artisan pail`.
````

- [ ] **Step 2: Commit**

```bash
git add backend/README-deployment-email.md
git commit -m "docs(deploy): production email delivery checklist"
```

---

## Task 6: Production rollout (server-side — run ONLY on the user's explicit "deploy")

Not repo code. Execute the checklist in `backend/README-deployment-email.md` against prod, in order: diagnose → set `QUEUE_CONNECTION=sync` + verify SMTP → `config:cache` + restart `php8.4-fpm` → install + enable the systemd timer → `mail:test` smoke → end-to-end booking. The `.env` password is set by the user on the server; the assistant never types credentials.

- [ ] **Step 1:** Run the diagnostic block (§1 of the checklist); paste the result into the PR.
- [ ] **Step 2:** Apply `.env` (`sync` + SMTP block confirmed); `config:clear && config:cache`; `systemctl restart php8.4-fpm`.
- [ ] **Step 3:** Install + enable `kidsclub-scheduler.timer`; verify with `systemctl list-timers`.
- [ ] **Step 4:** `php artisan mail:test <real-inbox>`; confirm receipt.
- [ ] **Step 5:** Real widget booking → confirm the confirmation email arrives; check `journalctl -u kidsclub-scheduler` after the next hour boundary for a reminder run.
- [ ] **Step 6:** Update the `deployment-infra` memory note (sync + timer now in place; `mail:test` procedure documented).

---

## Self-review notes (for the executor)

- **Spec §3.1 (diagnose first)** → Task 5 §1 + Task 6 Step 1.
- **Spec §3.2 (env sync + SMTP)** → Task 5 §2 + Task 6 Step 2.
- **Spec §3.3 (scheduler)** → Task 4 (units) + Task 6 Step 3; guard in Task 1.
- **Spec §3.4 (regression test)** → **intentionally NOT a new dispatch test** — already covered by `BookingConfirmationMailTest` / `CabinetNotifierTest` / `SendAppointmentRemindersTest` (DRY). The one genuine gap (command stays scheduled) is Task 1.
- **Spec §3.5 (`mail:test`)** → Tasks 2 + 3.
- **Spec §3.6 (docs)** → Task 5 + Task 6 Step 6.
- **Type consistency:** `MailTestMail(string $appName)` constructed identically in `MailTest::handle()` and `MailTestCommandTest`. Command signature `mail:test {email}` matches the test's `['email' => ...]` argument.
- **No new dispatch regression test** is a deliberate DRY decision, documented above so a reviewer doesn't flag it as missing coverage.
