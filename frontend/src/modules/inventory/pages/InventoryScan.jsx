import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  ScanLine, Package, Layers3, MapPin, FileText, Warehouse, AlertTriangle,
  History, ArrowRight, Trash2,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty } from '@/services/inventoryApi'
import ScanInput from '../components/ScanInput'

/**
 * The scan workbench.
 *
 * One field, anything you point at it. The server works out whether a code is a
 * product, a serial, a batch, a bin or a document (ScanService) — asking the
 * person to pick a mode first would defeat the point of holding a scanner.
 *
 * Results stack newest-first rather than replacing each other: someone counting
 * a shelf scans twenty things in a row and needs to see that the last twenty
 * registered, not just the last one.
 */

const KIND = {
  product:   { icon: Package,   color: INV_ACCENT, label: 'Item' },
  serial:    { icon: Layers3,   color: '#8b5cf6',  label: 'Serial' },
  batch:     { icon: Layers3,   color: '#f59e0b',  label: 'Batch' },
  location:  { icon: MapPin,    color: '#0ea5e9',  label: 'Bin' },
  warehouse: { icon: Warehouse, color: '#0ea5e9',  label: 'Warehouse' },
  voucher:   { icon: FileText,  color: '#3b82f6',  label: 'Document' },
}

export default function InventoryScan() {
  const navigate = useNavigate()
  const [log, setLog] = useState([])

  const scan = useMutation({
    mutationFn: (code) => inventoryApi.scan(code),
    // Keyed by time-of-arrival index rather than the code, because scanning the
    // same box twice is a real thing people do and both scans should show.
    onSuccess: (res) => setLog(l => [{ ...res, at: new Date(), key: Math.random() }, ...l].slice(0, 40)),
    onError: (e) => setLog(l => [{ found: false, error: e?.message, at: new Date(), key: Math.random() }, ...l].slice(0, 40)),
  })

  return (
    <div>
      <header className="flex items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <ScanLine size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Scan</h1>
        {log.length > 0 && (
          <button onClick={() => setLog([])} className="ml-auto flex items-center gap-1 text-[11px] font-bold"
            style={{ color: 'var(--text-muted)' }}>
            <Trash2 size={12} /> Clear
          </button>
        )}
      </header>
      <p className="text-xs mb-4 ml-10" style={{ color: 'var(--text-muted)' }}>
        Item barcode, serial sticker, batch label, bin tag or a printed docket — scan any of them.
      </p>

      <div className="rounded-2xl p-4 mb-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <ScanInput onScan={(code) => scan.mutate(code)} busy={scan.isPending} />
      </div>

      {log.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <ScanLine size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 10px' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Ready.</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Scans appear here newest first, so you can see the whole run rather than just the last one.
          </p>
        </div>
      )}

      <div className="flex flex-col gap-2">
        {log.map(r => <ScanResult key={r.key} r={r} navigate={navigate} />)}
      </div>
    </div>
  )
}

function ScanResult({ r, navigate }) {
  const time = r.at.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

  if (!r.found) {
    return (
      <div className="rounded-xl px-4 py-3 flex items-center gap-2"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--color-danger-500)' }}>
        <AlertTriangle size={14} style={{ color: 'var(--color-danger-500)' }} />
        <span className="text-xs" style={{ color: 'var(--text-h)' }}>
          {r.error || <>Nothing matches <span className="font-mono font-bold">{r.code}</span></>}
        </span>
        <span className="ml-auto text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{time}</span>
      </div>
    )
  }

  const cfg = KIND[r.kind] || { icon: Package, color: 'var(--text-muted)', label: r.kind }
  const Icon = cfg.icon
  const goTo = r.kind === 'product' ? `/app/inventory/products/${r.product?.id}`
    : r.kind === 'serial' || r.kind === 'batch' ? '/app/inventory/traceability'
    : r.kind === 'voucher' ? `/app/inventory/vouchers/${r.voucher?.type}`
    : r.kind === 'location' || r.kind === 'warehouse' ? '/app/inventory/warehouses'
    : null

  return (
    <div className="rounded-xl p-3.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-start gap-2.5">
        <span className="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${cfg.color} 14%, transparent)` }}>
          <Icon size={14} style={{ color: cfg.color }} />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-[9px] font-black uppercase tracking-wide px-1.5 py-0.5 rounded"
              style={{ background: `color-mix(in srgb, ${cfg.color} 14%, transparent)`, color: cfg.color }}>{cfg.label}</span>
            <span className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{r.label}</span>
            <span className="ml-auto text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{time}</span>
          </div>

          {r.note && (
            <p className="text-[11px] mt-0.5"
              style={{ color: String(r.note).startsWith('EXPIRED') ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
              {r.note}
            </p>
          )}

          {/* An item scan is nearly always a prelude to moving it, so where it
              currently sits comes back with the scan instead of being a second
              lookup the person does by hand. */}
          {r.kind === 'product' && r.levels?.length > 0 && (
            <div className="flex flex-wrap gap-1.5 mt-2">
              {r.levels.map(l => (
                <span key={l.id} className="text-[10px] px-2 py-0.5 rounded-lg"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-body)' }}>
                  {l.warehouse?.name || 'Warehouse'}{l.location?.name ? ` · ${l.location.name}` : ''}
                  <strong style={{ color: 'var(--text-h)' }}> {fmtQty(l.quantity)}</strong>
                </span>
              ))}
            </div>
          )}
          {r.kind === 'product' && r.totals && (
            <p className="text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
              On hand <strong style={{ color: 'var(--text-h)' }}>{fmtQty(r.totals.on_hand)}</strong>
              {' · '}available <strong style={{ color: 'var(--text-h)' }}>{fmtQty(r.totals.available)}</strong>
            </p>
          )}

          {goTo && (
            <button onClick={() => navigate(goTo)} className="flex items-center gap-1 text-[11px] font-bold mt-2"
              style={{ color: cfg.color }}>
              Open <ArrowRight size={11} />
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
