import { Link } from 'react-router-dom'
import { CheckCircle, Clock, Mail, ArrowLeft } from 'lucide-react'

export default function PendingApprovalPage() {
  return (
    <div className="min-h-screen min-h-dvh flex items-center justify-center p-4"
         style={{ background: '#0f172a' }}>
      <div className="w-full max-w-lg text-center space-y-6 animate-fade-in">

        {/* Icon */}
        <div className="flex justify-center">
          <div className="relative">
            <div className="w-24 h-24 rounded-full flex items-center justify-center"
                 style={{ background: 'rgba(16,185,129,0.15)', border: '2px solid rgba(16,185,129,0.3)' }}>
              <CheckCircle size={44} className="text-green-400" />
            </div>
            <div className="absolute -top-1 -right-1 w-8 h-8 rounded-full bg-orange-500/20 border border-orange-400/30
                            flex items-center justify-center">
              <Clock size={14} className="text-orange-400" />
            </div>
          </div>
        </div>

        {/* Heading */}
        <div>
          <h1 className="text-3xl font-black text-white mb-2">Registration Submitted!</h1>
          <p className="text-slate-400 text-sm leading-relaxed">
            Your account registration has been submitted successfully.<br />
            It is now <span className="text-orange-400 font-semibold">pending admin approval</span>.
          </p>
        </div>

        {/* Steps card */}
        <div className="rounded-2xl p-6 text-left space-y-4"
             style={{ background: 'rgba(255,255,255,0.04)', border: '1px solid rgba(255,255,255,0.08)' }}>
          <p className="text-xs font-bold uppercase tracking-widest text-slate-500">What happens next?</p>
          {[
            { icon: '✅', title: 'Registration Received', desc: 'Your details have been securely submitted to the admin.', done: true },
            { icon: '🔍', title: 'Admin Review', desc: 'An admin will review and verify your registration (usually within 24–48 hours).', done: false },
            { icon: '📧', title: 'Email Notification', desc: "You'll receive an email once your account is approved or if more info is needed.", done: false },
            { icon: '🚀', title: 'Access Granted', desc: 'Once approved, log in with your credentials and start using the CRM.', done: false },
          ].map((step, i) => (
            <div key={i} className="flex items-start gap-3">
              <div className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm
                ${step.done
                  ? 'bg-green-500/20 text-green-400'
                  : 'bg-slate-700/50 text-slate-400'}`}>
                {step.icon}
              </div>
              <div>
                <p className={`text-sm font-semibold ${step.done ? 'text-green-400' : 'text-white'}`}>
                  {step.title}
                </p>
                <p className="text-xs text-slate-500 mt-0.5">{step.desc}</p>
              </div>
            </div>
          ))}
        </div>

        {/* Email notice */}
        <div className="flex items-center gap-3 px-4 py-3 rounded-xl text-sm"
             style={{ background: 'rgba(37,99,235,0.1)', border: '1px solid rgba(37,99,235,0.25)' }}>
          <Mail size={16} className="text-blue-400 flex-shrink-0" />
          <p className="text-blue-300 text-left text-xs">
            Make sure to check your inbox (and spam folder) for the approval email.
          </p>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-center gap-3">
          <Link to="/auth/login"
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white
                           transition-all hover:opacity-90"
                style={{ background: 'linear-gradient(135deg, #7c3aed, #2563eb)' }}>
            <ArrowLeft size={14} /> Go to Login
          </Link>
          <Link to="/"
                className="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-400 hover:text-white
                           border border-slate-700 hover:border-slate-500 transition-all">
            Back to Home
          </Link>
        </div>
      </div>
    </div>
  )
}
