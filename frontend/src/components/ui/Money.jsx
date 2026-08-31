import { Eye, EyeOff } from 'lucide-react'
import { useMoneyVisibility } from '@/context/MoneyVisibilityContext'

const fmtINR = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Renders a money amount, masked when the global hide-amounts toggle is on. */
export function Money({ value, format = fmtINR }) {
  const { moneyHidden } = useMoneyVisibility()
  return <>{moneyHidden ? '••••••' : format(value)}</>
}

/** Hook version for places that need a plain string (table cell formatters). */
export function useMoneyFmt(format = fmtINR) {
  const { moneyHidden } = useMoneyVisibility()
  return (v) => (moneyHidden ? '••••••' : format(v))
}

/**
 * The eye toggle — place on any page that shows financial figures.
 *
 * MOBILE ONLY (`md:hidden`). The top bar already carries this exact toggle, on
 * the same MoneyVisibilityContext, so on desktop the two rendered side by side
 * read as two separate settings when they are one switch with two handles.
 * The header's copy is `hidden md:flex`, i.e. it disappears below 768px — this
 * one takes over there, so the capability is never lost, just never doubled.
 *
 * The two are therefore exact complements. Change the breakpoint on either and
 * you get a gap (no toggle at all on some widths) or the duplicate back.
 */
export function MoneyToggle() {
  const { moneyHidden, toggleMoney } = useMoneyVisibility()
  const Icon = moneyHidden ? EyeOff : Eye
  return (
    <button
      onClick={toggleMoney}
      className="md:hidden w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors"
      style={{ border: '1px solid var(--border)' }}
      title={moneyHidden ? 'Show amounts' : 'Hide amounts'}
    >
      <Icon size={15} style={{ color: moneyHidden ? 'var(--accent)' : 'var(--text-muted)' }} />
    </button>
  )
}
