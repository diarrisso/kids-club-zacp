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
php artisan mail:test your@email.example
```

Expect "Test mail sent ..." and confirm the email actually arrives.

## 5. End-to-end

Make a real booking through the widget → confirm the confirmation email arrives.
Tail logs: `journalctl -u php8.4-fpm` / `php artisan pail`.
