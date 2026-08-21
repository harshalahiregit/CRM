import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, FileSignature, CheckCircle, AlertTriangle, IndianRupee, RefreshCw } from 'lucide-react'
import { contractApi } from '@/services/contractApi'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import { exportSalesList } from '@/services/salesApi'
import ConfirmIconButton from '@/modules/customer/components/ConfirmIconButton'
import ContractDrawer from '@/modules/sales/components/ContractDrawer'

const STATUS_COLORS = { draft: '#94a3b8', active: '#10b981', expired: '#f59e0b', terminated: '#ef4444', renewed: '#3b82f6' }
const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const d10 = s => (s ? String(s).slice(0, 10) : '—')

export default function Contracts() {
  const toast = useToast()
  const nav = useNavigate()
  const [rows, setRows] = useState(null)
  const [filter, setFilter] = useState('')
  const [search, setSearch] = useState('')
  const [drawer, setDrawer] = useState({ open: false, id: null })

  const load = useCallback(() => {
    contractApi.list({ status: filter || undefined, search: search || undefined }).then(setRows).catch(e => toast.error(e.message))
  }, [filter, search])
  useEffect(() => { load() }, [load])

  const del = async (id) => { try { await contractApi.delete(id); toast.success('Contract deleted'); load() } catch (e) { toast.error(e.message) } }
  const renew = async (id) => { try { await contractApi.renew(id); toast.success('Contract renewed'); load() } catch (e) { toast.error(e.message) } }

  const kpis = rows ? [
    { label: 'Total', val: rows.length, icon: FileSignature, color: '#8b5cf6' },
    { label: 'Active', val: rows.filter(c => c.status === 'active').length, icon: CheckCircle, color: '#10b981' },
    { label: 'Expiring Soon', val: rows.filter(c => c.status === 'active' && c.end_date && new Date(c.end_date) <= new Date(Date.now() + 30 * 864e5)).length, icon: AlertTriangle, color: '#f59e0b' },
    { label: 'Total Value', val: fmt(rows.reduce((s, c) => s + Number(c.value || 0), 0)), icon: IndianRupee, color: '#3b82f6' },
  ] : []

  // Paging + count only: this list's search and status filter are server-side.
  const { pageSize, setPageSize, visible, matched } = useListView(rows, [])

  return (
    <div className="p-4 md:p-6 max-w-[1400px] mx-auto">
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales &amp; Revenue</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Contracts</h1>
        </div>
        <button onClick={() => setDrawer({ open: true, id: null })} className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={16} /> New Contract
        </button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        {kpis.map(k => (
          <div key={k.label} className="kpi-3d p-4">
            <k.icon size={18} style={{ color: k.color }} />
            <p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{k.val}</p>
            <p className="text-[11px] font-medium" style={{ color: 'var(--text-muted)' }}>{k.label}</p>
          </div>
        ))}
      </div>

      {/* Toolbar: search · status · count · rows-per-page · refresh · export */}
      <ListToolbar
        className="mb-4"
        search={search} onSearch={setSearch} searchPlaceholder="Search contracts…"
        count={matched} total={rows?.length ?? 0} unit="contract"
        pageSize={pageSize} onPageSize={setPageSize} onRefresh={load}
        onExport={() => exportSalesList('contracts', { status: filter || undefined, search: search || undefined })
          .catch(e => toast.error(e.message))}
      >
        <select className="input-3d text-sm w-40" value={filter} onChange={e => setFilter(e.target.value)}>
          <option value="">All Statuses</option>
          {['draft', 'active', 'expired', 'terminated', 'renewed'].map(s => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
        </select>
      </ListToolbar>

      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['Reference', 'Subject', 'Customer', 'Value', 'Start', 'End', 'Signed', 'Status', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
            </tr></thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={9} className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={9} className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No contracts yet.</td></tr>
              ) : visible.map(c => (
                <tr key={c.id} className="cursor-pointer" onClick={() => nav(`/app/sales/contracts/${c.id}`)} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{c.reference_no}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-body)' }}>{c.subject}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{c.client?.company || '—'}</td>
                  <td className="py-3 px-4 font-semibold" style={{ color: '#10b981' }}>{fmt(c.value)}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(c.start_date)}</td>
                  <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(c.end_date)}</td>
                  <td className="py-3 px-4" style={{ color: c.signed_at ? '#10b981' : 'var(--text-muted)' }} title={c.signed_at ? `Signed by ${c.signed_by_name || ''}` : 'Unsigned'}>{c.signed_at ? '✓ Signed' : '—'}</td>
                  <td className="py-3 px-4"><span className="text-[10px] font-bold px-2 py-0.5 rounded-md" style={{ background: `${STATUS_COLORS[c.status]}22`, color: STATUS_COLORS[c.status] }}>{c.status}</span></td>
                  <td className="py-3 px-4" onClick={e => e.stopPropagation()}>
                    <div className="flex gap-1 justify-end">
                      {c.status === 'active' && <button onClick={() => renew(c.id)} title="Renew" className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]"><RefreshCw size={13} style={{ color: 'var(--text-muted)' }} /></button>}
                      <ConfirmIconButton onConfirm={() => del(c.id)} title="Delete contract?" message="This contract will be removed." />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <ContractDrawer open={drawer.open} contractId={drawer.id} onClose={() => setDrawer({ open: false, id: null })} onSaved={load} />
    </div>
  )
}
