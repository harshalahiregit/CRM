import { useState, useEffect, useCallback } from 'react'
import { FolderLock, RefreshCw, Search, FileText, ExternalLink } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §30 — unified Document Vault. Read-only lens over statutory vendor
// documents, the compliance-evidence locker, and CAPA/NCR closure evidence, with
// a computed expiry state so nothing lapses unseen.
const SOURCE_TONE = { Statutory: '#8b5cf6', Evidence: '#0ea5e9', CAPA: '#22c55e', NCR: '#ef4444' }
const EXPIRY_TONE = { valid: '#22c55e', expiring: '#f59e0b', expired: '#ef4444', none: '#6b7280' }
const EXPIRY_LABEL = { valid: 'Valid', expiring: 'Expiring', expired: 'Expired', none: 'No expiry' }
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvDocumentVault() {
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ sources: [], expiry_states: [], summary: {} })
  const [sourceF, setSourceF] = useState('')
  const [expiryF, setExpiryF] = useState('')
  const [q, setQ] = useState('')

  const load = useCallback(() => {
    const params = {}
    if (sourceF) params.source = sourceF
    if (expiryF) params.expiry = expiryF
    if (q) params.q = q
    tpvApi.documentVault.list(params)
      .then(d => { setRows(d?.data ?? []); setMeta({ sources: d?.sources ?? [], expiry_states: d?.expiry_states ?? [], summary: d?.summary ?? {} }) })
      .catch(() => setRows([]))
  }, [sourceF, expiryF, q])
  useEffect(() => { const t = setTimeout(load, q ? 300 : 0); return () => clearTimeout(t) }, [load, q])

  const sum = meta.summary || {}
  const exp = sum.expiry || {}
  const cards = [
    { k: 'Documents', v: sum.total ?? 0, c: '#a78bfa', filter: () => { setSourceF(''); setExpiryF('') } },
    { k: 'Valid', v: exp.valid ?? 0, c: '#22c55e', filter: () => setExpiryF('valid') },
    { k: 'Expiring', v: exp.expiring ?? 0, c: '#f59e0b', filter: () => setExpiryF('expiring') },
    { k: 'Expired', v: exp.expired ?? 0, c: '#ef4444', filter: () => setExpiryF('expired') },
  ]

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>INTELLIGENCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Document Vault</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Every vendor document — statutory, evidence, CAPA &amp; NCR — in one lens with expiry tracking.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 10, marginBottom: 14 }}>
        {cards.map(c => (
          <button key={c.k} onClick={c.filter} className="pr-glass" style={{ padding: '12px 14px', borderRadius: 12, border: '1px solid var(--border)', textAlign: 'left', cursor: 'pointer', background: 'var(--bg-card)' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c.c }}>{c.v}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.k}</div>
          </button>
        ))}
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: '1 1 220px', maxWidth: 320 }}>
          <Search size={15} style={{ position: 'absolute', left: 11, top: 10, color: 'var(--text-muted)' }} />
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search title, vendor, reference…" style={{ ...inp, paddingLeft: 34 }} />
        </div>
        <select value={sourceF} onChange={e => setSourceF(e.target.value)} style={{ ...inp, maxWidth: 170 }}>
          <option value="">All sources</option>
          {(meta.sources || []).map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={expiryF} onChange={e => setExpiryF(e.target.value)} style={{ ...inp, maxWidth: 170 }}>
          <option value="">All expiry</option>
          {(meta.expiry_states || []).map(s => <option key={s} value={s}>{EXPIRY_LABEL[s] || s}</option>)}
        </select>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Source', 'Document', 'Vendor', 'Uploaded', 'Expires', 'Status', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No documents match.</td></tr>
                : rows.map(r => (
                  <tr key={r.key} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px' }}><Pill tone={SOURCE_TONE[r.source]} text={r.source} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <FileText size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                        <span>{r.title}</span>
                      </div>
                      {r.reference && r.reference !== r.title && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 19 }}>{r.reference}</div>}
                    </td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.vendor_name}<div style={{ fontSize: 11 }}>{r.vendor_code}</div></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(r.uploaded_at)}<div style={{ fontSize: 11 }}>{r.uploaded_by_name || ''}</div></td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={EXPIRY_TONE[r.expiry_state]} text={r.expires_at ? date(r.expires_at) : EXPIRY_LABEL[r.expiry_state]} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{r.status || '—'}</td>
                    <td style={{ padding: '10px 14px' }}>
                      {r.has_file && r.file && <a href={r.file} target="_blank" rel="noreferrer" style={iconBtn} title="Open file"><ExternalLink size={14} /></a>}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: '#a78bfa', cursor: 'pointer', textDecoration: 'none' }
const inp = { width: '100%', padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }
