# Driver app — over-the-air (OTA) updates

**What this gives you:** after the first install, when we change the driver
app's code, the phone downloads the new code itself on next launch. **No
re-installing the APK.**

**The one honest limit:** OTA updates the app's **JavaScript only** — screens,
logic, API calls, text, layout. That is the vast majority of changes. A change
to *native* capability (a new Android permission, a new native module, an Expo
SDK bump, or bumping `runtimeVersion`) still needs a fresh APK. See the last
section.

Everything is **self-hosted on our own live server** — no Expo account, no
third-party service, nothing on anyone's laptop.

---

## How it works (one minute)

- The app is built pointing at `https://crm.nexforeconsulting.com/api/app-updates/manifest`.
- On every launch it asks that URL "is there a newer bundle for runtime 1.0.0?"
- Our server (a plain Laravel controller) answers with the Expo Updates
  protocol: either "you're up to date" or a manifest describing the new bundle.
- If newer, the app downloads it in the background and runs it on the **next**
  launch.

Server code (already in this repo, deploys with a normal `git pull`):
- Controller: `app/Http/Controllers/Api/V1/Transport/AppUpdateController.php`
- Routes (public): `GET /api/app-updates/manifest`, `GET /api/app-updates/asset`

The update files themselves are **not** in git. They live on the server at:
```
storage/app/private/app-updates/android/<runtimeVersion>/
    manifest.json
    bundle.hbc
    assets/…
```

---

## One-time: make the endpoint live

On the live server, from the app root:

```bash
git pull origin master
composer install --no-dev --optimize-autoloader
php artisan route:cache
```

No migration is needed for OTA. Verify the endpoint is up:

```bash
# Expect an HTTP 200 with Content-Type: multipart/mixed
curl -s -D - -o /dev/null \
  'https://crm.nexforeconsulting.com/api/app-updates/manifest' \
  -H 'expo-platform: android' -H 'expo-runtime-version: 1.0.0'
```

Until you publish an update (next section), the endpoint correctly replies
"no update available" and phones keep running the bundle embedded in the APK.

---

## Every time you want to ship a code change

On a machine with the app checked out (`driver-app/`):

```bash
cd driver-app
npm install                       # first time only
node scripts/build-ota-update.mjs # exports + builds the update into ./ota-dist
```

That prints a folder like `driver-app/ota-dist/android/1.0.0/`. Copy its
**contents** to the live server:

```
driver-app/ota-dist/android/1.0.0/   →   storage/app/private/app-updates/android/1.0.0/
```

(For example with scp/rsync, or your normal deploy transfer. Overwrite the
previous update — each build gets a fresh update id, so phones see it as new.)

That's it. Drivers get the change the second time they open the app after you
copy the files. Nothing to re-install.

Verify a specific build went live:

```bash
curl -s 'https://crm.nexforeconsulting.com/api/app-updates/manifest' \
  -H 'expo-platform: android' -H 'expo-runtime-version: 1.0.0' | grep -o '"id":"[^"]*"'
```

---

## When you must build a NEW APK instead

OTA cannot ship native changes. Build and redistribute the APK when you:

- add or change an Android **permission**,
- add a **native module** / new Expo package with native code,
- bump the **Expo SDK** or React Native version,
- change `runtimeVersion` in `app.json` (and the matching value in
  `android/app/src/main/AndroidManifest.xml`).

`runtimeVersion` is the contract between an APK and the updates it will accept.
Keep it at `1.0.0` for pure-JavaScript changes so existing installs keep
updating. Bump it only alongside a native change **and** a new APK — and publish
the update under the new `storage/app/private/app-updates/android/<newVersion>/`
folder.
