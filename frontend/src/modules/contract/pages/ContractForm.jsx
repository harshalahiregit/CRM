import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2, GripVertical, Save, FilePlus2, FileEdit } from 'lucide-react'
import { contractModuleApi as api } from '@/services/contractModuleApi'
import { inputStyle, labelStyle, PRIMARY_GRADIENT, Overlay, ModalFooter } from '@/components/ui/kit3d'
import RichTextEditor from '@/components/ui/RichTextEditor'

/**
 * Create or edit a contract.
 *
 * Two things here are less obvious than they look:
 *
 * 1. The counterparty is chosen in two steps — what KIND of party, then which
 *    one. Customers, TPV vendors and purchase vendors live in three different
 *    tables with unrelated ids, so a single flat list would make id 5 ambiguous.
 *
 * 2. Terms are edited as ordered PAGES, not one box. The brief asks for 2 to 10+
 *    pages, and the PDF starts a new printed page per entry, so the clause
 *    numbering people quote matches what they are holding.
 *
 * 3. There are two ways out of this screen. "Save as draft" keeps whatever has
 *    been typed, no questions asked; "Create contract" first checks the record
 *    is complete enough to actually send to somebody. Both land in the draft
 *    state -- sending is what moves a contract on -- but only the second one
 *    promises you could send it today.
 */

/* The page runs edge to edge. Below 1280px the two panels stack; above it the
   details sit on the left and the terms editor takes the wider right column,
   which is where the width is worth having -- clauses are what people read.
   The form used to be capped at 980px, which on a normal monitor left half the
   screen empty while the clause editor was the narrowest thing on the page. */
const FORM_CSS = `
.cfm-cols { display: flex; flex-direction: column; gap: 16px; align-items: stretch; }
.cfm-left, .cfm-right { min-width: 0; }
@media (min-width: 1280px) {
  .cfm-cols  { flex-direction: row; align-items: flex-start; }
  .cfm-left  { flex: 1 1 0; }
  .cfm-right { flex: 1.3 1 0; }
}
.cfm-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
@media (min-width: 620px) { .cfm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.cfm-full { grid-column: 1 / -1; }
`

/* Same vocabulary as the Contracts list, so a status reads the same wherever
   it is shown. */
const STATUS_STYLE = {
  draft:     { bg: 'rgba(107,114,128,.12)', fg: '#6b7280', label: 'Draft' },
  sent:      { bg: 'rgba(59,130,246,.12)',  fg: '#3b82f6', label: 'Sent' },
  signed:    { bg: 'rgba(139,92,246,.12)',  fg: '#8b5cf6', label: 'Signed' },
  active:    { bg: 'rgba(16,185,129,.12)',  fg: '#10b981', label: 'Active' },
  expired:   { bg: 'rgba(245,158,11,.12)',  fg: '#f59e0b', label: 'Expired' },
  cancelled: { bg: 'rgba(239,68,68,.12)',   fg: '#ef4444', label: 'Cancelled' },
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }
const emptyPage = () => ({ key: Math.random().toString(36).slice(2), title: '', content: '' })

/** Does this HTML carry any actual words (or an image)? */
const hasText = (html) => {
  const s = String(html || '')
  if (/<img/i.test(s)) return true
  return s.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim().length > 0
}

const PARTY_KINDS = [
  { key: 'customer',        label: 'Customer' },
  { key: 'vendor',          label: 'Vendor (TPV)' },
  { key: 'purchase_vendor', label: 'Vendor (Purchase)' },
]

