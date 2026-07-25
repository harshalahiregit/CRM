import { useState, useEffect } from 'react'
import { accountsApi } from '@/services/accountsApi'

/**
 * HSN/SAC master codes from Accounts → Settings → HSN/SAC, used as the single
 * source for the line-item HSN picker. Each code may carry a default GST rate,
 * which the line-items table uses to auto-fill a line's tax.
 *
 * Shape: [{ code, description, type, rate, rateName }]. `rate` is null when the
 * code has no default GST rate configured. Degrades to [] when Accounts isn't
 * set up for the tenant (the field then behaves as plain free text).
 */
let cache = null // module-level: codes change rarely; one fetch per session

export function useHsnSac() {
  const [codes, setCodes] = useState(cache || [])

  useEffect(() => {
    if (cache) return
    accountsApi.hsnSac.list()
      .then(page => {
        const rows = (page?.data ?? page ?? [])
          .filter(r => r.is_active !== false)
          .map(r => ({
            code: r.code,
            description: r.description || '',
            type: r.type,
            rate: r.default_rate?.rate_percent != null ? Number(r.default_rate.rate_percent) : null,
            rateName: r.default_rate?.name || null,
          }))
        cache = rows
        setCodes(rows)
      })
      .catch(() => { cache = []; })
  }, [])

  return codes
}

export const invalidateHsnSac = () => { cache = null }
