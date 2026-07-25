import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Loader2, Edit2, Trash2, Lock } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { useToast } from '@/hooks/useToast'
import FormField, { Input } from '@/components/ui/FormField'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

/**
 * Reusable voucher-type manager — mounted in Accounts Settings and, via an
 * edit button, on the Vouchers page. System types can be renamed and have
 * their numbering prefix / GST flag edited but not deleted; custom types are
 * fully editable. A custom type is a named, separately-numbered manual voucher
 * — the entry screen supplies the balanced legs, so it carries no posting logic.
 */
export default function VoucherTypesManager() {
  const toast = useToast()
  const qc = useQueryClient()
  const [drawer, setDrawer] = useState(null)     // {} new · {id,...} edit
  const [confirmDel, setConfirmDel] = useState(null)

  const { data: types = [], isLoading, isError } = useQuery({
    queryKey: ['accounts', 'voucher-types'],
    queryFn: accountsApi.voucherTypes.list,
    retry: false,
  })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'voucher-types'] })

  const save = useMutation({
    mutationFn: ({ id, ...data }) => id ? accountsApi.voucherTypes.update(id, data) : accountsApi.voucherTypes.create(data),
    onSuccess: () => { toast.success('Voucher type saved'); setDrawer(null); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.voucherTypes.remove(id),
    onSuccess: () => { toast.success('Voucher type deleted'); setConfirmDel(null); invalidate() },
    onError: (e) => { toast.error(e.message); setConfirmDel(null) },
  })

  if (isLoading) return <div className="flex justify-center py-8"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  if (isError) return <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>The accounts books aren't set up yet — run the one-click setup first.</p>

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Voucher Types</h3>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Rename the built-in kinds or add your own (e.g. "Cash Sales") with a custom prefix.</p>
        </div>
        <button onClick={() => setDrawer({})} className="btn-3d flex items-center gap-2 flex-shrink-0"><Plus size={15} /> New Type</button>
      </div>

      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        {types.map((t, i) => (
          <div key={t.id} className="flex items-center justify-between px-3 py-2.5"
            style={{ borderBottom: i < types.length - 1 ? '1px solid var(--border)' : 'none', opacity: t.active ? 1 : 0.5 }}>
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{t.name}</span>
              {t.prefix && <span className="text-[10px] px-1.5 py-0.5 rounded-md font-mono" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>{t.prefix}</span>}
              {t.is_system
                ? <span className="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-md font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Lock size={9} /> System</span>
                : <span className="text-[10px] px-1.5 py-0.5 rounded-md font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>Custom</span>}
              {!t.active && <span className="text-[10px]" style={{ color: 'var(--text-faint)' }}>inactive</span>}
            </div>
            <div className="flex gap-1 flex-shrink-0">
              <button onClick={() => setDrawer(t)} title="Edit" className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]"><Edit2 size={13} style={{ color: 'var(--text-muted)' }} /></button>
              <button onClick={() => setConfirmDel(t)} disabled={t.is_system} title={t.is_system ? 'System type — cannot delete' : 'Delete'}
                className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] disabled:opacity-30 disabled:cursor-not-allowed">
                <Trash2 size={13} style={{ color: '#f87171' }} />
              </button>
            </div>
          </div>
        ))}
      </div>

      {drawer && <TypeDrawer type={drawer.id ? drawer : null} saving={save.isPending} onClose={() => setDrawer(null)} onSave={(d) => save.mutate(d)} />}
      {confirmDel && (
        <ConfirmDialog
          title="Delete voucher type?"
          message={`"${confirmDel.name}" will be removed. Types already used by posted vouchers can't be deleted — deactivate them instead.`}
          confirmLabel="Delete" tone="danger"
          onConfirm={() => remove.mutate(confirmDel.id)} onCancel={() => setConfirmDel(null)}
        />
      )}
    </div>
  )
}

function TypeDrawer({ type, saving, onClose, onSave }) {
  const [form, setForm] = useState({
    name: type?.name || '',
    prefix: type?.prefix || '',
    active: type?.active ?? true,
    affects_gst: type?.affects_gst ?? false,
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const sys = type?.is_system

  return (
    <Drawer open onClose={onClose} title={type ? `Edit "${type.name}"` : 'New Voucher Type'}
      footer={
        <div className="flex gap-3">
          <GhostButton className="flex-1" onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex-1 flex items-center justify-center gap-2" disabled={!form.name.trim() || saving}
            onClick={() => onSave({ id: type?.id, name: form.name.trim(), prefix: form.prefix.trim() || undefined, active: form.active, affects_gst: form.affects_gst })}>
            {saving && <Loader2 size={15} className="animate-spin" />} {type ? 'Save' : 'Create'}
          </button>
        </div>
      }>
      <div className="space-y-4">
        <FormField label="Name" required>
          <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Cash Sales" />
        </FormField>
        <FormField label="Numbering prefix" hint="Shown before the voucher number, e.g. CS-0001">
          <Input value={form.prefix} onChange={e => set('prefix', e.target.value.toUpperCase())} placeholder="CS" />
        </FormField>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
          <input type="checkbox" checked={form.affects_gst} onChange={e => set('affects_gst', e.target.checked)} /> Involves GST
        </label>
        {type && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="checkbox" checked={form.active} onChange={e => set('active', e.target.checked)} /> Active (available in the New Voucher dropdown)
          </label>
        )}
        {sys && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>This is a built-in type — you can rename it and change its numbering, but its posting behaviour and code stay fixed.</p>}
      </div>
    </Drawer>
  )
}
