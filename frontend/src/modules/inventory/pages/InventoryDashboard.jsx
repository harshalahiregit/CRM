import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Package, Warehouse, IndianRupee, AlertTriangle, XCircle, ArrowLeftRight, Boxes, ArrowRight, LifeBuoy,
  FileClock, Lock, User, Users,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import RaiseTicketModal from '../../helpdesk/components/RaiseTicketModal'

/**
 * Inventory command centre — the KPI tiles from the blueprint's Dashboard, built
 * on figures the ledger can actually prove. Cards that represent a problem
 * (low stock / out of stock) are clickable and lead straight to the work.
 */
export default function InventoryDashboard() {
  const navigate = useNavigate()
  const [raising, setRaising] = useState(false)
  const { data: s, isLoading } = useQuery({ queryKey: ['inv-summary'], queryFn: inventoryApi.summary })
  const { data: low = [] } = useQuery({ queryKey: ['inv-low-stock'], queryFn: inventoryApi.lowStock })

  if (isLoading) {
    return (
      <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
        {[...Array(8)].map((_, i) => (
          <div key={i} className="rounded-2xl animate-pulse" style={{ height: 92, background: 'var(--bg-card)' }} />
        ))}
      </div>
    )
  }

  // Stock levels are shared operational truth — identical for everyone. Only the
  // valuation tile is admin-gated (the server sends null when you may not see it).
  const tiles = [
    ...(s?.inventory_value != null
      ? [{ key: 'inventory_value', label: 'Inventory Value', value: money(s.inventory_value), icon: IndianRupee, color: INV_ACCENT }]
      : []),
    { key: 'available',   label: 'Available Stock', value: fmtQty(s?.available), icon: Boxes, color: '#3b82f6' },
    { key: 'reserved',    label: 'Reserved',        value: fmtQty(s?.reserved), icon: Package, color: '#8b5cf6' },
    { key: 'products',    label: 'Active Products', value: s?.products ?? 0, icon: Package, color: '#64748b' },
    { key: 'warehouses',  label: 'Warehouses',      value: s?.warehouses ?? 0, icon: Warehouse, color: '#0ea5e9' },
    { key: 'low_stock',   label: 'Low Stock',       value: s?.low_stock ?? 0, icon: AlertTriangle, color: '#f59e0b', to: '/app/inventory/products?filter=low' },
    { key: 'out_of_stock', label: 'Out of Stock',   value: s?.out_of_stock ?? 0, icon: XCircle, color: '#ef4444' },
    { key: 'movements_today', label: 'Movements Today', value: s?.movements_today ?? 0, icon: ArrowLeftRight, color: '#14b8a6' },
  ]

  // Work sitting with OTHER people — admin only, so a manager can see the
  // unposted drafts that would otherwise be invisible until their author posts.
  const team = s?.team
  const teamTiles = team ? [
    { key: 't_drafts', label: "Team's unposted drafts", value: team.open_drafts ?? 0, icon: FileClock, color: '#f59e0b', to: '/app/inventory/analytics' },
    { key: 't_res', label: "Team's reservations", value: team.reservations ?? 0, icon: Lock, color: '#8b5cf6', to: '/app/inventory/traceability' },
    { key: 't_people', label: 'People active today', value: team.active_people ?? 0, icon: Users, color: INV_ACCENT, to: '/app/inventory/analytics' },
  ] : []

  // The viewer's own work — what they moved, what they still owe, what they hold.
  const mine = s?.my
  const myTiles = mine ? [
    { key: 'my_moves', label: 'My movements today', value: mine.movements_today ?? 0, icon: ArrowLeftRight, color: '#14b8a6' },
    { key: 'my_drafts', label: 'My unposted drafts', value: mine.open_drafts ?? 0, icon: FileClock, color: '#f59e0b', to: '/app/inventory/vouchers/receipt' },
    { key: 'my_res', label: 'My reservations', value: mine.reservations ?? 0, icon: Lock, color: '#8b5cf6', to: '/app/inventory/traceability' },
  ] : []

  return (
    <div>
      <header className="flex items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Boxes size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Inventory</h1>
        {/* Raise a helpdesk ticket from Inventory — same flow as the Helpdesk module,
            tagged so the ticket queue shows it came from here. */}
        <button onClick={() => setRaising(true)}
          className="ml-auto flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold transition-opacity hover:opacity-90"
          style={{ background: 'var(--color-support-500)', color: '#fff' }}>
          <LifeBuoy size={15} /> Raise Ticket
        </button>
      </header>

      {/* KPI tiles */}
      <div className="grid gap-3 mb-5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
        {tiles.map(t => {
          const Icon = t.icon
          const clickable = Boolean(t.to) && t.value > 0
          return (
            <button key={t.key} disabled={!clickable} onClick={() => clickable && navigate(t.to)}
              className="rounded-2xl p-4 text-left transition-all"
              style={{
                background: 'var(--bg-card)',
                border: `1px solid ${clickable ? t.color : 'var(--border)'}`,
                cursor: clickable ? 'pointer' : 'default',
              }}>
              <div className="flex items-center gap-2 mb-2">
                <Icon size={14} style={{ color: t.color }} />
                <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{t.label}</span>
              </div>
              <p className="text-2xl font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{t.value}</p>
            </button>
          )
        })}
      </div>

      {/* My work — the part of the dashboard that is about the viewer, not the
          warehouse. Stock figures above are shared; these three are personal. */}
      {myTiles.length > 0 && (
        <section className="mb-5">
          <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide mb-2" style={{ color: 'var(--text-muted)' }}>
            <User size={12} style={{ color: INV_ACCENT }} /> My work
          </p>
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
            {myTiles.map(t => {
              const Icon = t.icon
              const clickable = Boolean(t.to) && t.value > 0
              return (
                <button key={t.key} disabled={!clickable} onClick={() => clickable && navigate(t.to)}
                  className="rounded-2xl p-4 text-left transition-all"
                  style={{
                    background: 'var(--bg-card)',
                    border: `1px solid ${clickable ? t.color : 'var(--border)'}`,
                    cursor: clickable ? 'pointer' : 'default',
                  }}>
                  <div className="flex items-center gap-2 mb-2">
                    <Icon size={14} style={{ color: t.color }} />
                    <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{t.label}</span>
                  </div>
                  <p className="text-2xl font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{t.value}</p>
                </button>
              )
            })}
          </div>
        </section>
      )}

      {/* Team pending — admin only. An unposted draft is stock that hasn't
          moved yet; until now only its author could see it. */}
      {teamTiles.length > 0 && (
        <section className="mb-5">
          <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide mb-2" style={{ color: 'var(--text-muted)' }}>
            <Users size={12} style={{ color: INV_ACCENT }} /> Team
          </p>
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
            {teamTiles.map(t => {
              const Icon = t.icon
              const clickable = Boolean(t.to) && t.value > 0
              return (
                <button key={t.key} disabled={!clickable} onClick={() => clickable && navigate(t.to)}
                  className="rounded-2xl p-4 text-left transition-all"
                  style={{
                    background: 'var(--bg-card)',
                    border: `1px solid ${clickable ? t.color : 'var(--border)'}`,
                    cursor: clickable ? 'pointer' : 'default',
                  }}>
                  <div className="flex items-center gap-2 mb-2">
                    <Icon size={14} style={{ color: t.color }} />
                    <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{t.label}</span>
                  </div>
                  <p className="text-2xl font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{t.value}</p>
                </button>
              )
            })}
          </div>
        </section>
      )}

      {/* Reorder worklist — the dashboard's one actionable list. */}
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center gap-2 mb-3">
          <AlertTriangle size={14} style={{ color: '#f59e0b' }} />
          <h2 className="font-bold text-xs" style={{ color: 'var(--text-h)' }}>Needs reordering</h2>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {low.length} {low.length === 1 ? 'product is' : 'products are'} at or below the reorder point
          </span>
          <button onClick={() => navigate('/app/inventory/products')}
            className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: INV_ACCENT }}>
            All products <ArrowRight size={11} />
          </button>
        </div>

        {low.length === 0 && (
          <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>
            Nothing to reorder — every product is above its threshold.
          </p>
        )}

        {low.length > 0 && (
          <ul className="space-y-1.5">
            {low.map(p => (
              <li key={p.id}>
                <button onClick={() => navigate(`/app/inventory/products/${p.id}`)}
                  className="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-left"
                  style={{ background: 'var(--bg-input)' }}>
                  <span className="flex-1 min-w-0">
                    <span className="block text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                    <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.sku}</span>
                  </span>
                  <span className="text-right shrink-0">
                    <span className="block text-xs font-black tabular-nums" style={{ color: '#f59e0b' }}>{fmtQty(p.on_hand)}</span>
                    <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>
                      reorder at {fmtQty(p.reorder_point || p.min_stock)}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <RaiseTicketModal open={raising} onClose={() => setRaising(false)} source="inventory"
        defaultSubject="[Inventory] " />
    </div>
  )
}
