import { createContext, useContext, useState, useCallback } from 'react'
import api from '@/lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user,   setUser]   = useState(() => { try { return JSON.parse(localStorage.getItem('crm_user'))   } catch { return null } })
  const [tenant, setTenant] = useState(() => { try { return JSON.parse(localStorage.getItem('crm_tenant')) } catch { return null } })
  const [loading, setLoading] = useState(false)

  const isAuthenticated = !!user && !!localStorage.getItem('crm_token')

  /* ── LOGIN ───────────────────────────────────────────────────────── */
  const login = useCallback(async ({ email, password, role, remember }) => {
    setLoading(true)
    try {
      const { data } = await api.post('/auth/login', { email, password, role, remember: !!remember })
      const { access_token, user: u, tenant: t } = data.data

      localStorage.setItem('crm_token',  access_token)
      localStorage.setItem('crm_user',   JSON.stringify(u))
      localStorage.setItem('crm_tenant', JSON.stringify(t))
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

      localStorage.setItem('crm_token',  access_token)
      localStorage.setItem('crm_user',   JSON.stringify(u))
      localStorage.setItem('crm_tenant', JSON.stringify(t))
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
    localStorage.removeItem('crm_token')
    localStorage.removeItem('crm_user')
    localStorage.removeItem('crm_tenant')
    setUser(null)
    setTenant(null)
  }, [])

  /* ── REFRESH USER (after profile update) ─────────────────────────── */
  const refreshUser = useCallback(async () => {
    try {
      const { data } = await api.get('/auth/me')
      const u = data.data.user
      const t = data.data.tenant
      localStorage.setItem('crm_user',   JSON.stringify(u))
      localStorage.setItem('crm_tenant', JSON.stringify(t))
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
