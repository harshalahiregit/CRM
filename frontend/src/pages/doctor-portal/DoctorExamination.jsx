import { useEffect, useState } from 'react'
import { useNavigate, useOutletContext, useSearchParams } from 'react-router-dom'
import { Building2, ClipboardPlus, UserPlus, X, ArrowRight, Layers } from 'lucide-react'
import { useToast } from '@/hooks/useToast'
import { medicalApi } from '@/services/medicalApi'
import { useExamSelection } from '@/hooks/useExamSelection'
import { draftedIds } from '@/hooks/useExamDraft'
import SubjectPicker from '@/components/medical/SubjectPicker'
import SearchSelect from '@/components/medical/SearchSelect'
import Modal from '@/components/ui/Modal'
import { S } from '@/components/medical/MedicalBits'

/**
 * Step one: WHO.
 *
 * This page used to be the whole flow — pick people at the top, and the
 * examination form appeared underneath, below the fold. A doctor tapped "Open"
 * and nothing appeared to happen, because the thing that happened was two
 * screens further down. Nobody scrolls to find out whether a button worked;
 * they tap it again.
 *
 * So the form is now its own page. This one does one job — choose — and ends
 * in a bar that says plainly what happens next and how many people it applies
 * to. Two short pages beat one long one that has to be explained.
 *
 * The ticks survive leaving: see useExamSelection. A doctor who examines one
 * person, goes back for the next, and finds their group deselected has been
 * given a queue that only works if they never look away from it.
 */
