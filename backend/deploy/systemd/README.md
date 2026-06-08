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
