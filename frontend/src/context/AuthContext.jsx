import { createContext, useContext, useState, useCallback, useEffect } from 'react'
import api from '@/lib/api'
import { setAuth, getToken, getUser, getTenant, clearAuth, updateUserTenant } from '@/lib/authStorage'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user,   setUser]   = useState(() => getUser())
  const [tenant, setTenant] = useState(() => getTenant())
  const [loading, setLoading] = useState(false)

  const isAuthenticated = !!user && !!getToken()

  /* ── LOGIN ───────────────────────────────────────────────────────── */
  const login = useCallback(async ({ email, password, role, remember }) => {
    setLoading(true)
    try {
      const { data } = await api.post('/auth/login', { email, password, role, remember: !!remember })
      const { access_token, user: u, tenant: t } = data.data

      // "Remember me" → localStorage (persists); otherwise sessionStorage (this tab only).
      setAuth({ token: access_token, user: u, tenant: t, remember: !!remember })
      setUser(u)
      setTenant(t)

      return { success: true, role: u.role }
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.message || 'Login failed. Please try again.',
      }
    } finally {
      setLoading(false)
    }
  }, [])

  /* ── REGISTER (Admin / Tenant owner) ─────────────────────────────── */
  const register = useCallback(async (payload) => {
    setLoading(true)
    try {
      const { data } = await api.post('/auth/register', payload)
      const { access_token, user: u, tenant: t } = data.data

      // A brand-new registration persists by default.
      setAuth({ token: access_token, user: u, tenant: t, remember: true })
      setUser(u)
      setTenant(t)

      return { success: true }
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.message || 'Registration failed.',
      }
    } finally {
      setLoading(false)
    }
  }, [])

  /* ── LOGOUT ──────────────────────────────────────────────────────── */
  const logout = useCallback(async () => {
    try { await api.post('/auth/logout') } catch { /* ignore if token already invalid */ }
    clearAuth()
    setUser(null)
    setTenant(null)
  }, [])

  /* ── REFRESH USER (after profile update) ─────────────────────────── */
  const refreshUser = useCallback(async () => {
    try {
      const { data } = await api.get('/auth/me')
      const u = data.data.user
      const t = data.data.tenant
      updateUserTenant(u, t)
      setUser(u)
      setTenant(t)
    } catch { /* silent fail */ }
  }, [])

  /* ── ON LOAD: refresh identity from the server ───────────────────────
     The cached user/tenant (name, tenant name + subdomain, role) comes from
     the last login and is otherwise never updated. Refreshing /auth/me once on
     mount — whenever a token exists — keeps the sidebar's company name and
     everything else in sync with the database without forcing a re-login.
     Silent-fails offline so it never blocks the app or logs anyone out. */
  useEffect(() => {
    if (getToken()) refreshUser()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /* ── WHAT THIS PERSON MAY DO ──────────────────────────────────────────
     Resolved on the server and sent with the user, because the screens cannot
     work it out. They were guessing from `role`, which is why the sidebar
     rendered every HR management item for everybody and each one 403'd when
     clicked — and no guess could have been right anyway: the advances gate asks
     whether anyone REPORTS to you, which is a database question.

     Fails CLOSED. An older cached user has no `permissions` key, and answering
     "yes, probably" there would put management screens in front of people who
     will only be refused by the server a moment later. /auth/me refreshes on
     mount, so the closed state lasts until that returns. */
  const perms = user?.permissions

  const can = useCallback((module, capability) =>
    !!perms?.can?.[module]?.includes(capability), [perms])

  // 'global' | 'own' | null — what WIDTH of a module they see. This is the
  // distinction that decides whether somebody gets the management screen or
  // their own records, so it is answered directly rather than inferred.
  const scopeOf = useCallback((module) => perms?.scope?.[module] ?? null, [perms])

  const canSee = useCallback((module) => scopeOf(module) !== null, [scopeOf])

  return (
    <AuthContext.Provider value={{
      user, tenant, loading,
      isAuthenticated,
      login, register, logout, refreshUser,
      can, scopeOf, canSee,
      isAdmin: !!perms?.is_admin,
    }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
