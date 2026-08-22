import { useState, useMemo, useEffect } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, Search, Loader2, Landmark, Wallet, Scale3d, ChevronRight } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { Input, Select } from '@/components/ui/FormField'
import DataTable from '@/components/ui/DataTable'
import PagerBar from '@/components/ui/PagerBar'

const NATURE_COLOR = {
  asset:     { text: '#10b981', label: 'Asset' },
  liability: { text: '#f59e0b', label: 'Liability' },
  equity:    { text: '#a78bfa', label: 'Equity' },
  income:    { text: '#22d3ee', label: 'Income' },
  expense:   { text: '#f87171', label: 'Expense' },
}

/**
 * Registers — every active ledger with its current balance, in one sortable
 * table (consistent with Vouchers/Cheques elsewhere in the module). Clicking a
 * row opens RegisterDetail for the full passbook. Read-only derived view.
 */
export default function Registers() {
  const inr = useInr()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [nature, setNature] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const handleSearch = (val) => {
    setSearch(val)
    clearTimeout(window._regSearchTimer)
    window._regSearchTimer = setTimeout(() => setDebouncedSearch(val), 350)
  }

  const [pageNo, setPageNo] = useState(1)
  useEffect(() => { setPageNo(1) }, [debouncedSearch, nature])

  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'registers', { search: debouncedSearch, nature }, pageNo],
    queryFn:  () => accountsApi.registers.list({ search: debouncedSearch, nature, per_page: 200, page: pageNo }),
    staleTime: 30_000,
    placeholderData: (prev) => prev,
  })

  const rows = data?.data ?? []

  const kpis = useMemo(() => {
    const signed = (r) => (r.balance_type === 'dr' ? 1 : -1) * Number(r.balance || 0)
    const bankCash = rows.filter(r => r.is_bank || r.is_cash).reduce((s, r) => s + signed(r), 0)
    const assets = rows.filter(r => r.nature === 'asset').reduce((s, r) => s + signed(r), 0)
    const liabilities = rows.filter(r => r.nature === 'liability').reduce((s, r) => s - signed(r), 0)
    return { count: rows.length, bankCash, assets, liabilities }
  }, [rows])

  const columns = [
    { key: 'name', label: 'Ledger', sortable: true, render: (r) => (
      <div>
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
          {r.code && <span className="text-[10px] px-1.5 py-0.5 rounded font-mono" style={{ background: 'var(--bg-hover)', color: 'var(--text-muted)' }}>{r.code}</span>}
          {r.is_bank && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: '#22d3ee18', color: '#22d3ee' }}>Bank</span>}
          {r.is_cash && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: '#10b98118', color: '#10b981' }}>Cash</span>}
          {r.is_party && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: '#a78bfa18', color: '#a78bfa' }}>Party</span>}
        </div>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
          {r.group_name}{r.last_activity && ` · Last activity ${r.last_activity}`}
        </p>
      </div>
    ) },
    { key: 'nature', label: 'Type', sortable: true, render: (r) => {
      const nc = NATURE_COLOR[r.nature] || { text: 'var(--text-muted)', label: r.nature }
      return <span className="text-xs font-bold px-2 py-0.5 rounded-lg" style={{ background: `${nc.text}18`, color: nc.text }}>{nc.label}</span>
    } },
    { key: 'balance', label: 'Balance', align: 'right', sortable: true, render: (r) => (
      <div>
        <span className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{inr(r.balance)}</span>
        <span className="text-[10px] font-bold ml-1.5 uppercase" style={{ color: NATURE_COLOR[r.nature]?.text || 'var(--text-muted)' }}>{r.balance_type}</span>
      </div>
    ) },
    { key: 'actions', label: '', align: 'right', render: () => <ChevronRight size={15} style={{ color: 'var(--text-muted)', opacity: 0.5 }} /> },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(34,211,238,0.12)' }}>
            <BookOpen size={18} style={{ color: '#22d3ee' }} />
          </div>
          <div>
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Registers</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Per-account passbook — click any ledger for its full transaction history</p>
          </div>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        {[
          { label: 'Bank & Cash Balance', value: inr(kpis.bankCash), icon: Wallet, color: '#22d3ee' },
          { label: 'Total Assets', value: inr(kpis.assets), icon: Landmark, color: '#10b981' },
          { label: 'Total Liabilities', value: inr(kpis.liabilities), icon: Scale3d, color: '#f59e0b' },
        ].map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="kpi-3d">
            <div className="flex items-center gap-2 mb-1">
              <div className="w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: `${color}18` }}>
                <Icon size={13} style={{ color }} />
              </div>
              <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
            </div>
            <p className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <Input className="pl-9 text-sm" placeholder="Search ledgers…" value={search} onChange={e => handleSearch(e.target.value)} />
        </div>
        <Select value={nature} onChange={e => setNature(e.target.value)} style={{ maxWidth: 180 }}>
          <option value="">All types</option>
          {Object.entries(NATURE_COLOR).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </Select>
        <p className="text-xs font-semibold ml-auto" style={{ color: 'var(--text-muted)' }}>{kpis.count} ledger{kpis.count !== 1 ? 's' : ''}</p>
      </div>

      {isLoading
        ? <div className="flex justify-center py-16"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <>
            <DataTable columns={columns} rows={rows} onRowClick={(r) => navigate(`/app/accounts/registers/${r.id}`)}
              filtered={!!(debouncedSearch || nature)}
              onClearFilters={() => { handleSearch(''); setNature('') }}
              emptyTitle="No registers yet" emptyDescription="Registers appear once ledgers carry entries." />
            <PagerBar meta={data} onPage={setPageNo} unit="registers" className="mt-3" />
          </>}
    </div>
  )
}
