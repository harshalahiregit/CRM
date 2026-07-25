import { useMoneyVisibility } from '@/context/MoneyVisibilityContext'
import { inr as inrRaw, inrPlain as inrPlainRaw } from '@/modules/accounts/format'

/**
 * Money-visibility–aware versions of the accounts ₹ formatters. Same call
 * signature as `inr`/`inrPlain` from ./format, but they render a masked
 * placeholder while the global "hide amounts" toggle (Header eye button) is on
 * — which it is by default. Swapping `import { inr }` for `const inr = useInr()`
 * makes every existing `inr(x)` call site hide/show with zero other changes.
 */
const MASK = '••••••'

export function useInr() {
  const { moneyHidden } = useMoneyVisibility()
  return (v) => (moneyHidden ? MASK : inrRaw(v))
}

export function useInrPlain() {
  const { moneyHidden } = useMoneyVisibility()
  return (v) => (moneyHidden ? MASK : inrPlainRaw(v))
}
