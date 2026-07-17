import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Settings2, Plus, Trash2, Pencil, Check, X, Layers, Lock } from 'lucide-react'
import { inventoryApi, INV_ACCENT, SETTING_TABS } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

/**
 * Inventory Settings (blueprint §10) — the master data every Item dropdown reads.
 *
 * Eight tabs over six tables: the four variation attributes (Color/Model/Size/
 * Style) share one table keyed by `kind`, so they share one tab renderer too.
 * Groups get an extra panel for their dependent sub-groups.
 *
 * Reads are open (staff need the lists); writes are admin-only, enforced server-side.
 */
export default function InventorySettings() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [tab, setTab] = useState(SETTING_TABS[0])

  return (
    <div className="max-w-4xl">
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Settings2 size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Inventory Settings</h1>
        {!isAdmin && (
          <span className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-lg"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            <Lock size={10} /> View only — admins can change master data
          </span>
        )}
      </header>

      <div className="flex items-center gap-1 mb-4 overflow-x-auto" style={{ borderBottom: '1px solid var(--border)' }}>
        {SETTING_TABS.map(t => (
          <button key={t.key} onClick={() => setTab(t)}
            className="px-3 py-2 text-xs font-bold whitespace-nowrap transition-colors"
            style={{
              color: tab.key === t.key ? INV_ACCENT : 'var(--text-muted)',
              borderBottom: `2px solid ${tab.key === t.key ? INV_ACCENT : 'transparent'}`,
              marginBottom: -1,
            }}>
            {t.label}
          </button>
        ))}
      </div>

      <LookupTab key={tab.key} tab={tab} isAdmin={isAdmin} />
    </div>
  )
}

/* ── One tab = one lookup list ────────────────────────────────── */