export default function ContractForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [search] = useSearchParams()
  const editing = Boolean(id)

  /**
   * A contract can be started from the counterparty's own record.
   *
   * The customer record's Contracts tab links here with the party already
   * decided, so the person raising it does not re-pick from a list the customer
   * they were just looking at — and cannot pick the wrong one. Only the two
   * keys travel: the name and e-mail are resolved from the party list below,
   * the same way a manual selection resolves them, so there is one place that
   * knows how a party turns into a name.
   *
   * `return` is where Cancel and a successful save go back to. It is honoured
   * only when it is a path on this app — an absolute URL in a query parameter
   * is somebody else's redirect.
   */
  const fromParty = search.get('party_id')
  const partyKind = search.get('party_type') || 'customer'
  const backTo = (() => {
    const r = search.get('return')
    return r && r.startsWith('/') && !r.startsWith('//') ? r : null
  })()

  const [form, setForm] = useState({
    title: '', description: '', contract_category_id: '',
    party_type: search.get('party_type') || 'customer',
    party_id: fromParty || '', party_name: '', party_email: '',
    value: '', currency: 'INR', start_date: '', end_date: '', renewal_notice_days: 30,
  })
  const [status, setStatus] = useState('draft')
  const [pages, setPages] = useState([emptyPage()])
  const [categories, setCategories] = useState([])
  const [parties, setParties] = useState({})
  // null | 'draft' | 'full' -- so only the button that was pressed says "Saving".
  const [saving, setSaving] = useState(null)
  const [err, setErr] = useState(null)
  const [newCat, setNewCat] = useState(null)

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e?.target ? e.target.value : e }))

  useEffect(() => {
    // Soft loads: a picker that fails to fetch leaves the form usable rather
    // than blocking the whole page.
    api.categories().then(d => setCategories(Array.isArray(d) ? d : [])).catch(() => {})
    api.parties().then(list => {
      setParties(list)

      // A party arriving in the URL has an id but no NAME, so resolve it here
      // exactly as picking from the dropdown would — the field has to SHOW who
      // the contract is with, or the person raising it cannot tell whether the
      // right customer came through.
      //
      // This was also a guard against the server storing the blank: it read
      // party_name with ??, which accepts an empty string. That is now `?:` in
      // ContractService::resolveParty and falls back to the linked record, so
      // the guard is no longer load-bearing — this is for the screen.
      if (!fromParty) return
      const chosen = (list?.[partyKind] || []).find(p => String(p.id) === String(fromParty))
      if (chosen) {
        setForm(f => f.party_name ? f : ({
          ...f, party_name: chosen.name ?? '', party_email: chosen.email ?? f.party_email,
        }))
      }
    }).catch(() => {})
    // Primitives, not the URLSearchParams object: that is a fresh instance on
    // every render, and depending on it would re-run this fetch in a loop.
  }, [fromParty, partyKind])

  useEffect(() => {
    if (!editing) return
    api.get(id).then(c => {
      setStatus(c.status || 'draft')
      setForm({
        title: c.title ?? '', description: c.description ?? '',
        contract_category_id: c.contract_category_id ?? '',
        // The server stores a class name; the form speaks the stable API key.
        party_type: PARTY_KINDS.find(k => (c.party_type || '').toLowerCase().includes(k.key.replace('_', '')))?.key
                    ?? (c.party_type?.includes('Client') ? 'customer'
                    : c.party_type?.includes('Purchase') ? 'purchase_vendor' : 'vendor'),
        party_id: c.party_id ?? '', party_name: c.party_name ?? '', party_email: c.party_email ?? '',
        value: c.value ?? '', currency: c.currency ?? 'INR',
        start_date: c.start_date ?? '', end_date: c.end_date ?? '',
        renewal_notice_days: c.renewal_notice_days ?? 30,
      })
      setPages((c.pages?.length ? c.pages : [emptyPage()]).map(p => ({
        key: Math.random().toString(36).slice(2), title: p.title ?? '', content: p.content ?? '',
      })))
    }).catch(() => setErr('That contract could not be loaded.'))
  }, [id, editing])

  /* ── Pages ──────────────────────────────────────────────────── */
  const addPage    = () => setPages(p => [...p, emptyPage()])
  const removePage = (key) => setPages(p => p.length === 1 ? p : p.filter(x => x.key !== key))
  const setPage    = (key, field, v) => setPages(p => p.map(x => x.key === key ? { ...x, [field]: v } : x))
  const movePage   = (i, dir) => setPages(p => {
    const next = [...p]
    const j = i + dir
    if (j < 0 || j >= next.length) return p
    ;[next[i], next[j]] = [next[j], next[i]]
    return next
  })

  /* ── Inline category creation (the form's + button) ─────────── */
  const saveCategory = async () => {
    const name = (newCat || '').trim()
    if (!name) return
    try {
      const c = await api.createCategory({ name })
      setCategories(list => list.some(x => x.id === c.id) ? list : [...list, c])
      setForm(f => ({ ...f, contract_category_id: c.id }))
      setNewCat(null)
    } catch { setErr('That contract type could not be created.') }
  }

  /**
   * Save.
   *
   * `asDraft` is not a different record -- it is a different promise. A draft is
   * parked work, so the only thing it owes is a name you can find it by again.
   * The full save is the one that says "this could go out", so it asks for the
   * counterparty and at least one page of terms now rather than letting somebody
   * discover the gap at the moment they try to send it.
   */
  const save = async (asDraft) => {
    setErr(null)
    if (!form.title.trim()) { setErr('Give the contract a name.'); return }
    if (form.end_date && form.start_date && form.end_date < form.start_date) {
      setErr('The end date cannot fall before the start date.'); return
    }
    if (!asDraft) {
      const missing = []
      if (!form.party_id) missing.push('a customer or vendor')
      if (!pages.some(p => hasText(p.content))) missing.push('at least one page of terms')
      if (missing.length) {
        setErr(`This contract still needs ${missing.join(' and ')}. `
          + 'Use "Save as draft" to keep it and finish later.')
        return
      }
    }

    setSaving(asDraft ? 'draft' : 'full')
    try {
      const payload = {
        ...form,
        contract_category_id: form.contract_category_id || null,
        party_id: form.party_id || null,
        party_type: form.party_id ? form.party_type : null,
        value: form.value === '' ? null : Number(form.value),
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        // A rich editor that has been focused and cleared still emits markup
        // like <p><br></p>, so "is this page empty?" has to be asked of the
        // TEXT. Without this every contract saves a trailing blank page that
        // prints as an empty sheet.
        pages: pages
          .filter(p => p.title.trim() || hasText(p.content))
          .map(({ title, content }) => ({ title, content })),
      }
      // Only ever push a status DOWN to draft when it already is one. Sending a
      // bare `status: 'draft'` while editing a sent or signed contract would
      // quietly un-send it, which is why the button is hidden in that case too.
      if (asDraft) payload.status = 'draft'

      const saved = editing ? await api.update(id, payload) : await api.create(payload)
      // Back where the contract was started from, when it was started from a
      // record: somebody who opened this from a customer wants that customer
      // again, not a contract screen they then have to navigate out of.
      navigate(backTo ?? `/app/contracts/${saved.id}`)
    } catch (e) {
      setErr(e?.response?.data?.message
        || Object.values(e?.response?.data?.errors || {}).flat()[0]
        || 'The contract could not be saved.')
    } finally { setSaving(null) }
  }

  const partyList = parties[form.party_type] || []
  // Demoting a sent or signed contract back to a draft is not something anybody
  // means to do, so the button is simply absent once it has left the drawer.
  const canDraft = !editing || status === 'draft'
  const chip = STATUS_STYLE[status] || STATUS_STYLE.draft

  return (
    <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16, width: '100%', boxSizing: 'border-box' }}>
      <style>{FORM_CSS}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <button onClick={() => navigate(backTo ?? '/app/contracts')}
          style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'transparent', border: 'none', color: 'var(--text-muted)', fontSize: 13, cursor: 'pointer', padding: 0 }}>
          <ArrowLeft size={15} /> Contracts
        </button>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
          {editing ? 'Edit contract' : 'New contract'}
        </h1>
        <span style={{ background: chip.bg, color: chip.fg, fontSize: 11, fontWeight: 700, padding: '3px 9px', borderRadius: 999 }}>
          {chip.label}
        </span>
      </div>

      <div className="cfm-cols">

      {/* ── Basic details ─────────────────────────────────────── */}
      <div className="cfm-left"><div style={card}>
        <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 14px', textTransform: 'uppercase', letterSpacing: '.04em' }}>
          Details
        </h2>

        <div className="cfm-grid">
          <div className="cfm-full">
            <label style={labelStyle}>Contract name <span style={{ color: '#ef4444' }}>*</span></label>
            <input value={form.title} onChange={set('title')} style={inputStyle}
              placeholder="Annual Maintenance Agreement" />
          </div>

          <div>
            <label style={labelStyle}>Contract type</label>
            <div style={{ display: 'flex', gap: 6 }}>
              <select value={form.contract_category_id} onChange={set('contract_category_id')} style={inputStyle}>
                <option value="">— none —</option>
                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {/* Created on the fly, so nobody has to leave a half-filled form
                  to go and add a type in Settings. */}
              <button type="button" onClick={() => setNewCat('')} title="Add a contract type"
                style={{ padding: '0 11px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: '#7C3AED', cursor: 'pointer' }}>
                <Plus size={15} />
              </button>
            </div>
          </div>

          <div>
            <label style={labelStyle}>Contract value</label>
            <div style={{ display: 'flex', gap: 6 }}>
              <select value={form.currency} onChange={set('currency')} style={{ ...inputStyle, width: 90 }}>
                {['INR', 'USD', 'EUR', 'GBP', 'AED'].map(c => <option key={c}>{c}</option>)}
              </select>
              <input type="number" min="0" value={form.value} onChange={set('value')} style={inputStyle} placeholder="250000" />
            </div>
          </div>

          <div>
            <label style={labelStyle}>Party type</label>
            <select value={form.party_type}
              onChange={e => setForm(f => ({ ...f, party_type: e.target.value, party_id: '', party_name: '' }))}
              style={inputStyle}>
              {PARTY_KINDS.map(k => <option key={k.key} value={k.key}>{k.label}</option>)}
            </select>
          </div>

          <div>
            <label style={labelStyle}>Customer / Vendor</label>
            <select value={form.party_id}
              onChange={e => {
                const chosen = partyList.find(p => String(p.id) === e.target.value)
                setForm(f => ({
                  ...f, party_id: e.target.value,
                  party_name: chosen?.name ?? '', party_email: chosen?.email ?? f.party_email,
                }))
              }}
              style={inputStyle}>
              <option value="">— select —</option>
              {partyList.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>

          {/* Filled in from the chosen party, but editable -- the signatory is
              often not the address on the account record, and this is the one
              the signing request is actually sent to. It also squares off the
              two-column grid, which otherwise ended on a dangling half row. */}
          <div>
            <label style={labelStyle}>Email for signing</label>
            <input type="email" value={form.party_email} onChange={set('party_email')}
              style={inputStyle} placeholder="accounts@example.com" />
          </div>

          <div>
            <label style={labelStyle}>Start date</label>
            <input type="date" value={form.start_date} onChange={set('start_date')} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>End date</label>
            <input type="date" value={form.end_date} onChange={set('end_date')} style={inputStyle} />
          </div>

          <div>
            <label style={labelStyle}>Renewal reminder</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <input type="number" min="0" max="365" value={form.renewal_notice_days}
                onChange={set('renewal_notice_days')} style={{ ...inputStyle, width: 90 }} />
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>days before it ends</span>
            </div>
          </div>

          <div className="cfm-full">
            <label style={labelStyle}>Description</label>
            <RichTextEditor
              value={form.description}
              onChange={(v) => setForm(f => ({ ...f, description: v }))}
              placeholder="What this agreement covers."
              minHeight={110} />
          </div>
        </div>
      </div></div>

      {/* ── Terms & conditions, page by page ──────────────────── */}
      <div className="cfm-right"><div style={card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10, flexWrap: 'wrap' }}>
          <div>
            <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.04em' }}>
              Terms &amp; Conditions
            </h2>
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '3px 0 0' }}>
              Each page below becomes a page of the printed contract.
            </p>
          </div>
          <button type="button" onClick={addPage}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>
            <FilePlus2 size={13} /> Add page
          </button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {pages.map((p, i) => (
            <div key={p.key} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 12, background: 'var(--bg-input)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                <GripVertical size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', flexShrink: 0 }}>PAGE {i + 1}</span>
                <input value={p.title} onChange={e => setPage(p.key, 'title', e.target.value)}
                  style={{ ...inputStyle, flex: 1, minWidth: 0, padding: '6px 10px' }} placeholder="Section heading — e.g. Scope of Work" />
                <button type="button" onClick={() => movePage(i, -1)} disabled={i === 0}
                  style={{ background: 'transparent', border: 'none', color: 'var(--text-muted)', cursor: i === 0 ? 'default' : 'pointer', opacity: i === 0 ? .3 : 1, fontSize: 13 }}>↑</button>
                <button type="button" onClick={() => movePage(i, 1)} disabled={i === pages.length - 1}
                  style={{ background: 'transparent', border: 'none', color: 'var(--text-muted)', cursor: i === pages.length - 1 ? 'default' : 'pointer', opacity: i === pages.length - 1 ? .3 : 1, fontSize: 13 }}>↓</button>
                <button type="button" onClick={() => removePage(p.key)} disabled={pages.length === 1}
                  style={{ background: 'transparent', border: 'none', color: '#ef4444', cursor: 'pointer', opacity: pages.length === 1 ? .3 : 1, display: 'flex' }}>
                  <Trash2 size={14} />
                </button>
              </div>
              {/* The same editor the rest of the app writes in — bold, lists,
                  headings, tables. Terms are the part of a contract people
                  actually format, and a bare textarea produced clauses that
                  printed as one grey wall in the PDF. The server runs the HTML
                  through the allowlist sanitizer on save. */}
              <RichTextEditor
                value={p.content}
                onChange={(v) => setPage(p.key, 'content', v)}
                placeholder="Clauses for this page…"
                minHeight={220} />
            </div>
          ))}
        </div>
      </div></div>

      </div>

      {err && <div style={{ color: '#ef4444', fontSize: 13 }}>{err}</div>}

      <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <button onClick={() => save(false)} disabled={Boolean(saving)}
          style={{ display: 'flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontSize: 13, fontWeight: 700, cursor: saving ? 'default' : 'pointer', opacity: saving ? .7 : 1 }}>
          <Save size={15} /> {saving === 'full' ? 'Saving…' : (editing ? 'Save changes' : 'Create contract')}
        </button>

        {canDraft && (
          <button onClick={() => save(true)} disabled={Boolean(saving)}
            title="Keep what you have typed. Nothing is sent, and you can finish it later."
            style={{ display: 'flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13, fontWeight: 600, cursor: saving ? 'default' : 'pointer', opacity: saving ? .7 : 1 }}>
            <FileEdit size={15} /> {saving === 'draft' ? 'Saving…' : 'Save as draft'}
          </button>
        )}

        {/* backTo, so a contract raised from a customer record goes back to it.
            Transparent rather than outlined: Save as draft now occupies the
            outlined slot, and two bordered buttons side by side read as two
            equal choices when one of them is only a way out. */}
        <button onClick={() => navigate(backTo ?? '/app/contracts')}
          style={{ padding: '10px 18px', borderRadius: 10, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontSize: 13, cursor: 'pointer' }}>
          Cancel
        </button>

        {canDraft && (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            A draft stays with your team until you send it for signature.
          </span>
        )}
      </div>

      {newCat !== null && (
        <Overlay onClose={() => setNewCat(null)} width={420}>
          <div style={{ padding: '18px 20px', borderBottom: '1px solid var(--border)' }}>
            <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>New contract type</h2>
          </div>
          <div style={{ padding: 20 }}>
            <label style={labelStyle}>Name</label>
            <input autoFocus value={newCat} onChange={e => setNewCat(e.target.value)} style={inputStyle}
              placeholder="Service Agreement" onKeyDown={e => { if (e.key === 'Enter') saveCategory() }} />
          </div>
          <ModalFooter onClose={() => setNewCat(null)} onConfirm={saveCategory} confirmLabel="Add type" />
        </Overlay>
      )}
    </div>
  )
}
