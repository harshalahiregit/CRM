import { useState, useEffect } from 'react'
import { Link, useNavigate, useLocation, useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Eye, EyeOff, ChevronDown, Shield, Zap, Globe, Lock, CheckCircle, User, Star } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import sangoeFull from '@/assets/sangoe-full.png'

/**
 * A Purchase Vendor is a PurchaseVendor record, not a User, so it cannot go
 * through the shared /api/auth/login — a User token would never pass
 * EnsurePurchaseVendorPortalAccess. The option below is therefore marked
 * `purchaseVendor` and submitted against /api/purchase-vendor/login instead,
 * storing its token under the portal's own key. Same door, different lock.
 */
const ROLES = [
  { value: 'admin',               label: 'Admin',                icon: '🛡️' },
  { value: 'staff',               label: 'Staff / Employee',     icon: '👔' },
  { value: 'purchase_vendor',     label: 'Purchase Vendor',      icon: '📦', purchaseVendor: true },
  { value: 'third_party_vendor',  label: 'Third-Party Vendor',   icon: '🤝' },
  { value: 'client',              label: 'Client / Customer',    icon: '👤' },
  { value: 'company',             label: 'Company',              icon: '🏢' },
]

const schema = z.object({
  role:     z.string().min(1, 'Please select a role'),
  email:    z.string().email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean().optional(),
})

// Post-login home when the user came to /login directly (no email deep-link).
const roleHome = (role) =>
  role === 'company' ? '/company-portal/dashboard'
  : role === 'third_party_vendor' ? '/vendor-portal/dashboard'
  : '/app/dashboard'

// ── Left Panel Features ───────────────────────────────────────────────
const FEATURES = [
  'Unified Control Hub',
  'AI-Driven Automation Workflow',
  'Real-Time Strategic Insights',
  'Zero-Trust Security Framework',
]

const WHATS_NEW = [
  { icon: '⭐', title: 'AI Task Intelligence Engine', sub: 'Behavior-driven task prioritization', badge: 'LIVE NOW', badgeColor: 'text-green-400' },
  { icon: '📊', title: 'Executive Dashboard v2.1',    sub: 'Predictive KPI & Analytics',         badge: null },
  { icon: '🔒', title: 'Advanced Audit & Risk Monitor', sub: 'Compliance & Threat Alerts',       badge: null },
]

