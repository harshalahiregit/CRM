import { Navigate } from 'react-router-dom'
import { clientToken } from '@/lib/clientPortalApi'

/**
 * Route guard for the customer portal. Admits only a session holding the
 * customer-contact token — independent of staff, vendor and purchase auth, so
 * being signed into the CRM in another tab grants nothing here.
 */
export default function ClientPortalGuard({ children }) {
  if (!clientToken.has()) return <Navigate to="/portal/login" replace />
  return children
}
