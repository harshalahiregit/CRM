import { useState } from 'react'
import { X, Printer } from 'lucide-react'
import { INV_ACCENT, money } from '@/services/inventoryApi'
import QrCode from './QrCode'

/**
 * Printable barcode labels (blueprint §1 "Print barcode").
 *
 * The barcode itself is drawn as Code 128 bars in plain SVG — no barcode
 * library and no external image service, so the sheet prints identically
 * offline and nothing leaves the browser. Labels are laid out on a grid sized
 * for standard sticker sheets and the print stylesheet hides everything else.
 */

/* ── Code 128 encoding ──────────────────────────────────────────── */

// Bar/space widths for values 0–106 of Code 128. Index = code value; each entry
// is six digits: bar, space, bar, space, bar, space widths in modules.
const C128 = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '233111',
]
const STOP = '2331112'

/**
 * Encode as Code 128 Set B (all printable ASCII), returning the module-width
 * runs. Checksum is the weighted sum mod 103, as the symbology requires — a
 * barcode without it scans as garbage on real hardware.
 */
function code128(value) {
  const text = String(value || '').replace(/[^\x20-\x7E]/g, '')
  if (!text) return null

  const codes = [104]                       // START B
  let sum = 104
  for (let i = 0; i < text.length; i++) {
    const v = text.charCodeAt(i) - 32
    codes.push(v)
    sum += v * (i + 1)
  }
  codes.push(sum % 103)                     // checksum

  return codes.map(c => C128[c]).join('') + STOP
}

function Barcode({ value, height = 44 }) {
  const runs = code128(value)
  if (!runs) return null

  const widths = runs.split('').map(Number)
  const total = widths.reduce((a, b) => a + b, 0)

  let x = 0
  const bars = []
  widths.forEach((w, i) => {
    if (i % 2 === 0) bars.push(<rect key={i} x={x} y={0} width={w} height={height} fill="#000" />)
    x += w
  })

  return (
    <svg viewBox={`0 0 ${total} ${height}`} width="100%" height={height} preserveAspectRatio="none" shapeRendering="crispEdges">
      {bars}
    </svg>
  )
}

/* ── The sheet ──────────────────────────────────────────────────── */

export default function BarcodeSheet({ products = [], onClose }) {
  const [copies, setCopies] = useState(1)
  const [showPrice, setShowPrice] = useState(true)
  const [symbology, setSymbology] = useState('barcode')

  // One entry per copy, so "3 copies of 4 items" prints 12 labels.
  const labels = products.flatMap(p => Array.from({ length: Math.max(1, Number(copies) || 1) }, () => p))

  return (
    <div className="fixed inset-0 z-[80] flex items-start justify-center p-4 overflow-y-auto" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #bc-sheet, #bc-sheet * { visibility: visible; }
          #bc-sheet { position: absolute; left: 0; top: 0; width: 100%; padding: 0; }
          .bc-noprint { display: none !important; }
          .bc-label { break-inside: avoid; page-break-inside: avoid; }
        }
      `}</style>

      <div className="w-full max-w-[820px] rounded-2xl mt-[4vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>

        <div className="bc-noprint flex flex-wrap items-center gap-3 p-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
            Print barcode · {products.length} item{products.length === 1 ? '' : 's'}
          </h2>

          <label className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
            Copies each
            <input type="number" min={1} max={50} value={copies} onChange={e => setCopies(e.target.value)}
              className="rounded-lg outline-none" style={{ width: 58, padding: '5px 8px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </label>

          <label className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={showPrice} onChange={e => setShowPrice(e.target.checked)} style={{ accentColor: INV_ACCENT }} />
            Show price
          </label>

          {/* Both symbologies encode the same string, so a label printed either
              way resolves identically at the scanner. Code 128 is narrower on a
              shelf edge; QR survives being scuffed and reads at an angle. */}
          <div className="flex items-center gap-1 text-[11px] rounded-lg p-0.5" style={{ background: 'var(--bg-input)' }}>
            {[['barcode', 'Barcode'], ['qr', 'QR']].map(([v, label]) => (
              <button key={v} onClick={() => setSymbology(v)}
                className="px-2 py-1 rounded-md font-bold"
                style={{
                  background: symbology === v ? INV_ACCENT : 'transparent',
                  color: symbology === v ? '#fff' : 'var(--text-muted)',
                }}>{label}</button>
            ))}
          </div>

          <div className="ml-auto flex items-center gap-2">
            <button onClick={() => window.print()}
              className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold"
              style={{ background: INV_ACCENT, color: '#fff' }}>
              <Printer size={13} /> Print
            </button>
            <button onClick={onClose} aria-label="Close" className="hover:opacity-70">
              <X size={18} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>
        </div>

        <div id="bc-sheet" className="p-4">
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(190px,1fr))' }}>
            {labels.map((p, i) => (
              <div key={`${p.id}-${i}`} className="bc-label rounded-lg p-2.5 text-center"
                style={{ border: '1px solid #cbd5e1', background: '#fff' }}>
                <p style={{ fontSize: 11, fontWeight: 700, color: '#0f172a', margin: 0, lineHeight: 1.25 }}>{p.name}</p>
                {showPrice && p.sale_price != null && (
                  <p style={{ fontSize: 11, fontWeight: 700, color: '#0f172a', margin: '2px 0 0' }}>{money(p.sale_price)}</p>
                )}
                <div style={{ margin: '5px 0 2px', display: 'flex', justifyContent: 'center' }}>
                  {symbology === 'qr'
                    ? <QrCode value={p.barcode || p.sku} size={78} />
                    : <Barcode value={p.barcode || p.sku} />}
                </div>
                <p style={{ fontSize: 9, fontFamily: 'monospace', letterSpacing: '0.08em', color: '#0f172a', margin: 0 }}>
                  {p.barcode || p.sku}
                </p>
              </div>
            ))}
          </div>

          {labels.length === 0 && (
            <p className="text-xs py-8 text-center" style={{ color: 'var(--text-muted)' }}>Nothing selected to print.</p>
          )}
        </div>
      </div>
    </div>
  )
}
