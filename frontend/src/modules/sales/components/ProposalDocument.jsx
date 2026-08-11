import { richHtml } from '@/lib/richText'
/**
 * Compiled client-facing proposal document — the single source of what the
 * client sees. Used by the wizard's Review step AND the public portal page,
 * so the internal preview is guaranteed to match the client view.
 * Accepts either an internal proposal record or the public payload.
 */
const fmt = (v, currency = 'INR') =>
  (currency === 'INR' ? '₹' : currency + ' ') + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const d10 = s => (s ? String(s).slice(0, 10) : null)

export default function ProposalDocument({ proposal }) {
  if (!proposal) return null
  const pages = proposal.pages || []
  const lineItems = proposal.line_items || proposal.lineItems || []
  const cur = proposal.currency || 'INR'
  const cover = proposal.cover && proposal.cover.enabled ? proposal.cover : null

  return (
    <div className="mx-auto space-y-5" style={{ maxWidth: 820 }}>
      {/* Cover page (Page 1) — a distinct card before the document body */}
      {cover && (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          {cover.image && (
            <img src={cover.image} alt="" style={{ width: '100%', maxHeight: 340, objectFit: 'cover', display: 'block' }} />
          )}
          <div className="text-center" style={{ padding: 'clamp(28px, 5vw, 56px)' }}>
            {cover.title && <p className="label-caps mb-2" style={{ color: 'var(--accent)', letterSpacing: '0.15em' }}>{cover.title}</p>}
            <h1 className="font-black" style={{ fontSize: 'clamp(1.6rem, 4vw, 2.6rem)', color: 'var(--text-h)', letterSpacing: '-0.02em', lineHeight: 1.15 }}>
              {cover.heading || proposal.subject}
            </h1>
            {cover.body && (
              <div className="rte-body text-left mt-5 mx-auto" style={{ maxWidth: 620, color: 'var(--text-body)' }}
                dangerouslySetInnerHTML={{ __html: cover.body }} />
            )}
            {(proposal.proposal_to) && (
              <p className="text-sm mt-4" style={{ color: 'var(--text-muted)' }}>Prepared for <b style={{ color: 'var(--text-h)' }}>{proposal.proposal_to}</b></p>
            )}
            {d10(proposal.date) && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{d10(proposal.date)}</p>}
          </div>
        </div>
      )}

      <div className="card-3d" style={{ padding: 'clamp(24px, 5vw, 48px)' }}>
        {/* Header */}
        <div className="pb-5 mb-6" style={{ borderBottom: '2px solid var(--accent)' }}>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem, 3vw, 1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{proposal.subject}</h1>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {proposal.reference_no && <>Ref: <b>{proposal.reference_no}</b> · </>}
            {d10(proposal.date) && <>Date: {d10(proposal.date)}</>}
            {d10(proposal.open_till) && <> · Valid until: {d10(proposal.open_till)}</>}
          </p>
        </div>

        {/* Prepared for */}
        {(proposal.proposal_to || proposal.address) && (
          <div className="p-4 rounded-xl mb-6" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <p className="label-caps mb-1" style={{ color: 'var(--accent)' }}>Prepared For</p>
            <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{proposal.proposal_to || '—'}</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {[proposal.address, proposal.city, proposal.state, proposal.zip, proposal.country].filter(Boolean).join(', ')}
            </p>
          </div>
        )}

        {/* Content pages */}
        {pages.map((pg, i) => (
          <div key={i} className="mb-8">
            <h2 className="font-black text-base mb-3 pb-2" style={{ color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>{pg.title}</h2>
            <div className="rich-content text-sm" style={{ color: 'var(--text-h)' }} dangerouslySetInnerHTML={{ __html: pg.content }} />
          </div>
        ))}

        {/* Legacy rule: no pages → notes as the body */}
        {!pages.length && proposal.notes && (
          <div className="mb-8">
            <div className="rich-content text-sm" style={{ color: 'var(--text-h)' }} dangerouslySetInnerHTML={richHtml(proposal.notes)} />
          </div>
        )}

        {/* Commercial */}
        {lineItems.length > 0 && (
          <div className="mb-6">
            <h2 className="font-black text-base mb-3 pb-2" style={{ color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>Investment</h2>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead><tr style={{ background: 'rgba(124,58,237,0.05)' }}>
                  {['Item', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => <th key={h} className="py-2.5 px-3 text-left label-caps">{h}</th>)}
                </tr></thead>
                <tbody>
                  {lineItems.map((li, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="py-2.5 px-3">
                        <p className="font-bold" style={{ color: 'var(--text-h)' }}>{li.item_name}</p>
                        {li.description && <p style={{ color: 'var(--text-muted)' }}>{li.description}</p>}
                      </td>
                      <td className="py-2.5 px-3" style={{ color: 'var(--text-muted)' }}>{Number(li.qty)}{li.unit ? ` ${li.unit}` : ''}</td>
                      <td className="py-2.5 px-3" style={{ color: 'var(--text-muted)' }}>{fmt(li.rate, cur)}</td>
                      <td className="py-2.5 px-3" style={{ color: 'var(--text-muted)' }}>{Number(li.tax || 0)}%</td>
                      <td className="py-2.5 px-3 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(li.amount ?? li.total, cur)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex justify-end mt-4">
              <div className="w-64 space-y-1.5 text-sm">
                <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Subtotal</span><span style={{ color: 'var(--text-h)' }}>{fmt(proposal.subtotal, cur)}</span></div>
                {Number(proposal.discount_amount) > 0 && (
                  <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Discount</span><span style={{ color: '#10b981' }}>− {fmt(proposal.discount_amount, cur)}</span></div>
                )}
{/* Each selected tax itemised by name; legacy docs fall back to the state split. */}
                {(proposal.tax_breakdown || []).length ? (
                  proposal.tax_breakdown.map((t, i) => (
                    <div key={i} className="flex justify-between">
                      <span style={{ color: 'var(--text-muted)' }}>{t.name} ({t.rate}%)</span>
                      <span style={{ color: 'var(--text-h)' }}>{fmt(t.amount, cur)}</span>
                    </div>
                  ))
                ) : proposal.supply_type === 'intra' ? (
                  <>
                    <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>CGST</span><span style={{ color: 'var(--text-h)' }}>{fmt(proposal.tax_total / 2, cur)}</span></div>
                    <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>SGST</span><span style={{ color: 'var(--text-h)' }}>{fmt(proposal.tax_total / 2, cur)}</span></div>
                  </>
                ) : proposal.supply_type === 'inter' ? (
                  <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>IGST</span><span style={{ color: 'var(--text-h)' }}>{fmt(proposal.tax_total, cur)}</span></div>
                ) : (
                  <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Tax</span><span style={{ color: 'var(--text-h)' }}>{fmt(proposal.tax_total, cur)}</span></div>
                )}
                <div className="flex justify-between pt-2 font-black text-base" style={{ borderTop: '1px solid var(--border)', color: 'var(--accent)' }}>
                  <span>Total</span><span>{fmt(proposal.total, cur)}</span>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Terms */}
        {proposal.terms && (
          <div className="pt-4" style={{ borderTop: '1px solid var(--border)' }}>
            <p className="label-caps mb-1" style={{ color: 'var(--accent)' }}>Terms & Conditions</p>
            <div className="rich-content text-xs" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: proposal.terms }} />
          </div>
        )}
      </div>
    </div>
  )
}
