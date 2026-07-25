import { useState, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, ChevronLeft, Loader2, ExternalLink } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { Input } from '@/components/ui/FormField'

const VOUCHER_TYPE_COLOR = {
  sales:       '#10b981',
  purchase:    '#f59e0b',
  payment:     '#f87171',
  receipt:     '#22d3ee',
  contra:      '#a78bfa',
  journal:     '#818cf8',
  debit_note:  '#f97316',
  credit_note: '#06b6d4',
  stock_journal: '#8b5cf6',
}

/**
 * RegisterDetail — per-ledger passbook / statement view. Every debit and
 * credit posted to this ledger in date order with a running balance,
 * filterable by date range and paginated. Uses the app's shared table system
 * (same as the Reports pages) for a consistent, responsive look.
 *
 * Old-CRM parity: the "Registers > [account name]" drill-through view.
 * Read-only — any new transaction must go through the Vouchers flow.
 */
export default function RegisterDetail() {
  const { ledgerId } = useParams()
  const inr = useInr()

  const today     = new Date().toISOString().slice(0, 10)
  const firstOfFY = `${today.slice(0, 4)}-04-01`

  const [from, setFrom] = useState(firstOfFY)
  const [to, setTo]     = useState(today)
  const [page, setPage] = useState(1)

  const applyFilter = useCallback(() => setPage(1), [])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['accounts', 'register-detail', ledgerId, { from, to, page }],
    queryFn:  () => accountsApi.registers.statement(ledgerId, { from, to, page, per_page: 50 }),
    keepPreviousData: true,
    staleTime: 15_000,
  })

  const ledger  = data?.ledger
  const lines   = data?.lines?.data ?? []
  const meta    = data?.lines
  const opening = data?.opening_balance ?? 0
  const openingType = data?.opening_type ?? 'dr'

  const totalDebit  = lines.reduce((s, l) => s + Number(l.debit), 0)
  const totalCredit = lines.reduce((s, l) => s + Number(l.credit), 0)
  const closingBal  = lines.at(-1)?.running_balance ?? opening
  const closingType = lines.at(-1)?.running_balance_type ?? openingType

  return (
    <div className="space-y-5 animate-fade-in">
      <Link to="/app/accounts/registers"
        className="inline-flex items-center gap-1.5 text-xs font-bold hover:opacity-80 transition-opacity"
        style={{ color: '#22d3ee' }}>
        <ChevronLeft size={13} /> Registers
      </Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(34,211,238,0.12)' }}>
            <BookOpen size={18} style={{ color: '#22d3ee' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{ledger?.name ?? '…'}</h1>
            {ledger && (
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {ledger.group_name} · {ledger.nature}{ledger.code && ` · ${ledger.code}`}
              </p>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          <label className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>From</label>
          <Input type="date" value={from} onChange={e => { setFrom(e.target.value); applyFilter() }} style={{ maxWidth: 145 }} />
          <label className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>To</label>
          <Input type="date" value={to} onChange={e => { setTo(e.target.value); applyFilter() }} style={{ maxWidth: 145 }} />
        </div>
      </div>

      {/* Summary KPI row */}
      {data && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {[
            { label: 'Opening Balance', value: inr(opening), sub: openingType.toUpperCase(), color: 'var(--text-h)' },
            { label: 'Total Debit',     value: inr(totalDebit),  sub: 'Dr', color: '#10b981' },
            { label: 'Total Credit',    value: inr(totalCredit), sub: 'Cr', color: '#f87171' },
            { label: 'Closing Balance', value: inr(Math.abs(closingBal)), sub: closingType.toUpperCase(), color: 'var(--text-h)' },
          ].map(({ label, value, sub, color }) => (
            <div key={label} className="kpi-3d">
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
              <p className="text-lg font-black mt-1" style={{ color }}>{value}</p>
              <p className="text-[10px] font-bold uppercase mt-0.5" style={{ color }}>{sub}</p>
            </div>
          ))}
        </div>
      )}

      {/* Statement table */}
      {isLoading ? (
        <div className="flex justify-center py-16"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
      ) : (
        <div className="table-wrapper" style={{ opacity: isFetching ? 0.7 : 1, transition: 'opacity 0.2s' }}>
          <table className="table">
            <thead>
              <tr>
                <th>Date</th><th>Particulars</th>
                <th style={{ textAlign: 'right' }}>Debit</th>
                <th style={{ textAlign: 'right' }}>Credit</th>
                <th style={{ textAlign: 'right' }}>Balance</th>
              </tr>
            </thead>
            <tbody>
              <tr style={{ background: 'var(--bg-section)' }}>
                <td colSpan={4} style={{ fontWeight: 700, color: 'var(--text-muted)' }}>Opening balance</td>
                <td style={{ textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{inr(opening)} {openingType.toUpperCase()}</td>
              </tr>

              {lines.length === 0 && (
                <tr><td colSpan={5} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2.5rem 0' }}>No transactions in this period.</td></tr>
              )}

              {lines.map((line) => {
                const vtColor = VOUCHER_TYPE_COLOR[line.voucher_type_code] ?? 'var(--text-muted)'
                const isDebit = Number(line.debit) > 0
                return (
                  <tr key={line.id}>
                    <td style={{ color: 'var(--text-muted)' }}>{fmtDate(line.date)}</td>
                    <td style={{ whiteSpace: 'normal', minWidth: 240 }}>
                      <div className="flex items-center gap-2">
                        <span className="text-[10px] font-bold px-1.5 py-0.5 rounded flex-shrink-0" style={{ background: `${vtColor}18`, color: vtColor }}>
                          {line.voucher_type_name}
                        </span>
                        <Link to={`/app/accounts/vouchers/${line.voucher_id}`}
                          className="text-xs font-semibold hover:underline flex items-center gap-1"
                          style={{ color: 'var(--text-h)' }}>
                          {line.voucher_number}
                          <ExternalLink size={10} className="flex-shrink-0 opacity-50" />
                        </Link>
                      </div>
                      {(line.line_narration || line.narration) && (
                        <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{line.line_narration || line.narration}</p>
                      )}
                    </td>
                    <td style={{ textAlign: 'right', color: isDebit ? '#10b981' : 'var(--text-muted)', fontWeight: isDebit ? 700 : 400 }}>
                      {isDebit ? inr(line.debit) : '—'}
                    </td>
                    <td style={{ textAlign: 'right', color: !isDebit ? '#f87171' : 'var(--text-muted)', fontWeight: !isDebit ? 700 : 400 }}>
                      {!isDebit ? inr(line.credit) : '—'}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <span style={{ fontWeight: 700, color: 'var(--text-h)' }}>{inr(line.running_balance)}</span>
                      <span className="text-[9px] font-bold ml-1 uppercase" style={{ color: line.running_balance_type === 'dr' ? '#10b981' : '#f87171' }}>
                        {line.running_balance_type}
                      </span>
                    </td>
                  </tr>
                )
              })}

              {lines.length > 0 && (
                <tr style={{ borderTop: '2px solid var(--border)', background: 'var(--bg-section)' }}>
                  <td colSpan={2} style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: '#10b981' }}>{inr(totalDebit)}</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: '#f87171' }}>{inr(totalCredit)}</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(Math.abs(closingBal))} {closingType.toUpperCase()}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-xs" style={{ color: 'var(--text-muted)' }}>
          <span>Showing {meta.from}–{meta.to} of {meta.total} entries</span>
          <div className="flex gap-1">
            {Array.from({ length: meta.last_page }, (_, i) => i + 1).map(p => (
              <button key={p} onClick={() => setPage(p)}
                className="px-2.5 py-1 rounded-lg text-xs font-semibold transition-all"
                style={{
                  background: p === page ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'var(--bg-hover)',
                  color: p === page ? '#fff' : 'var(--text-muted)',
                  border: '1px solid var(--border)',
                }}>
                {p}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
