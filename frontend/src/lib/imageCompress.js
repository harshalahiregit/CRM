// Downscale + recompress an image File before it goes into rich-text content.
//
// Rich editors embed pasted/selected images as base64 inside the HTML. A phone
// photo is 3–8 MB, so a couple of images bloat the stored HTML (and the DB row)
// into megabytes and make pages crawl.
//
// The actual compression now lives in mediaCompress.js, which is the one policy
// every upload in the app goes through. This kept its own copy before, so the
// two drifted: this one always re-encoded PNG as PNG (a screenshot stayed ten
// times bigger than it needed to be), had no size target to aim at, and dropped
// EXIF orientation, which laid portrait phone photos on their side.
//
// The one difference that is deliberate: skipUnder is 0 here. Everywhere else a
// small file is left alone because it is written to disk; here it is written
// into a database row, so every kilobyte is worth taking.

import { compressImageDataUrl } from './mediaCompress'

export async function compressImage(file, { maxDim = 1600 } = {}) {
  if (!file || !file.type || !file.type.startsWith('image/')) return null

  const original = await fileToDataUrl(file)
  if (typeof original !== 'string') return null

  // Never let an editor paste fail because the optimisation did.
  try {
    return await compressImageDataUrl(original, { skipUnder: 0, maxDim })
  } catch {
    return original
  }
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(file)
  })
}
