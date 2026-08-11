import { useState, useEffect } from 'react'
import { itemApi } from '@/services/itemApi'

/**
 * The saleable item catalog (Sales → Items), for the line-item picker.
 *
 * The catalog page, its API and `sales_line_items.item_id` all existed, but
 * nothing ever read the catalog — every document line was typed from scratch, so
 * maintaining Items bought you nothing. The old CRM drops the same picker into
 * every document form (invoice / estimate / credit note / proposal); this is the
 * data behind ours.
 *
 * Shape: [{ id, name, description, long_description, rate, unit, tax_rate, category }]
 * Degrades to [] if the request fails, in which case the field stays free text.
 */
let cache = null // module-level: the catalog changes rarely, one fetch per session

export function useSalesItems() {
  const [items, setItems] = useState(cache || [])

  useEffect(() => {
    if (cache) return
    itemApi.list()
      .then(page => {
        const rows = (page?.data ?? page ?? []).map(r => ({
          id: r.id,
          name: r.name,
          description: r.description || '',
          long_description: r.long_description || '',
          rate: r.rate != null ? Number(r.rate) : 0,
          unit: r.unit || 'pcs',
          tax_rate: r.tax_rate != null ? Number(r.tax_rate) : null,
          category: r.category || '',
        }))
        cache = rows
        setItems(rows)
      })
      .catch(() => { cache = [] })
  }, [])

  return items
}

/** Call after creating/editing an item so open forms pick up the change. */
export const invalidateSalesItems = () => { cache = null }

export default useSalesItems
