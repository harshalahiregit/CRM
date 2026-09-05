// Shrink uploaded media before it ever leaves the browser.
//
// Every upload in the CRM — vendor documents, worker photos, medical reports,
// ID proofs, attachments, avatars — went up at whatever size the camera or
// scanner produced. A phone photo is 3–8 MB, so the server received, validated,
// hashed and stored megabytes for something that is read at a few hundred
// pixels wide. That is bandwidth on the way in, disk forever after, and a slow
// page every time it is read back.
//
// Rather than edit ~50 upload sites (and miss the next one), this hooks the
// axios request interceptor: any image inside a FormData body, and any image
// data URL inside a JSON body, is downscaled and re-encoded on the way out.
// Every call site is covered, including ones written later, and none of them
// had to change.
//
// Deliberate limits:
//   • Images only. PDFs, Office files and archives cannot be re-encoded in a
//     browser without shipping a large library, so they pass through untouched.
//     There are no video uploads anywhere in the project.
//   • GIF and SVG pass through — rasterizing them would kill the animation or
//     throw away the vector.
//   • JPEG is the output format. Almost every backend rule here is
//     `mimes:pdf,jpg,jpeg,png`, so JPEG is accepted everywhere an image is;
//     WebP would be smaller but is allowed by exactly one rule and would start
//     failing validation.
//   • A PNG carrying transparency stays PNG (flattening it onto white would
//     wreck a signature or a logo) — it is still downscaled.
//
// Nothing here is allowed to break an upload: every step is wrapped, and any
// failure returns the ORIGINAL file untouched.

/** Longest edge kept. 1920 keeps a scanned document's text readable. */
const MAX_DIM = 1920

/** Stop compressing once under this. */
const TARGET_BYTES = 400 * 1024

/** Files already this small are not worth touching. */
const SKIP_UNDER_BYTES = 300 * 1024

/** Quality ladder — walked down only while the result is still over target. */
const QUALITY_STEPS = [0.85, 0.75, 0.65, 0.55]

/** A decode that neither loads nor errors must not hold the request open. */
const DECODE_TIMEOUT_MS = 15000

/** Formats that must not be rasterized. */
const PASS_THROUGH = ['image/gif', 'image/svg+xml']

const isBrowser = () => typeof document !== 'undefined' && typeof FileReader !== 'undefined'

const isCompressibleImage = (type) =>
  typeof type === 'string' && type.startsWith('image/') && !PASS_THROUGH.includes(type)

/* ── decoding ──────────────────────────────────────────────────────────────
 *
 * createImageBitmap with imageOrientation:'from-image' is used first because
 * canvas drops EXIF: a phone photo taken in portrait (Orientation 6) would come
 * back rotated on its side once re-encoded. The <img> fallback is for browsers
 * without that option — modern ones apply EXIF to <img> themselves.
 */
async function decode(blob) {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(blob, { imageOrientation: 'from-image' })
    } catch {
      // Older Safari rejects the options object rather than ignoring it.
      try { return await createImageBitmap(blob) } catch { /* fall through */ }
    }
  }

  const url = URL.createObjectURL(blob)
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image()
      // A decode that never fires load OR error would leave this promise
      // pending forever, and the request is waiting on it — the upload would
      // simply never happen. Time out into the caller's catch instead, which
      // sends the original file.
      const timer = setTimeout(() => reject(new Error('image decode timed out')), DECODE_TIMEOUT_MS)
      const settle = (fn) => (arg) => { clearTimeout(timer); fn(arg) }
      img.onload = settle(() => resolve(img))
      img.onerror = settle(reject)
      img.src = url
    })
  } finally {
    URL.revokeObjectURL(url)
  }
}

const toBlob = (canvas, type, quality) =>
  new Promise((resolve) => {
    try {
      canvas.toBlob(b => resolve(b), type, quality)
    } catch {
      resolve(null)
    }
  })

/**
 * Does this PNG actually use transparency?
 *
 * Most PNGs in a CRM are screenshots and scans with a solid background — those
 * become far smaller as JPEG. Only the ones that really are transparent (drawn
 * signatures, logos) need to stay PNG, so this checks rather than assumes.
 * Sampled on a stride: a single translucent pixel is enough to disqualify, and
 * scanning every pixel of a 1920px image to learn that is wasted work.
 */
function hasAlpha(ctx, width, height) {
  try {
    const { data } = ctx.getImageData(0, 0, width, height)
    for (let i = 3; i < data.length; i += 4 * 16) {
      if (data[i] < 255) return true
    }
    return false
  } catch {
    return true // tainted or unreadable — assume transparency and keep PNG
  }
}

/**
 * The compressed bytes for one image blob, or null to keep the original.
 *
 * @returns {Promise<{blob: Blob, type: string}|null>}
 */
async function shrink(blob, { maxDim = MAX_DIM, qualities = QUALITY_STEPS } = {}) {
  const source = await decode(blob)
  const srcW = source.width
  const srcH = source.height
  if (!srcW || !srcH) return null

  const scale = Math.min(1, maxDim / Math.max(srcW, srcH))
  const width = Math.max(1, Math.round(srcW * scale))
  const height = Math.max(1, Math.round(srcH * scale))

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) return null

  const keepPng = blob.type === 'image/png'
  // JPEG has no alpha channel: without a painted background the transparent
  // areas encode as black. Harmless for the PNG path, essential for the other.
  if (!keepPng) {
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, width, height)
  }
  ctx.drawImage(source, 0, 0, width, height)
  if (typeof source.close === 'function') source.close()

  if (keepPng && hasAlpha(ctx, width, height)) {
    const png = await toBlob(canvas, 'image/png')
    return png && png.size < blob.size ? { blob: png, type: 'image/png' } : null
  }

  // A PNG without transparency is re-encoded as JPEG — usually the single
  // biggest saving available, since a screenshot PNG is often 10x its JPEG.
  if (keepPng) {
    ctx.globalCompositeOperation = 'destination-over'
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, width, height)
    ctx.globalCompositeOperation = 'source-over'
  }

  let best = null
  for (const quality of qualities) {
    const out = await toBlob(canvas, 'image/jpeg', quality)
    if (!out) break
    best = out
    if (out.size <= TARGET_BYTES) break
  }

  return best && best.size < blob.size ? { blob: best, type: 'image/jpeg' } : null
}

