// Downscale + recompress an image File before it goes into rich-text content.
//
// Rich editors embed pasted/selected images as base64 inside the HTML. A phone
// photo is 3–8 MB, so a couple of images bloat the stored HTML (and the DB row)
// into megabytes and make pages crawl. Shrinking to a sane max dimension and
// re-encoding as JPEG turns that into ~100–300 KB with no visible quality loss
// and — crucially — no server upload pipeline to build/secure.
//
// PNGs are kept as PNG (they may carry transparency); GIF/SVG pass through
// untouched (animation / vector shouldn't be rasterized). If the "compressed"
// result somehow ends up larger, the original is kept.

export async function compressImage(file, { maxDim = 1600, quality = 0.82 } = {}) {
  if (!file || !file.type || !file.type.startsWith('image/')) return null

  const original = await fileToDataUrl(file)

  // Don't rasterize animated GIFs or vector SVGs.
  if (file.type === 'image/gif' || file.type === 'image/svg+xml') return original

  let img
  try {
    img = await loadImage(original)
  } catch {
    return original // unreadable by the browser — keep as-is rather than lose it
  }

  const scale = Math.min(1, maxDim / Math.max(img.width, img.height))
  const width = Math.max(1, Math.round(img.width * scale))
  const height = Math.max(1, Math.round(img.height * scale))

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) return original
  ctx.drawImage(img, 0, 0, width, height)

  const isPng = file.type === 'image/png'
  let out
  try {
    out = isPng ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', quality)
  } catch {
    return original
  }

  return out.length < original.length ? out : original
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(file)
  })
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = reject
    img.src = src
  })
}
