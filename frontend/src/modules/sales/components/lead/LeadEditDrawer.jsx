import { useState, useEffect } from 'react'
import { Pencil, X } from 'lucide-react'
import { useUpdateLead } from '@/hooks/useLeads'
import { useToast } from '@/hooks/useToast'
import { leadSettingsApi } from '@/services/leadSettingsApi'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { GRAD } from '@/components/ui/brand'

/**
 * Edit a lead after it has been created — SIR-000032.
 *
 * A lead could be created and then never corrected. Every field below was
 * already accepted by PUT /sales/leads/{id} and validated server-side; what was
 * missing was any control that sent them, so a typo in a company name survived
 * the whole pipeline.
 *
 * ── WHY THIS IS NOT THE CREATE DRAWER ────────────────────────────────────────
 * Leads.jsx has a create drawer with nearly this field set, and the obvious move
 * is to extract one component and use it for both. That refactor is deliberately
 * NOT done here: pulling ~100 lines of live JSX out of a page people create leads
 * on every day risks breaking creation to add editing, and the brief was to add
 * editing without disturbing what already works. Extracting the pair is a good
 * follow-up on a quiet day, not a rider on a defect fix.
 *
 * ── WHAT IS DELIBERATELY ABSENT ──────────────────────────────────────────────
 * Status and assignee. Both look like they belong on this form and neither is
 * accepted by UpdateLeadRequest — they have their own endpoints, because each
 * writes an activity-log entry this one does not. Putting them here would have
 * produced two controls that silently discarded what was typed into them, which
 * is worse than not offering them: the lead profile already moves status from its
 * own control, and assignment from the row menu.
 */
