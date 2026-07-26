import { useState } from 'react'
import { X, Printer, MapPin } from 'lucide-react'
import { INV_ACCENT } from '@/services/inventoryApi'
import QrCode from './QrCode'

/**
 * Printable labels for places rather than things — bins, shelves, racks, zones
 * and pallets.
 *
 * These are QR, not Code 128, and that is a deliberate difference from the item
 * labels: a shelf tag is read from a metre away, at an angle, by someone holding
 * a box in the other hand. QR reads in any orientation and survives a scuffed
 * corner; a linear barcode needs to be square-on and intact.
 *
 * The payload is the location's own code, which is exactly what ScanService
 * looks up — so scanning a shelf tells the app "you are standing here" with no
 * separate encoding scheme to keep in sync.
 */

const SIZES = {
  small:  { label: 'Small (shelf edge)', min: 150, qr: 64,  code: 12, name: 10 },
  medium: { label: 'Medium (bin front)', min: 220, qr: 96,  code: 16, name: 12 },
  large:  { label: 'Large (pallet)',     min: 320, qr: 150, code: 24, name: 15 },
}

export default function LocationLabelSheet({ locations = [], warehouseName = '', onClose }) {
  const [copies, setCopies] = useState(1)
  const [size, setSize] = useState('medium')
  const s = SIZES[size]

  const labels = locations.flatMap(l => Array.from({ length: Math.max(1, Number(copies) || 1) }, () => l))

  return (
    <div className="fixed inset-0 z-[80] flex items-start justify-center p-4 overflow-y-auto"
      style={{ background: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #loc-sheet, #loc-sheet * { visibility: visible; }
          #loc-sheet { position: absolute; left: 0; top: 0; width: 100%; padding: 0; }
          .loc-noprint { display: none !important; }
          .loc-label { break-inside: avoid; page-break-inside: avoid; }
        }
      `}</style>

      <div className="w-full max-w-[860px] rounded-2xl mt-[4vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>

        <div className="loc-noprint flex flex-wrap items-center gap-3 p-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <MapPin size={15} style={{ color: INV_ACCENT }} />
          <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
            Print location labels · {locations.length}
          </h2>

          <label className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
            Copies each
            <input type="number" min={1} max={50} value={copies} onChange={e => setCopies(e.target.value)}
              className="rounded-lg outline-none"
              style={{ width: 58, padding: '5px 8px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </label>

          <div className="flex items-center gap-1 text-[11px] rounded-lg p-0.5" style={{ background: 'var(--bg-input)' }}>
            {Object.entries(SIZES).map(([k, v]) => (
              <button key={k} onClick={() => setSize(k)} title={v.label}
                className="px-2 py-1 rounded-md font-bold"
                style={{ background: size === k ? INV_ACCENT : 'transparent', color: size === k ? '#fff' : 'var(--text-muted)' }}>
                {v.label.split(' ')[0]}
              </button>
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

        <div id="loc-sheet" className="p-4">
          <div className="grid gap-2.5" style={{ gridTemplateColumns: `repeat(auto-fill,minmax(${s.min}px,1fr))` }}>
            {labels.map((l, i) => (
              <div key={`${l.id}-${i}`} className="loc-label rounded-lg p-3 text-center"
                style={{ border: '2px solid #0f172a', background: '#fff' }}>
                {/* The code is the biggest thing on the label. Someone walking an
                    aisle reads it with their eyes long before a scanner is out. */}
                <p style={{ fontSize: s.code, fontWeight: 900, fontFamily: 'monospace', letterSpacing: '0.06em', color: '#0f172a', margin: 0 }}>
                  {l.code || l.name}
                </p>
                <p style={{ fontSize: s.name, color: '#334155', margin: '2px 0 0', lineHeight: 1.2 }}>{l.name}</p>
                {(warehouseName || l.warehouse?.name) && (
                  <p style={{ fontSize: s.name - 1, color: '#94a3b8', margin: '1px 0 0' }}>
                    {l.warehouse?.name || warehouseName}
                  </p>
                )}
                <div style={{ margin: '6px 0 0', display: 'flex', justifyContent: 'center' }}>
                  <QrCode value={l.code || l.name} size={s.qr} />
                </div>
              </div>
            ))}
          </div>

          {labels.length === 0 && (
            <p className="text-xs py-8 text-center" style={{ color: 'var(--text-muted)' }}>
              No locations to print. Add bins or shelves to a warehouse first.
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
