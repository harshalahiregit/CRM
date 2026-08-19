# Deployment & background processing

The app "sends" email and some jobs through a **queue** (`QUEUE_CONNECTION`).
Queued mailables (`ShouldQueue`) are written to the `jobs` table and only leave
the server when a **queue worker** is running. In-app bell notifications
(`NotificationService`) are a synchronous DB write and do **not** need the queue.

Two background pieces must run for the app to be fully functional:

| Piece | Command | What breaks without it |
|---|---|---|
| **Queue worker** | `php artisan queue:work` | Email never sends (piles up in `jobs`) |
| **Scheduler** | `php artisan schedule:run` (every minute) | Reminders, SLA sweeps, cycle counts, capacity/stock alerts never fire |

> These run from the Laravel app root: **`backend/`**.

---

## Local development

You do **not** run a worker locally. Two choices:

- **Simplest (current setting):** `QUEUE_CONNECTION=sync` in `backend/.env` — jobs
  run inline, so email sends the instant the action happens. Nothing to remember.
- **Production-like:** set `QUEUE_CONNECTION=database` and run a worker in a spare
  terminal: `cd backend && php artisan queue:work`.

After changing `.env`, restart `php artisan serve` (env is read at boot).

Start the app:
```bash
cd backend  && php artisan serve --host=127.0.0.1 --port=8000
cd frontend && npm run dev            # http://localhost:5173
```

---

## Production — VPS (nginx + php-fpm + Supervisor)

Serve the app with **nginx + php-fpm** (not `artisan serve`). Keep
`QUEUE_CONNECTION=database` (or `redis`) — queuing keeps web responses fast.

**1. Queue worker, kept alive by Supervisor** — `/etc/supervisor/conf.d/crm-worker.conf`:
```ini
[program:crm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/crm/backend/artisan queue:work --tries=3 --timeout=90 --sleep=3
autostart=true
autorestart=true
stopwaitsecs=120
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/crm/backend/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start crm-worker:*
```

**2. Scheduler cron** (`crontab -e` as the deploy user):
```cron
* * * * * cd /var/www/crm/backend && php artisan schedule:run >> /dev/null 2>&1
```

**3. Build the frontend** and serve `frontend/dist` via nginx:
```bash
cd frontend && npm ci && npm run build
```

**4. On every deploy** (so workers pick up new code):
```bash
cd backend
composer install --no-dev -o
php artisan migrate --force
php artisan config:cache && php artisan route:cache
php artisan queue:restart      # REQUIRED — long-running workers cache old code
```

---

## Production — managed platforms

- **Laravel Forge:** add a **Daemon** running `php artisan queue:work …`, and flip on
  the **Scheduler** toggle. Done.
- **Laravel Vapor:** queues are handled automatically (SQS); no worker to manage.
- **Render / Railway / Heroku:** define a `worker` (and `scheduler`) process — see
  `backend/Procfile`.

---

## Mail configuration

There are **two** mail paths, and which one is used depends on the tenant:

1. **Per-tenant SMTP** — whatever is saved in **Settings -> Email** for that
   workspace. Resolved by `TenantMailer`, and it takes precedence. This now
   covers vendor activation (TPV + Purchase), kickoff MoM notices, HR notices,
   proposals, contracts, lead mail and portal OTPs.
2. **Global `.env` SMTP** — the fallback, used only when a tenant has no mail
   settings saved, or has them saved but disabled.

So a workspace with working Settings -> Email needs nothing in `.env`, and a
bad `.env` account cannot break it. Conversely, fixing `.env` will **not** fix
a tenant whose own SMTP settings are wrong — check Settings -> Email first.

Delivery failures on the tenant path are recorded per attempt and logged to
`storage/logs/hr-*.log` (not `laravel.log`):
```bash
grep -h "Notification email failed" storage/logs/hr-*.log | tail -5
```

The global fallback needs SMTP creds in `backend/.env`:
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...          # Gmail: use an App Password, not the account password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=...
```
Check what's stuck / failed:
```bash
php artisan tinker --execute="echo DB::table('jobs')->count().' pending, '.DB::table('failed_jobs')->count().' failed';"
php artisan queue:work --stop-when-empty   # drain the queue once (sends real email)
php artisan queue:failed                    # list failures
```