export default function LeadEditDrawer({ lead, onClose }) {
  const toast = useToast()
  const update = useUpdateLead()
  const [sources, setSources] = useState([])

  // Seeded from the lead once, on open. Nulls become '' because a controlled
  // input given null warns and then behaves as uncontrolled from that point on.
  const [form, setForm] = useState(() => ({
    name: lead.name || '', company: lead.company || '', email: lead.email || '',
    phone: lead.phone || '', title: lead.title || '', website: lead.website || '',
    source: lead.source?.name || '', lead_value: lead.lead_value ?? '',
    tags: lead.tags || '', description: lead.description || '',
    industry: lead.industry || '', campaign: lead.campaign || '',
    priority: lead.priority || 'medium',
    expected_close_date: (lead.expected_close_date || '').slice(0, 10),
    pan: lead.pan || '', gst: lead.gst || '',
    address: lead.address || '', city: lead.city || '', state: lead.state || '',
    country: lead.country || '', zip: lead.zip || '',
    referral_type: lead.referral_type || 'none',
    referral_value: lead.referral_value ?? '', referral_contact: lead.referral_contact || '',
  }))

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    leadSettingsApi.sources.list().then(r => setSources(r || [])).catch(() => setSources([]))
  }, [])

  const save = () => {
    if (!form.name.trim()) return toast.error('Name is required')

    update.mutate({
      id: lead.id,
      data: {
        ...form,
        // The numeric fields are empty strings while the box is blank, and the
        // validator wants a number or nothing at all — not ''.
        lead_value: form.lead_value === '' ? 0 : form.lead_value,
        referral_value: form.referral_value === '' ? 0 : form.referral_value,
        expected_close_date: form.expected_close_date || null,
      },
    }, {
      onSuccess: () => { toast.success('Lead updated'); onClose() },
      onError: (e) => toast.error(e.message || 'Could not save the lead'),
    })
  }

  return (
    <>
      <div className="drawer-backdrop" onClick={onClose} />
      <div className="drawer-panel" style={{ width: 'min(820px,95vw)' }}>

        <div className="drawer-header">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: GRAD, boxShadow: '0 4px 12px rgba(124,58,237,0.4)' }}>
              <Pencil size={14} className="text-white" />
            </div>
            <div>
              <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Edit Lead</h2>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{lead.name}</p>
            </div>
          </div>
          <button onClick={onClose}
            className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="drawer-body">

          <div>
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Contact Information</p>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => sf('name', e.target.value)} /></div>
                <div><label className="label">Company</label><input className="input-3d text-sm" value={form.company} onChange={e => sf('company', e.target.value)} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input className="input-3d text-sm" value={form.email} onChange={e => sf('email', e.target.value)} /></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Title</label><input className="input-3d text-sm" value={form.title} onChange={e => sf('title', e.target.value)} /></div>
                <div><label className="label">Website</label><input className="input-3d text-sm" value={form.website} onChange={e => sf('website', e.target.value)} /></div>
              </div>
            </div>
          </div>

          <div className="mt-6">
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Pipeline Details</p>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Source</label>
                  {/* Free text with a datalist, same as the create drawer: the
                      backend matches the name case-insensitively against existing
                      sources and only creates one when it is genuinely new. */}
                  <input className="input-3d text-sm" list="lead-edit-source-options"
                    value={form.source} onChange={e => sf('source', e.target.value)} />
                  <datalist id="lead-edit-source-options">
                    {sources.map(s => <option key={s.id} value={s.name} />)}
                  </datalist>
                </div>
                <div><label className="label">Lead Value (₹)</label><input type="number" className="input-3d text-sm" value={form.lead_value} onChange={e => sf('lead_value', e.target.value)} /></div>
              </div>
              <div><label className="label">Tags</label><input className="input-3d text-sm" placeholder="Comma-separated tags" value={form.tags} onChange={e => sf('tags', e.target.value)} /></div>
              <div><label className="label">Description</label><RichTextEditor value={form.description} onChange={v => sf('description', v)} minHeight={110} /></div>
            </div>
          </div>

          <div className="mt-6">
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Business / Qualification</p>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Industry</label><input className="input-3d text-sm" value={form.industry} onChange={e => sf('industry', e.target.value)} /></div>
                <div><label className="label">Campaign</label><input className="input-3d text-sm" value={form.campaign} onChange={e => sf('campaign', e.target.value)} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">PAN</label><input className="input-3d text-sm" value={form.pan} onChange={e => sf('pan', e.target.value)} /></div>
                <div><label className="label">GST</label><input className="input-3d text-sm" value={form.gst} onChange={e => sf('gst', e.target.value)} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Priority</label>
                  <select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}>
                    <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                  </select>
                </div>
                <div><label className="label">Expected Close Date</label><input type="date" className="input-3d text-sm" value={form.expected_close_date} onChange={e => sf('expected_close_date', e.target.value)} /></div>
              </div>
            </div>
          </div>

          <div className="mt-6">
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Address</p>
            <div className="space-y-3">
              <div><label className="label">Address</label><input className="input-3d text-sm" value={form.address} onChange={e => sf('address', e.target.value)} /></div>
              <div className="grid grid-cols-4 gap-3">
                <div><label className="label">City</label><input className="input-3d text-sm" value={form.city} onChange={e => sf('city', e.target.value)} /></div>
                <div><label className="label">State</label><input className="input-3d text-sm" value={form.state} onChange={e => sf('state', e.target.value)} /></div>
                <div><label className="label">Country</label><input className="input-3d text-sm" value={form.country} onChange={e => sf('country', e.target.value)} /></div>
                <div><label className="label">ZIP</label><input className="input-3d text-sm" value={form.zip} onChange={e => sf('zip', e.target.value)} /></div>
              </div>
            </div>
          </div>

          <div className="mt-6">
            <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Referral</p>
            <div className="grid grid-cols-3 gap-3">
              <div><label className="label">Type</label>
                <select className="input-3d text-sm" value={form.referral_type} onChange={e => sf('referral_type', e.target.value)}>
                  <option value="none">None</option><option value="percentage">Percentage</option><option value="fixed">Fixed Amount</option>
                </select>
              </div>
              {form.referral_type !== 'none' && (
                <div><label className="label">{form.referral_type === 'percentage' ? '%' : '₹ Amount'}</label>
                  <input type="number" className="input-3d text-sm" value={form.referral_value} onChange={e => sf('referral_value', e.target.value)} />
                </div>
              )}
              {form.referral_type !== 'none' && (
                <div><label className="label">Referred By</label>
                  <input className="input-3d text-sm" value={form.referral_contact} onChange={e => sf('referral_contact', e.target.value)} />
                </div>
              )}
            </div>
          </div>

        </div>

        <div className="drawer-footer">
          <button onClick={onClose} className="flex-1 py-3 rounded-2xl text-sm font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            Cancel
          </button>
          <button onClick={save} disabled={update.isPending}
            className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white disabled:opacity-60"
            style={{ background: GRAD, boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
            {update.isPending ? 'Saving…' : 'Save Changes'}
          </button>
        </div>

      </div>
    </>
  )
}
