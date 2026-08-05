import { useState } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Settings2, Clock, Building, Banknote, ArrowRightLeft } from 'lucide-react'
import ShiftManagement from '../components/operations/ShiftManagement'
import WorkplaceManagement from '../components/operations/WorkplaceManagement'
import LoanManagement from '../components/operations/LoanManagement'
import EmployeeMovements from '../components/operations/EmployeeMovements'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

const TABS = [
  { key: 'shifts',    label: 'Shift Management',     icon: Clock },
  { key: 'workplace', label: 'Workplace',            icon: Building },
  { key: 'loans',     label: 'Loans & Advances',     icon: Banknote },
  { key: 'movements', label: 'Transfers & Promotions', icon: ArrowRightLeft },
]

/**
 * HR Operations — shifts, workplaces, and loans.
 *
 * Same tab shell as Payroll so the two read the same way; each tab is its own
 * component under components/operations/ rather than one 2,000-line page.
 */
export default function HrOperations() {
  useTheme()
  const [tab, setTab] = useState('shifts')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Records</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            <Settings2 size={22} style={{ color:'#a78bfa' }}/> <span className="text-gradient">HR Operations</span>
          </h1>
        </div>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15}/> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'shifts'    && <ShiftManagement showToast={showToast} />}
      {tab === 'workplace' && <WorkplaceManagement showToast={showToast} />}
      {tab === 'loans'     && <LoanManagement showToast={showToast} />}
      {tab === 'movements' && <EmployeeMovements showToast={showToast} />}
    </div>
  )
}
