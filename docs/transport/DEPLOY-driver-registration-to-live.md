# Deploying the driver-registration feature to the live server

**For whoever deploys `crm.nexforeconsulting.com` (manual deploy).**

This push adds driver self-registration + admin approval. It includes a **new
database migration**, so a plain code pull is not enough.

## On the live server, from the app root

```bash
# 1. Get the new code
git pull origin master

# 2. PHP deps (nothing new here, but safe)
composer install --no-dev --optimize-autoloader

# 3. Run the new migration — creates the driver_registrations table
php artisan migrate --force

# 4. Rebuild the web frontend (the admin "waiting for approval" panel)
cd frontend
npm ci
npm run build
cd ..

# 5. Refresh caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## What this makes live

- **Public:** `POST /api/driver/register` — a driver signs up from the app.
- **Admin:** the Drivers board shows a *"waiting for approval"* panel; Approve
  creates the login + driver profile, Reject turns them away.
- A driver can only sign in **after** an admin approves them.

## Verify after deploy

```bash
# Should return 422 (validation), NOT 405/404 — proves the route is live
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  https://crm.nexforeconsulting.com/api/driver/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'
```

## The mobile app

The APK is separate — it is not deployed to this server. It already points at
`https://crm.nexforeconsulting.com`, so once the above is deployed, the app's
Register button works against live. Drivers install the APK; the admin approves
them here.
