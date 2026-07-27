import { Navigate } from 'react-router-dom'
import { pvToken } from '@/lib/purchaseVendorApi'

/**
 * Route guard for the Purchase Vendor portal. Admits only a session holding the
 * Purchase-vendor token — completely independent of the shared user/vendor auth.
 */
export default function PurchaseVendorPortalGuard({ children }) {
  if (!pvToken.has()) {
    return <Navigate to="/purchase-portal/login" replace />
  }
  return children
}
