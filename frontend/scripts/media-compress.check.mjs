// Checks for src/lib/mediaCompress.js — run with `npm run check:media`.
//
// The frontend has no test runner, and this module sits on the request path of
// EVERY upload in the app: if it drops a FormData entry or hangs, uploads break
// everywhere at once. So its decisions are checked here against a canvas stub
// that records what it was asked to encode. The pixels are not under test; the
// decisions are — what gets touched, what format it becomes, how far it scales,
// and whether the original survives every failure path.

/* ── browser stubs ───────────────────────────────────────────────────────── */

// Node has no FileReader, and mediaCompress uses it to detect a browser.
// Without this the module short-circuits and every assertion passes vacuously.
globalThis.FileReader = class {
  readAsDataURL(blob) {
    blob.arrayBuffer().then(buf => {
      this.result = `data:${blob.type};base64,` + Buffer.from(buf).toString('base64')
      this.onload && this.onload()
    })
  }
}

const calls = []                 // every toBlob the module asked for
let canvasWorks = true           // false → getContext returns null
let alphaPixels = false          // what the alpha probe reports
let sizeFor = () => 100 * 1024   // bytes the stub "encodes" to
let bitmap = { width: 4000, height: 3000 }

globalThis.createImageBitmap = async () => ({ ...bitmap, close() {} })
globalThis.URL.createObjectURL = () => 'blob:stub'
globalThis.URL.revokeObjectURL = () => {}
globalThis.Image = class { set src(_v) { setTimeout(() => this.onerror?.(new Error('no decoder')), 0) } }

globalThis.document = {
  createElement: () => {
    const canvas = { width: 0, height: 0 }
    canvas.getContext = () => (canvasWorks ? {
      fillStyle: '', globalCompositeOperation: '',
      fillRect() {}, drawImage() {},
      getImageData: (x, y, w, h) => {
        const data = new Uint8ClampedArray(w * h * 4).fill(255)
        if (alphaPixels) data[3] = 10
        return { data }
      },
    } : null)
    canvas.toBlob = (cb, type, quality) => {
      calls.push({ type, quality, w: canvas.width, h: canvas.height })
      cb(new Blob([new Uint8Array(sizeFor(quality))], { type }))
    }
    return canvas
  },
}

const {
  compressImageFile, compressImageDataUrl, compressFormData, compressJsonMedia,
} = await import('../src/lib/mediaCompress.js')

/* ── harness ─────────────────────────────────────────────────────────────── */

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (name) => console.log(`\n── ${name}`)

const file = (name, type, bytes) => new File([new Uint8Array(bytes)], name, { type })
const bigFile = (name, type) => file(name, type, 4 * 1024 * 1024)
const reset = () => { calls.length = 0; canvasWorks = true; alphaPixels = false; sizeFor = () => 100 * 1024 }

/* ── what is left alone ──────────────────────────────────────────────────── */

group('pass-through')
reset()
const pdf = bigFile('report.pdf', 'application/pdf')
const gif = bigFile('anim.gif', 'image/gif')
const svg = bigFile('logo.svg', 'image/svg+xml')
const small = file('icon.png', 'image/png', 1024)

check('a PDF is untouched', await compressImageFile(pdf) === pdf)
check('an animated GIF is untouched', await compressImageFile(gif) === gif)
check('an SVG is untouched', await compressImageFile(svg) === svg)
check('an already-small image is untouched', await compressImageFile(small) === small)
check('nothing was encoded for any of them', calls.length === 0)

/* ── a phone photo ───────────────────────────────────────────────────────── */

group('image encode')
reset()
let out = await compressImageFile(bigFile('IMG_2201.JPEG', 'image/jpeg'))
check('a 4 MB photo comes back smaller', out.size < 4 * 1024 * 1024, `${Math.round(out.size / 1024)} KB`)
check('encoded as JPEG', out.type === 'image/jpeg')
check('extension rewritten to .jpg', out.name === 'IMG_2201.jpg', out.name)
check('longest edge capped at 1920, aspect kept', calls[0].w === 1920 && calls[0].h === 1440, `${calls[0].w}x${calls[0].h}`)
check('stops at the first quality that meets the target', calls.length === 1)

reset()
bitmap = { width: 800, height: 600 }
await compressImageFile(bigFile('small-but-heavy.jpg', 'image/jpeg'))
check('an image under the cap is not upscaled', calls[0].w === 800 && calls[0].h === 600, `${calls[0].w}x${calls[0].h}`)
bitmap = { width: 4000, height: 3000 }

/* ── PNG: transparency decides the format ────────────────────────────────── */

