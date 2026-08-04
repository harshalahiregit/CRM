import { useQuery } from '@tanstack/react-query'
import { HardHat } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { tpvApi } from '@/services/tpvApi'
import { portalApi } from '@/services/portalApi'
import { canManageTpv } from '../constants'
import PpeCatalogue from '@/components/vendor/PpeCatalogue'

/**
 * PPE stock — one page, three surfaces.
 *
 * Admin uses tpvApi, a TPV vendor uses portalApi; both hit the same PPE service,
 * which reads Inventory. The vendor's routes resolve the vendor from the token,
 * so a vendor only ever sees and issues against its own workers.
 */
export default function TpvPpe() {
  const { user } = useAuth()
  // Same rule the Workforce screens use — the portal vendor is 'third_party_vendor'.
  const isPortal = user?.role === 'third_party_vendor'
  const api = isPortal ? portalApi : tpvApi
  const canIssue = isPortal || canManageTpv(user)

  // Issuing needs someone to issue to; the picker is filled from the same list
  // the Workforce page shows.
  const { data: workerData } = useQuery({
    queryKey: ['ppe-workers'],
    queryFn: () => api.workers.list({ per_page: 500 }),
    staleTime: 1000 * 60,
  })
  const workers = Array.isArray(workerData) ? workerData : (workerData?.data ?? [])

  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: '#f59e0b' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>PPE Stock</h1>
      </div>
      <p style={{ margin: '0 0 20px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Live from Inventory. Issuing here moves real stock and writes a movement — there is no separate PPE store.
      </p>

      <PpeCatalogue api={api} workers={workers} canIssue={canIssue} accent="#f59e0b" />
    </div>
  )
}
