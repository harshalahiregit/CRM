import { Navigate, useLocation } from 'react-router-dom'
import { pvToken } from '@/lib/purchaseVendorApi'

/**
 * Route guard for the Purchase Vendor portal. Admits only a session holding the
 * Purchase-vendor token — completely independent of the shared user/vendor auth.
 *
 * Sends anyone else to the ONE login page. The portal used to have a sign-in
 * screen of its own, so a vendor following a link could arrive at a second,
 * unfamiliar login and reasonably conclude they were in the wrong place. The
 * role is named in the query so the form arrives with the right identity
 * already chosen, and the location is carried so signing in returns them to the
 * page they actually asked for rather than a generic dashboard.
 */
export default function PurchaseVendorPortalGuard({ children }) {
  const location = useLocation()

  if (!pvToken.has()) {
    return <Navigate to="/auth/login?role=purchase_vendor" state={{ from: location }} replace />
  }
  return children
}
