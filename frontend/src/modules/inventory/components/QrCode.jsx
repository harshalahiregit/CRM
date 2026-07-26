import qrcode from 'qrcode-generator'

/**
 * A QR code drawn as plain SVG.
 *
 * `qrcode-generator` is the reference JS implementation and has zero
 * dependencies — the alternative, `qrcode`, drags in yargs and pngjs, which are
 * CLI baggage in a browser bundle. It gives us the module matrix; the SVG is
 * built here so the result prints crisply at any size and matches how Code 128
 * is already drawn next door in BarcodeSheet.
 *
 * Error-correction level M (~15% recoverable) is the right default for a
 * warehouse: labels get scuffed, taped over and rained on, and the extra
 * density over L costs nothing at label sizes.
 *
 * Nothing is uploaded and no image service is called, so labels print
 * identically offline.
 */
export default function QrCode({ value, size = 96, level = 'M', quiet = 2 }) {
  const text = String(value ?? '').trim()
  if (!text) return null

  let qr
  try {
    // Type 0 = auto-size to the smallest version that fits the data.
    qr = qrcode(0, level)
    qr.addData(text)
    qr.make()
  } catch {
    // Only happens if the payload exceeds even version 40 — a bin code never
    // will, but a silent blank beats a crashed print sheet.
    return null
  }

  const count = qr.getModuleCount()
  const span = count + quiet * 2

  // One <rect> per dark module. At label sizes this is a few hundred nodes —
  // cheaper and sharper than a canvas that has to be re-rasterised for print.
  const rects = []
  for (let r = 0; r < count; r++) {
    for (let c = 0; c < count; c++) {
      if (qr.isDark(r, c)) {
        rects.push(<rect key={`${r}-${c}`} x={c + quiet} y={r + quiet} width={1} height={1} fill="#000" />)
      }
    }
  }

  return (
    <svg viewBox={`0 0 ${span} ${span}`} width={size} height={size} shapeRendering="crispEdges"
      role="img" aria-label={`QR code for ${text}`}>
      {/* The quiet zone must be white, not transparent — scanners need the
          contrast, and a transparent margin picks up whatever is behind it. */}
      <rect x={0} y={0} width={span} height={span} fill="#fff" />
      {rects}
    </svg>
  )
}
