// Build one over-the-air update for the Sangoé Driver app.
//
// What it does:
//   1. Exports the current JavaScript with `expo export` (Android).
//   2. Lays the bundle + assets out the way our self-hosted update server reads,
//      and writes a manifest.json (Expo Updates protocol) with real file hashes.
//
// The output folder is then copied to the live server under
//   storage/app/private/app-updates/android/<runtimeVersion>/
// and the phones pick it up on next launch. No APK re-install.
//
// Usage:
//   node scripts/build-ota-update.mjs [outputDir]
// Default outputDir: ./ota-dist/android/<runtimeVersion>

import { execSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import os from 'node:os'

const RUNTIME_VERSION = '1.0.0' // must match app.json runtimeVersion + the APK

const CONTENT_TYPES = {
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
  '.webp': 'image/webp', '.svg': 'image/svg+xml', '.bmp': 'image/bmp',
  '.ttf': 'font/ttf', '.otf': 'font/otf', '.woff': 'font/woff', '.woff2': 'font/woff2',
  '.json': 'application/json', '.mp4': 'video/mp4', '.mp3': 'audio/mpeg',
}

const appRoot = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..')
const outArg = process.argv[2]
const outDir = outArg
  ? path.resolve(outArg)
  : path.join(appRoot, 'ota-dist', 'android', RUNTIME_VERSION)

const base64url = (buf) => createHash('sha256').update(buf).digest('base64')
  .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
const hexHash = (buf) => createHash('sha256').update(buf).digest('hex')

function run() {
  const exportDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sangoe-ota-'))
  console.log('› Exporting JavaScript with expo export …')
  execSync(`npx expo export --platform android --output-dir "${exportDir}"`, {
    cwd: appRoot, stdio: 'inherit',
  })

  const metadata = JSON.parse(fs.readFileSync(path.join(exportDir, 'metadata.json'), 'utf8'))
  const android = metadata.fileMetadata?.android
  if (!android?.bundle) throw new Error('metadata.json has no android bundle — did the export fail?')

  // Clean output.
  fs.rmSync(outDir, { recursive: true, force: true })
  fs.mkdirSync(path.join(outDir, 'assets'), { recursive: true })

  // The JS bundle → bundle.hbc
  const bundleBuf = fs.readFileSync(path.join(exportDir, android.bundle))
  fs.writeFileSync(path.join(outDir, 'bundle.hbc'), bundleBuf)
  const launchAsset = {
    hash: base64url(bundleBuf),
    key: hexHash(bundleBuf),
    contentType: 'application/javascript',
    file: 'bundle.hbc',
  }

  // Each bundled asset → assets/<sha256hex>
  const assets = (android.assets || []).map((a) => {
    const buf = fs.readFileSync(path.join(exportDir, a.path))
    const key = hexHash(buf)
    const ext = a.ext ? (a.ext.startsWith('.') ? a.ext : '.' + a.ext) : ''
    const file = `assets/${key}`
    fs.writeFileSync(path.join(outDir, file), buf)
    return {
      hash: base64url(buf),
      key,
      contentType: CONTENT_TYPES[ext.toLowerCase()] || 'application/octet-stream',
      fileExtension: ext,
      file,
    }
  })

  const manifest = {
    id: randomUUID(),
    createdAt: new Date().toISOString(),
    runtimeVersion: RUNTIME_VERSION,
    launchAsset,
    assets,
    metadata: {},
    extra: {},
  }
  fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2))
  fs.rmSync(exportDir, { recursive: true, force: true })

  console.log('\n✓ Update built for runtime ' + RUNTIME_VERSION)
  console.log('  Update id : ' + manifest.id)
  console.log('  Assets    : ' + assets.length)
  console.log('  Output    : ' + outDir)
  console.log('\nNext: copy that folder to the live server at')
  console.log('  storage/app/private/app-updates/android/' + RUNTIME_VERSION + '/')
}

run()
