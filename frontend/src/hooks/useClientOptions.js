import { useState, useEffect } from 'react'
import { fetchClientOptions } from '@/services/customerApi'

/**
 * Shared list of customers for dropdowns across modules (invoices, estimates,
 * etc.). Replaces the old hardcoded `salesApi.clients: []`. Returns
 * [{ id, name, company }].
 */
export function useClientOptions() {
  const [clients, setClients] = useState([])

  useEffect(() => {
    let alive = true
    fetchClientOptions().then(list => { if (alive) setClients(list) })
    return () => { alive = false }
  }, [])

  return clients
}
