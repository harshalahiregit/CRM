import { useState, useEffect } from 'react'
import { Plus, Check, X, IndianRupee, Percent } from 'lucide-react'
import { commissionApi } from '@/services/commissionApi'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import ConfirmIconButton from '@/modules/customer/components/ConfirmIconButton'
import Drawer from '@/components/ui/Drawer'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const STATUS_COLORS = { pending: '#f59e0b', approved: '#10b981', rejected: '#ef4444' }
const BLANK = { name: '', basis: 'executive', calc_type: 'percentage', rate: '', applies_to: 'paid_invoice', min_amount: '', is_active: true }

export default function Commission() {
  const toast = useToast()
  const [tab, setTab] = useState('entries')
  const [rules, setRules] = useState(null)
  const [entries, setEntries] = useState(null)
  const [drawer, setDrawer] = useState(false)
  const [form, setForm] = useState(BLANK)

  const loadRules = () => commissionApi.rules().then(setRules).catch(e => toast.error(e.message))
  const loadEntries = () => commissionApi.entries().then(setEntries).catch(e => toast.error(e.message))
  useEffect(() => { loadRules(); loadEntries() }, [])

  const saveRule = async () => {
    if (!form.name.trim() || form.rate === '') return toast.error('Name & rate required')
    try {
      await commissionApi.createRule({
        name: form.name, basis: form.basis, calc_type: form.calc_type, rate: Number(form.rate),
        applies_to: form.applies_to, is_active: form.is_active,
        conditions: form.min_amount ? { min_amount: Number(form.min_amount) } : null,
      })
      toast.success('Rule created'); setDrawer(false); setForm(BLANK); loadRules()
    } catch (e) { toast.error(e.message) }
  }
  const delRule = async (id) => { try { await commissionApi.deleteRule(id); toast.success('Deleted'); loadRules() } catch (e) { toast.error(e.message) } }
  const act = async (fn, id) => { try { await fn(id); loadEntries() } catch (e) { toast.error(e.message) } }

  // Entries only: the rules table below is a short config list.
  const { search, setSearch, pageSize, setPageSize, visible, matched } =
    useListView(entries, ['staff_name', 'status', 'source_type'])

  return (
    <div className="p-4 md:p-6 max-w-[1300px] mx-auto">
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales &amp; Revenue</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Commission</h1>
        </div>
        {tab === 'rules' && (
          <button onClick={() => { setForm(BLANK); setDrawer(true) }} className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
            <Plus size={16} /> New Rule
          </button>
        )}
      </div>

      <div className="flex rounded-xl overflow-hidden mb-4 w-fit" style={{ border: '1px solid var(--border)' }}>
        <button onClick={() => setTab('entries')} className="px-4 py-2 text-xs font-bold" style={{ background: tab === 'entries' ? 'var(--accent)' : 'transparent', color: tab === 'entries' ? '#fff' : 'var(--text-body)' }}>Entries</button>
        <button onClick={() => setTab('rules')} className="px-4 py-2 text-xs font-bold" style={{ background: tab === 'rules' ? 'var(--accent)' : 'transparent', color: tab === 'rules' ? '#fff' : 'var(--text-body)' }}>Rules</button>
      </div>

      {tab === 'entries' && (
        <>
        <ListToolbar
          search={search} onSearch={setSearch} searchPlaceholder="Search by staff or status…"
          count={matched} total={(entries || []).length} unit="entry"
          pageSize={pageSize} onPageSize={setPageSize} onRefresh={loadEntries} className="mb-3" />
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['Rule', 'Staff', 'Base', 'Commission', 'Status', 'Payout', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
            </tr></thead>
            <tbody>
              {entries === null ? <tr><td colSpan={7} className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
                : entries.length === 0 ? <tr><td colSpan={7} className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No commission entries yet. They're generated automatically when invoices are paid or leads are won.</td></tr>
                : visible.map(e => (
                  <tr key={e.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{e.rule?.name || '—'}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{e.staff?.name || 'Unassigned'}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{fmt(e.base_amount)}</td>
                    <td className="py-3 px-4 font-semibold" style={{ color: '#10b981' }}>{fmt(e.commission_amount)}</td>
                    <td className="py-3 px-4"><span className="text-[10px] font-bold px-2 py-0.5 rounded-md" style={{ background: `${STATUS_COLORS[e.status]}22`, color: STATUS_COLORS[e.status] }}>{e.status}</span></td>
                    <td className="py-3 px-4"><span className="text-[10px] font-bold" style={{ color: e.payout_status === 'paid' ? '#10b981' : 'var(--text-muted)' }}>{e.payout_status}</span></td>
                    <td className="py-3 px-4">
                      <div className="flex gap-1 justify-end">
                        {e.status === 'pending' && <>
                          <button onClick={() => act(commissionApi.approve, e.id)} title="Approve"><Check size={14} style={{ color: '#10b981' }} /></button>
                          <button onClick={() => act(commissionApi.reject, e.id)} title="Reject"><X size={14} style={{ color: '#ef4444' }} /></button>
                        </>}
                        {e.status === 'approved' && e.payout_status === 'unpaid' && <button onClick={() => act(commissionApi.markPaid, e.id)} className="text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>Mark Paid</button>}
                      </div>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
        </>
      )}

      {tab === 'rules' && (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['Rule', 'Basis', 'Rate', 'Applies To', 'Active', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
            </tr></thead>
            <tbody>
              {rules === null ? <tr><td colSpan={6} className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rules.length === 0 ? <tr><td colSpan={6} className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No rules yet. Create one to auto-calculate commissions.</td></tr>
                : rules.map(r => (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{r.name}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{r.basis}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-body)' }}>{r.calc_type === 'flat' ? fmt(r.rate) : `${r.rate}%`}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{r.applies_to?.replace('_', ' ')}</td>
                    <td className="py-3 px-4">{r.is_active ? <Check size={14} style={{ color: '#10b981' }} /> : '—'}</td>
                    <td className="py-3 px-4 text-right"><ConfirmIconButton onConfirm={() => delRule(r.id)} title="Delete rule?" message="Existing entries are kept." /></td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}

      <Drawer open={drawer} onClose={() => setDrawer(false)} title="New Commission Rule"
        footer={<div className="flex justify-end gap-2">
          <button onClick={() => setDrawer(false)} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={saveRule} className="px-5 py-2 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Create</button>
        </div>}>
        <div className="space-y-4">
          <div><label className="label">Rule Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} placeholder="e.g. Standard 5% on paid invoices" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Basis</label><select className="input-3d text-sm" value={form.basis} onChange={e => setForm(p => ({ ...p, basis: e.target.value }))}><option value="executive">Executive</option><option value="product">Product</option><option value="target">Target</option></select></div>
            <div><label className="label">Applies To</label><select className="input-3d text-sm" value={form.applies_to} onChange={e => setForm(p => ({ ...p, applies_to: e.target.value }))}><option value="paid_invoice">Paid Invoice</option><option value="won_deal">Won Deal</option><option value="both">Both</option></select></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Type</label><select className="input-3d text-sm" value={form.calc_type} onChange={e => setForm(p => ({ ...p, calc_type: e.target.value }))}><option value="percentage">Percentage</option><option value="flat">Flat Amount</option></select></div>
            <div><label className="label">{form.calc_type === 'flat' ? 'Amount (₹)' : 'Rate (%)'}</label><input type="number" className="input-3d text-sm" value={form.rate} onChange={e => setForm(p => ({ ...p, rate: e.target.value }))} /></div>
          </div>
          <div><label className="label">Minimum Base Amount (optional)</label><input type="number" className="input-3d text-sm" value={form.min_amount} onChange={e => setForm(p => ({ ...p, min_amount: e.target.value }))} placeholder="Only apply above this value" /></div>
        </div>
      </Drawer>
    </div>
  )
}
