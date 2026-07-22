import { useState, useEffect } from 'react'
import api from '@/lib/api'

// Named tax rates from Settings → Tax Rates. A document line can carry several
// at once (e.g. CGST 9% + SGST 9%), so consumers need the NAME as well as the
// rate — the totals block groups the tax by name.
const FALLBACK = [
  { id: 'f-0', name: 'GST 0%', rate: 0 },
  { id: 'f-5', name: 'GST 5%', rate: 5 },
  { id: 'f-12', name: 'GST 12%', rate: 12 },
  { id: 'f-18', name: 'GST 18%', rate: 18 },
  { id: 'f-28', name: 'GST 28%', rate: 28 },
]

let cache = null   // module-level: rates change rarely; one fetch per session

/** @returns {{id:*, name:string, rate:number}[]} active tax rates */
export function useTaxRates() {
  const [rates, setRates] = useState(cache || FALLBACK)

  useEffect(() => {
    if (cache) return
    api.get('/sales/tax-rates')
      .then(r => {
        const active = (r.data || [])
          .filter(t => t.active)
          .map(t => ({ id: t.id, name: t.name, rate: Number(t.rate) }))
          .sort((a, b) => a.rate - b.rate)
        if (active.length) { cache = active; setRates(active) }
      })
      .catch(() => {})
  }, [])

  return rates
}

export const invalidateTaxRates = () => { cache = null }
