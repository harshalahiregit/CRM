import { NavLink, Outlet } from 'react-router-dom'
import { Mail, MailOpen, SlidersHorizontal, IndianRupee, Tags, Building2, Network, Palette, UploadCloud, ShieldCheck, Bell, Globe, Coins, Hash, Trash2, Shield, Users, Wallet, LifeBuoy, ShoppingCart, HardHat, Video} from 'lucide-react'

// Section registry — new settings pages plug in here.
//
// Grouped, because this is now the ONE Setup panel: every module's own settings
// are reachable from here as well as from inside the module. The module entries
// mount the module's OWN settings component, so the two doors can never drift
// apart — this is a second way in, not a second copy.
const SECTIONS = [
  { group: 'Workspace', label: 'General & Branding', path: 'general', icon: Palette },
  { group: 'Workspace', label: 'Localization', path: 'localization', icon: Globe },
  { group: 'Workspace', label: 'Meeting Server', path: 'meetings', icon: Video },
  { group: 'Workspace', label: 'Currency & Numbers', path: 'currency', icon: Coins },
  { group: 'Workspace', label: 'Document Numbering', path: 'numbering', icon: Hash },
  { group: 'Workspace', label: 'Custom Fields', path: 'custom-fields', icon: SlidersHorizontal },
  { group: 'Workspace', label: 'Upload', path: 'upload', icon: UploadCloud },
  { group: 'Workspace', label: 'Recycle Bin', path: 'recycle-bin', icon: Trash2 },

  { group: 'People & Access', label: 'Roles', path: 'roles', icon: Shield },
  { group: 'People & Access', label: 'Departments', path: 'departments', icon: Users },
  { group: 'People & Access', label: 'Security', path: 'security', icon: ShieldCheck },

  { group: 'Company', label: 'Company & Finance', path: 'company', icon: Building2 },
  { group: 'Company', label: 'Tax Rates', path: 'tax-rates', icon: IndianRupee },
  { group: 'Company', label: 'Expense Categories', path: 'expense-categories', icon: Tags },
  { group: 'Company', label: 'Account Groups', path: 'account-groups', icon: Network },

  { group: 'Communication', label: 'Email / SMTP', path: 'mail', icon: Mail },
  { group: 'Communication', label: 'Email Templates', path: 'email-templates', icon: MailOpen },
  { group: 'Communication', label: 'Notifications', path: 'notification-preferences', icon: Bell },

  // The module tabs the brief asked for, so a module's settings no longer need
  // their own button in their own corner of the app.
  { group: 'Modules', label: 'Accounts', path: 'modules/accounts', icon: Wallet },
  { group: 'Modules', label: 'Help Desk', path: 'modules/helpdesk', icon: LifeBuoy },
  { group: 'Modules', label: 'Purchase', path: 'modules/purchase', icon: ShoppingCart },
  { group: 'Modules', label: 'Third-Party Vendor', path: 'modules/tpv', icon: HardHat },
].map(s => ({ ...s, ready: true }))

/** Group order, kept explicit so the nav does not reorder itself. */
const GROUPS = ['Workspace', 'People & Access', 'Company', 'Communication', 'Modules']

export default function SettingsLayout() {
  return (
    // No page wrapper here on purpose. AppShell already supplies the sidebar
    // offset, header clearance, page padding and max width — adding
    // `.page-container` on top double-applied all four and pushed the whole
    // section ~260px to the right (and did not follow the sidebar collapse,
    // because that padding reads a CSS var nothing ever sets).
    <div>
      <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Workspace</p>
      <h1 className="text-2xl font-black mb-1" style={{ color: 'var(--text-h)' }}>Setup &amp; Settings</h1>
      <p className="text-sm mb-6" style={{ color: 'var(--text-muted)' }}>
        Everything configurable in one place &mdash; the workspace, who may do what, and each module&rsquo;s own settings.
      </p>

      <div className="flex flex-col md:flex-row gap-6 items-start">
        {/* Section nav — sticks under the fixed header while a long section scrolls. */}
        <nav className="w-full md:w-60 flex-shrink-0 card-3d md:sticky md:top-20" style={{ padding: '10px' }}>
          {GROUPS.map(group => {
            const items = SECTIONS.filter(s => s.group === group)
            if (!items.length) return null
            return (
              <div key={group} className="mb-2">
                <div className="px-3 pt-2 pb-1 text-[10px] font-black uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                  {group}
                </div>
                {items.map(({ label, path, icon: Icon }) => (
                  <NavLink key={path} to={path}
                    className={({ isActive }) => `flex items-center gap-2.5 px-3 py-2 rounded-xl text-[13px] font-semibold mb-0.5 transition-colors ${isActive ? '' : 'hover:bg-[rgba(124,58,237,0.06)]'}`}
                    style={({ isActive }) => isActive
                      ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
                      : { color: 'var(--text-body)' }}>
                    <Icon size={15} /> {label}
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>

        {/* Active section */}
        <div className="flex-1 min-w-0 w-full">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
