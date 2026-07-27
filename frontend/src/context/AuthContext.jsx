import { createContext, useContext, useState, useCallback } from 'react'
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

  return (
    <AuthContext.Provider value={{
      user, tenant, loading,
      isAuthenticated,
      login, register, logout, refreshUser,
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