export default function LoginPage() {
  const { login } = useAuth()
  const navigate  = useNavigate()
  const location  = useLocation()
  // The page the email/deep-link pointed at, preserved by ProtectedRoute as
  // location.state.from (pathname + query + hash). Only same-origin internal
  // paths are honoured — never an off-site or protocol-relative URL.
  const fromLoc   = location.state?.from
  const fromPath  = fromLoc ? `${fromLoc.pathname}${fromLoc.search || ''}${fromLoc.hash || ''}` : null
  const safeFrom  = fromPath && fromPath.startsWith('/') && !fromPath.startsWith('//') ? fromPath : null

  const [showPw,    setShowPw]    = useState(false)
  const [roleOpen,  setRoleOpen]  = useState(false)
  const [apiError,  setApiError]  = useState('')
  const [selRole,   setSelRole]   = useState(null)

  const { register, handleSubmit, setValue, watch, formState: { errors, isSubmitting } } = useForm({
    resolver: zodResolver(schema),
    defaultValues: { role: '', email: '', password: '', remember: false },
  })

  const watchedRole = watch('role')

  // Activation and invitation emails land here with ?role=... so the recipient
  // does not have to guess which of six they are. Those emails previously
  // pointed at /vendor-portal/login, which is not a route — a vendor following
  // their own activation link got a 404 and could not get in at all.
  const [searchParams] = useSearchParams()
  useEffect(() => {
    const asked = searchParams.get('role')
    if (asked && ROLES.some((r) => r.value === asked)) {
      setValue('role', asked, { shouldValidate: false })
    }
  }, [searchParams]) // eslint-disable-line react-hooks/exhaustive-deps
  const selectedRoleObj = ROLES.find(r => r.value === watchedRole)
  const isPurchaseVendor = Boolean(selectedRoleObj?.purchaseVendor)

  const onSubmit = async (values) => {
    setApiError('')

    // Purchase Vendors authenticate against their own endpoint and carry a
    // PurchaseVendor token, so they never touch the shared User login.
    if (selectedRoleObj?.purchaseVendor) {
      try {
        await purchaseVendorAuthApi.login(values.email, values.password)
        navigate(safeFrom || '/purchase-portal/dashboard', { replace: true })
      } catch (e) {
        setApiError(e?.response?.data?.message || 'Invalid credentials.')
      }
      return
    }

    const result = await login(values)
    if (result.success) {
      // Every role returns to the email's target page when there is one; a
      // direct login (no deep-link) falls back to that role's home. The route
      // guards still redirect if the target isn't allowed for the role.
      navigate(safeFrom || roleHome(result.role), { replace: true })
    } else {
      setApiError(result.message)
    }
  }

  const selectRole = (role) => {
    setValue('role', role.value, { shouldValidate: true })
    setSelRole(role)
    setRoleOpen(false)
  }

  return (
    <div className="min-h-screen min-h-dvh flex flex-col md:flex-row font-sans">

      {/* ── LEFT PANEL ───────────────────────────────────────────── */}
      <div className="relative flex-1 hidden md:flex flex-col px-10 py-8 overflow-hidden bg-grid"
           style={{ background: '#0b0b16' }}>

        {/* Purple glow + grid background */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute inset-0 bg-grid" style={{ backgroundImage: 'linear-gradient(rgba(124,58,237,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(124,58,237,0.04) 1px, transparent 1px)', backgroundSize: '40px 40px' }} />
          <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[400px]" style={{ background: 'radial-gradient(ellipse, rgba(124,58,237,0.18) 0%, transparent 65%)' }} />
          <div className="absolute bottom-10 right-10 w-[300px] h-[300px]" style={{ background: 'radial-gradient(ellipse, rgba(124,58,237,0.08) 0%, transparent 70%)' }} />
          {/* Floating orbit circles */}
          {[{s:200,t:'20%',l:'60%',o:0.06},{s:120,t:'50%',l:'40%',o:0.05},{s:80,t:'75%',l:'65%',o:0.07}].map((c,i)=>(
            <div key={i} className="absolute rounded-full border animate-spin-slow" style={{ width:c.s, height:c.s, top:c.t, left:c.l, borderColor:`rgba(124,58,237,${c.o})`, animationDuration:`${18+i*6}s` }} />
          ))}
        </div>

        {/* Logo */}
        <div className="relative z-10 mb-10">
          <div className="mb-4">
            {/* Full Sangoe wordmark on a white chip so the dark logo stays legible
                on the dark panel */}
            <div className="inline-flex items-center bg-white rounded-2xl px-4 py-3 shadow-xl">
              <img src={sangoeFull} alt="Sangoe CRM Software" className="w-52 h-auto" />
            </div>
          </div>
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold"
                style={{ background: 'rgba(16,185,129,0.12)', border: '1px solid rgba(16,185,129,0.3)', color: '#10b981' }}>
            <span className="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse" />
            Business Growth Operating System
          </span>
        </div>

        {/* Hero text */}
        <div className="relative z-10 mb-8">
          <h1 className="font-black leading-tight mb-2" style={{ fontSize: 'clamp(2rem,3.5vw,3rem)', letterSpacing: '-0.03em', color: '#edeaf8' }}>
            From Chaos<br/>to{' '}
            <span style={{ background: 'linear-gradient(135deg,#a78bfa,#7C3AED,#c4b5fd)', WebkitBackgroundClip:'text', WebkitTextFillColor:'transparent', backgroundClip:'text' }}>Clarity</span>
          </h1>
          <p className="text-sm" style={{ color: '#8b85a8', lineHeight: 1.7 }}>The complete Business Growth OS — from MSME to IPO-ready governance</p>
        </div>

        {/* Features */}
        <ul className="relative z-10 space-y-3 mb-8">
          {FEATURES.map(f => (
            <li key={f} className="flex items-center gap-3 text-sm font-medium" style={{ color: '#edeaf8' }}>
              <div className="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0" style={{ background: 'linear-gradient(135deg,#7C3AED,#6d28d9)' }}>
                <CheckCircle size={11} className="text-white" />
              </div>
              {f}
            </li>
          ))}
        </ul>

        {/* What's New card */}
        <div className="relative z-10 rounded-2xl overflow-hidden" style={{ background: 'rgba(28,28,46,0.8)', border: '1px solid rgba(124,58,237,0.2)', backdropFilter: 'blur(12px)' }}>
          <div className="flex items-center justify-between px-4 py-2.5" style={{ borderBottom: '1px solid rgba(124,58,237,0.15)' }}>
            <span className="text-xs font-bold uppercase tracking-wider label-caps" style={{ color: '#8b85a8' }}>What's new in Sangoe</span>
            <button className="text-xs font-semibold" style={{ color: '#a78bfa' }}>Explore →</button>
          </div>
          <div className="grid grid-cols-3" style={{ divideColor: 'rgba(124,58,237,0.1)' }}>
            {WHATS_NEW.map((item, i) => (
              <div key={i} className="px-3 py-3" style={{ borderRight: i < 2 ? '1px solid rgba(124,58,237,0.1)' : 'none' }}>
                <div className="text-lg mb-1">{item.icon}</div>
                <p className="text-xs font-bold leading-snug" style={{ color: '#edeaf8' }}>{item.title}</p>
                <p className="text-[10px] mt-0.5" style={{ color: '#8b85a8' }}>{item.sub}</p>
                {item.badge && (
                  <span className={`text-[10px] font-bold mt-1 block ${item.badgeColor}`}>● {item.badge}</span>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Trust Pills */}
        <div className="relative z-10 mt-auto pt-6 flex items-center gap-2 flex-wrap">
          {[{l:'SSL Secured',c:'#10b981'},{l:'GDPR Compliant',c:'#a855f7'},{l:'SOC 2',c:'#7c3aed'},{l:'Made in India',c:'#f97316'}].map(b=>(
            <span key={b.l} className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold hover-lift" style={{ border:`1px solid ${b.c}40`, color: b.c, background: `${b.c}10` }}>{b.l}</span>
          ))}
        </div>
      </div>

      {/* ── RIGHT PANEL ──────────────────────────────────────────── */}
      <div className="w-full md:w-[420px] lg:w-[460px] flex flex-col justify-center px-8 py-10" style={{ background: '#0e0e1a', borderLeft: '1px solid rgba(124,58,237,0.15)' }}>

        {/* Auth Portal label */}
        <div className="flex items-center gap-2 mb-4">
          <span className="w-2 h-2 rounded-full bg-purple-400 animate-pulse" />
          <span className="text-xs font-bold tracking-widest uppercase" style={{ color: '#8b85a8' }}>Auth Portal V4.2</span>
        </div>

        <h2 className="text-3xl font-black mb-1" style={{ color: '#edeaf8', letterSpacing: '-0.02em' }}>Access Command</h2>
        <p className="text-sm mb-7" style={{ color: '#8b85a8' }}>Authenticate to enter your workspace</p>

        {/* API Error */}
        {apiError && (
          <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}>
            {apiError}
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>

          {/* Role Dropdown */}
          <div>
            <label className="block text-[10px] font-bold uppercase tracking-widest mb-1.5" style={{ color: '#8b85a8', letterSpacing: '0.06em' }}>Select Access Role</label>
            <div className="relative">
              <button type="button" onClick={() => setRoleOpen(o => !o)}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm text-left transition-all duration-200
                  ${errors.role ? 'border-red-500/50' : roleOpen ? 'border-purple-500/60' : 'border-white/08'}`}
                style={{ background: 'rgba(255,255,255,0.04)', border: `1px solid ${errors.role ? 'rgba(239,68,68,0.4)' : roleOpen ? 'rgba(124,58,237,0.5)' : 'rgba(255,255,255,0.08)'}`, boxShadow: roleOpen ? '0 0 0 3px rgba(124,58,237,0.1)' : 'none' }}
              >
                <span className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-base" style={{ background: 'rgba(124,58,237,0.15)' }}>
                  {selectedRoleObj ? selectedRoleObj.icon : <User size={13} style={{ color: '#8b85a8' }} />}
                </span>
                <span className="flex-1 text-sm" style={{ color: selectedRoleObj ? '#edeaf8' : '#8b85a8' }}>
                  {selectedRoleObj ? selectedRoleObj.label : 'Choose your access role...'}
                </span>
                <ChevronDown size={15} className={`transition-transform duration-200 ${roleOpen ? 'rotate-180' : ''}`} style={{ color: '#8b85a8' }} />
              </button>
              <input type="hidden" {...register('role')} />
              {roleOpen && (
                <div className="absolute top-full left-0 right-0 mt-1.5 rounded-xl overflow-hidden z-50 animate-fade-in" style={{ background: '#1c1c2e', border: '1px solid rgba(124,58,237,0.25)', boxShadow: '0 20px 40px rgba(0,0,0,0.5), 0 0 30px rgba(124,58,237,0.1)' }}>
                  {ROLES.map(role => (
                    <button key={role.value} type="button" onClick={() => selectRole(role)}
                      className="w-full flex items-center gap-3 px-4 py-3 text-sm text-left transition-all duration-150"
                      style={{ background: watchedRole === role.value ? 'rgba(124,58,237,0.18)' : 'transparent', color: watchedRole === role.value ? '#c4b5fd' : '#8b85a8', borderBottom: '1px solid rgba(255,255,255,0.04)' }}
                      onMouseEnter={e => { if(watchedRole !== role.value) e.currentTarget.style.background = 'rgba(124,58,237,0.08)'; e.currentTarget.style.color = '#edeaf8' }}
                      onMouseLeave={e => { if(watchedRole !== role.value) e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = watchedRole === role.value ? '#c4b5fd' : '#8b85a8' }}
                    >
                      <span className="text-base">{role.icon}</span>
                      <span className="font-medium">{role.label}</span>
                      {watchedRole === role.value && <CheckCircle size={13} className="ml-auto text-purple-400" />}
                    </button>
                  ))}
                </div>
              )}
            </div>
            {errors.role && <p className="text-xs mt-1" style={{ color: '#f87171' }}>{errors.role.message}</p>}
          </div>

          {/* Email */}
          <div>
            <label className="block text-[10px] font-bold uppercase mb-1.5" style={{ color: '#8b85a8', letterSpacing: '0.06em' }}>Email Address</label>
            <input type="email" placeholder="you@company.com"
              className="w-full px-4 py-3 rounded-xl text-sm outline-none transition-all duration-200"
              style={{ background: 'rgba(255,255,255,0.04)', border: `1px solid ${errors.email ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.08)'}`, color: '#edeaf8' }}
              onFocus={e => { e.target.style.borderColor='rgba(124,58,237,0.5)'; e.target.style.background='rgba(124,58,237,0.06)'; e.target.style.boxShadow='0 0 0 3px rgba(124,58,237,0.1)' }}
              onBlur={e => { e.target.style.borderColor=errors.email?'rgba(239,68,68,0.4)':'rgba(255,255,255,0.08)'; e.target.style.background='rgba(255,255,255,0.04)'; e.target.style.boxShadow='none' }}
              {...register('email')}
            />
            {errors.email && <p className="text-xs mt-1" style={{ color: '#f87171' }}>{errors.email.message}</p>}
          </div>

          {/* Password */}
          <div>
            <label className="block text-[10px] font-bold uppercase mb-1.5" style={{ color: '#8b85a8', letterSpacing: '0.06em' }}>Password</label>
            <div className="relative">
              <input type={showPw ? 'text' : 'password'} placeholder="••••••••••••"
                className="w-full px-4 py-3 pr-11 rounded-xl text-sm outline-none transition-all duration-200"
                style={{ background: 'rgba(255,255,255,0.04)', border: `1px solid ${errors.password ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.08)'}`, color: '#edeaf8' }}
                onFocus={e => { e.target.style.borderColor='rgba(124,58,237,0.5)'; e.target.style.background='rgba(124,58,237,0.06)'; e.target.style.boxShadow='0 0 0 3px rgba(124,58,237,0.1)' }}
                onBlur={e => { e.target.style.borderColor=errors.password?'rgba(239,68,68,0.4)':'rgba(255,255,255,0.08)'; e.target.style.background='rgba(255,255,255,0.04)'; e.target.style.boxShadow='none' }}
                {...register('password')}
              />
              <button type="button" onClick={() => setShowPw(v => !v)}
                className="absolute right-3 top-1/2 -translate-y-1/2 transition-colors" style={{ color: '#8b85a8' }}
                onMouseEnter={e=>e.target.style.color='#edeaf8'} onMouseLeave={e=>e.target.style.color='#8b85a8'}>
                {showPw ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            {errors.password && <p className="text-xs mt-1" style={{ color: '#f87171' }}>{errors.password.message}</p>}
          </div>

          {/* Remember me */}
          <label className="flex items-center gap-2.5 cursor-pointer">
            <input type="checkbox" className="w-4 h-4 rounded" style={{ accentColor: '#7C3AED' }} {...register('remember')} />
            <span className="text-sm" style={{ color: '#8b85a8' }}>Remember me</span>
          </label>

          {/* Login Button */}
          <button type="submit" disabled={isSubmitting}
            className="w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase transition-all duration-200 active:scale-[0.98]"
            style={{ background: isSubmitting ? 'rgba(124,58,237,0.5)' : 'linear-gradient(135deg,#7C3AED,#6d28d9)', letterSpacing: '0.06em', boxShadow: '0 4px 20px rgba(124,58,237,0.45)' }}
            onMouseEnter={e => { if(!isSubmitting){ e.target.style.boxShadow='0 6px 30px rgba(124,58,237,0.65)'; e.target.style.transform='translateY(-1px)' }}}
            onMouseLeave={e => { e.target.style.boxShadow='0 4px 20px rgba(124,58,237,0.45)'; e.target.style.transform='translateY(0)' }}
          >
            {isSubmitting ? '🔐 Authenticating...' : 'LOGIN'}
          </button>
        </form>

        {/* Links */}
        <div className="flex items-center justify-between mt-4">
          {/* A Purchase Vendor registers and resets against its own portal —
              the shared pages create a User, which is the wrong identity. */}
          <Link to={isPurchaseVendor ? '/purchase-portal/forgot-password' : '/auth/forgot-password'}
            className="flex items-center gap-1.5 text-xs transition-colors" style={{ color: '#8b85a8' }}
            onMouseEnter={e=>e.currentTarget.style.color='#edeaf8'} onMouseLeave={e=>e.currentTarget.style.color='#8b85a8'}>
            <Lock size={12} /> Forgot Password?
          </Link>
          <Link to={isPurchaseVendor ? '/purchase-portal/register' : '/auth/register'}
            className="flex items-center gap-1.5 text-xs font-semibold transition-colors" style={{ color: '#a78bfa' }}
            onMouseEnter={e=>e.currentTarget.style.color='#c4b5fd'} onMouseLeave={e=>e.currentTarget.style.color='#a78bfa'}>
            <Star size={12} /> Register here →
          </Link>
        </div>

        {/* Last login bar */}
        <div className="mt-5 flex items-center gap-2 px-3 py-2.5 rounded-xl text-xs" style={{ background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.15)', color: '#8b85a8' }}>
          <span className="w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ background: '#a78bfa' }} />
          <span>Last login:</span>
          <span className="font-medium flex items-center gap-1" style={{ color: '#edeaf8' }}>📍 Pune, Maharashtra</span>
          <span className="flex items-center gap-1 ml-auto"><Globe size={11} /> Chrome</span>
        </div>

        {/* Brand footer */}
        <div className="mt-4 text-center space-y-2">
          <div className="flex items-center justify-center gap-2 text-xs" style={{ color: '#8b85a8' }}>
            <span className="font-bold" style={{ color: '#a78bfa' }}>Sangoe CRM</span>
            <span>·</span>
            <span>Business Growth OS</span>
          </div>
          <div className="flex items-center justify-center gap-4 text-[10px]" style={{ color: '#8b85a8' }}>
            <span>🔒 ENCRYPTED</span>
            <span>🛡️ PROTECTED</span>
            <span>⚡ INSTANT</span>
          </div>
          <div className="flex items-center justify-center gap-3 text-[10px]" style={{ color: '#8b85a8' }}>
            <a href="#" className="hover:underline">Terms</a>
            <a href="#" className="hover:underline">Privacy</a>
            <a href="#" className="hover:underline">Support</a>
          </div>
          <p className="text-[10px]" style={{ color: '#8b85a8' }}>© 2025 Sangoe. All rights reserved.</p>
        </div>
      </div>
    </div>
  )
}
