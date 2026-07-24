import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'

export function ProtectedRoute({ children, roles = [] }) {
  const { isAuthenticated, user } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to="/auth/login" state={{ from: location }} replace />
  }

  if (roles.length > 0 && !roles.includes(user?.role)) {
    return <Navigate to={homeFor(user?.role)} replace />
  }

  return children
}

// Resolve the post-login home for a given role.
function homeFor(role) {
  if (role === 'vendor' || role === 'third_party_vendor') return '/vendor-portal/dashboard'
  if (role === 'company') return '/company-portal/dashboard'
  return '/app/dashboard'
}

export function GuestRoute({ children }) {
  const { isAuthenticated, user } = useAuth()
  if (isAuthenticated) return <Navigate to={homeFor(user?.role)} replace />
  return children
}
