import { useForm } from 'react-hook-form'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { Eye, EyeOff, CheckCircle, ShieldCheck, Loader2 } from 'lucide-react'
import { useState } from 'react'
import api from '@/lib/api'

const inputCls = `w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-all
  placeholder-slate-600 border border-slate-600/50
  bg-slate-800/60 text-slate-200
  focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20`

const selectCls = `${inputCls} cursor-pointer`

function Field({ label, required, children, error }) {
  return (
    <div>
      <label className="block text-[11px] font-semibold uppercase tracking-wider mb-1"
             style={{ color: '#94a3b8' }}>
        {label}{required && <span className="text-red-400 ml-0.5">*</span>}
      </label>
      {children}
      {/* Field-level message — covers both client rules and 422s mapped back
          from the API, so the user is told exactly what to fix. */}
      {error && <p className="mt-1 text-[11px] text-red-400">{error.message}</p>}
    </div>
  )
}

const COUNTRIES = ['India', 'United States', 'United Kingdom', 'UAE', 'Singapore', 'Australia', 'Germany', 'France']

export default function TPVRegisterPage() {
  const [params] = useSearchParams()
  const type = params.get('type') || 'permanent'
  const isPermanent = type === 'permanent'
  const [showPw,  setShowPw]  = useState(false)
  const [showRpw, setShowRpw] = useState(false)
  const [apiError, setApiError] = useState('')
  const navigate = useNavigate()

  const { register, handleSubmit, setError, getValues, formState: { errors, isSubmitting } } = useForm()

  const onSubmit = async (data) => {
    setApiError('')
    try {
      await api.post('/auth/register/tpv', {
        ...data,
        tpv_type: isPermanent ? 'permanent' : 'temporary',
      })
      navigate('/auth/pending-approval')
    } catch (err) {
      // A 422 carries per-field reasons (e.g. email/username already taken).
      // Map them onto the fields instead of dropping them — otherwise the user
      // only sees "Registration failed" with no idea what to change.
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

  // Client-side rules mirror TPVRegisterRequest exactly — they catch mistakes
  // before the round-trip; they never replace the server's validation.
  const RULES = {
    first_name: { required: 'First name is required.', minLength: { value: 2, message: 'At least 2 characters.' } },
    last_name:  { required: 'Last name is required.' },
    email:      { required: 'Email is required.', pattern: { value: /^\S+@\S+\.\S+$/, message: 'Enter a valid email address.' } },
    username:   { required: 'Username is required.' },
    password:   { required: 'Password is required.', minLength: { value: 8, message: 'At least 8 characters.' } },
    password_confirmation: {
      required: 'Please confirm your password.',
      validate: (v) => v === getValues('password') || 'Passwords do not match.',
    },
  }

  return (
    <div className="min-h-screen min-h-dvh flex font-sans" style={{ background: '#0f172a' }}>

      {/* ── LEFT PANEL ───────────────────────────────────────────── */}
      <div className="hidden lg:flex w-[280px] xl:w-[320px] flex-col px-7 py-8 flex-shrink-0"
           style={{ background: 'linear-gradient(180deg, #1e3a5f 0%, #0f2744 100%)' }}>
        <div className="mb-6">
          <div className="text-xl font-black">
            <span style={{ color: '#ef4444' }}>MLA</span>
            <span className="text-white ml-1">CRM</span>
          </div>
          <div className="mt-2 flex items-center gap-1.5 px-2 py-1 rounded-md w-fit"
               style={{ background: 'rgba(59,130,246,0.15)', border: '1px solid rgba(59,130,246,0.3)' }}>
            <div className="w-4 h-4 rounded bg-blue-500 flex items-center justify-center text-[9px] font-bold text-white">S</div>
            <span className="text-xs font-bold text-blue-300">SANGOE</span>
          </div>
        </div>

        <div className="mb-5">
          <h2 className="text-xl font-black text-white leading-tight mb-1">AI-Powered<br />Enterprise OS</h2>
          <p className="text-xs text-slate-400">for Advanced Business Intelligence &<br />Vendor Relationship Management</p>
        </div>

        <ul className="space-y-2 mb-6">
          {['Unified Vendor Control Hub','AI-Driven Procurement Workflow','Real-Time Strategic Insights','Zero-Trust Security Framework','Automated Approval & Onboarding'].map(f => (
            <li key={f} className="flex items-center gap-2 text-xs text-slate-300">
              <CheckCircle size={11} className="text-blue-400 flex-shrink-0" /> {f}
            </li>
          ))}
        </ul>

        <div className="space-y-2.5">
          {[
            { icon: '⭐', title: 'AI Task Intelligence Engine', sub: 'Behavior-driven vendor prioritization', live: true },
            { icon: '📊', title: 'Executive Dashboard v2.1', sub: 'Predictive KPI & Analytics' },
            { icon: '🔒', title: 'Advanced Audit & Risk Monitor', sub: 'Compliance & Threat Alerts' },
          ].map((item, i) => (
            <div key={i} className="flex items-start gap-2 p-2.5 rounded-lg"
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
        <button className="mt-4 text-xs text-blue-400 hover:underline">Explore Solutions —</button>
      </div>

      {/* ── MAIN CONTENT ─────────────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto">

        {/* Top header */}
        <div className="text-center py-5 border-b border-slate-700/50">
          <h1 className="text-xl font-black text-white">Register</h1>
          <p className="text-xs text-slate-400 mt-1">
            Complete the form below — your account requires admin approval before login
          </p>
          <div className="flex items-center justify-center gap-2 mt-2">
            <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold
              ${isPermanent
                ? 'bg-green-500/20 text-green-300 border border-green-500/30'
                : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30'}`}>
              {isPermanent ? '🔗 Permanent Access' : '⏰ Temporary TPV'}
            </span>
            <Link to="/auth/register" className="text-xs text-slate-500 hover:text-blue-400">Change</Link>
          </div>
        </div>

        {/* Access type banner */}
        {isPermanent ? (
          <div className="mx-5 mt-4 flex items-start gap-3 px-4 py-3 rounded-xl"
               style={{ background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.25)' }}>
            <ShieldCheck size={16} className="text-green-400 flex-shrink-0 mt-0.5" />
            <p className="text-xs text-green-300">
              <span className="font-bold">PERMANENT ACCESS</span> — This account does not have an expiry.
              All actions are logged for compliance.
            </p>
          </div>
        ) : (
          <div className="mx-5 mt-4 flex items-start gap-3 px-4 py-3 rounded-xl"
               style={{ background: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.25)' }}>
            <span className="text-yellow-400 text-base flex-shrink-0">⚠️</span>
            <p className="text-xs text-yellow-300">
              <span className="font-bold">TEMPORARY ACCESS — 5 DAYS VALIDITY</span> — This account will expire 5 days
              after admin approval. Contact admin to extend.
            </p>
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="p-5">
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">

            {/* ── LEFT ── ACCOUNT USER INFORMATION ── */}
            <div className="rounded-xl p-5 space-y-4"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Account User Information</p>

              <div className="grid grid-cols-2 gap-3">
                <Field label="First Name" required error={errors.first_name}>
                  <input {...register('first_name', RULES.first_name)} placeholder="First name" className={inputCls} />
                </Field>
                <Field label="Last Name" required error={errors.last_name}>
                  <input {...register('last_name', RULES.last_name)} placeholder="Last name" className={inputCls} />
                </Field>
              </div>

              <Field label="Email Address" required error={errors.email}>
                <input {...register('email', RULES.email)} type="email"
                       placeholder="developers@nexforeconsulting.com" className={inputCls} />
              </Field>

              <Field label="Phone">
                <input {...register('phone')} placeholder="+91 9876543210" className={inputCls} />
              </Field>

              <Field label="Industry">
                <input {...register('industry')} placeholder="e.g. IT & Technology" className={inputCls} />
              </Field>

              <Field label="Position">
                <input {...register('position')} placeholder="e.g. Procurement Manager" className={inputCls} />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Password" required error={errors.password}>
                  <div className="relative">
                    <input {...register('password', RULES.password)} type={showPw ? 'text' : 'password'}
                           placeholder="••••••••" className={inputCls} />
                    <button type="button" onClick={() => setShowPw(v => !v)}
                            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                      {showPw ? <EyeOff size={13} /> : <Eye size={13} />}
                    </button>
                  </div>
                </Field>
                <Field label="Repeat Password" required error={errors.password_confirmation}>
                  <div className="relative">
                    <input {...register('password_confirmation', RULES.password_confirmation)} type={showRpw ? 'text' : 'password'}
                           placeholder="Repeat password" className={inputCls} />
                    <button type="button" onClick={() => setShowRpw(v => !v)}
                            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                      {showRpw ? <EyeOff size={13} /> : <Eye size={13} />}
                    </button>
                  </div>
                </Field>
              </div>
            </div>

            {/* ── RIGHT ── CONTACT / ADDRESS INFORMATION ── */}
            <div className="rounded-xl p-5 space-y-4"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Contact / Address Info</p>

              <Field label="Username" required error={errors.username}>
                <input {...register('username', RULES.username)} placeholder="@username" className={inputCls} />
              </Field>

              <Field label="VAT Number">
                <input {...register('vat_number')} placeholder="VAT / GST number" className={inputCls} />
              </Field>

              <Field label="Phone">
                <input {...register('alt_phone')} placeholder="Alternate phone" className={inputCls} />
              </Field>

              <Field label="Country">
                <select {...register('country')} className={selectCls}>
                  <option value="India">India</option>
                  {COUNTRIES.filter(c => c !== 'India').map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>

              <Field label="City">
                <input {...register('city')} placeholder="City" className={inputCls} />
              </Field>

              <Field label="Website">
                <input {...register('website')} placeholder="https://yourcompany.com" className={inputCls} />
              </Field>

              <Field label="Zip Code">
                <input {...register('zip')} placeholder="e.g. 411001" className={inputCls} />
              </Field>

              <Field label="State">
                <input {...register('state')} placeholder="State / Province" className={inputCls} />
              </Field>
            </div>
          </div>

          {/* Server-side error (duplicate email, validation, etc.) */}
          {apiError && (
            <div className="mt-4 px-4 py-3 rounded-xl text-sm"
                 style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', color: '#fca5a5' }}>
              {apiError}
            </div>
          )}

          {/* Terms & Submit */}
          <div className="mt-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4
                          pt-5 border-t border-slate-700/50">
            <div>
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" {...register('terms')} className="w-4 h-4 rounded accent-blue-500" />
                <span className="text-sm text-slate-300">
                  I agree to{' '}
                  <a href="#" className="text-blue-400 hover:underline font-medium">Terms & Conditions</a>
                  <span className="text-red-400 ml-0.5">*</span>
                </span>
              </label>
              <p className="text-xs text-slate-500 mt-1 pl-6">
                Already have an account?{' '}
                <Link to="/auth/login" className="text-blue-400 hover:underline">Login</Link>
              </p>
            </div>
            <button type="submit" disabled={isSubmitting}
                    className="flex items-center gap-2 px-6 py-3 rounded-xl font-bold text-sm text-white
                               transition-all hover:opacity-90 active:scale-95 flex-shrink-0"
                    style={{ background: 'linear-gradient(135deg, #7c3aed, #2563eb)' }}>
              REGISTER →
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
