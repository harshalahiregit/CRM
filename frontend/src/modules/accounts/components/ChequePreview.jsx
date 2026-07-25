import { amountToWords } from '@/modules/accounts/amountToWords'

/**
 * A realistic on-screen representation of a standard Indian bank cheque leaf
 * (spec §2 — visual & digital rendering). Always rendered on white regardless
 * of theme, because it depicts a physical document. Amounts here are NOT masked
 * by the money-visibility toggle: a cheque you're about to print must show its
 * figures.
 */
export function ChequePreview({ cheque, bankName }) {
  const inr = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const dateDigits = toDateBoxes(cheque?.cheque_date)
  const words = amountToWords(cheque?.amount)

  return (
    <div style={{
      position: 'relative', width: '100%', aspectRatio: '2.2 / 1', minHeight: 200,
      background: 'linear-gradient(135deg,#f4f8fc 0%,#eef3f9 100%)',
      border: '1px solid #cdd8e5', borderRadius: 10, padding: '18px 22px',
      fontFamily: '"Courier New", monospace', color: '#1f2d3d', overflow: 'hidden',
      boxShadow: 'inset 0 0 0 1px #ffffff, 0 1px 3px rgba(0,0,0,0.08)',
    }}>
      {/* A/C Payee crossing — the classic two parallel lines, top-left */}
      {cheque?.is_account_payee && (
        <div style={{ position: 'absolute', top: 8, left: 14, transform: 'rotate(-20deg)', lineHeight: 1 }}>
          <div style={{ borderTop: '2px solid #33506e', width: 74 }} />
          <div style={{ borderTop: '2px solid #33506e', width: 74, marginTop: 4 }} />
          <div style={{ fontSize: 8, letterSpacing: 1, color: '#33506e', marginTop: 2, fontWeight: 700 }}>A/C PAYEE</div>
        </div>
      )}

      {/* Bank name */}
      <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 15, letterSpacing: 0.5, color: '#22456b' }}>
        {bankName || cheque?.bank_account?.bank_name || 'BANK NAME'}
      </div>

      {/* Date boxes top-right */}
      <div style={{ position: 'absolute', top: 14, right: 20, display: 'flex', gap: 2 }}>
        {['D', 'D', 'M', 'M', 'Y', 'Y', 'Y', 'Y'].map((_, i) => (
          <span key={i} style={{
            width: 16, height: 22, border: '1px solid #9bb0c7', borderRadius: 2,
            display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, background: '#fff',
          }}>{dateDigits[i] ?? ''}</span>
        ))}
      </div>

      {/* Pay line */}
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, marginTop: 34 }}>
        <span style={{ fontSize: 12, color: '#5a6b7d' }}>Pay</span>
        <span style={{ flex: 1, borderBottom: '1px dotted #7f93a8', fontSize: 15, fontWeight: 700, paddingBottom: 1 }}>
          {cheque?.party_name || '—'}
        </span>
      </div>

      {/* Amount in words */}
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, marginTop: 14 }}>
        <span style={{ fontSize: 12, color: '#5a6b7d' }}>Rupees</span>
        <span style={{ flex: 1, borderBottom: '1px dotted #7f93a8', fontSize: 13, paddingBottom: 1, minHeight: 18 }}>
          {words}
        </span>
      </div>

      {/* Amount figure box */}
      <div style={{ position: 'absolute', right: 20, bottom: 62, display: 'flex', alignItems: 'center', gap: 4 }}>
        <span style={{ fontSize: 18, fontWeight: 700 }}>₹</span>
        <span style={{
          border: '1.5px solid #22456b', borderRadius: 4, padding: '4px 10px', fontSize: 15, fontWeight: 700,
          background: '#fff', minWidth: 96, textAlign: 'right',
        }}>{cheque?.amount != null ? inr.format(Number(cheque.amount)) : '0.00'}</span>
      </div>

      {/* A/C Payee stamp */}
      {cheque?.is_account_payee && (
        <div style={{
          position: 'absolute', left: 22, bottom: 58, transform: 'rotate(-8deg)',
          border: '2px solid #b23b3b', color: '#b23b3b', borderRadius: 4, padding: '2px 8px',
          fontSize: 10, fontWeight: 800, letterSpacing: 1, opacity: 0.85,
        }}>A/C PAYEE ONLY</div>
      )}

      {/* Footer: signatory + MICR line */}
      <div style={{ position: 'absolute', right: 22, bottom: 26, textAlign: 'right', fontSize: 10, color: '#5a6b7d' }}>
        Authorised Signatory
      </div>
      <div style={{ position: 'absolute', left: 22, bottom: 10, fontSize: 12, letterSpacing: 3, color: '#33506e' }}>
        ⑈ {cheque?.cheque_no || '––––––'} ⑈
      </div>
    </div>
  )
}

/** DD MM YYYY digit array from a YYYY-MM-DD date string. */
function toDateBoxes(d) {
  if (!d) return []
  const s = String(d).slice(0, 10)
  const [y, m, day] = s.split('-')
  if (!y || !m || !day) return []
  return `${day}${m}${y}`.split('')
}

/**
 * Precision print (spec §2). Opens an isolated print window that positions ONLY
 * the field values in millimetres on a blank page the size of a standard Indian
 * cheque leaf, so a pre-printed leaf fed into an office printer aligns with its
 * engraved fields. Coordinates are sensible defaults tuned to a ~203×92 mm leaf;
 * they can be nudged per printer without touching the app.
 */
export function printChequeLeaf(cheque) {
  const inr = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const amount = cheque?.amount != null ? inr.format(Number(cheque.amount)) : ''
  const words = amountToWords(cheque?.amount)
  const dd = toDateBoxes(cheque?.cheque_date).join('')

  // mm coordinates for a 203mm × 92mm cheque leaf (left, top).
  const at = (l, t, extra = '') => `position:absolute;left:${l}mm;top:${t}mm;${extra}`
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Cheque ${cheque?.cheque_no || ''}</title>
    <style>
      @page { size: 203mm 92mm; margin: 0; }
      html,body { margin:0; padding:0; }
      .leaf { position:relative; width:203mm; height:92mm; font-family:"Courier New",monospace; font-size:11pt; color:#000; }
      .fig { font-weight:700; }
      @media screen { body { background:#eee; } .leaf { background:#fff; box-shadow:0 0 6px rgba(0,0,0,.3); margin:12px auto; } }
    </style></head><body>
    <div class="leaf">
      <div style="${at(150, 8)}letter-spacing:3.4mm;">${dd}</div>
      <div style="${at(28, 26)}">${escapeHtml(cheque?.party_name || '')}</div>
      <div style="${at(30, 38)}font-size:10pt;">${escapeHtml(words)}</div>
      <div style="${at(165, 40)}" class="fig">${amount}</div>
    </div>
    <script>window.onload=function(){window.print();setTimeout(function(){window.close()},300)}<\/script>
    </body></html>`

  const w = window.open('', '_blank', 'width=900,height=500')
  if (!w) return
  w.document.open()
  w.document.write(html)
  w.document.close()
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

export default ChequePreview
