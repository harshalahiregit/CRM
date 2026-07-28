import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, useNavigate } from 'react-router-dom'
import { Eye, EyeOff, CheckCircle, User, Loader2 } from 'lucide-react'
import { useState } from 'react'
import api from '@/lib/api'

const schema = z.object({
  first_name:            z.string().min(2, 'Required'),
  last_name:             z.string().min(1, 'Required'),
  email:                 z.string().email('Invalid email'),
  company:               z.string().min(2, 'Required'),
  phone:                 z.string().min(7, 'Required'),
  address:               z.string().optional(),
  city:                  z.string().optional(),
  state:                 z.string().optional(),
  country:               z.string().optional(),
  password:              z.string().min(8, 'Min 8 characters'),
  password_confirmation: z.string().min(1, 'Required'),
  terms:                 z.literal(true, { errorMap: () => ({ message: 'Accept to continue' }) }),
}).refine(d => d.password === d.password_confirmation, {
  message: "Passwords don't match",
  path: ['password_confirmation'],
})

const inputCls = `w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-all
  placeholder-slate-600 border border-slate-600/50
  bg-slate-800/60 text-slate-200
  focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20`

function Field({ label, required, error, children }) {
  return (
    <div>
      <label className="block text-[11px] font-semibold uppercase tracking-wider mb-1" style={{ color: '#94a3b8' }}>
        {label}{required && <span className="text-red-400 ml-0.5">*</span>}
      </label>
      {children}
      {error && <p className="text-[11px] text-red-400 mt-0.5">{error}</p>}
    </div>
  )
}

const COUNTRIES = ['India', 'United States', 'United Kingdom', 'UAE', 'Singapore', 'Australia', 'Germany', 'France']

