import { HardHat } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import PpeCatalogue from '@/components/vendor/PpeCatalogue'

/**
 * PPE stock for a Purchase Vendor — read-only.
 *
 * Purchase vendors have no workers of their own, so there is nobody to issue to;
 * the value here is visibility of what is in stock. The figures come from the
 * same Inventory-backed endpoints the TPV side uses.
 */
export default function PurchasePortalPpe() {
  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <HardHat size={19} style={{ color: '#0ea5e9' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>PPE Stock</h1>
      </div>
      <p style={{ margin: '0 0 20px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Current PPE availability, read live from Inventory.
      </p>

      <PpeCatalogue api={purchasePortalApi} canIssue={false} accent="#0ea5e9" />
    </div>
  )
}
