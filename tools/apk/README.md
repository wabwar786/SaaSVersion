# Building the Android APK

The customer app is a PWA. The APK is a thin Android wrapper around it
(a Trusted Web Activity), so the app's screens and menu come from the
server — **you build the APK once**, and every later change to the app
reaches customers without a new APK.

I cannot build the APK here: it needs the Android SDK and a signing key,
neither of which exists in this environment. Everything else is prepared.
The steps below are a one-time job on any machine with Node and Java.

## One time

```bash
npm install -g @bubblewrap/cli
cd tools/apk
bubblewrap init --manifest=https://saasversion-production.up.railway.app/app-manifest.json
# it will ask a few questions; the answers are already in twa-manifest.json
bubblewrap build
```

`bubblewrap build` produces:

- `app-release-signed.apk` — give this to customers
- `android.keystore` — **keep this safe and never lose it.** Without the
  same key you cannot publish an update; Android will treat a differently
  signed APK as a different app.

## Putting it on the download page

Copy the signed APK into `public/` as `app-release.apk`:

```bash
cp app-release-signed.apk ../../public/app-release.apk
```

The download page (`/app-download.html`) picks it up on its own. Until
the file exists the page says so plainly and points customers at the web
version instead of a broken download.

## Later updates

App screens, menu and prices need **no new APK** — they are served from
the site. Rebuild only when the icon, name, or the URL the app opens has
to change. Bump `appVersionCode` before rebuilding.

## Per-business link

The APK opens `/app.html`. For a single business, set `startUrl` in
`twa-manifest.json` to `/app.html?b=<slug>` and rebuild — that build then
belongs to that one business.

For many businesses, keep one APK and hand each customer their own link
or QR from `/app-download.html?b=<slug>`.
