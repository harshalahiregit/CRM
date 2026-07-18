// Shared "Unique 3D" kit — the glassmorphism style block and modal/form
// primitives used across the Purchase and TPV modules. Promoted out of
// modules/purchase so no module imports from another module (TEAM-CONVENTIONS §3).

export const labelStyle = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
export const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }

/* The 3D layer: frosted glass, layered realistic shadows, a diagonal shine sweep
   on hover, extruded pipeline knobs, floating KPI tiles. Theme-aware (light/dark). */
export const KIT3D_STYLE = `
  .pr-glass {
    position: relative; border-radius: 20px; overflow: hidden;
    background: var(--bg-card); border: 1px solid var(--border);
    box-shadow: 0 1px 2px rgba(15,10,40,.06), 0 10px 30px -12px rgba(88,40,180,.28), inset 0 1px 0 var(--card-shine);
    transition: transform .32s cubic-bezier(.34,1.56,.64,1), box-shadow .32s ease, border-color .32s ease;
  }
  html:not(.light) .pr-glass { background: linear-gradient(160deg, rgba(40,34,60,.66), rgba(28,24,42,.52)); backdrop-filter: blur(14px) saturate(1.2); }
  html.light .pr-glass { background: linear-gradient(160deg, rgba(255,255,255,.9), rgba(248,245,255,.72)); backdrop-filter: blur(12px) saturate(1.1); }
  .pr-glass::after {
    content: ''; position: absolute; inset: 0; pointer-events: none; opacity: 0;
    background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,.16) 46%, transparent 60%);
    transform: translateX(-60%); transition: opacity .5s ease, transform .8s ease;
  }
  .pr-lift:hover { transform: translateY(-4px); box-shadow: 0 4px 8px rgba(15,10,40,.08), 0 26px 50px -18px rgba(108,56,210,.5), inset 0 1px 0 var(--card-shine-hover); border-color: var(--border-purple); }
  .pr-lift:hover::after { opacity: 1; transform: translateX(60%); }
  .pr-node { position: relative; transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease, opacity .2s ease, background .2s ease; }
  .pr-node:hover { transform: translateY(-2px) scale(1.015); }
  .pr-node:active { transform: scale(.98); }
  .pr-flow { background-size: 200% 100%; animation: mrFlow 2.2s linear infinite; }
  .pr-flow-dim { opacity: .28; animation: none; }
  .pr-kpi { position: relative; border-radius: 16px; padding: 15px 14px; cursor: pointer; overflow: hidden;
    background: var(--bg-card); border: 1px solid var(--border);
    box-shadow: 0 8px 22px -14px rgba(88,40,180,.5), inset 0 1px 0 var(--card-shine);
    transition: transform .28s cubic-bezier(.34,1.56,.64,1), box-shadow .28s ease; }
  .pr-kpi:hover { transform: translateY(-3px); box-shadow: 0 18px 36px -16px rgba(108,56,210,.55), inset 0 1px 0 var(--card-shine-hover); }
  .pr-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:40%; background:linear-gradient(180deg,rgba(255,255,255,.06),transparent); pointer-events:none; }
  .pr-li-row { transition: background .18s ease; }
  .pr-li-row:hover { background: var(--bg-input); }
  @keyframes prPop { 0%{transform:scale(.96);opacity:0} 100%{transform:scale(1);opacity:1} }
  .pr-pop { animation: prPop .22s cubic-bezier(.34,1.56,.64,1); }
  /* Receipt progress bar */
  .pr-bar { height: 7px; border-radius: 99px; background: var(--bg-input); overflow: hidden; }
  .pr-bar > span { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg,#7C3AED,#10b981); transition: width .5s cubic-bezier(.34,1.56,.64,1); }
`

// ── Modal primitives ─────────────────────────────────────────────────────────
export function Overlay({ onClose, width = 480, children }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
      onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="pr-glass pr-pop" style={{ width: '100%', maxWidth: width, maxHeight: '90vh', overflowY: 'auto', padding: 28 }}>{children}</div>
    </div>
  )
}

export function ModalFooter({ onClose, onConfirm, loading, disabled, confirmLabel, color = '#7C3AED' }) {
  return (
    <div style={{ display: 'flex', gap: 10, marginTop: 22, justifyContent: 'flex-end' }}>
      <button onClick={onClose} disabled={loading} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button>
      <button onClick={onConfirm} disabled={loading || disabled}
        style={{ padding: '9px 24px', borderRadius: 9, background: loading || disabled ? 'rgba(124,58,237,0.4)' : `linear-gradient(135deg,${color},${color}cc)`, color: '#fff', fontWeight: 700, border: 'none', cursor: loading || disabled ? 'not-allowed' : 'pointer', fontSize: 13, opacity: disabled ? 0.6 : 1 }}>
        {loading ? 'Processing…' : confirmLabel}
      </button>
    </div>
  )
}

export const InfoBox = ({ tone, children }) => (
  <p style={{ color: 'var(--text-muted)', fontSize: 12, padding: '10px 14px', marginBottom: 14, background: tone === 'danger' ? 'rgba(239,68,68,0.08)' : 'rgba(124,58,237,0.08)', borderRadius: 8, border: `1px solid ${tone === 'danger' ? 'rgba(239,68,68,0.2)' : 'rgba(124,58,237,0.2)'}` }}>{children}</p>
)

// ── Form field primitives ────────────────────────────────────────────────────
export const Field = ({ label, children, full }) => (
  <div style={full ? { gridColumn: '1/-1' } : undefined}>
    <label style={labelStyle}>{label}</label>
    {children}
  </div>
)
export const TextInput = (props) => <input {...props} style={{ ...inputStyle, ...(props.style || {}) }} />
export const SelectInput = ({ options, pairs, ...p }) => (
  <select {...p} style={{ ...inputStyle, cursor: 'pointer' }}>
    {options.map(o => pairs ? <option key={o[0]} value={o[0]}>{o[1]}</option> : <option key={o} value={o}>{o}</option>)}
  </select>
)

export const TotalRow = ({ label, value, strong }) => (
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
    <span style={{ fontSize: strong ? 13 : 12, fontWeight: strong ? 800 : 600, color: strong ? 'var(--text-h)' : 'var(--text-muted)' }}>{label}</span>
    <span style={{ fontSize: strong ? 17 : 13, fontWeight: strong ? 900 : 700, color: strong ? '#a78bfa' : 'var(--text-h)' }}>{value}</span>
  </div>
)

export function ActBtn({ onClick, icon: Icon, color, bg, border, children }) {
  return (
    <button onClick={onClick} style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, background: bg, border: `1px solid ${border ? 'var(--border)' : color + '4d'}`, color, cursor: 'pointer', fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>
      <Icon size={12} /> {children}
    </button>
  )
}

// Generic status badge driven by a { color, bg, label } config object.
export function StatusBadge({ cfg }) {
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.color}40`, whiteSpace: 'nowrap' }}>
      {cfg.label}
    </span>
  )
}
