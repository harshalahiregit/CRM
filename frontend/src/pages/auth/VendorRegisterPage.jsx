import { useForm } from 'react-hook-form'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { Eye, EyeOff, AlertTriangle, CheckCircle, Loader2 } from 'lucide-react'
import { useState } from 'react'
import api from '@/lib/api'

// ── Reusable Field ────────────────────────────────────────────────────
function Field({ label, required, error, children }) {
  return (
    <div>
      <label className="block text-[11px] font-semibold uppercase tracking-wider mb-1"
             style={{ color: '#94a3b8' }}>
        {label}{required && <span className="text-red-400 ml-0.5">*</span>}
      </label>
      {children}
      {error && <p className="text-xs text-red-400 mt-0.5">{error}</p>}
    </div>
  )
}

const inputCls = `w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-all
  placeholder-slate-600 border border-slate-600/50
  bg-slate-800/60 text-slate-200
  focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20`

const selectCls = `${inputCls} cursor-pointer`

const COUNTRIES = ['India', 'United States', 'United Kingdom', 'UAE', 'Singapore', 'Australia']
const CATEGORIES = ['IT & Technology', 'Manufacturing', 'Trading', 'Logistics', 'Consulting', 'Finance', 'Healthcare', 'Other']
const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+']

export default function VendorRegisterPage() {
  const [params] = useSearchParams()
  const type = params.get('type') || 'standard'
  const isTemporary = type === 'temporary'
  const [showPw, setShowPw]     = useState(false)
  const [showCpw, setShowCpw]   = useState(false)
  const [apiError, setApiError]  = useState('')
  const navigate = useNavigate()

  const { register, handleSubmit, setError, getValues, formState: { errors, isSubmitting } } = useForm()

  // Mirrors VendorRegisterRequest exactly — catches mistakes before the
  // round-trip without replacing the server's validation.
  const RULES = {
    company_name: { required: 'Company name is required.', minLength: { value: 2, message: 'At least 2 characters.' } },
    category:     { required: 'Please select a category.' },
    first_name:   { required: 'First name is required.', minLength: { value: 2, message: 'At least 2 characters.' } },
    last_name:    { required: 'Last name is required.' },
    email:        { required: 'Email is required.', pattern: { value: /^\S+@\S+\.\S+$/, message: 'Enter a valid email address.' } },
    password:     { required: 'Password is required.', minLength: { value: 8, message: 'At least 8 characters.' } },
    password_confirmation: {
      required: 'Please confirm your password.',
      validate: (v) => v === getValues('password') || 'Passwords do not match.',
    },
    terms:        { required: 'Please accept the terms to continue.' },
  }

  const onSubmit = async (data) => {
    setApiError('')
    try {
      await api.post('/auth/register/vendor', {
        ...data,
        vendor_type: isTemporary ? 'temporary' : 'standard',
        password_confirmation: data.password_confirmation,
      })
      navigate('/auth/pending-approval')
    } catch (err) {
      // A 422 carries per-field reasons (e.g. email already taken). Map them
      // onto the fields instead of dropping them.
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

      {/* ── LEFT PANEL (same as login) ─────────────────────────── */}
      <div className="hidden lg:flex w-[300px] xl:w-[340px] flex-col px-8 py-8 flex-shrink-0"
           style={{ background: 'linear-gradient(180deg, #1e3a5f 0%, #0f2744 100%)' }}>

        {/* Logo */}
        <div className="mb-6">
          <div className="text-xl font-black tracking-tight">
            <span style={{ color: '#ef4444' }}>MLA</span>
            <span className="text-white ml-1">CRM</span>
          </div>
          <div className="mt-2 flex items-center gap-1.5 px-2 py-1 rounded-md w-fit"
               style={{ background: 'rgba(59,130,246,0.15)', border: '1px solid rgba(59,130,246,0.3)' }}>
            <div className="w-5 h-5 rounded bg-blue-500 flex items-center justify-center text-[10px] font-bold text-white">S</div>
            <span className="text-xs font-bold text-blue-300">SANGOE</span>
          </div>
        </div>

        <div className="mb-6">
          <h2 className="text-2xl font-black text-white leading-tight mb-1">AI-Powered<br />Enterprise OS</h2>
          <p className="text-xs text-slate-400">for Advanced Business Intelligence &<br />Vendor Relationship Management</p>
        </div>

        <ul className="space-y-2.5 mb-8">
          {['Unified Vendor Control Hub','AI-Driven Procurement Workflow','Real-Time Strategic Insights','Zero-Trust Security Framework','Automated Approval & Onboarding'].map(f => (
            <li key={f} className="flex items-center gap-2 text-xs text-slate-300">
              <div className="w-4 h-4 rounded-full bg-blue-500/30 flex items-center justify-center flex-shrink-0">
                <CheckCircle size={10} className="text-blue-400" />
              </div>
              {f}
            </li>
          ))}
        </ul>

        {/* What's new */}
        <div className="space-y-3">
          {[
            { icon: '⭐', title: 'AI Task Intelligence Engine', sub: 'Behavior-driven vendor prioritization', live: true },
            { icon: '📊', title: 'Executive Dashboard v2.1', sub: 'Predictive KPI & Analytics' },
            { icon: '🔒', title: 'Advanced Audit & Risk Monitor', sub: 'Compliance & Threat Alerts' },
          ].map((item, i) => (
            <div key={i} className="flex items-start gap-2.5 p-2.5 rounded-lg"
                 style={{ background: 'rgba(255,255,255,0.04)' }}>
              <span className="text-base flex-shrink-0">{item.icon}</span>
              <div>
                <p className="text-xs font-bold text-white leading-snug">{item.title}</p>
                <p className="text-[10px] text-slate-400">{item.sub}</p>
                {item.live && <span className="text-[10px] font-bold text-green-400">● LIVE NOW</span>}
              </div>
            </div>
          ))}
        </div>

        <button className="mt-4 text-xs text-blue-400 hover:underline text-center">Explore Updates —</button>
      </div>

      {/* ── MAIN CONTENT ─────────────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto">

        {/* Top header */}
        <div className="text-center py-6 border-b border-slate-700/50">
          <h1 className="text-2xl font-black text-white">Vendor Registration</h1>
          <p className="text-sm text-slate-400 mt-1">
            Complete the form below — your account requires admin approval before login
          </p>
          {/* Type badge */}
          <div className="flex items-center justify-center gap-2 mt-3">
            <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold
              ${isTemporary
                ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30'
                : 'bg-blue-500/20 text-blue-300 border border-blue-500/30'}`}>
              {isTemporary ? '⏰' : '🔗'} {isTemporary ? 'Temporary Vendor' : 'Long Term Vendor'}
            </span>
            <span className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold
              ${isTemporary ? 'bg-yellow-500/20 text-yellow-400' : 'bg-green-500/20 text-green-400'}`}>
              {isTemporary ? '5-day access' : 'Permanent access'}
            </span>
            <Link to="/auth/register" className="text-xs text-slate-400 hover:text-blue-400">// Change</Link>
          </div>
        </div>

        {/* Temporary warning banner */}
        {isTemporary && (
          <div className="mx-6 mt-5 flex items-start gap-3 px-4 py-4 rounded-xl"
               style={{ background: 'rgba(234,179,8,0.1)', border: '1px solid rgba(234,179,8,0.3)' }}>
            <AlertTriangle size={18} className="text-yellow-400 flex-shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-bold text-yellow-300">Temporary Vendor Account — Limited Access</p>
              <p className="text-xs text-yellow-400/80 mt-0.5">
                This account will be valid for 5 days only from the date of admin approval.
                After expiry, access is revoked automatically. Contact your administrator for help and information.
              </p>
              <button className="mt-2 text-xs font-semibold text-yellow-300 border border-yellow-400/40
                                 px-3 py-1 rounded-lg hover:bg-yellow-400/10 transition-colors">
                ⚙ Setup access period
              </button>
            </div>
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="p-6">
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">

            {/* ── LEFT COLUMN ── */}
            <div className="space-y-4 rounded-xl p-5"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-3">Company Information</p>

              <Field label="Company Name" required error={errors.company_name?.message}>
                <input {...register('company_name', RULES.company_name)} placeholder="Enter registered company name"
                       className={inputCls} />
              </Field>

              <Field label="Category" required error={errors.category?.message}>
                <select {...register('category', RULES.category)} className={selectCls}>
                  <option value="">Nature of Business</option>
                  {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </Field>

              <Field label="Website">
                <input {...register('website')} placeholder="https://yourcompany.com" className={inputCls} />
              </Field>

              <Field label="Manpower Strength" required>
                <select {...register('manpower')} className={selectCls}>
                  <option value="">Select company size</option>
                  {COMPANY_SIZES.map(s => <option key={s} value={s}>{s} employees</option>)}
                </select>
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Company Phone" required>
                  <input {...register('company_phone')} placeholder="022-00000000" className={inputCls} />
                </Field>
                <Field label="Company Anniversary" required>
                  <input {...register('company_anniversary')} type="date" placeholder="dd-mm-yyyy" className={inputCls} />
                </Field>
              </div>

              <Field label="Address">
                <input {...register('address')} placeholder="Street / building / locality" className={inputCls} />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="City" required>
                  <input {...register('city')} placeholder="e.g. Pune" className={inputCls} />
                </Field>
                <Field label="PIN / Zipcode" required>
                  <input {...register('pincode')} placeholder="e.g. 411001" className={inputCls} />
                </Field>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <Field label="State">
                  <input {...register('state')} placeholder="e.g. Maharashtra" className={inputCls} />
                </Field>
                <Field label="Country">
                  <select {...register('country')} className={selectCls}>
                    <option value="">Select Country</option>
                    {COUNTRIES.map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                </Field>
              </div>

              {/* MSME */}
              <div className="pt-2 border-t border-slate-700/50">
                <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-2">MSME Registration</p>
                <div className="flex items-center gap-4">
                  <span className="text-xs text-slate-300">Registered under MSME?</span>
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" value="yes" {...register('msme')} className="accent-blue-500" />
                    <span className="text-xs text-slate-300">Yes</span>
                  </label>
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" value="no" {...register('msme')} className="accent-blue-500" defaultChecked />
                    <span className="text-xs text-slate-300">No</span>
                  </label>
                </div>
              </div>
            </div>

            {/* ── RIGHT COLUMN ── */}
            <div className="space-y-4 rounded-xl p-5"
                 style={{ background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.07)' }}>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-3">Primary Contact Information</p>

              <div className="grid grid-cols-2 gap-3">
                <Field label="First Name" required error={errors.first_name?.message}>
                  <input {...register('first_name', RULES.first_name)} placeholder="First name" className={inputCls} />
                </Field>
                <Field label="Last Name" required error={errors.last_name?.message}>
                  <input {...register('last_name', RULES.last_name)} placeholder="Last name" className={inputCls} />
                </Field>
              </div>

              <Field label="Designation / Position" required>
                <input {...register('designation')} placeholder="e.g. Procurement Manager" className={inputCls} />
              </Field>

              <Field label="Email Address" required error={errors.email?.message}>
                <input {...register('email', RULES.email)} type="email"
                       placeholder="developers@nexforeconsulting.com" className={inputCls} />
              </Field>

              <Field label="Phone Number" required>
                <div className="flex gap-2">
                  <div className="flex items-center px-3 rounded-lg text-sm text-slate-300 flex-shrink-0"
                       style={{ background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.12)' }}>
                    +91 🇮🇳
                  </div>
                  <input {...register('phone')} placeholder="9876543210" className={`${inputCls} flex-1`} />
                </div>
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Password" required error={errors.password?.message}>
                  <div className="relative">
                    <input {...register('password', RULES.password)} type={showPw ? 'text' : 'password'}
                           placeholder="••••••••" className={inputCls} />
                    <button type="button" onClick={() => setShowPw(v => !v)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200">
                      {showPw ? <EyeOff size={14} /> : <Eye size={14} />}
                    </button>
                  </div>
                </Field>
                <Field label="Confirm Password" required error={errors.password_confirmation?.message}>
                  <div className="relative">
                    <input {...register('password_confirmation', RULES.password_confirmation)} type={showCpw ? 'text' : 'password'}
                           placeholder="Repeat password" className={inputCls} />
                    <button type="button" onClick={() => setShowCpw(v => !v)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200">
                      {showCpw ? <EyeOff size={14} /> : <Eye size={14} />}
                    </button>
                  </div>
                </Field>
              </div>

              <Field label="Date of Birth" required>
                <input {...register('dob')} type="date" placeholder="dd-mm-yyyy" className={inputCls} />
              </Field>
            </div>
          </div>

          {/* Terms & Submit */}
          <div className="mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4
                          pt-5 border-t border-slate-700/50">
            <div className="space-y-1">
              <label className="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" {...register('terms', RULES.terms)}
                       className="w-4 h-4 rounded accent-blue-500" />
                <span className="text-sm text-slate-300">
                  I agree to the{' '}
                  <a href="#" className="text-blue-400 hover:underline font-medium">Terms & Conditions</a>
                  <span className="text-red-400 ml-0.5">*</span>
                </span>
              </label>
              <p className="text-xs text-slate-500 pl-6.5">
                Already have an account?{' '}
                <Link to="/purchase-portal/login" className="text-blue-400 hover:underline">Login</Link>
              </p>
            </div>

            <button type="submit" disabled={isSubmitting}
                    className="flex items-center gap-2 px-6 py-3 rounded-xl font-bold text-sm
                               text-white transition-all hover:opacity-90 active:scale-95 flex-shrink-0"
                    style={{ background: 'linear-gradient(135deg, #10b981, #059669)' }}>
              {isSubmitting ? 'Registering...' : 'REGISTER'} →
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
