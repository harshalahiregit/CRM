import { useQuery } from '@tanstack/react-query'
import { HardHat } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import PpeCatalogue from '@/components/vendor/PpeCatalogue'
import VendorPpeItemsPanel from '@/components/vendor/VendorPpeItemsPanel'

/**
 * PPE for a Purchase Vendor — parity with the TPV portal's PPE page.
 *
 * This used to be read-only on the grounds that Purchase vendors had no workers
 * to issue to. They do now (the portal registers them and takes them through
 * the five workforce steps), so issuing is on here exactly as it is for TPV:
 *
 *   - the company's PPE, read live from Inventory, issued to the vendor's own
 *     workers through the same ownership-checked route the worker wizard uses;
 *   - the vendor's OWN PPE list — kit it supplies itself, kept apart from
 *     Inventory — which it can add to, edit, and issue from.
 */
export default function PurchasePortalPpe() {
  // The picker is filled from the vendor's own roster; the server still checks
  // ownership on every issue.
  const { data: workers = [] } = useQuery({
    queryKey: ['ppe-workers', 'purchase-portal'],
    queryFn: () => purchasePortalApi.workforce.workers(),
    staleTime: 1000 * 60,
  })

  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: '#0ea5e9' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>PPE Stock</h1>
      </div>
      <p style={{ margin: '0 0 20px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Company PPE, read live from Inventory — issuing here moves real stock. Your own PPE is listed below.
      </p>

      <PpeCatalogue api={purchasePortalApi} workers={Array.isArray(workers) ? workers : []} canIssue accent="#0ea5e9" />

      <VendorPpeItemsPanel
        client={purchasePortalApi.ppe.myItems}
        scopeKey="purchase-portal"
        canManage
        workers={Array.isArray(workers) ? workers : []}
        issue={purchasePortalApi.ppe.issue}
        accent="#0ea5e9"
      />
    </div>
  )
}
