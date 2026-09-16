import { Navigate, useLocation } from 'react-router-dom'
import { clientToken } from '@/lib/clientPortalApi'

/**
 * Route guard for the customer portal. Admits only a session holding the
 * customer-contact token — independent of staff, vendor and purchase auth, so
 * being signed into the CRM in another tab grants nothing here.
 *
 * Sends anyone else to the ONE login page, naming the role so the form opens on
 * the right identity and carrying the location so signing in returns them to
 * the page they asked for.
 */
export default function ClientPortalGuard({ children }) {
  const location = useLocation()

  if (!clientToken.has()) {
    return <Navigate to="/auth/login?role=client" state={{ from: location }} replace />
  }
  return children
}
