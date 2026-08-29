import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'

export function ProtectedRoute({ children, roles = [], blockRoles = [] }) {
  const { isAuthenticated, user } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to="/auth/login" state={{ from: location }} replace />
  }

  // Denylist: portal-only roles must never reach the internal /app shell, even by
  // typing the URL. They are bounced to their own portal home.
  if (blockRoles.length > 0 && blockRoles.includes(user?.role)) {
    return <Navigate to={homeFor(user?.role)} replace />
  }

  if (roles.length > 0 && !roles.includes(user?.role)) {
    return <Navigate to={homeFor(user?.role)} replace />
  }

  return children
}

// Resolve the post-login home for a given role.
function homeFor(role) {
  // TPV only. A Purchase Vendor never reaches here — it holds a PurchaseVendor
  // token, not a User session, and lands via PurchaseVendorPortalGuard instead.
  if (role === 'third_party_vendor') return '/vendor-portal/dashboard'
  if (role === 'company') return '/company-portal/dashboard'
  return '/app/dashboard'
}

export function GuestRoute({ children }) {
  const { isAuthenticated, user } = useAuth()
  if (isAuthenticated) return <Navigate to={homeFor(user?.role)} replace />
  return children
}
