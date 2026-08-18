import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, BookText, Loader2, X, Receipt, Settings2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate, VOUCHER_TYPES } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import { GhostButton } from '@/modules/accounts/components/Btn'
import VoucherTypesManager from '@/modules/accounts/components/VoucherTypesManager'

const STATUS_COLORS = { posted: '#10b981', cancelled: '#f87171', draft: '#f59e0b' }

export default function Vouchers() {
  const inr = useInr()
  const toast = useToast()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [filters, setFilters] = useState({ type: '', status: '', search: '' })
  const [drawer, setDrawer] = useState(false)
  const [taxDrawer, setTaxDrawer] = useState(false)
  const [manageTypes, setManageTypes] = useState(false)

  const { data: voucherTypes = [] } = useQuery({ queryKey: ['accounts', 'voucher-types'], queryFn: accountsApi.voucherTypes.list, retry: false })
  const activeTypes = (voucherTypes.length ? voucherTypes.filter(t => t.active) : VOUCHER_TYPES.map(t => ({ code: t.code, name: t.label })))

  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'vouchers', filters],
    queryFn: () => accountsApi.vouchers.list({ ...filters, per_page: 50 }),
  })
  const vouchers = page?.data ?? []

  const post = useMutation({
    mutationFn: (payload) => accountsApi.vouchers.create(payload),
    onSuccess: (v) => { toast.success(`Voucher ${v.number} posted`); setDrawer(false); qc.invalidateQueries({ queryKey: ['accounts'] }) },
    onError: (e) => toast.error(e.message),
  })
  const postTax = useMutation({
    mutationFn: (payload) => accountsApi.taxInvoice(payload),
    onSuccess: (v) => { toast.success(`Tax invoice ${v.number} posted`); setTaxDrawer(false); qc.invalidateQueries({ queryKey: ['accounts'] }) },
    onError: (e) => toast.error(e.message),
  })

  const columns = [
    { key: 'number', label: 'Number', sortable: true, render: (r) => <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.number}</span> },
    { key: 'date', label: 'Date', sortable: true, render: (r) => fmtDate(r.date) },
    { key: 'type', label: 'Type', render: (r) => r.voucher_type?.name || '—' },
    { key: 'narration', label: 'Narration', render: (r) => <span style={{ color: 'var(--text-muted)' }}>{r.narration || '—'}</span> },
    { key: 'total_amount', label: 'Amount', align: 'right', sortable: true, render: (r) => inr(r.total_amount) },
    { key: 'status', label: 'Status', render: (r) => (
      <span className="flex items-center gap-1.5">
        <span className="text-xs font-bold capitalize" style={{ color: STATUS_COLORS[r.status] || 'var(--text-muted)' }}>{r.status}</span>
        {r.reversed_by_count > 0 && <span className="text-[10px] px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(239,68,68,0.12)', color: '#f87171' }}>reversed</span>}
        {r.is_reversal && <span className="text-[10px] px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>reversal</span>}
      </span>
    ) },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <BookText size={18} style={{ color: '#a78bfa' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Vouchers</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>The transaction journal — every entry balances to zero</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <GhostButton className="flex items-center gap-2" onClick={() => setTaxDrawer(true)}><Receipt size={15} /> Tax Invoice</GhostButton>
          <GhostButton className="flex items-center gap-2" onClick={() => setManageTypes(true)}><Settings2 size={15} /> Voucher Types</GhostButton>
          <button className="btn-3d flex items-center gap-2" onClick={() => setDrawer(true)}><Plus size={15} /> New Voucher</button>
        </div>
      </div>

      {/* Transaction type sub-tabs (old CRM parity: Sales, Expenses, Banking, etc.) */}
      <div className="flex gap-1 overflow-x-auto pb-1" style={{ borderBottom: '2px solid var(--border)' }}>
        {[
          { code: '', label: 'All Transactions' },
          { code: 'sales', label: 'Sales' },
          { code: 'purchase', label: 'Purchase' },
          { code: 'payment', label: 'Payment' },
          { code: 'receipt', label: 'Receipt' },
          { code: 'contra', label: 'Banking' },
          { code: 'journal', label: 'Journal Entry' },
          { code: 'debit_note', label: 'Debit Note' },
          { code: 'credit_note', label: 'Credit Note' },
        ].map(tab => {
          const active = filters.type === tab.code
          return (
            <button
              key={tab.code}
              onClick={() => setFilters(f => ({ ...f, type: tab.code }))}
              className="px-3.5 py-2 text-xs font-bold whitespace-nowrap rounded-t-xl transition-all"
              style={{
                color: active ? '#a78bfa' : 'var(--text-muted)',
                background: active ? 'rgba(167,139,250,0.08)' : 'transparent',
                borderBottom: active ? '2px solid #a78bfa' : '2px solid transparent',
                marginBottom: '-2px',
              }}
            >
              {tab.label}
            </button>
          )
        })}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <input className="input-3d text-sm flex-1 min-w-[200px]" placeholder="Search number / narration / ref…"
          value={filters.search} onChange={e => setFilters(f => ({ ...f, search: e.target.value }))} />
        <Select value={filters.status} onChange={e => setFilters(f => ({ ...f, status: e.target.value }))} style={{ maxWidth: 160 }}>
          <option value="">All statuses</option>
          <option value="posted">Posted</option>
          <option value="cancelled">Cancelled</option>
        </Select>
      </div>

      {isLoading
        ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <DataTable columns={columns} rows={vouchers} onRowClick={(r) => navigate(`/app/accounts/vouchers/${r.id}`)} />}

      {drawer && <VoucherDrawer types={activeTypes} saving={post.isPending} onClose={() => setDrawer(false)} onSave={(p) => post.mutate(p)} />}
      {manageTypes && (
        <Drawer open onClose={() => setManageTypes(false)} title="Manage Voucher Types" width="min(640px, 96vw)"
          footer={<GhostButton className="w-full" onClick={() => setManageTypes(false)}>Done</GhostButton>}>
          <VoucherTypesManager />
        </Drawer>
      )}
      {taxDrawer && <TaxInvoiceDrawer saving={postTax.isPending} onClose={() => setTaxDrawer(false)} onSave={(p) => postTax.mutate(p)} />}
    </div>
  )
}

const emptyItem = () => ({ ledger_id: '', taxable_amount: '', gst_rate_id: '', hsn_sac_id: '' })

function TaxInvoiceDrawer({ saving, onClose, onSave }) {
  const inr = useInr()
  const [head, setHead] = useState({ type: 'sales', date: new Date().toISOString().slice(0, 10), party_ledger_id: '', place_of_supply_state: '', reference_no: '', tds_section_code: '' })
  const [items, setItems] = useState([emptyItem()])
  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })
  const { data: rates = [] } = useQuery({ queryKey: ['accounts', 'gst-rates'], queryFn: accountsApi.gstRates.list })
  const { data: tds = [] } = useQuery({ queryKey: ['accounts', 'tds-sections'], queryFn: accountsApi.tdsSections.list })

  const setH = (k, v) => setHead(h => ({ ...h, [k]: v }))
  const setI = (i, k, v) => setItems(is => is.map((x, idx) => idx === i ? { ...x, [k]: v } : x))
  const taxable = items.reduce((s, it) => s + (parseFloat(it.taxable_amount) || 0), 0)
  const valid = head.party_ledger_id && head.date && items.some(it => it.ledger_id && parseFloat(it.taxable_amount) > 0)

  const submit = () => onSave({
    ...head,
    party_ledger_id: Number(head.party_ledger_id),
    tds_section_code: head.type === 'purchase' ? (head.tds_section_code || null) : null,
    items: items.filter(it => it.ledger_id && parseFloat(it.taxable_amount) > 0).map(it => ({
      ledger_id: Number(it.ledger_id), taxable_amount: parseFloat(it.taxable_amount),
      gst_rate_id: it.gst_rate_id ? Number(it.gst_rate_id) : null, hsn_sac_id: it.hsn_sac_id ? Number(it.hsn_sac_id) : null,
    })),
  })

  return (
    <Drawer open onClose={onClose} title="Book a GST Invoice" width="min(720px, 96vw)"
      footer={
        <div className="flex items-center gap-3">
          <div className="flex-1 text-sm" style={{ color: 'var(--text-muted)' }}>Taxable {inr(taxable)} <span className="text-xs">+ GST auto-computed</span></div>
          <GhostButton onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex items-center gap-2" disabled={!valid || saving} onClick={submit}>{saving && <Loader2 size={15} className="animate-spin" />} Post</button>
        </div>
      }>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Type" required>
            <Select value={head.type} onChange={e => setH('type', e.target.value)}><option value="sales">Sales (outward)</option><option value="purchase">Purchase (inward)</option></Select>
          </FormField>
          <FormField label="Date" required><Input type="date" value={head.date} onChange={e => setH('date', e.target.value)} /></FormField>
          <FormField label={head.type === 'sales' ? 'Customer ledger' : 'Vendor ledger'} required>
            <Select value={head.party_ledger_id} onChange={e => setH('party_ledger_id', e.target.value)}>
              <option value="">Select…</option>{ledgers.filter(l => l.is_party).map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
              {ledgers.filter(l => l.is_party).length === 0 && ledgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          </FormField>
          <FormField label="Place of supply (state code)" hint="Same as your state = CGST+SGST, else IGST">
            <Input value={head.place_of_supply_state} onChange={e => setH('place_of_supply_state', e.target.value)} maxLength={2} placeholder="27" />
          </FormField>
        </div>

        <div>
          <p className="label-caps mb-2">Line items</p>
          <div className="space-y-2">
            <div className="hidden sm:grid gap-2 px-1 text-[10px] font-bold uppercase" style={{ gridTemplateColumns: '1fr 130px 130px 28px', color: 'var(--text-muted)' }}>
              <span>{head.type === 'sales' ? 'Income ledger' : 'Expense/Asset ledger'}</span><span className="text-right">Taxable</span><span>GST rate</span><span />
            </div>
            {items.map((it, i) => (
              <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '1fr 130px 130px 28px' }}>
                <Select value={it.ledger_id} onChange={e => setI(i, 'ledger_id', e.target.value)}>
                  <option value="">Select ledger…</option>{ledgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
                </Select>
                <Input type="number" step="0.01" className="text-right" placeholder="0.00" value={it.taxable_amount} onChange={e => setI(i, 'taxable_amount', e.target.value)} />
                <Select value={it.gst_rate_id} onChange={e => setI(i, 'gst_rate_id', e.target.value)}>
                  <option value="">No GST</option>{rates.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                </Select>
                <button className="p-1 rounded-lg disabled:opacity-30" onClick={() => setItems(is => is.length > 1 ? is.filter((_, idx) => idx !== i) : is)} disabled={items.length <= 1} style={{ color: '#f87171' }}><X size={15} /></button>
              </div>
            ))}
            <button className="text-xs font-bold flex items-center gap-1" style={{ color: '#a78bfa' }} onClick={() => setItems(is => [...is, emptyItem()])}><Plus size={13} /> Add line</button>
          </div>
        </div>

        {head.type === 'purchase' && (
          <FormField label="Deduct TDS (optional)">
            <Select value={head.tds_section_code} onChange={e => setH('tds_section_code', e.target.value)}>
              <option value="">No TDS</option>{tds.map(s => <option key={s.id} value={s.section_code}>{s.section_code} — {s.rate_percent}%</option>)}
            </Select>
          </FormField>
        )}
      </div>
    </Drawer>
  )
}

const emptyLine = () => ({ ledger_id: '', debit: '', credit: '', line_narration: '' })

function VoucherDrawer({ types, saving, onClose, onSave }) {
  const typeOptions = types?.length ? types : VOUCHER_TYPES.map(t => ({ code: t.code, name: t.label }))
  const inr = useInr()
  const [head, setHead] = useState({
    voucher_type_code: 'journal',
    date: new Date().toISOString().slice(0, 10),
    narration: '',
    reference_no: '',
  })
  const [lines, setLines] = useState([emptyLine(), emptyLine()])

  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })

  const setHeadK = (k, v) => setHead(h => ({ ...h, [k]: v }))
  const setLine = (i, k, v) => setLines(ls => ls.map((l, idx) => idx === i ? { ...l, [k]: v } : l))
  const addLine = () => setLines(ls => [...ls, emptyLine()])
  const removeLine = (i) => setLines(ls => ls.length > 2 ? ls.filter((_, idx) => idx !== i) : ls)

  const totalDebit = lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0)
  const totalCredit = lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0)
  const diff = Math.round((totalDebit - totalCredit) * 100) / 100
  const filledLines = lines.filter(l => l.ledger_id && ((parseFloat(l.debit) || 0) > 0 || (parseFloat(l.credit) || 0) > 0))
  const balanced = diff === 0 && totalDebit > 0 && filledLines.length >= 2

  const submit = () => {
    onSave({
      ...head,
      lines: filledLines.map(l => ({
        ledger_id: Number(l.ledger_id),
        debit: parseFloat(l.debit) || 0,
        credit: parseFloat(l.credit) || 0,
        line_narration: l.line_narration || null,
      })),
    })
  }

  return (
    <Drawer
      open
      onClose={onClose}
      title="New Voucher"
      width="min(760px, 96vw)"
      footer={
        <div className="flex items-center gap-3">
          <div className="flex-1 text-sm font-bold" style={{ color: diff === 0 ? '#10b981' : '#f87171' }}>
            {diff === 0 ? 'Balanced' : `Out by ${inr(Math.abs(diff))}`}
          </div>
          <GhostButton onClick={onClose}>Cancel</GhostButton>
          <button className="btn-3d flex items-center gap-2" disabled={!balanced || saving} onClick={submit}>
            {saving && <Loader2 size={15} className="animate-spin" />} Post Voucher
          </button>
        </div>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Voucher type" required>
            <Select value={head.voucher_type_code} onChange={e => setHeadK('voucher_type_code', e.target.value)}>
              {typeOptions.map(t => <option key={t.code} value={t.code}>{t.name}</option>)}
            </Select>
          </FormField>
          <FormField label="Date" required>
            <Input type="date" value={head.date} onChange={e => setHeadK('date', e.target.value)} />
          </FormField>
        </div>
        <FormField label="Reference no. (optional)">
          <Input value={head.reference_no} onChange={e => setHeadK('reference_no', e.target.value)} />
        </FormField>
        <FormField label="Narration">
          <Textarea rows={2} value={head.narration} onChange={e => setHeadK('narration', e.target.value)} />
        </FormField>

        {/* Legs */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <p className="label-caps">Entries</p>
            <button className="text-xs font-bold flex items-center gap-1" style={{ color: '#a78bfa' }} onClick={addLine}><Plus size={13} /> Add line</button>
          </div>

          <div className="space-y-2">
            {/* header row */}
            <div className="hidden sm:grid gap-2 px-1 text-[10px] font-bold uppercase" style={{ gridTemplateColumns: '1fr 120px 120px 28px', color: 'var(--text-muted)' }}>
              <span>Ledger</span><span className="text-right">Debit</span><span className="text-right">Credit</span><span />
            </div>
            {lines.map((l, i) => (
              <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '1fr 120px 120px 28px' }}>
                <Select value={l.ledger_id} onChange={e => setLine(i, 'ledger_id', e.target.value)}>
                  <option value="">Select ledger…</option>
                  {ledgers.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                </Select>
                <Input type="number" step="0.01" min="0" placeholder="0.00" className="text-right"
                  value={l.debit} onChange={e => setLine(i, 'debit', e.target.value)} disabled={(parseFloat(l.credit) || 0) > 0} />
                <Input type="number" step="0.01" min="0" placeholder="0.00" className="text-right"
                  value={l.credit} onChange={e => setLine(i, 'credit', e.target.value)} disabled={(parseFloat(l.debit) || 0) > 0} />
                <button className="p-1 rounded-lg disabled:opacity-30" onClick={() => removeLine(i)} disabled={lines.length <= 2} style={{ color: '#f87171' }}><X size={15} /></button>
              </div>
            ))}
          </div>

          {/* totals */}
          <div className="grid gap-2 mt-3 pt-3 items-center" style={{ gridTemplateColumns: '1fr 120px 120px 28px', borderTop: '1px solid var(--border)' }}>
            <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Totals</span>
            <span className="text-right text-sm font-bold" style={{ color: 'var(--text-h)' }}>{inr(totalDebit)}</span>
            <span className="text-right text-sm font-bold" style={{ color: 'var(--text-h)' }}>{inr(totalCredit)}</span>
            <span />
          </div>
        </div>
      </div>
    </Drawer>
  )
}
