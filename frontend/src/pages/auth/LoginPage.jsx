import { useState } from 'react'
import { Link, useNavigate, useLocation } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Eye, EyeOff, ChevronDown, Shield, Zap, Globe, Lock, CheckCircle, User, Star } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'

const ROLES = [
  { value: 'admin',               label: 'Admin',                icon: '🛡️' },
  { value: 'vendor',              label: 'Vendor',               icon: '🏭' },
  { value: 'third_party_vendor',  label: 'Third-Party Vendor',   icon: '🤝' },
  { value: 'client',              label: 'Client / Customer',    icon: '👤' },
]

const schema = z.object({
  role:     z.string().min(1, 'Please select a role'),
  email:    z.string().email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean().optional(),
})

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
  const from      = location.state?.from?.pathname || '/app/dashboard'

  const [showPw,    setShowPw]    = useState(false)
  const [roleOpen,  setRoleOpen]  = useState(false)
  const [apiError,  setApiError]  = useState('')
  const [selRole,   setSelRole]   = useState(null)

  const { register, handleSubmit, setValue, watch, formState: { errors, isSubmitting } } = useForm({
    resolver: zodResolver(schema),
    defaultValues: { role: '', email: '', password: '', remember: false },
  })

  const watchedRole = watch('role')
  const selectedRoleObj = ROLES.find(r => r.value === watchedRole)

  const onSubmit = async (values) => {
    setApiError('')
    const result = await login(values)
    if (result.success) {
      if (result.role === 'third_party_vendor') navigate('/vendor-portal/app/dashboard', { replace: true })
      else navigate(from, { replace: true })
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
      <div className="relative flex-1 hidden md:flex flex-col px-10 py-8 overflow-hidden"
           style={{ background: 'linear-gradient(135deg, #c8f7e8 0%, #d4f0f7 40%, #cce8f4 70%, #d8eefa 100%)' }}>

        {/* Floating geometric shapes */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          {[
            { top:'8%',  left:'65%', size:60,  rotate:15,  opacity:0.18 },
            { top:'18%', left:'78%', size:40,  rotate:45,  opacity:0.13 },
            { top:'38%', left:'70%', size:80,  rotate:30,  opacity:0.10 },
            { top:'55%', left:'60%', size:50,  rotate:20,  opacity:0.15 },
            { top:'70%', left:'75%', size:35,  rotate:60,  opacity:0.12 },
            { top:'82%', left:'55%', size:65,  rotate:10,  opacity:0.10 },
            { top:'5%',  left:'40%', size:30,  rotate:40,  opacity:0.14 },
            { top:'28%', left:'50%', size:45,  rotate:25,  opacity:0.08 },
          ].map((s, i) => (
            <svg key={i} className="absolute" style={{ top: s.top, left: s.left, opacity: s.opacity }}
                 width={s.size} height={s.size} viewBox="0 0 60 60">
              <polygon points="30,2 58,58 2,58" fill="none" stroke="#0ea5e9"
                       strokeWidth="2" transform={`rotate(${s.rotate} 30 30)`} />
            </svg>
          ))}
          {/* Diamond shapes */}
          {[
            { top:'15%', left:'30%', opacity:0.12 },
            { top:'60%', left:'20%', opacity:0.10 },
            { top:'45%', left:'42%', opacity:0.09 },
          ].map((d, i) => (
            <svg key={`d${i}`} className="absolute" style={{ top: d.top, left: d.left, opacity: d.opacity }}
                 width="30" height="30" viewBox="0 0 30 30">
              <rect x="5" y="5" width="20" height="20" fill="#06b6d4" transform="rotate(45 15 15)" />
            </svg>
          ))}
        </div>

        {/* Logo */}
        <div className="relative z-10 mb-8">
          <div className="flex items-center gap-2 mb-3">
            <span className="text-2xl font-black tracking-tight"
                  style={{ color: '#dc2626' }}>MLA</span>
            <span className="text-2xl font-black tracking-tight text-gray-800">CRM</span>
          </div>
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border"
                style={{ background: 'rgba(16,185,129,0.12)', borderColor: '#10b981', color: '#059669' }}>
            <span className="w-1.5 h-1.5 rounded-full bg-green-500" />
            Next-Gen Intelligence Platform
          </span>
        </div>

        {/* Hero text */}
        <div className="relative z-10 mb-6">
          <h1 className="text-4xl font-black leading-tight text-gray-800 mb-1">
            AI-Powered
          </h1>
          <h1 className="text-4xl font-black leading-tight mb-2"
              style={{ background: 'linear-gradient(135deg, #7c3aed, #2563eb)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>
            Enterprise OS
          </h1>
          <p className="text-sm text-gray-500">for Advanced Business Intelligence</p>
        </div>

        {/* Features */}
        <ul className="relative z-10 space-y-2.5 mb-8">
          {FEATURES.map(f => (
            <li key={f} className="flex items-center gap-2.5 text-sm text-gray-700 font-medium">
              <div className="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0"
                   style={{ background: 'linear-gradient(135deg, #10b981, #059669)' }}>
                <CheckCircle size={12} className="text-white" />
              </div>
              {f}
            </li>
          ))}
        </ul>

        {/* What's New card */}
        <div className="relative z-10 rounded-2xl border border-white/60 overflow-hidden"
             style={{ background: 'rgba(255,255,255,0.55)', backdropFilter: 'blur(12px)' }}>
          <div className="flex items-center justify-between px-4 py-2.5 border-b border-white/40">
            <span className="text-xs font-bold text-gray-600 uppercase tracking-wider">What's new in MLA CRM</span>
            <button className="text-xs font-semibold flex items-center gap-1"
                    style={{ color: '#2563eb' }}>
              Explore Updates →
            </button>
          </div>
          <div className="grid grid-cols-3 divide-x divide-white/40">
            {WHATS_NEW.map((item, i) => (
              <div key={i} className="px-3 py-3">
                <div className="text-lg mb-1">{item.icon}</div>
                <p className="text-xs font-bold text-gray-800 leading-snug">{item.title}</p>
                <p className="text-[10px] text-gray-500 mt-0.5">{item.sub}</p>
                {item.badge && (
                  <span className={`text-[10px] font-bold mt-1 block ${item.badgeColor}`}>
                    ● {item.badge}
                  </span>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* ISO Badges */}
        <div className="relative z-10 mt-auto pt-6 flex items-center gap-3 flex-wrap">
          {['ISO 9001', 'ISO 27001', 'ISO 42001'].map(iso => (
            <span key={iso} className="text-xs font-bold text-gray-500 border border-gray-300 px-2 py-1 rounded">{iso}</span>
          ))}
        </div>
      </div>

      {/* ── RIGHT PANEL ──────────────────────────────────────────── */}
      <div className="w-full md:w-[420px] lg:w-[460px] bg-white flex flex-col justify-center px-8 py-10 shadow-2xl">

        {/* Auth Portal label */}
        <div className="flex items-center gap-2 mb-3">
          <span className="w-2 h-2 rounded-full bg-green-500" />
          <span className="text-xs font-bold tracking-widest uppercase text-gray-400">Auth Portal V4.2</span>
        </div>

        <h2 className="text-3xl font-black text-gray-900 mb-1">Access Command</h2>
        <p className="text-sm text-gray-400 mb-7">Authenticate to enter your workspace</p>

        {/* API Error */}
        {apiError && (
          <div className="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-600 text-sm">
            {apiError}
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>

          {/* Role Dropdown */}
          <div>
            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">
              Select Access Role
            </label>
            <div className="relative">
              <button
                type="button"
                onClick={() => setRoleOpen(o => !o)}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg border-2 text-sm
                            bg-white transition-all duration-150 text-left
                            ${errors.role ? 'border-red-400' : 'border-gray-200 hover:border-blue-400'}
                            ${roleOpen ? 'border-blue-500 ring-2 ring-blue-100' : ''}`}
              >
                <span className="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0 text-base">
                  {selectedRoleObj ? selectedRoleObj.icon : <User size={14} className="text-gray-400" />}
                </span>
                <span className={`flex-1 ${selectedRoleObj ? 'text-gray-800 font-medium' : 'text-gray-400'}`}>
                  {selectedRoleObj ? selectedRoleObj.label : 'Choose your access role...'}
                </span>
                <ChevronDown size={16} className={`text-gray-400 transition-transform ${roleOpen ? 'rotate-180' : ''}`} />
              </button>
              <input type="hidden" {...register('role')} />

              {/* Dropdown list */}
              {roleOpen && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200
                                rounded-xl shadow-xl z-50 overflow-hidden animate-fade-in">
                  {ROLES.map(role => (
                    <button
                      key={role.value}
                      type="button"
                      onClick={() => selectRole(role)}
                      className={`w-full flex items-center gap-3 px-4 py-3 text-sm text-left transition-colors
                                  hover:bg-blue-50 hover:text-blue-700
                                  ${watchedRole === role.value ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-700'}`}
                    >
                      <span className="text-base">{role.icon}</span>
                      {role.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
            {errors.role && <p className="text-xs text-red-500 mt-1">{errors.role.message}</p>}
          </div>

          {/* Email */}
          <div>
            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">
              Email Address
            </label>
            <input
              type="email"
              placeholder="developers@nexforeconsulting.com"
              className={`w-full px-4 py-3 rounded-lg border-2 text-sm text-gray-800 bg-white
                          placeholder-gray-300 outline-none transition-all
                          ${errors.email ? 'border-red-400' : 'border-gray-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100'}`}
              {...register('email')}
            />
            {errors.email && <p className="text-xs text-red-500 mt-1">{errors.email.message}</p>}
          </div>

          {/* Password */}
          <div>
            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">
              Password
            </label>
            <div className="relative">
              <input
                type={showPw ? 'text' : 'password'}
                placeholder="••••••••••••"
                className={`w-full px-4 py-3 pr-11 rounded-lg border-2 text-sm text-gray-800 bg-white
                            placeholder-gray-300 outline-none transition-all
                            ${errors.password ? 'border-red-400' : 'border-gray-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100'}`}
                {...register('password')}
              />
              <button type="button" onClick={() => setShowPw(v => !v)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                {showPw ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            {errors.password && <p className="text-xs text-red-500 mt-1">{errors.password.message}</p>}
          </div>

          {/* Remember me */}
          <label className="flex items-center gap-2.5 cursor-pointer">
            <input type="checkbox" className="w-4 h-4 rounded accent-blue-600" {...register('remember')} />
            <span className="text-sm text-gray-500">Remember me</span>
          </label>

          {/* Login Button */}
          <button
            type="submit"
            disabled={isSubmitting}
            className="w-full py-3.5 rounded-xl text-white font-bold text-sm tracking-widest uppercase
                       transition-all duration-200 hover:opacity-90 active:scale-[0.98] shadow-lg"
            style={{ background: 'linear-gradient(135deg, #7c3aed 0%, #2563eb 100%)' }}
          >
            {isSubmitting ? 'Authenticating...' : 'LOGIN'}
          </button>
        </form>

        {/* Links */}
        <div className="flex items-center justify-between mt-4">
          <Link to="/auth/forgot-password"
                className="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-600">
            <Lock size={12} /> Forgot Password?
          </Link>
          <Link to="/auth/register"
                className="flex items-center gap-1.5 text-xs font-semibold hover:underline"
                style={{ color: '#7c3aed' }}>
            <Star size={12} /> Register here —
          </Link>
        </div>

        {/* Last login bar */}
        <div className="mt-5 flex items-center gap-2 px-3 py-2.5 rounded-lg text-xs"
             style={{ background: '#1e293b', color: '#94a3b8' }}>
          <span className="w-1.5 h-1.5 rounded-full bg-blue-400 flex-shrink-0" />
          <span>Last login:</span>
          <span className="text-white font-medium flex items-center gap-1">
            📍 Pune, Maharashtra
          </span>
          <span className="flex items-center gap-1">
            <Globe size={11} /> Chrome
          </span>
        </div>

        {/* Brand footer */}
        <div className="mt-4 text-center space-y-2">
          <div className="flex items-center justify-center gap-2 text-xs text-gray-400">
            <span className="font-semibold text-blue-600">MLA Perfex CRM</span>
            <span>·</span>
            <span>Secure Enterprise Build</span>
          </div>
          <div className="flex items-center justify-center gap-4 text-[10px] text-gray-400">
            <span className="flex items-center gap-1">🔒 ENCRYPTED</span>
            <span className="flex items-center gap-1">🛡️ PROTECTED</span>
            <span className="flex items-center gap-1">⚡ INSTANT ACCESS</span>
          </div>
          <div className="flex items-center justify-center gap-3 text-[10px] text-gray-400">
            <a href="#" className="hover:underline">Terms</a>
            <a href="#" className="hover:underline">Privacy</a>
            <a href="#" className="hover:underline">Support</a>
          </div>
          <p className="text-[10px] text-gray-300">© 2024 MLA CRM. All rights reserved.</p>
        </div>
      </div>
    </div>
  )
}
