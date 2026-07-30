import { NavLink, Outlet } from 'react-router-dom'
import { Mail, MailOpen, SlidersHorizontal, IndianRupee, Tags, Building2, Network, Palette, UploadCloud, ShieldCheck, Bell, Globe, Coins, Hash } from 'lucide-react'

// Section registry — new settings pages plug in here (tax rates, expense
// categories, company/finance land with their tracks).
const SECTIONS = [
  { label: 'General & Branding', path: 'general', icon: Palette, ready: true },
  { label: 'Localization', path: 'localization', icon: Globe, ready: true },
  { label: 'Currency & Numbers', path: 'currency', icon: Coins, ready: true },
  { label: 'Document Numbering', path: 'numbering', icon: Hash, ready: true },
  { label: 'Email / SMTP', path: 'mail', icon: Mail, ready: true },
  { label: 'Email Templates', path: 'email-templates', icon: MailOpen, ready: true },
  { label: 'Custom Fields', path: 'custom-fields', icon: SlidersHorizontal, ready: true },
  { label: 'Company & Finance', path: 'company', icon: Building2, ready: true },
  { label: 'Tax Rates', path: 'tax-rates', icon: IndianRupee, ready: true },
  { label: 'Expense Categories', path: 'expense-categories', icon: Tags, ready: true },
  { label: 'Account Groups', path: 'account-groups', icon: Network, ready: true },
  { label: 'Upload', path: 'upload', icon: UploadCloud, ready: true },
  { label: 'Security', path: 'security', icon: ShieldCheck, ready: true },
  { label: 'Notifications', path: 'notification-preferences', icon: Bell, ready: true },
]

export default function SettingsLayout() {
  return (
    // No page wrapper here on purpose. AppShell already supplies the sidebar
    // offset, header clearance, page padding and max width — adding
    // `.page-container` on top double-applied all four and pushed the whole
    // section ~260px to the right (and did not follow the sidebar collapse,
    // because that padding reads a CSS var nothing ever sets).
    <div>
      <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Workspace</p>
      <h1 className="text-2xl font-black mb-6" style={{ color: 'var(--text-h)' }}>Settings</h1>

      <div className="flex flex-col md:flex-row gap-6 items-start">
        {/* Section nav — sticks under the fixed header while a long section scrolls. */}
        <nav className="w-full md:w-56 flex-shrink-0 card-3d md:sticky md:top-20" style={{ padding: '10px' }}>
          {SECTIONS.map(({ label, path, icon: Icon, ready }) => (
            ready ? (
              <NavLink key={path} to={path}
                className={({ isActive }) => `flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold mb-0.5 transition-colors ${isActive ? '' : 'hover:bg-[rgba(124,58,237,0.06)]'}`}
                style={({ isActive }) => isActive
                  ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
                  : { color: 'var(--text-body)' }}>
                <Icon size={15} /> {label}
              </NavLink>
            ) : (
              <div key={path} className="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold mb-0.5 opacity-45 cursor-not-allowed" style={{ color: 'var(--text-muted)' }} title="Coming soon">
                <Icon size={15} /> {label}
                <span className="ml-auto text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>SOON</span>
              </div>
            )
          ))}
        </nav>

        {/* Active section */}
        <div className="flex-1 min-w-0 w-full">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
