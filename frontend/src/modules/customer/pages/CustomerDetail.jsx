import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Building2, Phone, Receipt, Wallet, CreditCard, Mail, User,
  Globe, Linkedin, Facebook, Instagram, Twitter, Calendar, LifeBuoy,
  Package, Users2, UserPlus, Link2,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const TABS = ['Overview', 'Contacts', 'Tax', 'Support', 'Related']

export default function CustomerDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const toast = useToast()

  const [client, setClient] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('Overview')
  const [tax, setTax] = useState(null)
  const [tickets, setTickets] = useState(null)

  useEffect(() => {
    customerApi.get(id)
      .then(setClient)
      .catch(e => { toast.error(e.message); nav('/app/customers') })
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => {
    if (tab === 'Tax' && !tax) customerApi.taxSummary(id).then(setTax).catch(() => setTax({}))
    if (tab === 'Support' && !tickets) customerApi.tickets(id).then(setTickets).catch(() => setTickets([]))
  }, [tab])

  if (loading) return <div className="p-6"><div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} /></div>
  if (!client) return null

  const fin = client.financials || {}
  const social = client.social_links || {}
  const socialLinks = [
    { k: 'website', url: client.website, Icon: Globe },
    { k: 'linkedin', url: social.linkedin, Icon: Linkedin },
    { k: 'facebook', url: social.facebook, Icon: Facebook },
    { k: 'instagram', url: social.instagram, Icon: Instagram },
    { k: 'twitter', url: social.twitter, Icon: Twitter },
  ].filter(s => s.url)

  // Basic-info block (top of profile) — per spec: name, balance, pending credit, GST, phone.
  const INFO = [
    { label: 'Outstanding Balance', value: fmt(fin.outstanding), icon: Wallet, color: '#ef4444' },
    { label: 'Available Credit', value: fmt(fin.available_credit), icon: CreditCard, color: '#10b981' },
    { label: 'GST Number', value: client.gst_number || '—', icon: Receipt, color: '#7C3AED', raw: true },
    { label: 'Phone', value: client.phone || '—', icon: Phone, color: '#3b82f6', raw: true },
  ]

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {/* Back + header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/app/customers')} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div className="w-12 h-12 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Building2 size={22} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.2rem,2vw,1.6rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{client.company}</h1>
            <div className="flex items-center gap-2 mt-0.5">
              <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: client.active ? 'rgba(16,185,129,0.1)' : 'rgba(148,163,184,0.12)', color: client.active ? '#10b981' : '#94a3b8' }}>
                {client.active ? 'Active' : 'Inactive'}
              </span>
              {client.parent_company && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Parent: <b style={{ color: 'var(--text-h)' }}>{client.parent_company}</b></span>}
            </div>
          </div>
        </div>
        {socialLinks.length > 0 && (
          <div className="flex items-center gap-1.5">
            {socialLinks.map(({ k, url, Icon }) => (
              <a key={k} href={url.startsWith('http') ? url : `https://${url}`} target="_blank" rel="noreferrer"
                className="w-8 h-8 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <Icon size={14} style={{ color: 'var(--text-muted)' }} />
              </a>
            ))}
          </div>
        )}
      </div>

      {/* Basic-info KPI block */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {INFO.map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="card-3d flex items-center gap-3" style={{ padding: '18px' }}>
            <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ background: `${color}1a` }}>
              <Icon size={18} style={{ color }} />
            </div>
            <div className="min-w-0">
              <p className="font-black truncate" style={{ color: 'var(--text-h)', fontSize: '1.1rem' }}>{value}</p>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</p>
            </div>
          </div>
        ))}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 flex-wrap p-1 rounded-2xl w-fit" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        {TABS.map(t => (
          <button key={t} onClick={() => setTab(t)} className="px-4 py-2 rounded-xl text-xs font-bold transition-all"
            style={{ background: tab === t ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent', color: tab === t ? '#fff' : 'var(--text-muted)' }}>
            {t}
          </button>
        ))}
      </div>

      {/* ── Overview ── */}
      {tab === 'Overview' && (
        <div className="grid md:grid-cols-2 gap-4">
          <div className="card-3d" style={{ padding: '20px' }}>
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Company Details</p>
            <Row label="Company" value={client.company} />
            <Row label="GST Number" value={client.gst_number || '—'} />
            <Row label="Phone" value={client.phone || '—'} />
            <Row label="Website" value={client.website || '—'} />
            <Row label="Parent Company" value={client.parent_company || '—'} />
            <Row label="Registered Address" value={[client.address, client.city, client.state, client.zip, client.country].filter(Boolean).join(', ') || '—'} />
          </div>
          <div className="card-3d" style={{ padding: '20px' }}>
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Key Dates & Billing</p>
            <Row label="Founded" value={client.foundation_date || '—'} icon={Calendar} />
            <Row label="Date of Birth" value={client.dob || '—'} icon={Calendar} />
            <Row label="Anniversary" value={client.anniversary_date || '—'} icon={Calendar} />
            <Row label="Billing Address" value={[client.billing_street, client.billing_city, client.billing_state, client.billing_zip].filter(Boolean).join(', ') || '—'} />
            <Row label="Shipping Address" value={[client.shipping_street, client.shipping_city, client.shipping_state, client.shipping_zip].filter(Boolean).join(', ') || '—'} />
            <Row label="Total Billed" value={fmt(fin.total_billed)} />
            <Row label="Total Paid" value={fmt(fin.total_paid)} />
          </div>

          {/* Custom fields */}
          {(client.custom_fields ?? []).some(f => f.value) && (
            <div className="card-3d md:col-span-2" style={{ padding: '20px' }}>
              <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Custom Fields</p>
              <div className="grid md:grid-cols-2 gap-x-8">
                {(client.custom_fields ?? []).filter(f => f.value).map(f => <Row key={f.id} label={f.name} value={f.value} />)}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── Contacts ── */}
      {tab === 'Contacts' && (
        <div className="grid md:grid-cols-2 gap-4">
          {(client.contacts ?? []).length === 0 && <div className="card-3d py-10 text-center text-sm md:col-span-2" style={{ color: 'var(--text-muted)', padding: '20px' }}>No contacts. Edit the customer to add one.</div>}
          {(client.contacts ?? []).map(c => (
            <div key={c.id} className="card-3d" style={{ padding: '18px' }}>
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(59,130,246,0.12)' }}>
                    <User size={16} style={{ color: '#3b82f6' }} />
                  </div>
                  <div>
                    <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{c.name || `${c.first_name} ${c.last_name || ''}`}</p>
                    {c.title && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{c.title}</p>}
                  </div>
                </div>
                {c.is_primary && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>Primary</span>}
              </div>
              <div className="mt-3 space-y-1.5">
                {c.email && <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}><Mail size={12} /> {c.email}</div>}
                {c.phone && <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}><Phone size={12} /> {c.phone}</div>}
                {c.user_id && <span className="text-[10px] font-bold" style={{ color: '#10b981' }}>● Portal access enabled</span>}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── Tax (GST / TDS) ── */}
      {tab === 'Tax' && (
        <div className="space-y-4">
          {!tax ? <div className="skeleton h-24 rounded-2xl" style={{ background: 'var(--border)' }} /> : (
            <>
              <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <TaxTile label="GST Total" value={fmt(tax.gst_total)} color="#7C3AED" />
                <TaxTile label="GST Paid" value={fmt(tax.gst_paid)} color="#10b981" />
                <TaxTile label="GST Unpaid" value={fmt(tax.gst_unpaid)} color="#ef4444" />
                <TaxTile label="TDS Deducted" value={fmt(tax.tds_deducted)} color="#f59e0b" />
              </div>
              <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
                <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
                  <p className="label-caps" style={{ color: '#a78bfa' }}>Invoice GST Breakdown</p>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                      {['Invoice', 'Date', 'Total', 'GST Amount', 'GST Status', 'Doc Status'].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
                    </tr></thead>
                    <tbody>
                      {(tax.invoices ?? []).length === 0 ? (
                        <tr><td colSpan="6" className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No invoices for this customer yet.</td></tr>
                      ) : tax.invoices.map(inv => (
                        <tr key={inv.id} style={{ borderBottom: '1px solid var(--border)' }}>
                          <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{inv.number}</td>
                          <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{inv.date}</td>
                          <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmt(inv.total)}</td>
                          <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmt(inv.gst_amount)}</td>
                          <td className="py-3 px-4">
                            <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: inv.gst_paid ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)', color: inv.gst_paid ? '#10b981' : '#ef4444' }}>
                              {inv.gst_paid ? 'Paid' : 'Unpaid'}
                            </span>
                          </td>
                          <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{inv.status}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}
        </div>
      )}

      {/* ── Support (Helpdesk loop-in) ── */}
      {tab === 'Support' && (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="px-4 py-3 flex items-center gap-2" style={{ borderBottom: '1px solid var(--border)' }}>
            <LifeBuoy size={14} style={{ color: '#a78bfa' }} />
            <p className="label-caps" style={{ color: '#a78bfa' }}>Support Tickets</p>
          </div>
          {!tickets ? <div className="p-4"><div className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} /></div> : tickets.length === 0 ? (
            <div className="py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>No support tickets linked to this customer.</div>
          ) : (
            <table className="w-full text-xs">
              <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                {['Subject', 'Status', 'Priority', 'Opened'].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
              </tr></thead>
              <tbody>
                {tickets.map(t => (
                  <tr key={t.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{t.subject}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{t.status}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{t.priority}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{(t.created_at || '').slice(0, 10)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* ── Related: Vendors / TPV / Leads loop-ins ── */}
      {tab === 'Related' && (
        <div className="grid md:grid-cols-3 gap-4">
          <RelatedCard icon={Package} title="Vendors" hint="Purchase vendors linked to this customer will appear here once the Purchase module ships." />
          <RelatedCard icon={Users2} title="Third-Party Vendors"
            hint={client.vendor_id ? `Linked TPV #${client.vendor_id}. Details load once the TPV module ships.` : 'No third-party vendor linked. Available once the TPV module ships.'} />
          <RelatedCard icon={UserPlus} title="Leads" hint={client.lead_id ? `Converted from lead #${client.lead_id}.` : 'This customer was not converted from a lead.'} />
        </div>
      )}
    </div>
  )
}

function Row({ label, value, icon: Icon }) {
  return (
    <div className="flex items-start justify-between py-2" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-xs flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>{Icon && <Icon size={12} />}{label}</span>
      <span className="text-xs font-semibold text-right max-w-[60%]" style={{ color: 'var(--text-h)' }}>{value}</span>
    </div>
  )
}

function TaxTile({ label, value, color }) {
  return (
    <div className="card-3d" style={{ padding: '16px' }}>
      <p className="text-[11px] mb-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="font-black text-lg" style={{ color }}>{value}</p>
    </div>
  )
}

function RelatedCard({ icon: Icon, title, hint }) {
  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center gap-2 mb-3">
        <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
          <Icon size={15} style={{ color: '#a78bfa' }} />
        </div>
        <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{title}</p>
      </div>
      <p className="text-xs leading-relaxed flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><Link2 size={11} /> {hint}</p>
    </div>
  )
}
