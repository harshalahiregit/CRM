import { createContext, useContext, useState } from 'react'

// Universal hide/show for financial figures (meeting 1.7): honoured on every
// money surface — including for admins. Amounts only; counts/dates/statuses
// stay visible. Purpose is avoiding accidental exposure while presenting, so
// it ALWAYS starts hidden on a fresh load/login — "show" is a per-session
// choice that resets on the next login (never persisted).
const Ctx = createContext({ moneyHidden: true, toggleMoney: () => {} })

export function MoneyVisibilityProvider({ children }) {
  const [moneyHidden, setHidden] = useState(true)   // hidden by default, every load
  const toggleMoney = () => setHidden(h => !h)

  return <Ctx.Provider value={{ moneyHidden, toggleMoney }}>{children}</Ctx.Provider>
}

export const useMoneyVisibility = () => useContext(Ctx)