group('PNG handling')
reset()
out = await compressImageFile(bigFile('screenshot.png', 'image/png'))
check('an opaque PNG becomes JPEG', out.type === 'image/jpeg')
check('and is renamed .jpg', out.name === 'screenshot.jpg', out.name)

reset()
alphaPixels = true
out = await compressImageFile(bigFile('signature.png', 'image/png'))
check('a transparent PNG stays PNG', out.type === 'image/png')
check('keeps its .png name', out.name === 'signature.png', out.name)
check('and no JPEG encode was attempted', calls.every(c => c.type === 'image/png'))

/* ── the quality ladder ──────────────────────────────────────────────────── */

group('quality ladder')
reset()
sizeFor = (q) => (q >= 0.75 ? 900 * 1024 : 200 * 1024)
out = await compressImageFile(bigFile('poster.jpg', 'image/jpeg'))
check('steps down until under target', calls.length === 3, calls.map(c => c.quality).join(' -> '))
check('keeps the step that met it', out.size === 200 * 1024, `${Math.round(out.size / 1024)} KB`)

reset()
sizeFor = () => 9 * 1024 * 1024
const heavy = bigFile('already-optimised.jpg', 'image/jpeg')
check('a result bigger than the source is discarded', await compressImageFile(heavy) === heavy)

/* ── failure paths must return the original, never throw or hang ─────────── */

group('failure paths')
reset()
canvasWorks = false
const noCanvas = bigFile('photo.jpg', 'image/jpeg')
check('a canvas-less browser returns the original', await compressImageFile(noCanvas) === noCanvas)

/* ── FormData: order and repeated keys must survive the rebuild ──────────── */

group('FormData')
reset()
const photo = file('photo.JPG', 'image/jpeg', 900 * 1024)
const fd = new FormData()
fd.append('title', 'Site pack')
fd.append('documents[]', photo)
fd.append('documents[]', pdf)
fd.append('documents[]', small)
fd.append('names[]', 'a')
fd.append('names[]', 'b')

const rebuilt = await compressFormData(fd)
const before = Array.from(fd.entries())
const after = Array.from(rebuilt.entries())

check('every entry is still present', before.length === after.length, `${before.length} -> ${after.length}`)
check('keys keep their order and repeats',
  JSON.stringify(before.map(([k]) => k)) === JSON.stringify(after.map(([k]) => k)))
check('the repeated file key kept all three files',
  after.filter(([k]) => k === 'documents[]').length === 3)
check('the image entry was compressed',
  after.filter(([k]) => k === 'documents[]')[0][1].size < photo.size)
check('the PDF beside it is the same object',
  after.filter(([k]) => k === 'documents[]')[1][1] === pdf)
check('non-file values pass through', after.find(([k]) => k === 'title')[1] === 'Site pack')
check('both repeated string values survive',
  after.filter(([k]) => k === 'names[]').map(([, v]) => v).join('') === 'ab')

const noImages = new FormData()
noImages.append('file', pdf)
check('a body with no compressible image is the same object',
  await compressFormData(noImages) === noImages)

/* ── JSON bodies ─────────────────────────────────────────────────────────── */

group('JSON bodies')
reset()
const bigDataUrl = 'data:image/png;base64,' + 'A'.repeat(400 * 1024)
const walked = await compressJsonMedia({
  fitness_status: 'Fit',
  signature_data: bigDataUrl,
  nested: { capture_photo: bigDataUrl, note: 'not media' },
  list: ['plain', bigDataUrl],
  when: new Date('2026-01-01'),
})

check('scalar fields are preserved', walked.fitness_status === 'Fit')
check('a top-level data URL is compressed', walked.signature_data.length < bigDataUrl.length,
  `${Math.round(bigDataUrl.length / 1024)} KB -> ${Math.round(walked.signature_data.length / 1024)} KB`)
check('nested objects are reached', walked.nested.capture_photo.length < bigDataUrl.length)
check('non-media strings are left alone', walked.nested.note === 'not media')
check('arrays are preserved in order', walked.list[0] === 'plain' && walked.list.length === 2)
check('a Date is not mangled into a plain object', walked.when instanceof Date)

const smallDataUrl = 'data:image/png;base64,' + 'A'.repeat(64)
check('a small data URL is left alone', await compressImageDataUrl(smallDataUrl) === smallDataUrl)
check('an SVG data URL is left alone',
  await compressImageDataUrl('data:image/svg+xml;base64,' + 'A'.repeat(400 * 1024))
    .then(r => r.startsWith('data:image/svg+xml')))

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)
