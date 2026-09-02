import { useState, useEffect, useMemo, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { HardHat, ShieldCheck, ShieldAlert, CircleSlash, ChevronRight, RefreshCw } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { KIT3D_STYLE, Overlay, inputStyle } from '@/components/ui/kit3d'
import { fmtDate } from '../constants'

/**
 * PPE Matrix — designation → the kit workers in that role actually hold.
 *
 * The TPV screen this mirrors is a matrix of RULES: an admin writes "a Welder must
 * be issued a face shield" and the badge gate validates against it. Purchase has no
 * such table — PurchasePpeService says so in as many words, and routes/purchase.php
 * exposes no requirement CRUD, no PPE catalogue and no tenant-wide compliance
 * endpoint. Inventing calls to those would 404, so this page is built from the two
 * reads the admin API genuinely has:
 *
 *   GET /purchase/workforce/workers          — the rows of the matrix
 *   GET /purchase/workforce/workers/{id}/ppe — { issues, compliance } per worker
 *
 * That inverts the matrix from PRESCRIPTIVE to OBSERVED: instead of what a role
 * must hold, it shows what the role is holding, which items cover the whole role
 * and which workers are carrying nothing. Same question ("is this role equipped?"),
 * answered from the ledger rather than from a rule set — and it cannot flatter the
 * gate, because complianceFor() is the same verdict the badge check reads.
 */

const ACCENT = '#f59e0b'          // PPE is amber across Purchase (worker wizard step 4)
const TONE = {
  full:    '#0ca30c',             // every worker in the role holds this item
  partial: '#fab219',             // some do
  none:    '#d03b3b',             // nobody does
  idle:    '#8b8b8b',
}

// One request per worker is the only shape the admin API offers, so the fan-out is
// capped and batched rather than fired at the whole register at once. Filter by
// vendor to bring a larger workforce inside the cap.
const WORKER_CAP = 80
const BATCH_SIZE = 8

/** Per-worker PPE, in small waves so a big register never floods the connection. */
async function fetchPpe(workers, onProgress, cancelled) {
  const out = new Map()
  for (let i = 0; i < workers.length; i += BATCH_SIZE) {
    if (cancelled()) return out
    const slice = workers.slice(i, i + BATCH_SIZE)
    // A worker whose read fails is left unresolved rather than counted as
    // unequipped — an absent verdict and a failing verdict are not the same thing.
    const res = await Promise.all(slice.map(w => purchaseApi.workforce.ppe(w.id).catch(() => null)))
    slice.forEach((w, n) => { if (res[n]) out.set(w.id, res[n]) })
    onProgress(Math.min(i + BATCH_SIZE, workers.length))
  }
  return out
}

export default function PurchasePpeMatrix() {
  const [searchParams] = useSearchParams()

  const [vendorId, setVendorId] = useState(searchParams.get('vendor_id') || '')
  const [vendors, setVendors] = useState([])
  const [rows, setRows] = useState([])            // workers inside the cap
  const [ppe, setPpe] = useState(new Map())       // worker id → { issues, compliance }
  const [beyondCap, setBeyondCap] = useState(0)
  const [progress, setProgress] = useState(0)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [openRole, setOpenRole] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [])

  // Keyed on reloadKey rather than called imperatively, so Refresh and a vendor
  // change both tear down the in-flight fan-out instead of racing it.
  const refresh = useCallback(() => setReloadKey(k => k + 1), [])

  useEffect(() => {
    let dead = false
    setLoading(true); setErr(''); setProgress(0); setPpe(new Map())

    ;(async () => {
      try {
        // vendor_id only FILTERS — the server scopes by tenant, so a tampered id
        // narrows the matrix and never widens it.
        const listRes = await purchaseApi.workforce.workers(vendorId ? { vendor_id: vendorId } : {})
        const list = Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : []
        if (dead) return

        const scope = list.slice(0, WORKER_CAP)
        setRows(scope)
        setBeyondCap(Math.max(list.length - scope.length, 0))

        const map = await fetchPpe(scope, p => { if (!dead) setProgress(p) }, () => dead)
        if (!dead) setPpe(map)
      } catch (e) {
        if (!dead) { setRows([]); setErr(e?.response?.data?.message || 'Could not read the workforce register.') }
      } finally {
        if (!dead) setLoading(false)
      }
    })()

    return () => { dead = true }
  }, [vendorId, reloadKey])

  // One card per designation, so the matrix reads the way a role does. Item
  // coverage is counted across the role, which is what makes a gap visible: a
  // helmet held by three of eight Welders is the interesting row, not the total.
  const matrix = useMemo(() => {
    const by = new Map()

    rows.forEach(w => {
      const role = w.designation || 'Unspecified'
      if (!by.has(role)) by.set(role, { role, workers: [], items: new Map(), equipped: 0 })
      const g = by.get(role)

      const rec = ppe.get(w.id)
      const held = rec?.compliance?.items ?? []
      g.workers.push({ ...w, held, assessed: !!rec, compliant: !!rec?.compliance?.compliant })
      if (rec?.compliance?.compliant) g.equipped++

      held.forEach(i => {
        const key = i.name || `Item #${i.product_id}`
        const row = g.items.get(key) || { name: key, holders: 0, qty: 0 }
        row.holders += 1
        row.qty += Number(i.qty) || 0
        g.items.set(key, row)
      })
    })

    return [...by.values()]
      .map(g => ({ ...g, items: [...g.items.values()].sort((a, b) => b.holders - a.holders) }))
      .sort((a, b) => b.workers.length - a.workers.length)
  }, [rows, ppe])

  const compliance = useMemo(() => {
    let equipped = 0, bare = 0
    rows.forEach(w => {
      const c = ppe.get(w.id)?.compliance
      if (!c) return
      if (c.compliant) equipped += 1; else bare += 1
    })
    return {
      equipped,
      bare,
      // Workers past the cap plus any whose PPE read failed — counted apart so a
      // green figure never quietly includes people nobody actually checked.
      unassessed: beyondCap + rows.filter(w => !ppe.get(w.id)).length,
      // Roles carrying unequipped people, worst first — the Purchase equivalent of
      // TPV's most-missed items.
      gaps: matrix
        .map(g => ({ role: g.role, workers: g.workers.filter(w => w.assessed && !w.compliant).length }))
        .filter(g => g.workers > 0)
        .sort((a, b) => b.workers - a.workers)
        .slice(0, 8),
      // Kit in circulation. Purchase has no admin PPE catalogue endpoint, so the
      // stock view TPV gets from /tpv/ppe is derived here from what is actually
      // out on site — issued but not yet handed back.
      circulation: [...rows.reduce((acc, w) => {
        ;(ppe.get(w.id)?.compliance?.items ?? []).forEach(i => {
          const row = acc.get(i.name) || { name: i.name, holders: 0, qty: 0 }
          row.holders += 1
          row.qty += Number(i.qty) || 0
          acc.set(i.name, row)
        })
        return acc
      }, new Map()).values()].sort((a, b) => b.holders - a.holders).slice(0, 10),
    }
  }, [rows, ppe, matrix, beyondCap])

  if (loading) {
    return (
      <div style={{ padding: '24px 32px' }}>
        <p className="text-sm p-8 text-center" style={{ color: 'var(--text-muted)' }}>
          Loading the PPE matrix{rows.length ? ` — read ${Math.min(progress, rows.length)} of ${rows.length} workers` : ''}…
        </p>
      </div>
    )
  }

  return (
    <div className="animate-fade-in" style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: ACCENT }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>PPE Matrix</h1>
      </div>
      <p style={{ margin: '0 0 18px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        What each designation is actually holding, read from the PPE ledger. Purchase configures no per-role
        requirements, so this is the issued kit itself — every line has a matching Inventory movement.
      </p>

      <ComplianceCards c={compliance} />

      {err && <p className="text-xs mb-3" style={{ color: '#d03b3b' }}>{err}</p>}

      <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 10, marginBottom: 12 }}>
        {beyondCap > 0 && (
          <span style={{ marginRight: 'auto', fontSize: 11.5, color: ACCENT, fontWeight: 600 }}>
            Showing the {WORKER_CAP} most recent workers — {beyondCap} more not matrixed. Filter by vendor to see them.
          </span>
        )}
        <select value={vendorId} onChange={e => setVendorId(e.target.value)}
          style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All vendors</option>
          {vendors.map(v => <option key={v.id} value={String(v.id)}>{v.company_name}</option>)}
        </select>
        <button onClick={refresh}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: ACCENT, color: '#fff', border: 'none', cursor: 'pointer' }}>
          <RefreshCw size={13} /> Refresh
        </button>
      </div>

      {matrix.length === 0 ? (
        <div style={{ padding: '34px 22px', textAlign: 'center', borderRadius: 12, background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <HardHat size={24} strokeWidth={1.8} style={{ color: 'var(--text-muted)', marginBottom: 9 }} />
          <p style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>No workers to matrix</p>
          <p style={{ margin: '6px auto 0', maxWidth: 460, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.55 }}>
            Register workers against a vendor and issue their kit — the roles they carry appear here as they are equipped.
          </p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12 }}>
          {matrix.map(g => (
            <div key={g.role} className="pr-glass" style={{ padding: 15, borderRadius: 12 }}>
              <div className="flex items-center justify-between gap-2 mb-3">
                <div className="min-w-0">
                  <p style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{g.role}</p>
                  <p style={{ margin: '2px 0 0', fontSize: 10.5, color: 'var(--text-muted)' }}>
                    {g.workers.length} worker{g.workers.length === 1 ? '' : 's'} · {g.equipped} equipped
                  </p>
                </div>
                <button onClick={() => setOpenRole(g)}
                  title="See who holds what"
                  style={{ padding: 5, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }}>
                  <ChevronRight size={13} />
                </button>
              </div>

              {g.items.length === 0 ? (
                <p style={{ margin: 0, fontSize: 11.5, fontWeight: 600, color: TONE.none }}>
                  ✗ No PPE held by anyone in this role.
                </p>
              ) : (
                <ul className="space-y-1.5">
                  {g.items.map(it => {
                    // Colour is coverage, and the count beside it says the same thing
                    // in words — identity is never carried by colour alone.
                    const tone = it.holders >= g.workers.length ? TONE.full : TONE.partial
                    return (
                      <li key={it.name} className="flex items-center gap-2 px-2.5 py-2 rounded-lg" style={{ background: 'var(--bg-input)' }}>
                        <span className="flex-1 min-w-0">
                          <span className="block text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{it.name}</span>
                          <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
                            {it.holders} of {g.workers.length} workers
                            {it.qty > it.holders && ` · ${it.qty} issued`}
                          </span>
                        </span>
                        <ShieldCheck size={13} style={{ color: tone }} />
                      </li>
                    )
                  })}
                </ul>
              )}

              {g.workers.some(w => w.assessed && !w.compliant) && (
                <p style={{ margin: '10px 0 0', fontSize: 11, fontWeight: 700, color: TONE.none }}>
                  {g.workers.filter(w => w.assessed && !w.compliant).length} carrying no PPE
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {openRole && <RoleDialog group={openRole} onClose={() => setOpenRole(null)} />}
    </div>
  )
}

/** How much of the workforce is actually equipped — counted, never estimated. */
function ComplianceCards({ c }) {
  const cards = [
    { label: 'Workers Holding PPE', value: c.equipped,   tone: TONE.full, icon: ShieldCheck },
    { label: 'Carrying No PPE',     value: c.bare,       tone: TONE.none, icon: ShieldAlert },
    { label: 'Not Assessed',        value: c.unassessed, tone: TONE.idle, icon: CircleSlash },
  ]

  return (
    <div style={{ marginBottom: 18 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 10 }}>
        {cards.map(x => (
          <div key={x.label} className="pr-glass" style={{ padding: '13px 15px', borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 7 }}>
              <x.icon size={13} strokeWidth={2.4} style={{ color: x.value ? x.tone : 'var(--text-muted)' }} />
              <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{x.label}</span>
            </div>
            <div style={{ fontSize: 22, fontWeight: 800, lineHeight: 1, fontVariantNumeric: 'tabular-nums', color: x.value ? 'var(--text-h)' : 'var(--text-muted)' }}>
              {x.value ?? 0}
            </div>
          </div>
        ))}
      </div>

      {c.gaps?.length > 0 && (
        <div className="pr-glass" style={{ marginTop: 10, padding: '12px 15px', borderRadius: 12 }}>
          <p style={{ margin: '0 0 8px', fontSize: 10, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
            Roles with unequipped workers
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
            {c.gaps.map(g => (
              <span key={g.role} style={{ fontSize: 11.5, fontWeight: 700, padding: '4px 10px', borderRadius: 8, color: TONE.none, background: 'color-mix(in srgb, #d03b3b 12%, transparent)' }}>
                {g.role} · {g.workers}
              </span>
            ))}
          </div>
        </div>
      )}

      {c.circulation?.length > 0 && (
        <div className="pr-glass" style={{ marginTop: 10, padding: '12px 15px', borderRadius: 12 }}>
          <p style={{ margin: '0 0 8px', fontSize: 10, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
            Kit in circulation
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
            {c.circulation.map(i => (
              <span key={i.name} style={{ fontSize: 11.5, fontWeight: 700, padding: '4px 10px', borderRadius: 8, color: ACCENT, background: 'color-mix(in srgb, #f59e0b 12%, transparent)' }}>
                {i.name} · {i.holders}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Who in this role holds what.
 *
 * Read-only by design: issuing is the vendor's own portal call and returns belong
 * to the worker wizard's step 4, which already owns that dialog. A second writer
 * here would give the same ledger two UIs and no single place to reason about it.
 */
function RoleDialog({ group, onClose }) {
  return (
    <Overlay onClose={onClose} width={520}>
      <h3 style={{ margin: 0, fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)', paddingRight: 24 }}>{group.role}</h3>
      <p style={{ margin: '4px 0 16px', fontSize: 12, color: 'var(--text-muted)' }}>
        {group.equipped} of {group.workers.length} equipped
      </p>

      <div style={{ display: 'grid', gap: 8 }}>
        {group.workers.map(w => (
          <div key={w.id} style={{ padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between gap-2">
              <div className="min-w-0">
                <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{w.full_name}</span>
                {w.worker_code && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}> · {w.worker_code}</span>}
              </div>
              <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 7, whiteSpace: 'nowrap', color: w.compliant ? TONE.full : TONE.none, background: `${w.compliant ? TONE.full : TONE.none}1f` }}>
                {w.compliant ? `${w.held.length} item${w.held.length === 1 ? '' : 's'}` : 'No PPE'}
              </span>
            </div>

            {w.held.length > 0 && (
              <ul style={{ margin: '7px 0 0', padding: 0, listStyle: 'none', display: 'grid', gap: 4 }}>
                {w.held.map(i => (
                  <li key={i.issue_id} style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                    <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{i.name}</span>
                    {i.qty > 1 && ` ×${i.qty}`}
                    {i.size && ` · ${i.size}`}
                    {i.issued_on && ` · ${fmtDate(i.issued_on)}`}
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}
      </div>
    </Overlay>
  )
}
