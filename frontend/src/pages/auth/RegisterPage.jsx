import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { X } from 'lucide-react'

const ACCOUNT_TYPES = [
  {
    section: 'THIRD PARTY VENDOR',
    sectionColor: 'text-blue-500',
    items: [
      {
        type: 'long_term_tpv',
        title: 'Long-Term TPV',
        desc: 'Full platform access with no expiry',
        badge: 'Permanent',
        badgeStyle: 'bg-blue-100 text-blue-700',
        gradient: 'from-blue-50 to-cyan-50',
        border: 'border-blue-100',
        icon: '🌐',
        iconBg: 'bg-blue-100',
        route: '/auth/register/tpv?type=permanent',
      },
      {
        type: 'temp_tpv',
        title: 'Temporary TPV',
        desc: 'Short-term or project-based access',
        badge: '5 Days Validity',
        badgeStyle: 'bg-purple-100 text-purple-700',
        gradient: 'from-purple-50 to-violet-50',
        border: 'border-purple-100',
        icon: '⏱️',
        iconBg: 'bg-purple-100',
        route: '/auth/register/tpv?type=temporary',
      },
    ],
  },
  {
    section: 'VENDOR',
    sectionColor: 'text-orange-500',
    items: [
      {
        type: 'vendor',
        title: 'Vendor',
        desc: 'Register as a vendor / supplier',
        badge: 'Vendor Access',
        badgeStyle: 'bg-orange-100 text-orange-700',
        gradient: 'from-orange-50 to-amber-50',
        border: 'border-orange-100',
        icon: '🏭',
        iconBg: 'bg-orange-100',
        route: '/auth/register/vendor?type=standard',
      },
      {
        type: 'temp_vendor',
        title: 'Temporary Vendor',
        desc: 'Short-term vendor / limited access',
        badge: 'Temporary Access',
        badgeStyle: 'bg-yellow-100 text-yellow-700',
        gradient: 'from-yellow-50 to-amber-50',
        border: 'border-yellow-100',
        icon: '⏰',
        iconBg: 'bg-yellow-100',
        route: '/auth/register/vendor?type=temporary',
      },
    ],
  },
  {
    section: 'CLIENT',
    sectionColor: 'text-teal-500',
    items: [
      {
        type: 'client',
        title: 'Client',
        desc: 'Register as a client user',
        badge: 'Client Access',
        badgeStyle: 'bg-teal-100 text-teal-700',
        gradient: 'from-teal-50 to-cyan-50',
        border: 'border-teal-100',
        icon: '👤',
        iconBg: 'bg-teal-100',
        route: '/auth/register/client',
        full: true,
      },
    ],
  },
]

export default function RegisterPage() {
  const navigate = useNavigate()

  const handleSelect = (route) => {
    navigate(route)
  }

  return (
    // Blurred backdrop (simulating login page behind)
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4"
         style={{ background: 'rgba(30,41,59,0.55)', backdropFilter: 'blur(6px)' }}>

      {/* Modal */}
      <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden animate-scale-in">

        {/* Top progress bar */}
        <div className="h-1 w-full" style={{ background: 'linear-gradient(90deg, #7c3aed, #2563eb, #06b6d4)' }} />

        {/* Close */}
        <button
          onClick={() => navigate('/auth/login')}
          className="absolute top-4 right-4 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center
                     text-gray-400 hover:bg-gray-200 hover:text-gray-700 transition-all z-10"
        >
          <X size={16} />
        </button>

        <div className="px-8 pt-7 pb-6">
          {/* Header */}
          <div className="text-center mb-6">
            <p className="text-xs font-bold tracking-widest text-blue-500 uppercase mb-1">Registration</p>
            <h2 className="text-2xl font-black text-gray-900">Sign up as...</h2>
            <p className="text-sm text-gray-400 mt-1">Choose your account type to continue</p>
          </div>

          {/* Account type sections */}
          <div className="space-y-5">
            {ACCOUNT_TYPES.map(({ section, sectionColor, items }) => (
              <div key={section}>
                {/* Section label */}
                <p className={`text-[10px] font-bold tracking-widest uppercase mb-2.5 ${sectionColor}`}>
                  — {section} —
                </p>

                {/* Cards */}
                <div className={`grid gap-3 ${items.length === 1 ? 'grid-cols-1' : 'grid-cols-2'}`}>
                  {items.map((item) => (
                    <button
                      key={item.type}
                      onClick={() => handleSelect(item.route)}
                      className={`text-left p-4 rounded-xl border-2 bg-gradient-to-br ${item.gradient} ${item.border}
                                  hover:shadow-md hover:scale-[1.02] transition-all duration-150 group
                                  ${item.full ? 'flex items-center justify-between' : ''}`}
                    >
                      <div className={`flex items-start gap-3 ${item.full ? 'flex-1' : ''}`}>
                        <div className={`w-9 h-9 rounded-xl ${item.iconBg} flex items-center justify-center
                                         text-lg flex-shrink-0`}>
                          {item.icon}
                        </div>
                        <div>
                          <p className="font-bold text-gray-800 text-sm">{item.title}</p>
                          <p className="text-xs text-gray-500 mt-0.5">{item.desc}</p>
                          <span className={`inline-block mt-2 px-2.5 py-0.5 rounded-full text-[10px] font-bold ${item.badgeStyle}`}>
                            {item.badge}
                          </span>
                        </div>
                      </div>
                      {item.full && (
                        <span className={`text-xs font-bold px-3 py-1.5 rounded-full ${item.badgeStyle} flex items-center gap-1`}>
                          Client Access <span>›</span>
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>

          {/* Footer note */}
          <div className="mt-5 flex items-center gap-2 px-4 py-3 rounded-xl text-xs text-blue-700 font-medium"
               style={{ background: '#eff6ff', border: '1px solid #bfdbfe' }}>
            <span className="text-blue-500">ℹ️</span>
            All registrations require admin approval before you can log in.
          </div>
        </div>
      </div>
    </div>
  )
}
