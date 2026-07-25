import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Loader2, Edit2, Trash2, Lock, X } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { NATURE_LABEL } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import FormField, { Input, Select } from '@/components/ui/FormField'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

const NATURE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense']
const NATURE_COLOR = { asset: '#22d3ee', liability: '#f59e0b', equity: '#a78bfa', income: '#10b981', expense: '#f87171' }

/**
 * Reusable Chart-of-Accounts group manager — the single source of the account
 * groups that feed the New Ledger / New Group dropdowns everywhere. Mounted in
 * both the Accounts Settings and the main workspace Settings so groups can be
 * curated in either place. System (seeded) groups are protected: they can't be
 * renamed or deleted, only extended with sub-groups.
 */
export default function AccountGroupsManager() {
  const toast = useToast()
  const qc = useQueryClient()
  const [drawer, setDrawer] = useState(null)     // {} = new, {id,...} = edit
  const [confirmDel, setConfirmDel] = useState(null)

  const { data: groups = [], isLoading, isError } = useQuery({
    queryKey: ['accounts', 'account-groups', 'flat'],
    queryFn: accountsApi.groups.flat,
    retry: false,
  })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'account-groups'] })

  const save = useMutation({
    mutationFn: ({ id, ...data }) => id ? accountsApi.groups.update(id, data) : accountsApi.groups.create(data),
    onSuccess: () => { toast.success('Group saved'); setDrawer(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.groups.remove(id),
    onSuccess: () => { toast.success('Group deleted'); setConfirmDel(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirmDel(null) },
  })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (isError) {
    return (
      <div className="text-center py-10">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>The accounts books aren't set up yet.</p>
        <p className="text-xs mt-1" style={{ color: 'var(--text-faint)' }}>Open Accounts &amp; Finance → run the one-click setup, then manage groups here.</p>
      </div>
    )
  }

  const byNature = NATURE_ORDER.map(n => ({ nature: n, items: groups.filter(g => g.nature === n) })).filter(g => g.items.length)

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Account Groups</h3>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>The classification tree behind every ledger dropdown. Add your own; seeded groups are locked.</p>
        </div>
        <button onClick={() => setDrawer({})} className="btn-3d flex items-center gap-2 flex-shrink-0"><Plus size={15} /> New Group</button>
      </div>

      <div className="space-y-4">
        {byNature.map(({ nature, items }) => (
          <div key={nature}>
            <p className="label-caps mb-1.5" style={{ color: NATURE_COLOR[nature] }}>{NATURE_LABEL[nature]}</p>
            <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              {items.map((g, i) => (
                <div key={g.id} className="flex items-center justify-between px-3 py-2.5"
                  style={{ borderBottom: i < items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                  <div className="flex items-center gap-2 min-w-0">
                    {g.parent_id && <span style={{ color: 'var(--text-faint)' }}>↳</span>}
                    <span className="text-sm truncate" style={{ color: 'var(--text-h)' }}>{g.name}</span>
                    {g.is_system && <span className="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-md font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Lock size={9} /> System</span>}
                  </div>
                  <div className="flex gap-1 flex-shrink-0">
                    <button onClick={() => setDrawer(g)} disabled={g.is_system} title={g.is_system ? 'System group — locked' : 'Edit'}
                      className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] disabled:opacity-30 disabled:cursor-not-allowed">
                      <Edit2 size={13} style={{ color: 'var(--text-muted)' }} />
                    </button>
                    <button onClick={() => setConfirmDel(g)} disabled={g.is_system} title={g.is_system ? 'System group — locked' : 'Delete'}
                      className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] disabled:opacity-30 disabled:cursor-not-allowed">
                      <Trash2 size={13} style={{ color: '#f87171' }} />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>

      {drawer && <GroupDrawer group={drawer.id ? drawer : null} groups={groups} saving={save.isPending} onClose={() => setDrawer(null)} onSave={(d) => save.mutate(d)} />}
      {confirmDel && (
        <ConfirmDialog
          title="Delete account group?"
          message={`"${confirmDel.name}" will be removed. A group with ledgers or sub-groups under it can't be deleted.`}
          confirmLabel="Delete" tone="danger"
          onConfirm={() => remove.mutate(confirmDel.id)}
          onCancel={() => setConfirmDel(null)}
        />
      )}
    </div>
  )
}

function GroupDrawer({ group, groups, saving, onClose, onSave }) {
  const [form, setForm] = useState({
    name: group?.name || '',
    parent_id: group?.parent_id || '',
    nature: group?.nature || 'asset',
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const valid = form.name.trim() && (form.parent_id || form.nature)
  // A group can't be its own parent.
  const parentOptions = groups.filter(g => !group || g.id !== group.id)

  return (
    <Drawer
      open onClose={onClose}
      title={group ? 'Edit Account Group' : 'New Account Group'}
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!valid || saving}
            onClick={() => onSave({ id: group?.id, name: form.name.trim(), parent_id: form.parent_id || null, nature: form.parent_id ? undefined : form.nature })}>
            {saving && <Loader2 size={15} className="animate-spin" />} {group ? 'Save' : 'Create'}
          </button>
        </div>
      }
    >
      <div className="space-y-4">
        <FormField label="Group name" required>
          <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Sales — GST 18%" />
        </FormField>
        <FormField label="Parent group (optional)" hint="A sub-group inherits its parent's nature.">
          <Select value={form.parent_id} onChange={e => set('parent_id', e.target.value)}>
            <option value="">None (top-level group)</option>
            {parentOptions.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select>
        </FormField>
        {!form.parent_id && (
          <FormField label="Nature" required>
            <Select value={form.nature} onChange={e => set('nature', e.target.value)}>
              {Object.entries(NATURE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
          </FormField>
        )}
      </div>
    </Drawer>
  )
}
