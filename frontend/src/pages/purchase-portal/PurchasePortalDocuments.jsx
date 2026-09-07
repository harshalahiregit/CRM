import { purchasePortalApi } from '@/services/purchasePortalApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import VendorDocumentsPanel from '@/components/vendor/VendorDocumentsPanel'
import { PURCHASE_DOC_CATALOG } from '@/components/vendor/documentCatalog'

/**
 * The Purchase vendor's own compliance documents.
 *
 * Same panel TPV uses, given the Purchase api and the Purchase catalog — see
 * VendorDocumentsPanel for why these are one component. `manage` lets the
 * vendor upload, replace and remove its own unapproved files; `admin` is false,
 * so approve and reject are not drawn and the server would refuse them anyway.
 */
export default function PurchasePortalDocuments() {
  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '0 0 4px' }}>Documents</h1>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 18px' }}>
        Upload your statutory documents. Each one is reviewed by the procurement team.
      </p>
      <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
        <VendorDocumentsPanel
          api={purchasePortalApi.documents}
          catalog={PURCHASE_DOC_CATALOG}
          manage
          admin={false}
        />
      </div>
    </div>
  )
}
