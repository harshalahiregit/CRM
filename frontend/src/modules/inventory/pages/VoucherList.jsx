import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { FileText, Plus, Search, Send, Ban, Trash2, Eye, Mail } from 'lucide-react'
import { inventoryApi, VOUCHER_TYPES, VOUCHER_STATUS, money } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import VoucherFormModal from '../components/VoucherFormModal'
import SendVoucherModal from '../components/SendVoucherModal'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

/**
 * One list screen for all four document types — the route's :type picks the
 * config. Status is the story here: a draft is editable paperwork, a posted
 * voucher has moved stock and can only be cancelled (which reverses it).
 */
export default function VoucherList() {
  const { type } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const cfg = VOUCHER_TYPES[type]

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirm, setConfirm] = useState(null)   // { kind:'cancel'|'delete', row }
  const [sending, setSending] = useState(null)   // voucher to email
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')
  const [raisedBy, setRaisedBy] = useState('')   // who filed the document
  const [err, setErr] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const filters = {}
  if (debounced) filters.search = debounced
  if (status) filters.status = status
  if (raisedBy) filters.created_by = raisedBy

  // Who raised each document. Admin-only: lets a manager answer "show me
  // everything Rohit filed" without reading every row.
  const { data: staff = [] } = useQuery({
    queryKey: ['inv-staff'], queryFn: inventoryApi.staff, enabled: isAdmin,
  })

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-vouchers', type, filters],
    queryFn: () => inventoryApi.vouchers.list(type, filters),
    enabled: Boolean(cfg),
  })

  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-vouchers'] })
    qc.invalidateQueries({ queryKey: ['inv-products'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
  }
  const onErr = (e) => { setConfirm(null); setErr(e?.message || 'That action failed.') }

  const post = useMutation({ mutationFn: (id) => inventoryApi.vouchers.post(type, id), onSuccess: () => { setErr(''); bust() }, onError: onErr })
  const cancel = useMutation({ mutationFn: (id) => inventoryApi.vouchers.cancel(type, id), onSuccess: () => { setConfirm(null); setErr(''); bust() }, onError: onErr })
  const remove = useMutation({ mutationFn: (id) => inventoryApi.vouchers.remove(type, id), onSuccess: () => { setConfirm(null); setErr(''); bust() }, onError: onErr })

  if (!cfg) {
    return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Unknown voucher type.</p>
  }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${cfg.accent} 14%, transparent)` }}>
          <FileText size={17} style={{ color: cfg.accent }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>{cfg.label}</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{rows.length}</span>
        <button onClick={() => { setEditing(null); setShowForm(true) }}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: cfg.accent, color: '#fff' }}>
          <Plus size={14} /> New
        </button>
      </header>
      <p className="text-xs mb-4 ml-10" style={{ color: 'var(--text-muted)' }}>{cfg.blurb}</p>

      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search code, supplier, note…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 140 }}>
          <Select size="sm" value={status} onChange={setStatus} placeholder="Any status"
            options={[{ value: '', label: 'Any status' }, ...Object.entries(VOUCHER_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))]} />
        </div>
        {isAdmin && (
          <div style={{ minWidth: 150 }}>
            <Select size="sm" value={raisedBy} onChange={setRaisedBy} placeholder="Raised by anyone"
              options={[{ value: '', label: 'Raised by anyone' }, ...staff.map(u => ({ value: String(u.id), label: u.name }))]} />
          </div>
        )}
      </div>

      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
        <table className="w-full text-sm" style={{ minWidth: 820 }}>
          <thead>
            <tr className="text-left text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
              <th className="px-4 py-3 font-bold">Docket code</th>
              <th className="px-4 py-3 font-bold">{type === 'receipt' ? 'Supplier' : type === 'delivery' ? 'Customer' : type === 'loss_adjustment' ? 'Type' : 'Note'}</th>
              <th className="px-4 py-3 font-bold">Warehouse</th>
              <th className="px-4 py-3 font-bold">Voucher day</th>
              <th className="px-4 py-3 font-bold text-right">Lines</th>
              {cfg.pricing && <th className="px-4 py-3 font-bold text-right">Total</th>}
              <th className="px-4 py-3 font-bold">Raised by</th>
              <th className="px-4 py-3 font-bold">Status</th>
              <th className="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && [1, 2, 3].map(i => (
              <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                {[...Array(cfg.pricing ? 9 : 8)].map((_, j) => (
                  <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} /></td>
                ))}
              </tr>
            ))}
            {!isLoading && rows.length === 0 && (
              <tr><td colSpan={9} className="px-4 py-12 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                {debounced || status || raisedBy ? 'No vouchers match.' : `No ${cfg.short.toLowerCase()} vouchers yet.`}
              </td></tr>
            )}
            {!isLoading && rows.map(v => {
              const st = VOUCHER_STATUS[v.status] || { label: v.status, color: 'var(--text-muted)' }
              const who = type === 'receipt' ? v.supplier_name
                : type === 'delivery' ? v.customer_name
                : type === 'loss_adjustment' ? (v.adjustment_type === 'loss' ? 'Loss' : 'Adjustment')
                : v.description
              return (
                <tr key={v.id} className="cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                  onClick={() => { setEditing(v); setShowForm(true) }}
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <td className="px-4 py-3 font-mono text-[11px] font-bold" style={{ color: cfg.accent }}>{v.code}</td>
                  <td className="px-4 py-3 text-xs truncate" style={{ color: 'var(--text-h)', maxWidth: 180 }}>{who || '—'}</td>
                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{v.warehouse?.name || (type === 'internal' ? 'per line' : '—')}</td>
                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{fmtDate(v.date_add)}</td>
                  <td className="px-4 py-3 text-right text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{v.items_count ?? 0}</td>
                  {cfg.pricing && <td className="px-4 py-3 text-right text-xs tabular-nums" style={{ color: 'var(--text-h)' }}>{money(v.total_amount)}</td>}
                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{v.creator?.name || '—'}</td>
                  <td className="px-4 py-3">
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                      style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
                  </td>
                  <td className="px-4 py-3" onClick={e => e.stopPropagation()}>
                    <div className="flex items-center justify-end gap-1">
                      {v.status === 'draft' && (
                        <RowBtn onClick={() => post.mutate(v.id)} label="Post — moves stock" color={cfg.accent}><Send size={12} /></RowBtn>
                      )}
                      {v.status === 'posted' && (
                        <RowBtn onClick={() => setConfirm({ kind: 'cancel', row: v })} label="Cancel — reverses stock" danger><Ban size={12} /></RowBtn>
                      )}
                      <RowBtn onClick={() => setSending(v)} label="Email this document"><Mail size={12} /></RowBtn>
                      <RowBtn onClick={() => { setEditing(v); setShowForm(true) }} label="View"><Eye size={12} /></RowBtn>
                      {isAdmin && v.status !== 'posted' && (
                        <RowBtn onClick={() => setConfirm({ kind: 'delete', row: v })} label="Delete" danger><Trash2 size={12} /></RowBtn>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <SendVoucherModal type={type} voucher={sending} onClose={() => setSending(null)} />

      <VoucherFormModal open={showForm} type={type} voucher={editing}
        onClose={() => { setShowForm(false); setEditing(null) }} onSaved={() => setErr('')} />

      <ConfirmModal open={Boolean(confirm)} onClose={() => setConfirm(null)}
        onConfirm={() => confirm.kind === 'cancel' ? cancel.mutate(confirm.row.id) : remove.mutate(confirm.row.id)}
        title={confirm?.kind === 'cancel' ? 'Cancel this voucher?' : 'Delete this voucher?'}
        message={confirm?.kind === 'cancel'
          ? `${confirm?.row?.code} has already moved stock. Cancelling writes reversing movements — the ledger keeps both, so the history stays honest.`
          : `${confirm?.row?.code} will be removed. Only drafts can be deleted.`}
        confirmLabel={confirm?.kind === 'cancel' ? 'Cancel voucher' : 'Delete'} danger />
    </div>
  )
}

function RowBtn({ onClick, label, danger, color, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-7 h-7 rounded-lg flex items-center justify-center"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: color || (danger ? 'var(--color-danger-500)' : 'var(--text-muted)') }}>
      {children}
    </button>
  )
}
