/**
 * Professional salary sheet — the enterprise CTC breakdown, exactly in the order
 * Earnings → Gross → Employer Contribution → CTC → Deductions → Net (In Hand), with
 * Monthly and Yearly columns. Pure presentation: it renders the `breakdown` object
 * returned by the Salary Formula Engine (SalaryStructureService::preview / show).
 * Reused by the Salary Builder preview and the read-only Employee Profile section.
 */
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

function Line({ label, monthly, yearly, muted }) {
  return (
    <div className="flex items-center text-xs py-1.5" style={{ borderBottom: '1px dashed var(--border)' }}>
      <span className="flex-1" style={{ color: muted ? 'var(--text-muted)' : 'var(--text-h)' }}>{label}</span>
      <span className="w-28 text-right font-semibold" style={{ color: 'var(--text-h)' }}>{inr(monthly)}</span>
      <span className="w-28 text-right" style={{ color: 'var(--text-muted)' }}>{inr(yearly)}</span>
    </div>
  )
}

function Total({ label, monthly, yearly, color, strong }) {
  return (
    <div className="flex items-center py-2" style={{ borderTop: '1px solid var(--border)' }}>
      <span className={`flex-1 ${strong ? 'text-sm font-black' : 'text-xs font-bold'}`} style={{ color: color || 'var(--text-h)' }}>{label}</span>
      <span className={`w-28 text-right ${strong ? 'text-sm font-black' : 'text-xs font-bold'}`} style={{ color: color || 'var(--text-h)' }}>{inr(monthly)}</span>
      <span className={`w-28 text-right ${strong ? 'text-sm font-black' : 'text-xs font-bold'}`} style={{ color: color || 'var(--text-muted)' }}>{inr(yearly)}</span>
    </div>
  )
}

export default function SalarySheet({ breakdown, employeeName, structureName }) {
  if (!breakdown) return null
  const b = breakdown
  const has = (arr) => Array.isArray(arr) && arr.length > 0

  return (
    <div className="rounded-xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="px-4 py-3" style={{ background: 'linear-gradient(135deg,rgba(124,58,237,0.12),rgba(91,33,182,0.06))', borderBottom: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between">
          <div>
            {employeeName && <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{employeeName}</p>}
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{structureName || 'Salary Structure'}</p>
          </div>
          <div className="flex text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
            <span className="w-28 text-right">Monthly</span>
            <span className="w-28 text-right">Yearly</span>
          </div>
        </div>
      </div>

      <div className="px-4 py-2">
        {/* Earnings */}
        <p className="text-[10px] font-black uppercase tracking-wide mt-1 mb-0.5" style={{ color: '#10b981' }}>Earnings</p>
        {has(b.earnings) ? b.earnings.map((e, i) => <Line key={i} label={e.name} monthly={e.monthly} yearly={e.yearly} muted />)
          : <p className="text-[11px] py-1" style={{ color: 'var(--text-muted)' }}>No earnings.</p>}
        <Total label="Gross Salary" monthly={b.gross_salary?.monthly} yearly={b.gross_salary?.yearly} color="#10b981" />

        {/* Employer Contribution */}
        {has(b.employer) && (
          <>
            <p className="text-[10px] font-black uppercase tracking-wide mt-3 mb-0.5" style={{ color: '#3b82f6' }}>Employer Contribution</p>
            {b.employer.map((e, i) => <Line key={i} label={e.name} monthly={e.monthly} yearly={e.yearly} muted />)}
            <Total label="Employer Total" monthly={b.employer_contribution?.monthly} yearly={b.employer_contribution?.yearly} color="#3b82f6" />
          </>
        )}

        {/* CTC */}
        <Total label="Cost to Company (CTC)" monthly={b.ctc?.monthly} yearly={b.ctc?.yearly} color="#7C3AED" strong />

        {/* Deductions */}
        {has(b.deductions) && (
          <>
            <p className="text-[10px] font-black uppercase tracking-wide mt-3 mb-0.5" style={{ color: '#f87171' }}>Employee Deductions</p>
            {b.deductions.map((e, i) => <Line key={i} label={e.name} monthly={e.monthly} yearly={e.yearly} muted />)}
            <Total label="Total Deduction" monthly={b.total_deduction?.monthly} yearly={b.total_deduction?.yearly} color="#f87171" />
          </>
        )}

        {/* Net */}
        <div className="mt-1 mb-1 rounded-lg px-3" style={{ background: 'rgba(16,185,129,0.08)' }}>
          <Total label="Net Salary (In Hand)" monthly={b.net_salary?.monthly} yearly={b.net_salary?.yearly} color="#059669" strong />
        </div>
      </div>
    </div>
  )
}
