import { useEffect, useState } from 'react'
import { FileSignature, FileText, CheckCircle2, Clock, ExternalLink } from 'lucide-react'
import { contractModuleApi } from '@/services/contractModuleApi'

/**
 * A party's contracts — the same list on both sides of the glass.
 *
 * One component serves the customer portal, the TPV portal, the Purchase portal
 * AND the admin record, because they show the same rows and only differ in where
 * the data comes from and whether a row opens the internal screen. Writing four
 * of these is how three of them end up out of date.
 *
 * `fetcher` is passed in rather than the component choosing an endpoint: each
 * portal authenticates a different identity, and the party is resolved from that
 * session server-side. There is deliberately no id prop to get wrong.
 */
export default function PartyContractList({
  fetcher,
  onOpen,
  title = 'Contracts',
  emptyText = 'No contracts yet.',
  compact = false,
}) {
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(null)

  useEffect(() => {
    let live = true
    fetcher()
      .then(d => { if (live) setRows(Array.isArray(d) ? d : (d?.data ?? [])) })
      .catch(() => { if (live) { setRows([]); setErr('Contracts could not be loaded.') } })
    return () => { live = false }
  }, [fetcher])

  const money = (v, c) => v ? `${c || ''} ${Number(v).toLocaleString('en-IN')}`.trim() : '—'
  const date = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

  const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: compact ? 0 : 18 }

  return (
    <div style={card}>
      {!compact && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
          <FileSignature size={16} style={{ color: '#7C3AED' }} />
          <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
            {title}
          </h2>
        </div>
      )}

      {err && <div style={{ color: '#ef4444', fontSize: 12.5, marginBottom: 10 }}>{err}</div>}

      {rows === null ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, padding: compact ? 16 : 0 }}>Loading…</p>
      ) : rows.length === 0 ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, padding: compact ? 16 : 0 }}>{emptyText}</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 620 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.05em' }}>
                {['Reference', 'Contract', 'Value', 'Signatures', 'Ends', ''].map((h, i) => (
                  <th key={i} style={{ padding: '9px 12px', borderBottom: '1px solid var(--border)', fontWeight: 700 }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(r => {
                const sigOf = (p) => (r.signatures || []).find(s => (s.party ?? s.signer_party) === p)
                return (
                  <tr key={r.id}
                    onClick={onOpen ? () => onOpen(r) : undefined}
                    style={{ borderBottom: '1px solid var(--border)', cursor: onOpen ? 'pointer' : 'default' }}>
                    <td style={{ padding: '10px 12px', fontFamily: 'monospace', fontSize: 11.5, color: 'var(--text-muted)' }}>
                      {r.reference_no}
                    </td>
                    <td style={{ padding: '10px 12px' }}>
                      <div style={{ fontWeight: 600, color: 'var(--text-h)' }}>{r.title}</div>
                      {(r.category?.name || r.category) && (
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.category?.name ?? r.category}</div>
                      )}
                    </td>
                    <td style={{ padding: '10px 12px', fontVariantNumeric: 'tabular-nums' }}>{money(r.value, r.currency)}</td>
                    <td style={{ padding: '10px 12px' }}>
                      {/* Both parties, not one tick: half-signed is the state
                          that needs chasing, and a boolean hides it. */}
                      <div style={{ display: 'flex', gap: 9 }}>
                        {[['party', 'Them'], ['company', 'Us']].map(([k, lbl]) => {
                          const s = sigOf(k)
                          const done = Boolean(s?.signed_at)
                          return (
                            <span key={k} title={done ? `Signed by ${s.signer_name}` : 'Awaiting signature'}
                              style={{ display: 'flex', alignItems: 'center', gap: 3, fontSize: 11, color: done ? '#10b981' : 'var(--text-muted)' }}>
                              {done ? <CheckCircle2 size={12} /> : <Clock size={12} />} {lbl}
                            </span>
                          )
                        })}
                      </div>
                    </td>
                    <td style={{ padding: '10px 12px', fontSize: 12, color: 'var(--text-muted)' }}>
                      {r.end_date ? date(r.end_date) : 'open-ended'}
                    </td>
                    <td style={{ padding: '10px 12px', textAlign: 'right' }}>
                      {/* Two different routes on purpose. A portal row carries a
                          public pdf_url built from the party's own token, so a
                          plain link works. The admin route is behind Sanctum,
                          where a link navigates without the token and opens a
                          blank tab — that one has to be fetched. */}
                      {r.pdf_url ? (
                        <a href={r.pdf_url} target="_blank" rel="noreferrer"
                          onClick={e => e.stopPropagation()}
                          title="Open the PDF"
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: 'var(--text-muted)', fontSize: 11.5, textDecoration: 'none' }}>
                          <FileText size={13} /> PDF
                        </a>
                      ) : (
                        <button
                          onClick={e => { e.stopPropagation(); contractModuleApi.openPdf(r.id).catch(() => {}) }}
                          title="Open the PDF"
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 4, background: 'transparent', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', fontSize: 11.5 }}>
                          <FileText size={13} /> PDF
                        </button>
                      )}
                      {onOpen && <ExternalLink size={13} style={{ color: 'var(--text-muted)', marginLeft: 8 }} />}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
