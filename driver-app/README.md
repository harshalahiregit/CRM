# Sangoé Driver — the driver's phone app (MVP)

A React Native / Expo app. It is a **thin client** on the same STOS backend the
web app uses — same API, same database — so anything filed here appears in the
office instantly.

## What the MVP does (step 1)

- **Sign in** (server address + email + password)
- **My trips** — the list of trips (open trips for now; a driver-scoped list is
  Dev 1's to add — see below)
- **Trip detail** — the journey so far, and
- **Capture POD** — photograph the signed delivery sheet; it uploads to the
  trip and the office verifies it

## Run it on your phone (Expo Go — no APK needed yet)

1. Install **Expo Go** from the Play Store on the phone.
2. Phone and this computer on the **same Wi-Fi**.
3. On the computer, in this folder: `npx expo start --lan`
4. Scan the QR code with Expo Go.
5. In the app's **Server address**, enter this computer's address:
   `http://<computer-LAN-IP>:8000` (e.g. `http://192.168.29.60:8000`).

### The backend must be reachable from the phone

The dev backend usually binds to `127.0.0.1` (this computer only). For the phone
to reach it, run it bound to all interfaces:

```
php artisan serve --host=0.0.0.0 --port=8000
```

Then the phone uses `http://<computer-LAN-IP>:8000`. Make sure Windows Firewall
allows port 8000 on the private network.

## Build the installable APK (later)

The Android SDK is already on this machine, so a local build works:

```
npx expo prebuild --platform android
cd android && ./gradlew assembleRelease
```

The APK lands in `android/app/build/outputs/apk/release/`. (Expo's EAS cloud
build is the alternative, but not needed since the SDK is here.)

## What still needs Dev 1 (Ops)

The trip-progress actions — **departed / arrived / delivered, tapped by the
driver** — and a **driver-scoped trip list** are Ops endpoints that do not exist
yet. See `docs-driver-app-contract.md`. Until they land, the journey strip is
read-only and the list shows open trips. Wiring them is a URL change here, not a
rewrite — the screens are already built for it.