export default function ClientRegisterPage() {
  const navigate = useNavigate()
  const [showPw,  setShowPw]  = useState(false)
  const [showCpw, setShowCpw] = useState(false)
  const [apiError, setApiError] = useState('')

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  const onSubmit = async (data) => {
    setApiError('')
    try {
      await api.post('/auth/register/client', {
        ...data,
        role: 'client',
      })
      navigate('/auth/login', { state: { message: 'Registration submitted! Awaiting admin approval.' } })
    } catch (err) {
      // The zod schema catches shape errors up-front; a 422 is the server's own
      // rules (e.g. email already taken). Surface those on the fields too,
      // instead of only in the banner.
      const fieldErrors = err.response?.data?.errors
      if (err.response?.status === 422 && fieldErrors) {
        Object.entries(fieldErrors).forEach(([field, messages]) => {
          setError(field, { type: 'server', message: Array.isArray(messages) ? messages[0] : String(messages) })
        })
        setApiError('Please correct the highlighted fields and try again.')
        return
      }
      setApiError(err.response?.data?.message || 'Registration failed. Please try again.')
    }
  }

  return (
    <div className="min-h-screen min-h-dvh flex font-sans" style={{ background: '#0f172a' }}>

      {/* ── LEFT PANEL ─────────────────────────────────────────── */}
      <div className="hidden lg:flex w-[300px] xl:w-[340px] flex-col px-8 py-8 flex-shrink-0"
           style={{ background: 'linear-gradient(180deg, #1e3a5f 0%, #0f2744 100%)' }}>

        {/* Logo */}
        <div className="mb-6">
          <div className="text-xl font-black tracking-tight">
            <span style={{ color: '#ef4444' }}>MLA</span>
            <span className="text-white ml-1">CRM</span>
          </div>
          <div className="mt-2 flex items-center gap-1.5 px-2 py-1 rounded-md w-fit"
               style={{ background: 'rgba(20,184,166,0.15)', border: '1px solid rgba(20,184,166,0.3)' }}>
            <div className="w-4 h-4 rounded bg-teal-500 flex items-center justify-center text-[9px] font-bold text-white">C</div>
            <span className="text-xs font-bold text-teal-300">CLIENT PORTAL</span>
          </div>
        </div>

        <div className="mb-6">
          <h2 className="text-2xl font-black text-white leading-tight mb-1">AI-Powered<br />Enterprise OS</h2>
          <p className="text-xs text-slate-400">for Advanced Business Intelligence &<br />Client Relationship Management</p>
        </div>

        <ul className="space-y-2.5 mb-8">
          {[
            'Unified Client Dashboard',
            'Real-Time Invoice Tracking',
            'Project Status Visibility',
            'Secure Document Access',
            'Direct Support Ticketing',
          ].map(f => (
            <li key={f} className="flex items-center gap-2 text-xs text-slate-300">
              <CheckCircle size={11} className="text-teal-400 flex-shrink-0" /> {f}
            </li>
          ))}
        </ul>

        {/* What's new */}
        <div className="space-y-3">
          {[
            { icon: '⭐', title: 'AI Task Intelligence Engine', sub: 'Behavior-driven task prioritization', live: true },
            { icon: '📊', title: 'Executive Dashboard v2.1',   sub: 'Predictive KPI & Analytics' },
            { icon: '🔒', title: 'Advanced Audit & Risk Monitor', sub: 'Compliance & Threat Alerts' },
          ].map((item, i) => (
            <div key={i} className="flex items-start gap-2.5 p-2.5 rounded-lg"
                 style={{ background: 'rgba(255,255,255,0.04)' }}>
              <span className="text-sm flex-shrink-0">{item.icon}</span>
              <div>
                <p className="text-[11px] font-bold text-white">{item.title}</p>
                <p className="text-[10px] text-slate-400">{item.sub}</p>
                {item.live && <span className="text-[10px] font-bold text-green-400">● LIVE NOW</span>}
              </div>
            </div>
          ))}
        </div>
        <button className="mt-4 text-xs text-teal-400 hover:underline">Explore Updates →</button>
      </div>

      {/* ── MAIN CONTENT ─────────────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto">

        {/* Top header */}
        <div className="text-center py-6 border-b border-slate-700/50">
          <h1 className="text-2xl font-black text-white">Client Registration</h1>
          <p className="text-sm text-slate-400 mt-1">
            Complete the form below — your account requires admin approval before login
          </p>
          <div className="flex items-center justify-center gap-2 mt-3">
            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold
                             bg-teal-500/20 text-teal-300 border border-teal-500/30">
              👤 Client Access
            </span>
            <Link to="/auth/register" className="text-xs text-slate-400 hover:text-teal-400 transition-colors">
              // Change
            </Link>
          </div>
        </div>

        {/* Client access info banner */}
        <div className="mx-6 mt-5 flex items-start gap-3 px-4 py-3 rounded-xl"
             style={{ background: 'rgba(20,184,166,0.08)', border: '1px solid rgba(20,184,166,0.25)' }}>
          <User size={16} className="text-teal-400 flex-shrink-0 mt-0.5" />
          <p className="text-xs text-teal-300">
            <span className="font-bold">CLIENT ACCESS</span> — As a client, you will have read-only access to your
            invoices, project status, and support tickets. All access is managed by your assigned admin.
          </p>
        </div>

        {/* API Error */}
        {apiError && (
          <div className="mx-6 mt-4 px-4 py-3 rounded-xl text-sm text-red-300 font-medium"
               style={{ background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
            {apiError}
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="p-6" noValidate>
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">

            {/* ── LEFT ── PERSONAL INFORMATION ── */}
            <div className="rounded-xl p-5 space-y-4"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Personal Information</p>

              <div className="grid grid-cols-2 gap-3">
                <Field label="First Name" required error={errors.first_name?.message}>
                  <input {...register('first_name')} placeholder="First name" className={inputCls} />
                </Field>
                <Field label="Last Name" required error={errors.last_name?.message}>
                  <input {...register('last_name')} placeholder="Last name" className={inputCls} />
                </Field>
              </div>

              <Field label="Email Address" required error={errors.email?.message}>
                <input {...register('email')} type="email" placeholder="you@company.com" className={inputCls} />
              </Field>

              <Field label="Company / Organization" required error={errors.company?.message}>
                <input {...register('company')} placeholder="Your company name" className={inputCls} />
              </Field>

              <Field label="Phone Number" required error={errors.phone?.message}>
                <div className="flex gap-2">
                  <div className="flex items-center px-3 rounded-lg text-sm text-slate-300 flex-shrink-0"
                       style={{ background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)' }}>
                    +91 🇮🇳
                  </div>
                  <input {...register('phone')} placeholder="9876543210" className={`${inputCls} flex-1`} />
                </div>
              </Field>

              <Field label="Address">
                <input {...register('address')} placeholder="Street / locality" className={inputCls} />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="City">
                  <input {...register('city')} placeholder="e.g. Pune" className={inputCls} />
                </Field>
                <Field label="State">
                  <input {...register('state')} placeholder="e.g. Maharashtra" className={inputCls} />
                </Field>
              </div>

              <Field label="Country">
                <select {...register('country')} className={`${inputCls} cursor-pointer`}>
                  <option value="">Select Country</option>
                  {COUNTRIES.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>
            </div>

            {/* ── RIGHT ── ACCOUNT SECURITY ── */}
            <div className="rounded-xl p-5 space-y-4"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Account Security</p>

              <Field label="Password" required error={errors.password?.message}>
                <div className="relative">
                  <input {...register('password')} type={showPw ? 'text' : 'password'}
                         placeholder="Min. 8 characters" className={inputCls} />
                  <button type="button" onClick={() => setShowPw(v => !v)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                    {showPw ? <EyeOff size={14} /> : <Eye size={14} />}
                  </button>
                </div>
              </Field>

              <Field label="Confirm Password" required error={errors.password_confirmation?.message}>
                <div className="relative">
                  <input {...register('password_confirmation')} type={showCpw ? 'text' : 'password'}
                         placeholder="Repeat password" className={inputCls} />
                  <button type="button" onClick={() => setShowCpw(v => !v)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                    {showCpw ? <EyeOff size={14} /> : <Eye size={14} />}
                  </button>
                </div>
              </Field>

              {/* What you get as a client */}
              <div className="mt-4 rounded-xl p-4 space-y-2.5"
                   style={{ background: 'rgba(20,184,166,0.05)', border: '1px solid rgba(20,184,166,0.15)' }}>
                <p className="text-[10px] font-bold uppercase tracking-widest text-teal-500">What you get</p>
                {[
                  'View your invoices & payment history',
                  'Track your project milestones',
                  'Raise & monitor support tickets',
                  'Access shared documents securely',
                  'Get real-time project updates',
                ].map(item => (
                  <div key={item} className="flex items-center gap-2 text-xs text-slate-300">
                    <CheckCircle size={11} className="text-teal-400 flex-shrink-0" />
                    {item}
                  </div>
                ))}
              </div>

              {/* Approval notice */}
              <div className="rounded-xl p-3 text-xs text-slate-400"
                   style={{ background: 'rgba(255,255,255,0.03)', border: '1px dashed rgba(255,255,255,0.1)' }}>
                🔔 After registration, your request will be reviewed by an admin.
                You'll receive an email once your account is activated.
              </div>
            </div>
          </div>

          {/* Terms & Submit */}
          <div className="mt-6 flex flex-col sm:flex-row items-start sm:items-center
                          justify-between gap-4 pt-5 border-t border-slate-700/50">
            <div className="space-y-1">
              <label className="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" {...register('terms')} className="w-4 h-4 rounded accent-teal-500" />
                <span className="text-sm text-slate-300">
                  I agree to the{' '}
                  <a href="#" className="text-teal-400 hover:underline font-medium">Terms & Conditions</a>
                  <span className="text-red-400 ml-0.5">*</span>
                </span>
              </label>
              {errors.terms && <p className="text-xs text-red-400 pl-6">{errors.terms.message}</p>}
              <p className="text-xs text-slate-500 pl-6">
                Already have an account?{' '}
                <Link to="/auth/login" className="text-teal-400 hover:underline">Login</Link>
              </p>
            </div>

            <button type="submit" disabled={isSubmitting}
                    className="flex items-center gap-2 px-7 py-3 rounded-xl font-bold text-sm text-white
                               transition-all hover:opacity-90 active:scale-95 flex-shrink-0 shadow-lg"
                    style={{ background: 'linear-gradient(135deg, #0d9488, #0891b2)' }}>
              {isSubmitting
                ? <><Loader2 size={15} className="animate-spin" /> Submitting...</>
                : <>REGISTER →</>
              }
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
