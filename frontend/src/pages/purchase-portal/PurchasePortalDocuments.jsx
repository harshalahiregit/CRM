import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import PurchaseVendorDocuments from '@/modules/purchase/components/PurchaseVendorDocuments'

// Portal documents — reuses the shared component with the own-vendor-scoped
// portal api. Vendors upload/resubmit/view; they cannot approve their own docs.
export default function PurchasePortalDocuments() {
  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '0 0 4px' }}>Documents</h1>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 18px' }}>Upload your statutory documents. Each is reviewed by the procurement team.</p>
      <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
        <PurchaseVendorDocuments api={purchasePortalApi.documents} manage admin={false} />
      </div>
    </div>
  )
}
