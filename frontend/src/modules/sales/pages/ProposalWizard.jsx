import { useState, useEffect, useMemo } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, FileText, LayoutTemplate, Printer, Download, Send, UserPlus } from 'lucide-react'
import { proposalApi } from '@/services/proposalApi'
import { proposalTemplateApi } from '@/services/proposalTemplateApi'
import { customerApi } from '@/services/customerApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import { useToast } from '@/hooks/useToast'
import LineItemsTable from '../components/LineItemsTable'
import DocumentTemplateBar from '../components/DocumentTemplateBar'
import PagesEditor from '../components/PagesEditor'
import CoverEditor from '../components/CoverEditor'
import ContactFormDrawer from '@/modules/customer/components/ContactFormDrawer'
import RichTextEditor from '@/components/ui/RichTextEditor'
import ProposalDocument from '../components/ProposalDocument'
import ProposalSubmitModal from '../components/ProposalSubmitModal'

const STEPS = ['Start', 'Assignment', 'Content', 'Commercial', 'Review']

const EMPTY = {
  subject: '', rel_type: 'customer', rel_id: '', contact_id: '',
  date: new Date().toISOString().split('T')[0], open_till: '',
  currency: 'INR', status: 'Draft', assigned_to: '',
  proposal_to: '', address: '', city: '', state: '', country: 'India', zip: '',
  email: '', phone: '', tags: '', notes: '', terms: '',
  public_view_otp_enabled: false,
  discount_type: 'before_tax', discount_mode: 'fixed', discount_value: 0,
  pages: [{ title: 'Introduction', content: '' }],
  cover: { enabled: false, image: '', title: 'Proposal', heading: '' },
  line_items: [],
}

