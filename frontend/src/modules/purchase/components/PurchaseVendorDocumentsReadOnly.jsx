import { purchaseApi } from '@/services/purchaseApi'
import VendorDocumentsPanel from '@/components/vendor/VendorDocumentsPanel'
import { PURCHASE_DOC_CATALOG } from '@/components/vendor/documentCatalog'

/**
 * The admin's view of a Purchase vendor's onboarding documents.
 *
 * ── Why this file is now three lines of wiring ──────────────────────────────
 * It used to render its own list, and that list was always empty. The checklist
 * endpoint answers an OBJECT — { required, extras, summary, complete } — and
 * this component read it as `Array.isArray(r) ? r : (r?.checklist ?? r?.data ?? [])`.
 * None of those keys exist, so every load fell through to `[]` and the tab drew
 * "No Documents" for every vendor, however many they had uploaded. No error, no
 * empty response, nothing in the console: the same silent-fallback failure as
 * the portal worker list. It has never shown a document.
 *
 * So rather than repair a second implementation, this hands the job to the
 * panel TPV uses, which reads the real shape. The admin gets what TPV's admin
 * gets: grouping, search and filters, preview, version history, and approve /
 * reject through a proper dialog with a mandatory reason — instead of a
 * `window.prompt()`.
 *
 * Documents are still supplied by the vendor through the portal; `manage` is
 * false, so no admin upload control is drawn here.
 *
 * @param {boolean} canReview  whether this admin may approve/reject
 */
export default function PurchaseVendorDocumentsReadOnly({ vendorId, canReview = true, onChanged }) {
  return (
    <>
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 12px' }}>
        Uploaded by the vendor through the portal during onboarding. Admins review — they do not upload.
      </p>
      <VendorDocumentsPanel
        api={purchaseApi.documentsFor(vendorId)}
        catalog={PURCHASE_DOC_CATALOG}
        vendorId={vendorId}
        manage={false}
        admin={canReview}
        reviewMode
        onChanged={onChanged}
      />
    </>
  )
}
