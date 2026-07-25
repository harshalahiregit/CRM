import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Landmark, TrendingUp, TrendingDown, Hourglass, AlertTriangle, Loader2,
  Waves, ArrowLeftRight, FileText, RefreshCw, CheckCircle2, CreditCard,
  BookText, Scale, Calendar, ChevronDown,
} from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useMoneyFmt, MoneyToggle } from '@/components/ui/Money'

/* ── Tiny inline SVG chart helpers ─────────────────────────────────────── */

function BarChart({ data, keys, colors, height = 140, mfmt }) {
  const maxVal = Math.max(1, ...data.flatMap(d => keys.map(k => d[k] || 0)))
  const W = 100 / data.length
  return (
    <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="w-full" style={{ height }}>
      {data.map((d, i) =>
        keys.map((k, ki) => {
          const barW = (W / keys.length) * 0.8
          const x = i * W + ki * (W / keys.length) + (W * 0.1)
          const barH = ((d[k] || 0) / maxVal) * (height - 20)
          return (
            <g key={`${i}-${ki}`}>
              <rect
                x={x} y={height - 20 - barH} width={barW} height={Math.max(barH, 1)}
                fill={colors[ki]} rx="1" opacity="0.85"
              >
                <title>{`${d.month}: ${mfmt(d[k])}`}</title>
              </rect>
            </g>
          )
        })
      )}
      {data.map((d, i) => (
        <text key={i} x={i * W + W / 2} y={height - 4} textAnchor="middle"
          style={{ fontSize: 4.5, fill: 'var(--text-muted)', fontFamily: 'inherit' }}>
          {d.month}
        </text>
      ))}
    </svg>
  )
}

function DonutChart({ data, total, size = 120 }) {
  const COLORS = ['#a78bfa', '#10b981', '#f59e0b', '#22d3ee', '#f87171', '#818cf8']
  const cx = size / 2, cy = size / 2, r = size * 0.38, stroke = size * 0.14
  let cumAngle = -Math.PI / 2

  const arcs = data.map((item, i) => {
    const pct = total > 0 ? item.amount / total : 0
    const startAngle = cumAngle
    const sweep = pct * 2 * Math.PI
    cumAngle += sweep
    const x1 = cx + r * Math.cos(startAngle)
    const y1 = cy + r * Math.sin(startAngle)
    const x2 = cx + r * Math.cos(startAngle + sweep)
    const y2 = cy + r * Math.sin(startAngle + sweep)
    const large = sweep > Math.PI ? 1 : 0
    return { d: `M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2}`, color: COLORS[i % COLORS.length], name: item.name, amt: item.amount, pct }
  })

  return (
    <svg viewBox={`0 0 ${size} ${size}`} className="flex-shrink-0" style={{ width: size, height: size }}>
      {arcs.map((a, i) => (
        <path key={i} d={a.d} fill="none" stroke={a.color}
          strokeWidth={stroke} strokeLinecap="butt" opacity={0.9}>
          <title>{`${a.name}: ${(a.pct * 100).toFixed(1)}%`}</title>
        </path>
      ))}
      {data.length === 0 && (
        <circle cx={cx} cy={cy} r={r} fill="none" stroke="var(--border)" strokeWidth={stroke} />
      )}
      <text x={cx} y={cy - 4} textAnchor="middle" style={{ fontSize: 9, fontWeight: 700, fill: 'var(--text-h)', fontFamily: 'inherit' }}>
        {data.length}
      </text>
      <text x={cx} y={cy + 8} textAnchor="middle" style={{ fontSize: 5, fill: 'var(--text-muted)', fontFamily: 'inherit' }}>
        categories
      </text>
    </svg>
  )
}

/* ── KPI card ───────────────────────────────────────────────────────────── */
function KpiCard({ label, value, sub, icon: Icon, color, to }) {
  const inner = (
    <div className="kpi-3d flex items-start gap-3 group">
      <div className="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-110"
        style={{ background: `${color}22` }}>
        <Icon size={18} style={{ color }} />
      </div>
      <div className="min-w-0">
        <p className="text-lg font-black truncate leading-tight" style={{ color: 'var(--text-h)' }}>{value}</p>
        {sub && <p className="text-[10px] font-semibold" style={{ color }}>{sub}</p>}
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
    </div>
  )
  return to ? <Link to={to}>{inner}</Link> : inner
}

