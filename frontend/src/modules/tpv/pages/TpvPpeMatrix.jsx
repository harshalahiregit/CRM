import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { HardHat, Plus, Trash2, ShieldCheck, ShieldAlert, CircleSlash, X } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

/**
 * PPE Requirement Matrix — role → required PPE.
 *
 * The rules are data: changing one changes the next badge validation with no
 * deploy. Every item offered comes from the Inventory PPE catalogue, and every
 * role offered is one workers actually have, so a rule can never be written
 * against a value nothing will ever match.
 */

const ACCENT = '#f59e0b'

export default function TpvPpeMatrix() {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(null)   // the scope being added to
  const [err, setErr] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['ppe-requirements'],
    queryFn: tpvApi.ppe.requirements,
    staleTime: 0,
  })
  const { data: compliance } = useQuery({
    queryKey: ['ppe-compliance'],
    queryFn: tpvApi.ppe.compliance,
    staleTime: 0,
  })

  const refresh = () => {
    setAdding(null); setErr('')
    qc.invalidateQueries({ queryKey: ['ppe-requirements'] })
    qc.invalidateQueries({ queryKey: ['ppe-compliance'] })
  }
  const onError = (e) => setErr(e?.response?.data?.message || 'That change was rejected.')

  const del = useMutation({ mutationFn: tpvApi.ppe.deleteRequirement, onSuccess: refresh, onError })
  const toggle = useMutation({
    mutationFn: ({ id, is_active }) => tpvApi.ppe.updateRequirement(id, { is_active }),
    onSuccess: refresh, onError,
  })

  // One card per configured role, so the matrix reads the way it is described.
  const groups = useMemo(() => {
    const by = new Map()
    ;(data?.rules || []).forEach(r => {
      const key = `${r.scope_type}::${r.scope_value ?? ''}`
      if (!by.has(key)) by.set(key, { scope_type: r.scope_type, scope_value: r.scope_value, rules: [] })
      by.get(key).rules.push(r)
    })
    return [...by.values()]
  }, [data])

  if (isLoading) return <p className="text-sm p-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading the PPE matrix…</p>

  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: ACCENT }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>PPE Requirement Matrix</h1>
      </div>
      <p style={{ margin: '0 0 18px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Which PPE each role must be issued before a badge can be issued. Items come from Inventory; these rules add no stock of their own.
      </p>

      {compliance && <ComplianceCards c={compliance} />}

      {err && <p className="text-xs mb-3" style={{ color: '#d03b3b' }}>{err}</p>}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <button onClick={() => { setAdding({ scope_type: 'designation', scope_value: '' }); setErr('') }}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: ACCENT, color: '#fff', border: 'none', cursor: 'pointer' }}>
          <Plus size={13} /> Add requirement
        </button>
      </div>

      {groups.length === 0 ? (
        <div style={{ padding: '34px 22px', textAlign: 'center', borderRadius: 12, background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <HardHat size={24} strokeWidth={1.8} style={{ color: 'var(--text-muted)', marginBottom: 9 }} />
          <p style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>No PPE requirements configured</p>
          <p style={{ margin: '6px auto 0', maxWidth: 460, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.55 }}>
            Until a role has requirements, no PPE is demanded of it and badges are not blocked on PPE.
          </p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12 }}>
          {groups.map(g => (
            <div key={`${g.scope_type}${g.scope_value}`} style={{ padding: 15, borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex items-center justify-between gap-2 mb-3">
                <div className="min-w-0">
                  <p style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>
                    {g.scope_value || 'All Workers'}
                  </p>
                  <p style={{ margin: '2px 0 0', fontSize: 10.5, color: 'var(--text-muted)' }}>
                    {data.scopes?.[g.scope_type] || g.scope_type}
                  </p>
                </div>
                <button onClick={() => { setAdding({ scope_type: g.scope_type, scope_value: g.scope_value || '' }); setErr('') }}
                  title="Add an item to this role"
                  style={{ padding: 5, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }}>
                  <Plus size={13} />
                </button>
              </div>

              <ul className="space-y-1.5">
                {g.rules.map(r => (
                  <li key={r.id} className="flex items-center gap-2 px-2.5 py-2 rounded-lg" style={{ background: 'var(--bg-input)', opacity: r.is_active ? 1 : 0.5 }}>
                    <span className="flex-1 min-w-0">
                      <span className="block text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{r.product}</span>
                      <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {r.sku}{r.qty > 1 && ` · ×${r.qty}`}{!r.is_active && ' · inactive'}
                      </span>
                    </span>
                    <button onClick={() => toggle.mutate({ id: r.id, is_active: !r.is_active })}
                      title={r.is_active ? 'Make optional' : 'Make required'}
                      style={{ padding: 3, background: 'none', border: 'none', cursor: 'pointer', color: r.is_active ? '#0ca30c' : 'var(--text-muted)' }}>
                      <ShieldCheck size={13} />
                    </button>
                    <button onClick={() => del.mutate(r.id)} title="Remove"
                      style={{ padding: 3, background: 'none', border: 'none', cursor: 'pointer', color: '#d03b3b' }}>
                      <Trash2 size={12} />
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      {adding && (
        <AddDialog initial={adding} meta={data} onClose={() => setAdding(null)} onDone={refresh} onError={onError} />
      )}
    </div>
  )
}

/** Tenant-wide compliance — how many workers are actually equipped. */
function ComplianceCards({ c }) {
  const cards = [
    { label: 'Workers Fully Equipped', value: c.fully_equipped, tone: '#0ca30c', icon: ShieldCheck },
    { label: 'Missing Mandatory PPE',  value: c.missing_ppe,    tone: '#d03b3b', icon: ShieldAlert },
    { label: 'No Rules For Their Role', value: c.not_configured, tone: '#8b8b8b', icon: CircleSlash },
  ]

  return (
    <div style={{ marginBottom: 18 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 10 }}>
        {cards.map(x => (
          <div key={x.label} style={{ padding: '13px 15px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
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

      {c.missing_by_item?.length > 0 && (
        <div style={{ marginTop: 10, padding: '12px 15px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <p style={{ margin: '0 0 8px', fontSize: 10, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
            Most-missed items
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
            {c.missing_by_item.map(i => (
              <span key={i.product_id} style={{ fontSize: 11.5, fontWeight: 700, padding: '4px 10px', borderRadius: 8, color: '#d03b3b', background: 'color-mix(in srgb, #d03b3b 12%, transparent)' }}>
                Missing {i.name} · {i.workers}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/** Add one rule: scope → role value → Inventory item. */
function AddDialog({ initial, meta, onClose, onDone, onError }) {
  const [scopeType, setScopeType] = useState(initial.scope_type)
  const [scopeValue, setScopeValue] = useState(initial.scope_value || '')
  const [productId, setProductId] = useState('')
  const [qty, setQty] = useState(1)

  const roles = meta?.roles?.[scopeType] || []
  const needsValue = scopeType !== 'all'
  const valid = productId && (!needsValue || scopeValue)

  const save = useMutation({
    mutationFn: () => tpvApi.ppe.addRequirement({
      scope_type: scopeType,
      scope_value: needsValue ? scopeValue : null,
      product_id: Number(productId),
      qty: Number(qty) || 1,
    }),
    onSuccess: onDone,
    onError,
  })

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }}>
      <div onClick={e => e.stopPropagation()} style={{ width: 400, maxWidth: '94vw', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 22 }}>
        <div className="flex items-center justify-between mb-4">
          <h3 style={{ margin: 0, fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }}>Add PPE requirement</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}><X size={17} /></button>
        </div>

        <label style={lbl}>Applies to</label>
        <select value={scopeType} onChange={e => { setScopeType(e.target.value); setScopeValue('') }} style={inp}>
          {Object.entries(meta?.scopes || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>

        {needsValue && (
          <>
            <label style={{ ...lbl, marginTop: 12 }}>Role</label>
            {roles.length > 0 ? (
              <select value={scopeValue} onChange={e => setScopeValue(e.target.value)} style={inp}>
                <option value="">Select…</option>
                {roles.map(r => <option key={r} value={r}>{r}</option>)}
              </select>
            ) : (
              <input value={scopeValue} onChange={e => setScopeValue(e.target.value)} placeholder="e.g. Welder" style={inp} />
            )}
            {roles.length === 0 && (
              <p style={{ margin: '5px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
                No worker records this yet — a rule here will apply once one does.
              </p>
            )}
          </>
        )}

        <label style={{ ...lbl, marginTop: 12 }}>PPE item (from Inventory)</label>
        <select value={productId} onChange={e => setProductId(e.target.value)} style={inp}>
          <option value="">Select…</option>
          {(meta?.items || []).map(i => <option key={i.product_id} value={i.product_id}>{i.name} · {i.sku}</option>)}
        </select>

        <label style={{ ...lbl, marginTop: 12 }}>Quantity required</label>
        <input type="number" min="1" value={qty} onChange={e => setQty(e.target.value)} style={inp} />

        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button onClick={onClose} style={{ flex: 1, padding: 9, borderRadius: 9, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>Cancel</button>
          <button onClick={() => save.mutate()} disabled={!valid || save.isPending}
            style={{ flex: 1, padding: 9, borderRadius: 9, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: valid ? 'pointer' : 'not-allowed', background: valid ? ACCENT : 'var(--bg-input)', color: valid ? '#fff' : 'var(--text-muted)' }}>
            {save.isPending ? 'Saving…' : 'Add'}
          </button>
        </div>
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }
const inp = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
