import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  ArrowLeft, BarChart3, Download, FileText, Loader2, Users,
  IndianRupee, Receipt, Clock, Activity as ActivityIcon,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useMoneyFmt, MoneyToggle } from '@/components/ui/Money'
import { useToast } from '@/hooks/useToast'

// Sections rather than one giant table — the report carries four different
// stories (money owed, tax, how late it is, how engaged the customer is) and
// they have nothing useful to say side by side.
const SECTIONS = [
  { key: 'financials', label: 'Financials', icon: IndianRupee },
  { key: 'tax',        label: 'GST & TDS',  icon: Receipt },
  { key: 'ageing',     label: 'Ageing',     icon: Clock },
  { key: 'activity',   label: 'Activity',   icon: ActivityIcon },
  { key: 'compare',    label: 'All Groups', icon: BarChart3 },
]

export default function GroupReports() {
  const nav = useNavigate()
  const toast = useToast()
  const money = useMoneyFmt()
  const [groupId, setGroupId] = useState('')      // '' = all customers
  const [range, setRange] = useState({ from: '', to: '' })
  const [section, setSection] = useState('financials')
  const [busy, setBusy] = useState(false)

  const params = {
    ...(groupId ? { group_id: Number(groupId) } : {}),
    ...(range.from ? { from: range.from } : {}),
    ...(range.to ? { to: range.to } : {}),
  }

  const { data: groups = [] } = useQuery({ queryKey: ['client-groups'], queryFn: customerApi.groups.list })
  const { data: report, isLoading, isError, error } = useQuery({
    queryKey: ['group-report', params],
    queryFn: () => customerApi.groupReports.show(params),
  })
  const { data: compare } = useQuery({
    queryKey: ['group-report-compare', range],
    queryFn: () => customerApi.groupReports.comparison({ ...(range.from ? { from: range.from } : {}), ...(range.to ? { to: range.to } : {}) }),
    enabled: section === 'compare',
  })

  const download = async (kind, format) => {
    setBusy(true)
    try { await customerApi.groupReports.download(kind, { ...params, ...(format ? { format } : {}) }) }
    catch (e) { toast.error(e?.message || 'Download failed') }
    finally { setBusy(false) }
  }

  const t = report?.totals
  const rows = report?.clients ?? []

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/app/customers')}
            className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Customers</p>
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Group Reports</h1>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <MoneyToggle />
          <button disabled={busy} onClick={() => download('csv', 'csv')}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold disabled:opacity-50"
            style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            <Download size={14} /> CSV
          </button>
          <button disabled={busy} onClick={() => download('csv', 'xlsx')}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold disabled:opacity-50"
            style={{ background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            <Download size={14} /> Excel
          </button>
          <button disabled={busy} onClick={() => download('pdf')}
            className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-50"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <FileText size={14} />} PDF
          </button>
        </div>
      </div>

      {/* Filters */}
      <div className="card-3d flex flex-wrap items-end gap-3" style={{ padding: 16 }}>
        <div style={{ minWidth: 220 }}>
          <label className="label">Group</label>
          <select className="input-3d text-sm" value={groupId} onChange={e => setGroupId(e.target.value)}>
            <option value="">All Customers</option>
            {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Invoices from</label>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
        </div>
        <div>
          <label className="label">to</label>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
        {(range.from || range.to) && (
          <button onClick={() => setRange({ from: '', to: '' })} className="text-xs font-semibold px-3 py-2" style={{ color: 'var(--accent)' }}>
            Clear dates
          </button>
        )}
        <p className="text-[11px] ml-auto" style={{ color: 'var(--text-muted)' }}>
          Ageing is always as of today{report?.as_of ? ` (${report.as_of})` : ''}, regardless of the date range.
        </p>
      </div>

      {isError && (
        <div className="card-3d text-sm" style={{ padding: 16, color: '#f87171' }}>
          Couldn’t load the report: {error?.message || 'unknown error'}
        </div>
      )}

      {isLoading ? (
        <div className="flex justify-center py-12"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
      ) : t && (
        <>
          {/* Summary tiles */}
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <Tile label="Customers" value={t.customer_count} icon={Users} plain />
            <Tile label="Invoices" value={t.invoice_count} plain />
            <Tile label="Billed" value={money(t.total_billed)} />
            <Tile label="Paid" value={money(t.total_paid)} color="#10b981" />
            <Tile label="Outstanding" value={money(t.outstanding)} color={t.outstanding > 0 ? '#f59e0b' : undefined} />
            <Tile label="GST Unpaid" value={money(t.gst_unpaid)} color={t.gst_unpaid > 0 ? '#f87171' : undefined} />
          </div>

          {/* Section tabs */}
          <div className="flex gap-1 flex-wrap" style={{ borderBottom: '1px solid var(--border)' }}>
            {SECTIONS.map(s => {
              const Icon = s.icon
              return (
                <button key={s.key} onClick={() => setSection(s.key)}
                  className="px-4 py-2.5 text-sm font-bold -mb-px flex items-center gap-1.5"
                  style={{ color: section === s.key ? '#a78bfa' : 'var(--text-muted)',
                    borderBottom: section === s.key ? '2px solid #a78bfa' : '2px solid transparent' }}>
                  <Icon size={14} /> {s.label}
                </button>
              )
            })}
          </div>

          {section !== 'compare' && rows.length === 0 && (
            <p className="text-sm text-center py-10" style={{ color: 'var(--text-muted)' }}>
              No customers {groupId ? 'in this group' : 'yet'}.
            </p>
          )}

          {section === 'financials' && rows.length > 0 && (
            <Table head={['Customer', 'Invoices', 'Billed', 'Paid', 'Outstanding', 'Credit']}
              rows={rows.map(c => [nameCell(c, nav), c.invoice_count, money(c.total_billed), money(c.total_paid), money(c.outstanding), money(c.available_credit)])}
              total={['TOTAL', t.invoice_count, money(t.total_billed), money(t.total_paid), money(t.outstanding), money(t.available_credit)]} />
          )}

          {section === 'tax' && rows.length > 0 && (
            <Table head={['Customer', 'GST Total', 'GST Paid', 'GST Unpaid', 'TDS Deducted']}
              rows={rows.map(c => [nameCell(c, nav), money(c.gst_total), money(c.gst_paid), money(c.gst_unpaid), money(c.tds_deducted)])}
              total={['TOTAL', money(t.gst_total), money(t.gst_paid), money(t.gst_unpaid), money(t.tds_deducted)]} />
          )}

          {section === 'ageing' && rows.length > 0 && (
            <Table head={['Customer', 'Not due', '1–30d', '31–60d', '61–90d', '90d+', 'Total']}
              rows={rows.map(c => [nameCell(c, nav), money(c.ageing.current), money(c.ageing.d30), money(c.ageing.d60), money(c.ageing.d90), money(c.ageing.d90plus), money(c.outstanding)])}
              total={['TOTAL', money(t.ageing.current), money(t.ageing.d30), money(t.ageing.d60), money(t.ageing.d90), money(t.ageing.d90plus), money(t.outstanding)]} />
          )}

          {section === 'activity' && rows.length > 0 && (
            <Table head={['Customer', 'Proposals', 'Estimates', 'Invoices', 'Contracts', 'Tickets', 'Open', 'Projects']}
              rows={rows.map(c => [nameCell(c, nav), c.activity.proposals, c.activity.estimates, c.activity.invoices, c.activity.contracts, c.activity.tickets, c.activity.open_tickets, c.activity.projects])}
              total={['TOTAL', t.activity.proposals, t.activity.estimates, t.activity.invoices, t.activity.contracts, t.activity.tickets, t.activity.open_tickets, t.activity.projects]} />
          )}

          {section === 'compare' && (
            !compare ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
              <>
                <Table head={['Group', 'Customers', 'Invoices', 'Billed', 'Paid', 'Outstanding', 'GST Unpaid']}
                  rows={[...(compare.groups || []), compare.ungrouped].map(g => [
                    g.name, g.customer_count, g.invoice_count,
                    money(g.total_billed), money(g.total_paid), money(g.outstanding), money(g.gst_unpaid),
                  ])}
                  total={['GRAND TOTAL', compare.grand.customer_count, compare.grand.invoice_count,
                    money(compare.grand.total_billed), money(compare.grand.total_paid),
                    money(compare.grand.outstanding), money(compare.grand.gst_unpaid)]} />
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  “Ungrouped” covers customers in no group, so the rows reconcile with the grand total. A customer in
                  two groups is counted in both, which is why the grand customer count is the distinct total rather
                  than the sum of the rows.
                </p>
              </>
            )
          )}
        </>
      )}
    </div>
  )
}

function nameCell(c, nav) {
  return (
    <button onClick={() => nav(`/app/customers/${c.id}`)} className="font-semibold hover:underline text-left"
      style={{ color: 'var(--text-h)' }}>
      {c.company}{!c.active && <span className="text-xs ml-1" style={{ color: 'var(--text-muted)' }}>(inactive)</span>}
    </button>
  )
}

function Tile({ label, value, color, icon: Icon, plain }) {
  return (
    <div className="kpi-3d">
      <p className="text-[10px] uppercase font-bold flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
        {Icon && <Icon size={11} />} {label}
      </p>
      <p className={plain ? 'text-2xl font-black mt-1' : 'text-lg font-black mt-1'} style={{ color: color || 'var(--text-h)' }}>{value}</p>
    </div>
  )
}

function Table({ head, rows, total }) {
  return (
    <div className="table-wrapper">
      <table className="table">
        <thead><tr>{head.map((h, i) => <th key={h} style={i ? { textAlign: 'right' } : undefined}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map((r, ri) => (
            <tr key={ri}>{r.map((cell, ci) => <td key={ci} style={ci ? { textAlign: 'right' } : undefined}>{cell}</td>)}</tr>
          ))}
          {total && (
            <tr style={{ background: 'rgba(124,58,237,0.05)', fontWeight: 800 }}>
              {total.map((cell, ci) => <td key={ci} style={ci ? { textAlign: 'right', color: 'var(--text-h)' } : { color: 'var(--text-h)' }}>{cell}</td>)}
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}