/* ── Alert banner ───────────────────────────────────────────────────────── */
function AlertBanner({ icon: Icon, color, title, count, to }) {
  if (!count) return null
  return (
    <Link to={to} className="flex items-center gap-2.5 px-4 py-2.5 rounded-2xl hover:opacity-90 transition-opacity"
      style={{ background: `${color}12`, border: `1px solid ${color}30` }}>
      <Icon size={15} style={{ color }} />
      <span className="text-xs font-bold" style={{ color }}>{title}</span>
      <span className="ml-auto text-xs font-black px-2 py-0.5 rounded-lg text-white" style={{ background: color }}>{count}</span>
    </Link>
  )
}

/* ── Financial year dropdown ────────────────────────────────────────────── */
function FYSelector({ fy, setFy }) {
  const [open, setOpen] = useState(false)
  const now = new Date()
  const cy = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1
  const years = Array.from({ length: 5 }, (_, i) => {
    const y = cy - i
    return { value: `${y}-${y + 1}`, label: `FY ${y}–${String(y + 1).slice(2)}` }
  })

  return (
    <div className="relative">
      <button onClick={() => setOpen(!open)}
        className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all hover:opacity-80"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
        <Calendar size={13} style={{ color: '#a78bfa' }} />
        {years.find(y => y.value === fy)?.label || years[0].label}
        <ChevronDown size={12} style={{ color: 'var(--text-muted)' }} />
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-full mt-1 z-50 min-w-[160px] py-1 rounded-xl shadow-xl"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            {years.map(y => (
              <button key={y.value} onClick={() => { setFy(y.value); setOpen(false) }}
                className="w-full text-left px-4 py-2 text-xs font-semibold hover:opacity-80 transition-opacity"
                style={{ color: fy === y.value ? '#a78bfa' : 'var(--text-h)', background: fy === y.value ? 'rgba(167,139,250,0.08)' : 'transparent' }}>
                {y.label}
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}

/* ── Currency display tag ───────────────────────────────────────────────── */
function CurrencyBadge({ currency }) {
  const symbols = { INR: '₹', USD: '$', EUR: '€', GBP: '£' }
  return (
    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
      style={{ background: 'rgba(167,139,250,0.1)', color: '#a78bfa' }}>
      {symbols[currency] || ''} {currency}
    </span>
  )
}

/* ── Main component ─────────────────────────────────────────────────────── */
export default function Dashboard() {
  const mfmt = useMoneyFmt()

  // FY selector defaults to current FY
  const now = new Date()
  const defaultFY = now.getMonth() >= 3 ? `${now.getFullYear()}-${now.getFullYear() + 1}` : `${now.getFullYear() - 1}-${now.getFullYear()}`
  const [fy, setFy] = useState(defaultFY)

  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'dashboard', fy],
    queryFn: () => accountsApi.dashboard({ fy }),
  })

  if (isLoading || !data) {
    return (
      <div className="space-y-4 animate-fade-in">
        {[1, 2, 3].map(i => (
          <div key={i} className="kpi-3d h-28 animate-pulse" style={{ background: 'var(--bg-input)' }} />
        ))}
      </div>
    )
  }

  const cf = data.cash_flow || {}
  const cs = data.convert_status || {}
  const baseCurrency = data.base_currency || 'INR'
  const expTotal = data.expense_breakdown.reduce((s, e) => s + Number(e.amount), 0)
  const DONUT_COLORS = ['#a78bfa', '#10b981', '#f59e0b', '#22d3ee', '#f87171', '#818cf8']

  const kpiTiles = [
    { label: 'Cash & Bank',           value: mfmt(data.cash_and_bank),      icon: Landmark,       color: '#a78bfa', to: '/app/accounts/banking' },
    { label: 'This Month — Income',   value: mfmt(data.month_income),        icon: TrendingUp,     color: '#10b981', to: '/app/accounts/reports/profit-loss', sub: data.month_net >= 0 ? `Net: ${mfmt(data.month_net)}` : `Loss: ${mfmt(Math.abs(data.month_net))}` },
    { label: 'This Month — Expense',  value: mfmt(data.month_expense),       icon: TrendingDown,   color: '#f87171', to: '/app/accounts/vouchers' },
    { label: 'Receivable (AR)',        value: mfmt(data.receivable_total),    icon: Hourglass,      color: '#22d3ee', to: '/app/accounts/reports/ageing' },
    { label: 'Payable — Bills',        value: mfmt(data.payable_total),       icon: Hourglass,      color: '#f59e0b', to: '/app/accounts/bills' },
    { label: 'Overdue Bills',          value: mfmt(data.payable_overdue),     icon: AlertTriangle,  color: '#f87171', to: '/app/accounts/bills' },
  ]

  const quickLinks = [
    { label: 'Vouchers', icon: BookText, to: '/app/accounts/vouchers', color: '#a78bfa' },
    { label: 'Transfer Funds', icon: ArrowLeftRight, to: '/app/accounts/transfer', color: '#10b981' },
    { label: 'Bills', icon: FileText, to: '/app/accounts/bills', color: '#f59e0b' },
    { label: 'Cheques', icon: CreditCard, to: '/app/accounts/cheques', color: '#22d3ee' },
    { label: 'Reports', icon: Scale, to: '/app/accounts/reports', color: '#f87171' },
    { label: 'Reconcile', icon: CheckCircle2, to: '/app/accounts/banking', color: '#818cf8' },
  ]

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-0.5" style={{ color: '#a78bfa' }}>Accounts & Finance</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Dashboard</h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Live from the ledger — all figures derived from posted vouchers</p>
        </div>
        <div className="flex items-center gap-2">
          <CurrencyBadge currency={baseCurrency} />
          <FYSelector fy={fy} setFy={setFy} />
          <MoneyToggle />
        </div>
      </div>

      {/* Alert banners */}
      <div className="flex flex-col gap-2">
        <AlertBanner icon={RefreshCw} color="#f59e0b" title="Unposted transactions pending auto-conversion" count={cs.total} to="/app/accounts/vouchers" />
        <AlertBanner icon={CreditCard} color="#ef4444" title="Cheques maturing in next 7 days" count={data.cheques_due} to="/app/accounts/cheques" />
      </div>

      {/* KPI Tiles */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {kpiTiles.map(({ label, value, sub, icon, color, to }) => (
          <KpiCard key={label} label={label} value={value} sub={sub} icon={icon} color={color} to={to} />
        ))}
      </div>

      {/* Quick Links Row */}
      <div className="grid grid-cols-3 sm:grid-cols-6 gap-2">
        {quickLinks.map(q => (
          <Link key={q.label} to={q.to} className="flex flex-col items-center gap-2 p-3 rounded-2xl text-center transition-all hover:scale-[1.04]"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: `${q.color}15` }}>
              <q.icon size={16} style={{ color: q.color }} />
            </div>
            <span className="text-[10px] font-bold leading-tight" style={{ color: 'var(--text-muted)' }}>{q.label}</span>
          </Link>
        ))}
      </div>

      {/* Charts row */}
      <div className="grid gap-5 lg:grid-cols-2">
        {/* Income vs Expense — bar chart */}
        <div className="kpi-3d">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Income vs Expense</h3>
              <CurrencyBadge currency={baseCurrency} />
            </div>
            <Link to="/app/accounts/reports/profit-loss" className="text-[11px] font-bold" style={{ color: '#a78bfa' }}>Full P&L →</Link>
          </div>
          <BarChart data={data.trend} keys={['income', 'expense']} colors={['#10b981', '#f87171']} height={140} mfmt={mfmt} />
          <div className="flex items-center gap-4 mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#10b981' }} /> Income</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#f87171' }} /> Expense</span>
          </div>
        </div>

        {/* Expense breakdown — donut + legend */}
        <div className="kpi-3d">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Top expenses this month</h3>
              <CurrencyBadge currency={baseCurrency} />
            </div>
            <Link to="/app/accounts/reports/profit-loss" className="text-[11px] font-bold" style={{ color: '#a78bfa' }}>Details →</Link>
          </div>
          {!data.expense_breakdown.length ? (
            <p className="text-xs py-8 text-center" style={{ color: 'var(--text-muted)' }}>No expenses posted this month.</p>
          ) : (
            <div className="flex items-center gap-5">
              <DonutChart data={data.expense_breakdown} total={expTotal} size={110} />
              <div className="flex-1 space-y-2 min-w-0">
                {data.expense_breakdown.map((e, i) => (
                  <div key={e.name}>
                    <div className="flex justify-between text-xs mb-0.5">
                      <span className="flex items-center gap-1.5 truncate" style={{ color: 'var(--text-h)' }}>
                        <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: DONUT_COLORS[i % DONUT_COLORS.length] }} />
                        {e.name}
                      </span>
                      <span className="font-bold flex-shrink-0 ml-2" style={{ color: 'var(--text-muted)' }}>{mfmt(e.amount)}</span>
                    </div>
                    <div className="h-1 rounded-full" style={{ background: 'var(--bg-hover)' }}>
                      <div className="h-full rounded-full" style={{ width: `${(e.amount / expTotal) * 100}%`, background: DONUT_COLORS[i % DONUT_COLORS.length] }} />
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Cash Flow + Convert Status */}
      <div className="grid gap-5 lg:grid-cols-3">
        {/* Cash Flow */}
        <div className="kpi-3d lg:col-span-2">
          <div className="flex items-center gap-2 mb-4">
            <Waves size={15} style={{ color: '#22d3ee' }} />
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Cash Flow — this month</h3>
            <CurrencyBadge currency={baseCurrency} />
            <Link to="/app/accounts/reports/cash-flow" className="text-[11px] font-bold ml-auto" style={{ color: '#a78bfa' }}>Full report →</Link>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            {[
              { label: 'Opening Cash', val: cf.opening_cash, color: 'var(--text-h)' },
              { label: 'Net Movement', val: cf.net, color: (cf.net ?? 0) >= 0 ? '#10b981' : '#f87171' },
              { label: 'Closing Cash', val: cf.closing_cash, color: 'var(--text-h)' },
            ].map(({ label, val, color }) => (
              <div key={label} className="rounded-2xl p-3 text-center" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</p>
                <p className="text-xl font-black mt-1" style={{ color }}>{mfmt(val)}</p>
              </div>
            ))}
          </div>
          {!!(cf.sections?.length) && (
            <div className="mt-4 pt-4 grid gap-3 sm:grid-cols-3" style={{ borderTop: '1px solid var(--border)' }}>
              {cf.sections.map(s => (
                <div key={s.key}>
                  <p className="text-[11px] capitalize" style={{ color: 'var(--text-muted)' }}>{s.key} activities</p>
                  <p className="text-sm font-bold" style={{ color: s.total >= 0 ? '#10b981' : '#f87171' }}>{mfmt(s.total)}</p>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Convert Status */}
        <div className="kpi-3d flex flex-col gap-3">
          <div className="flex items-center gap-2">
            <RefreshCw size={14} style={{ color: '#f59e0b' }} />
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Auto-Conversion</h3>
          </div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Sales docs pending accounting post</p>
          <div className="flex-1 space-y-3">
            {[
              { label: 'Pending Invoices', val: cs.pending_invoices, color: '#f59e0b', to: '/app/accounts/vouchers' },
              { label: 'Pending Payments', val: cs.pending_payments, color: '#22d3ee', to: '/app/accounts/vouchers' },
            ].map(({ label, val, color, to }) => (
              <Link key={label} to={to} className="flex items-center justify-between p-3 rounded-2xl hover:opacity-80 transition-opacity"
                style={{ background: `${color}10`, border: `1px solid ${color}25` }}>
                <span className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</span>
                <span className="text-lg font-black" style={{ color: val > 0 ? color : '#10b981' }}>{val ?? 0}</span>
              </Link>
            ))}
            {cs.total === 0 && (
              <div className="flex items-center gap-2 text-xs" style={{ color: '#10b981' }}>
                <CheckCircle2 size={14} />
                <span>All transactions posted</span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Bank accounts + Recent vouchers */}
      <div className="grid gap-5 lg:grid-cols-2">
        <div className="kpi-3d">
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Bank & cash accounts</h3>
              <CurrencyBadge currency={baseCurrency} />
            </div>
            <Link to="/app/accounts/banking" className="text-[11px] font-bold" style={{ color: '#a78bfa' }}>Manage →</Link>
          </div>
          {!data.bank_accounts.length ? (
            <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No bank accounts set up yet.</p>
          ) : data.bank_accounts.map(b => (
            <div key={b.id} className="flex justify-between items-center py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
              <div>
                <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{b.bank_name}</span>
                {b.account_number && <span className="text-xs ml-2" style={{ color: 'var(--text-muted)' }}>···{b.account_number.slice(-4)}</span>}
              </div>
              <span className="text-sm font-bold" style={{ color: b.current_balance >= 0 ? 'var(--text-h)' : '#f87171' }}>{mfmt(b.current_balance)}</span>
            </div>
          ))}
        </div>

        <div className="kpi-3d">
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Recent vouchers</h3>
            <Link to="/app/accounts/vouchers" className="text-[11px] font-bold" style={{ color: '#a78bfa' }}>All vouchers →</Link>
          </div>
          {!data.recent_vouchers.length ? (
            <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No vouchers posted yet.</p>
          ) : data.recent_vouchers.map(v => (
            <Link key={v.id} to={`/app/accounts/vouchers/${v.id}`}
              className="flex justify-between items-center py-2.5 hover:opacity-80 transition-opacity"
              style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="min-w-0">
                <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{v.number}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {v.voucher_type?.name} · {fmtDate(v.date)}
                </p>
              </div>
              <span className="text-sm font-bold flex-shrink-0 ml-4" style={{ color: 'var(--text-h)' }}>{mfmt(v.total_amount)}</span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  )
}
