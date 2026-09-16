import { Navigate, useLocation, useSearchParams } from 'react-router-dom'
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

/**
 * Resolve the post-login home for a given role.
 *
 * Every role this app BLOCKS from a shell must have an answer here, and that
 * answer must be somewhere it is allowed. `vendor` had neither: it is blocked
 * from /app and it fell through to the default, so the bounce below sent it
 * straight back to the shell that had just rejected it —
 *
 *   /app/dashboard -> blocked -> homeFor('vendor') -> /app/dashboard -> ...
 *
 * which is not a wrong landing page but an infinite redirect. Three real users
 * hold that role and none of them could sign in at all.
 */
function homeFor(role) {
  // Both vendor spellings land in the same portal, which already admits both
  // (see the /vendor-portal route). A Purchase Vendor is NOT one of these — it
  // holds a PurchaseVendor token, not a User session, and lands via
  // PurchaseVendorPortalGuard instead.
  if (role === 'third_party_vendor' || role === 'vendor') return '/vendor-portal/dashboard'
  if (role === 'company') return '/company-portal/dashboard'
  // A doctor examines workers and nothing else — the internal /app shell would
  // show them a CRM they have no business in.
  if (role === 'doctor') return '/doctor-portal/dashboard'
  return '/app/dashboard'
}

/**
 * Identities that do NOT authenticate as a shared User.
 *
 * A Purchase vendor and a customer contact each hold their own token in their
 * own storage key, so being signed in as an admin says nothing about whether
 * they may enter those portals. Without this, consolidating every portal onto
 * one login page dead-ends: a signed-in admin who opens a portal link is sent
 * to /auth/login by the portal guard and immediately bounced back to
 * /app/dashboard by this one, never reaching the form that would let them in.
 */
const SEPARATE_TOKEN_ROLES = ['purchase_vendor', 'client']

export function GuestRoute({ children }) {
  const { isAuthenticated, user } = useAuth()
  const [params] = useSearchParams()

  // Show the form when the visitor is explicitly asking for an identity this
  // session cannot satisfy; otherwise a signed-in user has no business here.
  if (isAuthenticated && !SEPARATE_TOKEN_ROLES.includes(params.get('role'))) {
    return <Navigate to={homeFor(user?.role)} replace />
  }
  return children
}
