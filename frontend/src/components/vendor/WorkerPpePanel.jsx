import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { HardHat, RotateCcw } from 'lucide-react'

/**
 * Assigned PPE for one worker, with the return / lost / damaged actions.
 *
 * Every action posts through the PPE service, which moves Inventory stock in the
 * same transaction — so the catalogue and summary refresh from the same truth the
 * moment one completes. Nothing here computes a quantity.
 */

const STATUS_TONE = {
  issued:   { label: 'Issued',         tone: '#fab219' },
  returned: { label: 'Returned',       tone: '#0ca30c' },
  lost:     { label: 'Lost',           tone: '#d03b3b' },
  damaged:  { label: 'Damaged',        tone: '#ec835a' },
  partial:  { label: 'Partial Return', tone: '#2a78d6' },
}

export default function WorkerPpePanel({ workerId, api, accent = '#7C3AED', canManage = true, onChanged }) {
  const qc = useQueryClient()
  const [acting, setActing] = useState(null)

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['worker-ppe', workerId],
    queryFn: () => api.ppe.forWorker(workerId),
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const refresh = () => {
    setActing(null)
    // Stock just moved, so the catalogue and the summary cards are stale too.
    ;['worker-ppe', 'ppe-catalogue', 'ppe-summary'].forEach(k => qc.invalidateQueries({ queryKey: [k] }))
    // Returning everything drops the worker out of "PPE issued" — reload the host.
    onChanged?.()
  }

  if (isLoading) return <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading PPE…</p>

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <HardHat size={15} style={{ color: accent }} />
        <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: 'var(--text-h)' }}>Assigned PPE</h3>
      </div>

      {rows.length === 0 ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No PPE issued to this worker yet.</p>
      ) : (
        <div style={{ borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['Item', 'Qty', 'Issue Date', 'Return Date', 'Status', ''].map(h => (
                  <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 9.5, fontWeight: 700, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(r => {
                // A part-returned line is still 'issued' server-side; label it honestly.
                const partial = r.status === 'issued' && r.returned_qty > 0
                const cfg = partial ? STATUS_TONE.partial : (STATUS_TONE[r.status] || STATUS_TONE.issued)
                const outstanding = (r.qty || 0) - (r.returned_qty || 0)
                return (
                  <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '9px 12px', fontWeight: 650, color: 'var(--text-h)' }}>
                      {r.item}{r.sku && <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}> · {r.sku}</span>}
                    </td>
                    <td style={{ padding: '9px 12px', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>
                      {r.qty}{r.returned_qty > 0 && <span> ({r.returned_qty} back)</span>}
                    </td>
                    <td style={{ padding: '9px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmt(r.issue_date)}</td>
                    <td style={{ padding: '9px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmt(r.return_date)}</td>
                    <td style={{ padding: '9px 12px' }}>
                      <span style={{ fontSize: 10.5, fontWeight: 700, padding: '3px 8px', borderRadius: 7, color: cfg.tone, background: `color-mix(in srgb, ${cfg.tone} 12%, transparent)`, whiteSpace: 'nowrap' }}>
                        {cfg.label}
                      </span>
                    </td>
                    <td style={{ padding: '9px 12px', textAlign: 'right' }}>
                      {canManage && outstanding > 0 && (
                        <button onClick={() => setActing({ row: r, outstanding })}
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 7, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer' }}>
                          <RotateCcw size={11} /> Return
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {acting && <ReturnDialog {...acting} api={api} accent={accent} onClose={() => setActing(null)} onDone={refresh} />}
    </div>
  )
}

/** Return / lost / damaged, with a quantity so partial returns are possible. */
function ReturnDialog({ row, outstanding, api, accent, onClose, onDone }) {
  const [qty, setQty] = useState(outstanding)
  const [condition, setCondition] = useState('returned')
  const [err, setErr] = useState('')

  const n = Number(qty) || 0
  const valid = n > 0 && n <= outstanding

  const act = useMutation({
    mutationFn: () => api.ppe.returnIssue(row.id, { qty: n, condition }),
    onSuccess: onDone,
    onError: (e) => setErr(e?.response?.data?.message || 'That action failed.'),
  })

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }}>
      <div onClick={e => e.stopPropagation()} style={{ width: 380, maxWidth: '94vw', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 22 }}>
        <h3 style={{ margin: 0, fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }}>Return {row.item}</h3>
        <p style={{ margin: '4px 0 14px', fontSize: 12, color: 'var(--text-muted)' }}>{outstanding} outstanding</p>

        <label style={lbl}>Quantity</label>
        <input type="number" min="1" max={outstanding} value={qty} onChange={e => { setQty(e.target.value); setErr('') }} style={inp} />

        <label style={{ ...lbl, marginTop: 12 }}>Condition</label>
        <select value={condition} onChange={e => setCondition(e.target.value)} style={inp}>
          <option value="returned">Returned — back into stock</option>
          <option value="damaged">Damaged — written off</option>
          <option value="lost">Lost — written off</option>
        </select>

        <p style={{ margin: '10px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          {condition === 'returned'
            ? 'Stock returns to Inventory immediately.'
            : 'These items left Inventory when they were issued, so stock does not change — the write-off is recorded against this issue.'}
        </p>

        {err && <p style={{ margin: '10px 0 0', fontSize: 12, color: '#d03b3b' }}>{err}</p>}

        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button onClick={onClose} style={{ flex: 1, padding: 9, borderRadius: 9, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>Cancel</button>
          <button onClick={() => act.mutate()} disabled={!valid || act.isPending}
            style={{ flex: 1, padding: 9, borderRadius: 9, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: valid ? 'pointer' : 'not-allowed', background: valid ? accent : 'var(--bg-input)', color: valid ? '#fff' : 'var(--text-muted)' }}>
            {act.isPending ? 'Saving…' : 'Confirm'}
          </button>
        </div>
      </div>
    </div>
  )
}

const fmt = (d) => (d ? new Date(d).toLocaleDateString() : '—')
const lbl = { display: 'block', fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }
const inp = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
