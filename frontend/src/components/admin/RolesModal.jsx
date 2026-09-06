/**
 * Managing roles: rename one, change what it grants, add or remove your own.
 *
 * The API for this shipped without a screen, so roles could be created by the
 * seeder and assigned to people, but never edited — which removes the point of
 * roles being records rather than code.
 *
 * Editing a role reaches everybody who holds it, immediately. That is the whole
 * advantage over the old copy-on-create templates and also the thing most likely
 * to surprise someone, so the screen says it out loud before saving.
 */

import { useState, useEffect, useCallback } from 'react'
import { X, Shield, Trash2, Plus, Check } from 'lucide-react'
import api from '@/lib/api'
import { useToast } from '@/components/ui/Toast'

const LABEL = k => k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

export default function RolesModal({ onClose, onChanged }) {
  const toast = useToast()

  const [roles,   setRoles]   = useState([])
  const [modules, setModules] = useState([])
  const [caps,    setCaps]    = useState([])
  const [loading, setLoading] = useState(true)
  const [busy,    setBusy]    = useState(false)

  const [selected, setSelected] = useState(null)   // the role being edited
  const [name,     setName]     = useState('')
  const [perms,    setPerms]    = useState({})
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const d = (await api.get('/admin/roles'))?.data?.data
      setRoles(d?.roles || []); setModules(d?.modules || []); setCaps(d?.capabilities || [])
    } catch {
      toast.error('Could not load roles.')
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => { load() }, [load])

  const pick = (role) => {
    setCreating(false)
    setSelected(role)
    setName(role.name)
    setPerms(role.permissions || {})
  }

  const startNew = () => {
    setCreating(true); setSelected(null); setName(''); setPerms({})
  }

  const toggle = (mod, cap) => {
    setPerms(prev => {
      const have = prev[mod] || []
      const next = have.includes(cap) ? have.filter(c => c !== cap) : [...have, cap]
      const out = { ...prev }
      // Drop the module entirely when nothing is left, so an empty array is never
      // stored as "this module was considered and denied" — it means the same as
      // absent, and two representations of one state invite a bug.
      if (next.length) out[mod] = next; else delete out[mod]
      return out
    })
  }

  const save = async () => {
    if (!name.trim()) return toast.error('Give the role a name.')

    setBusy(true)
    try {
      if (creating) {
        await api.post('/admin/roles', { name: name.trim(), permissions: perms })
        toast.success('Role created.')
      } else {
        const res = await api.put(`/admin/roles/${selected.id}`, { name: name.trim(), permissions: perms })
        toast.success(res?.data?.message || 'Role updated.')
      }
      setCreating(false); setSelected(null)
      await load(); onChanged?.()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  const remove = async (role) => {
    setBusy(true)
    try {
      await api.delete(`/admin/roles/${role.id}`)
      toast.success('Role deleted.')
      if (selected?.id === role.id) setSelected(null)
      await load(); onChanged?.()
    } catch (e) {
      // The server refuses a role still in use, and names how many hold it.
      toast.error(e?.response?.data?.message || 'That role could not be deleted.')
    } finally {
      setBusy(false)
    }
  }

  const editing = creating || selected

  return (
    <div role="dialog" aria-modal="true" aria-label="Roles"
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: 'rgba(0,0,0,0.6)' }} onClick={onClose}>
      <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-3xl flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

        <div className="flex items-center gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <Shield size={18} style={{ color: '#7C3AED' }} />
          <div className="flex-1">
            <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>Roles</h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              A role sets what someone can do. Changing one applies to everybody who has it.
            </p>
          </div>
          <button onClick={onClose} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
        </div>

        <div className="flex-1 overflow-y-auto grid" style={{ gridTemplateColumns: '220px 1fr', minHeight: 0 }}>
          {/* the roles */}
          <div className="overflow-y-auto p-3 flex flex-col gap-1" style={{ borderRight: '1px solid var(--border)' }}>
            {loading ? (
              <p className="text-xs p-3" style={{ color: 'var(--text-muted)' }}>Loading…</p>
            ) : (
              <>
                {roles.map(r => (
                  <button key={r.id} onClick={() => pick(r)}
                    className="text-left rounded-lg flex items-center gap-2"
                    style={{
                      padding: '8px 10px',
                      background: selected?.id === r.id ? 'rgba(124,58,237,0.12)' : 'transparent',
                      border: `1px solid ${selected?.id === r.id ? 'rgba(124,58,237,0.35)' : 'transparent'}`,
                    }}>
                    <span className="flex-1 min-w-0">
                      <span className="text-xs font-bold block truncate" style={{ color: 'var(--text-h)' }}>{r.name}</span>
                      <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {r.granted_count} permission{r.granted_count === 1 ? '' : 's'}
                        {r.is_system && ' · standard'}
                      </span>
                    </span>
                    {/* Standard roles can be renamed and re-scoped but not removed:
                        deleting the one every employee holds is not an undo. */}
                    {!r.is_system && (
                      <span role="button" tabIndex={0}
                        onClick={e => { e.stopPropagation(); remove(r) }}
                        onKeyDown={e => { if (e.key === 'Enter') { e.stopPropagation(); remove(r) } }}
                        aria-label={`Delete ${r.name}`} style={{ color: 'var(--text-muted)' }}>
                        <Trash2 size={12} />
                      </span>
                    )}
                  </button>
                ))}

                <button onClick={startNew}
                  className="text-left rounded-lg flex items-center gap-1.5 mt-1"
                  style={{ padding: '8px 10px', color: '#7C3AED', border: '1px dashed var(--border)' }}>
                  <Plus size={12} /> <span className="text-xs font-bold">New role</span>
                </button>
              </>
            )}
          </div>

          {/* what it grants */}
          <div className="overflow-y-auto p-4">
            {!editing ? (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Pick a role to see what it grants, or create one.
              </p>
            ) : (
              <div className="flex flex-col gap-3">
                <label className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    Role name
                  </span>
                  <input value={name} onChange={e => setName(e.target.value)} maxLength={80}
                    className="rounded-lg text-sm w-full"
                    style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                </label>

                {selected && (
                  <p className="text-[11px] rounded-lg" style={{ padding: '7px 9px', background: 'rgba(251,191,36,0.08)', color: '#fbbf24' }}>
                    Saving applies to everyone on this role.
                  </p>
                )}

                <div className="flex flex-col gap-1.5">
                  {modules.map(mod => {
                    const on = perms[mod] || []
                    return (
                      <div key={mod} className="rounded-lg" style={{ padding: '8px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                        <p className="text-[11px] font-bold mb-1.5" style={{ color: on.length ? 'var(--text-h)' : 'var(--text-muted)' }}>
                          {LABEL(mod)}
                        </p>
                        <div className="flex flex-wrap gap-1">
                          {caps.map(cap => {
                            const active = on.includes(cap)
                            return (
                              <button key={cap} type="button" onClick={() => toggle(mod, cap)}
                                className="rounded-md text-[10px] font-bold flex items-center gap-1"
                                style={{
                                  padding: '3px 7px',
                                  background: active ? 'rgba(124,58,237,0.16)' : 'var(--bg-card)',
                                  border: `1px solid ${active ? '#7C3AED' : 'var(--border)'}`,
                                  color: active ? '#a78bfa' : 'var(--text-muted)',
                                }}>
                                {active && <Check size={9} />} {LABEL(cap)}
                              </button>
                            )
                          })}
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            )}
          </div>
        </div>

        <div className="p-4 flex gap-2" style={{ borderTop: '1px solid var(--border)' }}>
          <button onClick={onClose}
            className="rounded-lg text-xs font-semibold flex-1"
            style={{ padding: '9px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
            Close
          </button>
          {editing && (
            <button onClick={save} disabled={busy}
              className="rounded-lg text-xs font-bold flex-1"
              style={{ padding: '9px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Saving…' : creating ? 'Create role' : 'Save role'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