function LookupTab({ tab, isAdmin }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState({ name: '', extra: '' })
  const [editingId, setEditingId] = useState(null)
  const [editDraft, setEditDraft] = useState({ name: '', extra: '' })
  const [err, setErr] = useState('')

  const params = tab.attrKind ? { kind: tab.attrKind } : {}
  const qk = ['inv-setting', tab.key]

  const { data: rows = [], isLoading } = useQuery({ queryKey: qk, queryFn: () => inventoryApi.settings.list(tab.kind, params) })
  const bust = () => {
    qc.invalidateQueries({ queryKey: qk })
    qc.invalidateQueries({ queryKey: ['inv-settings'] })   // the Item form's dropdowns
  }

  // Each section stores its "extra" value in a different column.
  const payload = (d) => {
    const p = { name: d.name.trim() }
    if (tab.attrKind) p.kind = tab.attrKind
    if (tab.extra === 'short_name') p.short_name = d.extra || null
    if (tab.extra === 'rate') p.rate = Number(d.extra || 0)
    if (tab.extra === 'value') p.value = d.extra || null
    return p
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.settings.create(tab.kind, payload(draft)),
    onSuccess: () => { setDraft({ name: '', extra: '' }); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that.'),
  })
  const save = useMutation({
    mutationFn: (id) => inventoryApi.settings.update(tab.kind, id, payload(editDraft)),
    onSuccess: () => { setEditingId(null); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not save that.'),
  })
  const del = useMutation({
    mutationFn: (id) => inventoryApi.settings.remove(tab.kind, id),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that.'),
  })

  const extraLabel = { short_name: 'Short (kg)', rate: 'Rate %', value: 'Hex / value' }[tab.extra]
  const rowExtra = (r) => tab.extra === 'short_name' ? r.short_name : tab.extra === 'rate' ? r.rate : r.value

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />

  return (
    <div className="space-y-4">
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <Layers size={14} style={{ color: INV_ACCENT }} /> {tab.label}
          <span className="font-normal" style={{ color: 'var(--text-muted)' }}>{rows.length}</span>
        </h2>

        {err && <p className="text-xs mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

        <ul className="space-y-1.5 mb-3">
          {rows.map(r => (
            <li key={r.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
              {editingId === r.id ? (
                <>
                  <input value={editDraft.name} onChange={e => setEditDraft(d => ({ ...d, name: e.target.value }))}
                    className="flex-1 rounded-lg outline-none" style={MINI} autoFocus />
                  {tab.extra && (
                    <input value={editDraft.extra} onChange={e => setEditDraft(d => ({ ...d, extra: e.target.value }))}
                      placeholder={extraLabel} className="rounded-lg outline-none" style={{ ...MINI, width: 110 }} />
                  )}
                  <button onClick={() => save.mutate(r.id)} aria-label="Save" className="hover:opacity-70">
                    <Check size={14} style={{ color: INV_ACCENT }} />
                  </button>
                  <button onClick={() => setEditingId(null)} aria-label="Cancel" className="hover:opacity-70">
                    <X size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                </>
              ) : (
                <>
                  {tab.attrKind === 'color' && r.value && (
                    <span className="w-4 h-4 rounded-full shrink-0" style={{ background: r.value, border: '1px solid var(--border)' }} />
                  )}
                  <span className="flex-1 text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
                  {rowExtra(r) != null && rowExtra(r) !== '' && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                      {tab.extra === 'rate' ? `${rowExtra(r)}%` : rowExtra(r)}
                    </span>
                  )}
                  {tab.kind === 'groups' && (
                    <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                      {(r.subgroups || []).length} sub-groups
                    </span>
                  )}
                  {isAdmin && (
                    <>
                      <button onClick={() => { setEditingId(r.id); setEditDraft({ name: r.name, extra: rowExtra(r) ?? '' }) }}
                        aria-label={`Edit ${r.name}`} className="hover:opacity-70">
                        <Pencil size={12} style={{ color: 'var(--text-muted)' }} />
                      </button>
                      <button onClick={() => del.mutate(r.id)} aria-label={`Delete ${r.name}`} className="hover:opacity-70">
                        <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
                      </button>
                    </>
                  )}
                </>
              )}
            </li>
          ))}
          {rows.length === 0 && <li className="text-xs py-3" style={{ color: 'var(--text-muted)' }}>Nothing here yet.</li>}
        </ul>

        {isAdmin && (
          <form onSubmit={e => { e.preventDefault(); if (draft.name.trim()) add.mutate() }} className="flex flex-wrap gap-2">
            <input value={draft.name} onChange={e => setDraft(d => ({ ...d, name: e.target.value }))}
              placeholder={`New ${tab.label.replace(/s$/, '').toLowerCase()}`}
              className="flex-1 rounded-lg outline-none" style={{ ...MINI, minWidth: 160 }} />
            {tab.extra && (
              <input value={draft.extra} onChange={e => setDraft(d => ({ ...d, extra: e.target.value }))}
                placeholder={extraLabel} className="rounded-lg outline-none" style={{ ...MINI, width: 120 }} />
            )}
            <button type="submit" disabled={!draft.name.trim() || add.isPending}
              className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add">
              <Plus size={13} />
            </button>
          </form>
        )}
      </section>

      {tab.kind === 'groups' && <SubgroupPanel groups={rows} isAdmin={isAdmin} onChange={bust} />}
    </div>
  )
}

/* ── Sub-groups (dependent on a group) ────────────────────────── */

function SubgroupPanel({ groups, isAdmin, onChange }) {
  const qc = useQueryClient()
  const [groupId, setGroupId] = useState(groups[0]?.id ?? '')
  const [name, setName] = useState('')
  const [err, setErr] = useState('')

  const { data: subs = [] } = useQuery({
    queryKey: ['inv-subgroups', groupId], queryFn: () => inventoryApi.settings.subgroups(groupId), enabled: !!groupId,
  })
  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-subgroups', groupId] })
    qc.invalidateQueries({ queryKey: ['inv-setting', 'groups'] })
    onChange?.()
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.settings.create('subgroups', { name: name.trim(), group_id: Number(groupId) }),
    onSuccess: () => { setName(''); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that sub-group.'),
  })
  const del = useMutation({
    mutationFn: (id) => inventoryApi.settings.remove('subgroups', id),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that sub-group.'),
  })

  if (!groups.length) return null

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3" style={{ color: 'var(--text-h)' }}>
        Sub groups <span className="font-normal" style={{ color: 'var(--text-muted)' }}>— belong to one group</span>
      </h2>

      <div className="mb-3" style={{ maxWidth: 240 }}>
        <Select size="sm" value={groupId} onChange={setGroupId} options={groups.map(g => ({ value: g.id, label: g.name }))} />
      </div>

      {err && <p className="text-xs mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <ul className="space-y-1.5 mb-3">
        {subs.map(s => (
          <li key={s.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
            <span className="flex-1 text-xs" style={{ color: 'var(--text-h)' }}>{s.name}</span>
            {isAdmin && (
              <button onClick={() => del.mutate(s.id)} aria-label={`Delete ${s.name}`} className="hover:opacity-70">
                <Trash2 size={11} style={{ color: 'var(--color-danger-500)' }} />
              </button>
            )}
          </li>
        ))}
        {subs.length === 0 && <li className="text-xs py-2" style={{ color: 'var(--text-muted)' }}>No sub-groups in this group.</li>}
      </ul>

      {isAdmin && (
        <form onSubmit={e => { e.preventDefault(); if (name.trim() && groupId) add.mutate() }} className="flex gap-2">
          <input value={name} onChange={e => setName(e.target.value)} placeholder="New sub-group"
            className="flex-1 rounded-lg outline-none" style={MINI} />
          <button type="submit" disabled={!name.trim() || add.isPending}
            className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add sub-group">
            <Plus size={13} />
          </button>
        </form>
      )}
    </section>
  )
}

const MINI = { padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