export default function ProposalWizard() {
  const nav = useNavigate()
  const toast = useToast()
  const { id } = useParams()                      // present on /:id/edit
  const [params] = useSearchParams()
  const editing = !!id

  const clients = useClientOptions()
  const [templates, setTemplates] = useState([])
  const [contacts, setContacts] = useState([])
  const [addingContact, setAddingContact] = useState(false)
  const [step, setStep] = useState(editing ? 1 : 0)
  // Launched from a customer profile? Lock that customer (Phase 1).
  const lockedClientId = params.get('client_id') || ''
  const [form, setForm] = useState(() => ({ ...EMPTY, rel_id: lockedClientId }))
  const [savedId, setSavedId] = useState(id ? Number(id) : null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(editing)
  const [submitOpen, setSubmitOpen] = useState(false)
  const [savedProposal, setSavedProposal] = useState(null)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => { proposalTemplateApi.list().then(setTemplates).catch(() => {}) }, [])

  // Load for edit
  useEffect(() => {
    if (!editing) return
    proposalApi.get(id).then(p => {
      setSavedProposal(p)
      setForm({
        ...EMPTY,
        ...Object.fromEntries(Object.entries(p).filter(([k]) => k in EMPTY && p[k] !== null)),
        rel_id: p.rel_id ?? '',
        contact_id: p.contact_id ?? '',
        assigned_to: p.assigned_to ?? '',
        date: p.date ? String(p.date).slice(0, 10) : EMPTY.date,
        open_till: p.open_till ? String(p.open_till).slice(0, 10) : '',
        public_view_otp_enabled: !!p.public_view_otp_enabled,
        cover: p.cover || EMPTY.cover,
        pages: p.pages?.length ? p.pages.map(pg => ({ title: pg.title, content: pg.content }))
          : [{ title: 'Page 1', content: p.notes || '' }],   // legacy rule: notes → page 1
        line_items: (p.line_items || p.lineItems || []).map(li => ({
          item_name: li.item_name, description: li.description || '', qty: li.qty,
          rate: li.rate, unit: li.unit || '', tax: li.tax || 0, discount: li.discount || 0,
          discount_mode: li.discount_mode || 'fixed',
          // Named taxes (CGST/SGST/IGST …) must survive a reload, or the
          // picker resets to "No tax" and the breakdown silently reverts.
          taxes: Array.isArray(li.taxes) ? li.taxes : [],
        })),
      })
      setLoading(false)
    }).catch(e => { toast.error(e.message); nav('/app/sales/proposals') })
  }, [id])

  // Contacts for the chosen customer
  useEffect(() => {
    if (form.rel_type !== 'customer' || !form.rel_id) { setContacts([]); return }
    customerApi.contacts.list(form.rel_id).then(setContacts).catch(() => setContacts([]))
  }, [form.rel_type, form.rel_id])

  const startFromTemplate = (t) => {
    setForm(p => ({
      ...p,
      subject: p.subject || t.name,
      template_id: t.id,
      pages: t.pages?.length ? t.pages.map(pg => ({ title: pg.title, content: pg.content }))
        : [{ title: 'Page 1', content: t.content || '' }],
    }))
    setStep(1)
  }

  const categories = useMemo(() => {
    const map = {}
    templates.forEach(t => { (map[t.category || 'General'] ||= []).push(t) })
    return map
  }, [templates])

  const stepError = (s) => {
    if (s === 1) {
      if (!form.subject.trim()) return 'Subject is required'
      if (!form.rel_id) return 'Select a customer'
      if (form.rel_type === 'customer' && !form.contact_id) return 'Select a recipient contact'
    }
    return null
  }

  const payload = () => ({
    ...form,
    rel_id: form.rel_id ? Number(form.rel_id) : null,
    contact_id: form.contact_id ? Number(form.contact_id) : null,
    assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
    open_till: form.open_till || null,
  })

  // Autosave a Draft (create on first pass; update afterwards)
  const persist = async () => {
    setSaving(true)
    try {
      let p
      if (savedId) p = await proposalApi.update(savedId, payload())
      else { p = await proposalApi.create(payload()); setSavedId(p.id) }
      const full = await proposalApi.get(p.id ?? savedId)
      setSavedProposal(full)
      return full
    } finally { setSaving(false) }
  }

  const goNext = async () => {
    const err = stepError(step)
    if (err) return toast.error(err)
    try {
      if (step >= 1) await persist()   // draft autosaves from Assignment onward
      setStep(s => Math.min(s + 1, STEPS.length - 1))
    } catch (e) { toast.error(e.message) }
  }

  const openSubmit = async () => {
    const err = stepError(1)
    if (err) return toast.error(err)
    try { await persist(); setSubmitOpen(true) }
    catch (e) { toast.error(e.message) }
  }

  const selectedContact = contacts.find(c => String(c.id) === String(form.contact_id))

  // Live preview object for Review (merges unsaved edits over the saved record)
  const previewDoc = { ...(savedProposal || {}), ...payload(), line_items: form.line_items.length ? form.line_items.map(li => ({ ...li, amount: li.qty * li.rate * (1 + (li.tax || 0) / 100) })) : (savedProposal?.line_items || savedProposal?.lineItems || []), subtotal: savedProposal?.subtotal, tax_total: savedProposal?.tax_total, total: savedProposal?.total }

  if (loading) return <div className="p-6"><div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} /></div>

  /**
   * A contact created here must land in the dropdown AND be selected, otherwise
   * the user has to find it themselves. Refetch rather than push the response so
   * the option text matches exactly what the list endpoint returns (`name`).
   */
  const onContactSaved = async (saved) => {
    try {
      const list = await customerApi.contacts.list(form.rel_id)
      setContacts(list)
      const c = list.find(x => String(x.id) === String(saved?.id)) || saved
      if (c) {
        setForm(p => ({
          ...p, contact_id: String(c.id),
          proposal_to: p.proposal_to || c.name || `${c.first_name || ''} ${c.last_name || ''}`.trim(),
          email: c.email || p.email, phone: c.phone || p.phone,
        }))
      }
    } catch { /* the drawer already reported any save error */ }
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease]">
      {/* Header */}
      <div className="flex items-center gap-3">
        <button onClick={() => nav('/app/sales/proposals')} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)]" style={{ border: '1px solid var(--border)' }}>
          <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
        </button>
        <div>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.2rem,2vw,1.5rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            {editing ? `Edit Proposal${savedProposal?.reference_no ? ` — ${savedProposal.reference_no}` : ''}` : 'New Proposal'}
          </h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{saving ? 'Saving draft…' : savedId ? 'Draft saved' : 'Not saved yet'}</p>
        </div>
      </div>

      {/* Step nav */}
      <div className="flex items-center gap-1 flex-wrap">
        {STEPS.map((label, i) => {
          if (i === 0 && editing) return null
          const active = i === step
          const done = i < step
          const clickable = done || i <= step
          return (
            <button key={label} onClick={() => clickable && setStep(i)}
              className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors"
              style={{
                background: active ? 'rgba(124,58,237,0.12)' : 'transparent',
                color: active ? 'var(--accent)' : done ? '#10b981' : 'var(--text-muted)',
                border: `1px solid ${active ? 'var(--border-purple)' : 'transparent'}`,
                cursor: clickable ? 'pointer' : 'default',
              }}>
              <span className="w-5 h-5 rounded-full flex items-center justify-center text-[10px]"
                style={{ background: done ? 'rgba(16,185,129,0.15)' : active ? 'var(--accent)' : 'var(--bg-input)', color: done ? '#10b981' : active ? '#fff' : 'var(--text-muted)' }}>
                {done ? <Check size={11} /> : i + 1}
              </span>
              {label}
            </button>
          )
        })}
      </div>

      {/* ── Step 0: Start ── */}
      {step === 0 && (
        <div className="space-y-5">
          <button onClick={() => setStep(1)} className="w-full card-3d flex items-center gap-4 hover:scale-[1.005] transition-transform text-left" style={{ padding: '20px' }}>
            <div className="w-12 h-12 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><FileText size={20} style={{ color: 'var(--accent)' }} /></div>
            <div>
              <p className="font-black text-sm" style={{ color: 'var(--text-h)' }}>Blank Canvas</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Start from scratch — write every page yourself</p>
            </div>
            <ArrowRight size={16} className="ml-auto" style={{ color: 'var(--text-muted)' }} />
          </button>

          {Object.entries(categories).map(([cat, list]) => (
            <div key={cat}>
              <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>{cat}</p>
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {list.map(t => (
                  <button key={t.id} onClick={() => startFromTemplate(t)} className="card-3d text-left hover:scale-[1.01] transition-transform" style={{ padding: '16px' }}>
                    <LayoutTemplate size={16} style={{ color: 'var(--accent)' }} />
                    <p className="font-bold text-sm mt-2" style={{ color: 'var(--text-h)' }}>{t.name}</p>
                    <p className="text-[11px] mt-0.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{t.description || `${t.pages?.length || 1} page(s)`}</p>
                  </button>
                ))}
              </div>
            </div>
          ))}
          {!templates.length && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No templates yet — save any proposal as a template to build your library.</p>}
        </div>
      )}

      {/* ── Step 1: Assignment ── */}
      {step === 1 && (
        <div className="card-3d max-w-3xl space-y-4" style={{ padding: '24px' }}>
          <div><label className="label">Subject *</label><input className="input-3d text-sm" value={form.subject} onChange={e => sf('subject', e.target.value)} placeholder="Website redesign proposal" /></div>
          <div className="grid md:grid-cols-2 gap-4">
            <div>
              <label className="label">Customer *</label>
              <select className="input-3d text-sm" value={form.rel_id} disabled={!!lockedClientId} onChange={e => { sf('rel_id', e.target.value); sf('contact_id', '') }}
                style={lockedClientId ? { opacity: 0.7, cursor: 'not-allowed' } : undefined}>
                <option value="">Select customer…</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
              </select>
              {lockedClientId && <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>🔒 Locked — started from this customer's profile.</p>}
            </div>
            <div>
              <label className="label">Recipient Contact *</label>
              <select className="input-3d text-sm" value={form.contact_id} onChange={e => {
                const c = contacts.find(x => String(x.id) === e.target.value)
                setForm(p => ({
                  ...p, contact_id: e.target.value,
                  proposal_to: p.proposal_to || (c ? c.name : ''),
                  email: c?.email || p.email, phone: c?.phone || p.phone,
                }))
              }} disabled={!form.rel_id}>
                <option value="">{form.rel_id ? 'Select contact…' : 'Pick a customer first'}</option>
                {contacts.map(c => <option key={c.id} value={c.id}>{c.name}{c.email ? ` — ${c.email}` : ''}</option>)}
              </select>
              {/* Add a contact without leaving the wizard — same form as the customer
                  profile (ContactFormDrawer), so nothing is captured differently here. */}
              {form.rel_id && (
                <button type="button" onClick={() => setAddingContact(true)}
                  className="flex items-center gap-1.5 mt-2 text-xs font-bold" style={{ color: 'var(--accent)' }}>
                  <UserPlus size={13} /> New contact for this customer
                </button>
              )}
              {form.rel_id && !contacts.length && (
                <p className="text-[11px] mt-1" style={{ color: '#f59e0b' }}>
                  This customer has no contacts yet — add one above to continue.
                </p>
              )}
            </div>
          </div>
          <div className="grid md:grid-cols-3 gap-4">
            <div><label className="label">Date</label><input type="date" className="input-3d text-sm" value={form.date} onChange={e => sf('date', e.target.value)} /></div>
            <div><label className="label">Open Till</label><input type="date" className="input-3d text-sm" value={form.open_till} onChange={e => sf('open_till', e.target.value)} /></div>
            <div><label className="label">Currency</label><select className="input-3d text-sm" value={form.currency} onChange={e => sf('currency', e.target.value)}>{['INR', 'USD', 'EUR', 'GBP', 'AED'].map(c => <option key={c}>{c}</option>)}</select></div>
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            <div><label className="label">Proposal To (display name)</label><input className="input-3d text-sm" value={form.proposal_to} onChange={e => sf('proposal_to', e.target.value)} /></div>
            <div><label className="label">Tags</label><input className="input-3d text-sm" value={form.tags} onChange={e => sf('tags', e.target.value)} placeholder="comma,separated" /></div>
          </div>
          <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.public_view_otp_enabled} onChange={e => sf('public_view_otp_enabled', e.target.checked)} />
            Require OTP verification (emailed code) before the client can open the public link
          </label>
        </div>
      )}

      {/* ── Step 2: Content ── */}
      {step === 2 && (
        <div className="card-3d" style={{ padding: '20px' }}>
          <CoverEditor value={form.cover} onChange={cover => sf('cover', cover)} />
          <PagesEditor pages={form.pages} onChange={pages => sf('pages', pages)} />
        </div>
      )}

      {/* ── Step 3: Commercial ── */}
      {step === 3 && (
        <div className="card-3d space-y-4" style={{ padding: '20px' }}>
          {/* Proposal templates carry cover + content pages but deliberately no
              pricing, so the costing step gets its own line-item templates. */}
          <DocumentTemplateBar docType="proposal" form={form}
            onApply={patch => setForm(p => ({ ...p, ...patch }))} />
          <LineItemsTable
            items={form.line_items}
            onChange={items => sf('line_items', items)}
            discount={{ type: form.discount_type, mode: form.discount_mode, value: form.discount_value }}
            onDiscountChange={d => setForm(p => ({ ...p, discount_type: d.type ?? p.discount_type, discount_mode: d.mode ?? p.discount_mode, discount_value: d.value ?? p.discount_value }))}
            supplyType={savedProposal?.supply_type ?? null}
          />
          <div>
            <label className="label">Terms & Conditions</label>
            <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Payment terms, validity, scope notes…" minHeight={120} />
          </div>
        </div>
      )}

      {/* ── Step 4: Review ── */}
      {step === 4 && (
        <div className="space-y-4">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              This is exactly what {selectedContact?.name || 'the client'} will see on the public link.
            </p>
            <div className="flex gap-2">
              <button onClick={() => window.print()} className="px-3 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Printer size={13} /> Print</button>
              {savedId && <button onClick={() => proposalApi.downloadPdf(savedId).catch(e => toast.error(e.message))} className="px-3 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Download size={13} /> PDF</button>}
            </div>
          </div>
          <ProposalDocument proposal={previewDoc} />
        </div>
      )}

      {/* Footer nav */}
      {step > 0 && (
        <div className="flex justify-between items-center pb-8">
          <button onClick={() => setStep(s => Math.max(s - 1, editing ? 1 : 0))} className="px-5 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Back</button>
          {step < 4 ? (
            <button onClick={goNext} disabled={saving} className="px-6 py-2.5 rounded-xl text-sm font-bold text-white flex items-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
              Next <ArrowRight size={14} />
            </button>
          ) : (
            <button onClick={openSubmit} disabled={saving} className="px-6 py-2.5 rounded-xl text-sm font-bold text-white flex items-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.35)' }}>
              <Send size={14} /> Submit to Client
            </button>
          )}
        </div>
      )}

      {submitOpen && savedProposal && (
        <ProposalSubmitModal
          proposal={savedProposal}
          contact={selectedContact}
          onClose={() => setSubmitOpen(false)}
          onSent={() => { setSubmitOpen(false); toast.success('Proposal emailed to client'); nav(`/app/sales/proposals/${savedId}`) }}
        />
      )}
      {addingContact && form.rel_id && (
        <ContactFormDrawer
          clientId={form.rel_id}
          onClose={() => setAddingContact(false)}
          onSaved={onContactSaved}
        />
      )}
    </div>
  )
}