/** Swap the extension so Laravel's `mimes:` rule sees the format we sent. */
const renameFor = (name, type) => {
  const base = String(name || 'upload').replace(/\.[^./\\]+$/, '')
  return `${base}.${type === 'image/png' ? 'png' : 'jpg'}`
}

/**
 * Compress one File. Returns the original when it is not a compressible image,
 * is already small, or when anything at all goes wrong.
 */
export async function compressImageFile(file) {
  if (!isBrowser() || !(file instanceof File) && !(file instanceof Blob)) return file
  if (!isCompressibleImage(file.type)) return file
  if (file.size <= SKIP_UNDER_BYTES) return file

  try {
    const result = await shrink(file)
    if (!result) return file

    return new File([result.blob], renameFor(file.name, result.type), {
      type: result.type,
      lastModified: file.lastModified || Date.now(),
    })
  } catch {
    return file
  }
}

/* ── data URLs ─────────────────────────────────────────────────────────────
 *
 * Signature pads, the medical scene photo and the contract signature image are
 * sent as base64 inside a JSON body rather than as files. Base64 is ~33% larger
 * than the bytes it carries, and one endpoint caps the string at 1,400,000
 * characters — a phone photo blows straight through that and 422s. Same
 * treatment, applied to the string.
 */

const DATA_URL = /^data:(image\/[a-zA-Z0-9.+-]+);base64,/

const dataUrlToBlob = (dataUrl) => {
  const [meta, b64] = dataUrl.split(',')
  const type = meta.match(/data:([^;]+)/)?.[1] || 'image/png'
  const bin = atob(b64)
  const bytes = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
  return new Blob([bytes], { type })
}

const blobToDataUrl = (blob) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })

export async function compressImageDataUrl(dataUrl, { skipUnder = SKIP_UNDER_BYTES, ...shrinkOpts } = {}) {
  if (!isBrowser() || typeof dataUrl !== 'string') return dataUrl

  const match = dataUrl.match(DATA_URL)
  if (!match || PASS_THROUGH.includes(match[1])) return dataUrl
  // Base64 characters, not bytes — the string length is what the column and the
  // `max:` rule actually measure. Callers that embed the result in a stored
  // document (the rich-text editor) pass skipUnder: 0, because there every
  // kilobyte lands in a database row rather than a file.
  if (dataUrl.length <= skipUnder) return dataUrl

  try {
    const result = await shrink(dataUrlToBlob(dataUrl), shrinkOpts)
    if (!result) return dataUrl

    const out = await blobToDataUrl(result.blob)
    return typeof out === 'string' && out.length < dataUrl.length ? out : dataUrl
  } catch {
    return dataUrl
  }
}

/* ── request bodies ───────────────────────────────────────────────────────── */

/**
 * A copy of the FormData with every image entry compressed.
 *
 * Rebuilt rather than mutated: FormData.set() collapses repeated keys, and the
 * multi-file uploads here post several entries under the same `documents[]`
 * name. Appending into a fresh one preserves both the order and the repeats.
 */
export async function compressFormData(formData) {
  if (!isBrowser() || typeof FormData === 'undefined' || !(formData instanceof FormData)) return formData

  const entries = Array.from(formData.entries())
  if (!entries.some(([, v]) => v instanceof File && isCompressibleImage(v.type) && v.size > SKIP_UNDER_BYTES)) {
    return formData
  }

  const next = new FormData()
  for (const [key, value] of entries) {
    next.append(key, value instanceof File ? await compressImageFile(value) : value)
  }
  return next
}

/**
 * Walk a JSON body replacing oversized image data URLs.
 *
 * Depth-limited and array/plain-object only, so it cannot wander into a File,
 * a Blob or a circular structure that happens to be on the payload.
 */
export async function compressJsonMedia(body, depth = 0) {
  if (depth > 6 || !body || typeof body !== 'object') return body
  if (body instanceof File || body instanceof Blob || body instanceof Date) return body

  if (Array.isArray(body)) {
    return Promise.all(body.map(v => (
      typeof v === 'string' ? compressImageDataUrl(v) : compressJsonMedia(v, depth + 1)
    )))
  }

  if (Object.getPrototypeOf(body) !== Object.prototype && Object.getPrototypeOf(body) !== null) {
    return body
  }

  const out = {}
  for (const [key, value] of Object.entries(body)) {
    out[key] = typeof value === 'string'
      ? await compressImageDataUrl(value)
      : await compressJsonMedia(value, depth + 1)
  }
  return out
}

/**
 * Wire compression into an axios instance.
 *
 * Registered on every client in the app (see src/lib/api.js and the per-portal
 * instances), so an upload is shrunk no matter which one carries it.
 */
export function attachMediaCompression(instance) {
  instance.interceptors.request.use(async (config) => {
    try {
      if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
        config.data = await compressFormData(config.data)
      } else if (config.data && typeof config.data === 'object') {
        config.data = await compressJsonMedia(config.data)
      }
    } catch {
      // An upload must never fail because the optimisation did.
    }
    return config
  })
  return instance
}