export default function DoctorExamination() {
  const { module, audience } = useOutletContext()
  const isVendorSide = (audience?.kind ?? 'vendor') === 'vendor'
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()

  const [vendors, setVendors] = useState([])
  const [vendorId, setVendorId] = useState(params.get('vendor') || '')
  const [vendorMeta, setVendorMeta] = useState(null)
  const [vendorBusy, setVendorBusy] = useState(false)

  const [people, setPeople] = useState([])
  const [listMeta, setListMeta] = useState(null)
  const [listBusy, setListBusy] = useState(false)
  const [search, setSearch] = useState('')

  const { selected, setSelected, clear } = useExamSelection(module)
  const [showVisitorForm, setShowVisitorForm] = useState(false)
  // Who has an examination part-typed and unsaved — surfaced on the row, so a
  // doctor can pick up where they left off instead of starting again.
  const [drafts, setDrafts] = useState([])

  /* ── Loading ──────────────────────────────────────────────────────────── */

  useEffect(() => {
    setVendors([]); setVendorId(''); setSearch(''); setPeople([]); setListMeta(null)
    if (isVendorSide) searchVendors('')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module])

  /** Vendors narrow the list; they never gate it. */
  const searchVendors = (q) => {
    setVendorBusy(true)
    medicalApi.doctor.vendors(module, q ? { q } : {})
      .then(res => { setVendors(res?.data ?? []); setVendorMeta(res?.meta ?? null) })
      .catch(() => setVendors([]))
      .finally(() => setVendorBusy(false))
  }

  const loadPeople = () => {
    setListBusy(true)
    const query = {
      ...(search ? { q: search } : {}),
      ...(isVendorSide && vendorId ? { vendor_id: vendorId } : {}),
    }
    const request = isVendorSide
      ? medicalApi.doctor.workers(module, query)
      : medicalApi.doctor.people(module, query)

    request
      .then(res => {
        const rows = res?.data ?? []
        setPeople(rows)
        setListMeta(res?.meta ?? null)
        setDrafts(draftedIds(module, rows.map(r => r.id)))
      })
      .catch(() => { setPeople([]); setListMeta(null) })
      .finally(() => setListBusy(false))
  }

  useEffect(() => { loadPeople() }, [module, vendorId, search]) // eslint-disable-line react-hooks/exhaustive-deps

  const pickVendor = (id) => {
    setVendorId(id)
    setParams(id ? { vendor: id } : {})
  }

  /* ── Going forward ────────────────────────────────────────────────────── */

  /**
   * Open the form on one person.
   *
   * The whole ticked list travels with them, in order, so the next page can say
   * "person 3 of 11" — which is the thing a doctor working through a group
   * actually wants to know and cannot work out for themselves. Somebody opened
   * from a row they had not ticked is a queue of one.
   */
  const examine = (personId) => {
    const inGroup = selected.some(id => String(id) === String(personId))
    const order = inGroup ? selected.map(String) : [String(personId)]

    navigate({
      pathname: '/doctor-portal/examine/form',
      search: new URLSearchParams({
        person: String(personId),
        queue: order.join(','),
        ...(vendorId ? { vendor: vendorId } : {}),
      }).toString(),
    })
  }

  const examineSelected = () => selected.length && examine(selected[0])

  const reviewTogether = () => navigate({
    pathname: '/doctor-portal/examine/group',
    search: new URLSearchParams({ ids: selected.join(',') }).toString(),
  })

  return (
    <div style={{ paddingBottom: selected.length ? 96 : 0 }}>
      <header style={{ marginBottom: 14 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
          <ClipboardPlus size={20} /> Who are you examining?
        </h1>
        <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
          {audience?.label || 'Workers'} · search for a name, tick everyone you are seeing, then press the button at the bottom.
        </p>
      </header>

      <SubjectPicker
        people={people}
        loading={listBusy}
        selected={selected}
        onSelectedChange={setSelected}
        onOpen={examine}
        openLabel="Examine"
        search={search}
        onSearchChange={setSearch}
        meta={listMeta}
        draftIds={drafts}
        emptyHint={isVendorSide
          ? 'No workers found — try a name or a worker code.'
          : 'Nobody to show for this audience yet.'}
        header={
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            {isVendorSide && (
              <div style={{ flex: '1 1 260px', minWidth: 0 }}>
                <SearchSelect
                  label={<><Building2 size={11} style={{ verticalAlign: -1 }} /> Filter by vendor (optional)</>}
                  value={vendorId}
                  options={vendors}
                  meta={vendorMeta}
                  loading={vendorBusy}
                  emptyLabel="All vendors"
                  placeholder="Search vendors by name or code…"
                  onSearch={searchVendors}
                  onSelect={pickVendor}
                />
              </div>
            )}
            {module === 'visitor' && (
              <button type="button" onClick={() => setShowVisitorForm(true)} style={{ ...S.btnPrimary, minHeight: 44 }}>
                <UserPlus size={14} /> Register a visitor
              </button>
            )}
          </div>
        }
      />

      {showVisitorForm && (
        <VisitorForm
          onClose={() => setShowVisitorForm(false)}
          onCreated={(v) => { setShowVisitorForm(false); loadPeople(); examine(v.id) }}
        />
      )}

      {/* ── What happens next ────────────────────────────────────────────
          Pinned to the bottom of the screen rather than placed after the list.
          A button below a list of two hundred people is a button that is only
          found by accident. */}
      {selected.length > 0 && (
        <>
          <style>{BAR_CSS}</style>
          <div className="dx-bar">
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 14, fontWeight: 900, color: 'var(--text-h)' }}>
                {selected.length} {selected.length === 1 ? 'person' : 'people'} selected
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                Examined one at a time — the form moves to the next as each is saved.
              </div>
            </div>

            <button type="button" onClick={clear} style={{ ...S.btn, minHeight: 44 }}>Clear</button>

            {/* Only offered for a group, because "what these people have in
                common" is not a question about one person. */}
            {selected.length > 1 && (
              <button type="button" onClick={reviewTogether} style={{ ...S.btn, minHeight: 44 }}>
                <Layers size={14} /> Compare findings
              </button>
            )}

            <button type="button" onClick={examineSelected}
              style={{ ...S.btnPrimary, minHeight: 48, padding: '12px 20px', fontSize: 14 }}>
              Start examining <ArrowRight size={15} />
            </button>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * Pinned above the bottom edge, and clear of the panel on a desktop so it never
 * sits over the navigation. On a tablet it spans the width, because there is no
 * panel to clear.
 */
const BAR_CSS = `
.dx-bar {
  position: fixed; z-index: 45; left: 244px; right: 0; bottom: 0;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 12px 20px;
  background: var(--bg-card); border-top: 1px solid var(--border);
  box-shadow: 0 -10px 30px -14px rgba(0,0,0,.6);
}
.dx-bar > :last-child { margin-left: auto; }
@media (max-width: 1023px) { .dx-bar { left: 0; padding: 10px 14px; } }
@media (max-width: 560px) {
  .dx-bar > :last-child { margin-left: 0; width: 100%; justify-content: center; }
}
`

/**
 * Registering a walk-in.
 *
 * A site visitor has no user account and no client record — the system has
 * never met them — so there is nothing to attach an examination to until this
 * creates it. Only the name is required: somebody at a gate with a queue behind
 * them should not be blocked by an optional field.
 */
function VisitorForm({ onClose, onCreated }) {
  const toast = useToast()
  const [form, setForm] = useState({ name: '', phone: '', company: '', purpose: '', id_proof_type: '', id_proof_number: '' })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    if (!form.name.trim()) return toast.error('Enter the visitor’s name.')
    setBusy(true)
    try {
      const res = await medicalApi.doctor.createVisitor(form)
      toast.success('Visitor registered.')
      onCreated(res?.data ?? res)
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} style={{ width: 'min(520px, 96vw)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
        <UserPlus size={17} style={{ color: '#a78bfa' }} />
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>Register a visitor</h3>
        <button onClick={onClose} aria-label="Close" style={{ marginLeft: 'auto', ...S.btn, padding: '6px 9px', minHeight: 36 }}>
          <X size={15} />
        </button>
      </div>
      <div style={{ padding: 16, display: 'grid', gap: 11 }}>
        <div>
          <label style={S.label}>Name *</label>
          <input value={form.name} onChange={e => set('name', e.target.value)} style={{ ...S.input, minHeight: 44 }} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 11 }}>
          <div>
            <label style={S.label}>Phone</label>
            <input value={form.phone} onChange={e => set('phone', e.target.value)} style={{ ...S.input, minHeight: 44 }} />
          </div>
          <div>
            <label style={S.label}>Company</label>
            <input value={form.company} onChange={e => set('company', e.target.value)} style={{ ...S.input, minHeight: 44 }} />
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 11 }}>
          <div>
            <label style={S.label}>ID proof type</label>
            <input value={form.id_proof_type} onChange={e => set('id_proof_type', e.target.value)}
              placeholder="Aadhaar, Driving licence…" style={{ ...S.input, minHeight: 44 }} />
          </div>
          <div>
            <label style={S.label}>ID proof number</label>
            <input value={form.id_proof_number} onChange={e => set('id_proof_number', e.target.value)} style={{ ...S.input, minHeight: 44 }} />
          </div>
        </div>
        <div>
          <label style={S.label}>Purpose of visit</label>
          <input value={form.purpose} onChange={e => set('purpose', e.target.value)}
            placeholder="Safety audit, delivery, client walkthrough…" style={{ ...S.input, minHeight: 44 }} />
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} style={{ ...S.btn, minHeight: 42 }}>Cancel</button>
        <button onClick={save} disabled={busy} style={{ ...S.btnPrimary, minHeight: 42 }}>
          {busy ? 'Saving…' : 'Register & examine'}
        </button>
      </div>
    </Modal>
  )
}
