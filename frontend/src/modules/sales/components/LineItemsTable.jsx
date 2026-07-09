import { Plus, Trash2 } from 'lucide-react'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')

// GST slab options standard in India
const TAX_SLABS = [0, 5, 12, 18, 28]

const EMPTY_ROW = {
  item_name: '',
  description: '',
  qty: 1,
  unit: 'pcs',
  rate: 0,
  tax: 18,
  discount: 0,   // flat discount per line in ₹
}

const UNITS = ['pcs', 'hrs', 'days', 'months', 'kg', 'ltr', 'box', 'set']

function calcLine(row) {
  const base    = Number(row.qty) * Number(row.rate)
  const afterDis = base - Number(row.discount || 0)
  const taxAmt  = afterDis * (Number(row.tax) / 100)
  return { base, afterDis, taxAmt, total: afterDis + taxAmt }
}

export default function LineItemsTable({ items = [], onChange }) {
  // Always have at least one row
  const rows = items.length > 0 ? items : [{ ...EMPTY_ROW }]

  const update = (idx, field, rawValue) => {
    const updated = rows.map((r, i) => {
      if (i !== idx) return r
      return { ...r, [field]: rawValue }
    })
    onChange(updated)
  }

  const addRow    = () => onChange([...rows, { ...EMPTY_ROW }])
  const removeRow = idx => {
    if (rows.length === 1) return  // keep at least one row
    onChange(rows.filter((_, i) => i !== idx))
  }

  // Totals
  const subtotal   = rows.reduce((s, r) => s + Number(r.qty) * Number(r.rate), 0)
  const discountTotal = rows.reduce((s, r) => s + Number(r.discount || 0), 0)
  const taxTotal   = rows.reduce((s, r) => s + calcLine(r).taxAmt, 0)
  const grandTotal = subtotal - discountTotal + taxTotal

  return (
    <div className="space-y-3">
      {/* Table */}
      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-xs" style={{ minWidth: '700px' }}>
          <thead>
            <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              <th className="px-3 py-2.5 text-left label-caps" style={{ minWidth: '140px' }}>Item / Service</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ minWidth: '160px' }}>Description</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '60px' }}>Qty</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '70px' }}>Unit</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '100px' }}>Rate (₹)</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '70px' }}>Tax %</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '90px' }}>Discount</th>
              <th className="px-3 py-2.5 text-right label-caps" style={{ width: '100px' }}>Total</th>
              <th className="px-3 py-2.5 w-8" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, idx) => {
              const line = calcLine(row)
              return (
                <tr key={idx} style={{ borderBottom: idx < rows.length - 1 ? '1px solid var(--border)' : 'none' }}>
                  {/* Item name */}
                  <td className="px-3 py-2">
                    <input
                      className="input-3d text-xs w-full"
                      style={{ padding: '5px 8px' }}
                      placeholder="Item name"
                      value={row.item_name}
                      onChange={e => update(idx, 'item_name', e.target.value)}
                    />
                  </td>
                  {/* Description */}
                  <td className="px-3 py-2">
                    <input
                      className="input-3d text-xs w-full"
                      style={{ padding: '5px 8px' }}
                      placeholder="Short description"
                      value={row.description}
                      onChange={e => update(idx, 'description', e.target.value)}
                    />
                  </td>
                  {/* Qty */}
                  <td className="px-3 py-2">
                    <input
                      type="number" min="0.01" step="0.01"
                      className="input-3d text-xs"
                      style={{ padding: '5px 8px', width: '56px' }}
                      value={row.qty}
                      onChange={e => update(idx, 'qty', e.target.value)}
                    />
                  </td>
                  {/* Unit */}
                  <td className="px-3 py-2">
                    <select
                      className="input-3d text-xs"
                      style={{ padding: '5px 8px', width: '66px' }}
                      value={row.unit}
                      onChange={e => update(idx, 'unit', e.target.value)}
                    >
                      {UNITS.map(u => <option key={u} value={u}>{u}</option>)}
                    </select>
                  </td>
                  {/* Rate */}
                  <td className="px-3 py-2">
                    <input
                      type="number" min="0" step="0.01"
                      className="input-3d text-xs"
                      style={{ padding: '5px 8px', width: '90px' }}
                      value={row.rate}
                      onChange={e => update(idx, 'rate', e.target.value)}
                    />
                  </td>
                  {/* Tax */}
                  <td className="px-3 py-2">
                    <select
                      className="input-3d text-xs"
                      style={{ padding: '5px 8px', width: '64px' }}
                      value={row.tax}
                      onChange={e => update(idx, 'tax', Number(e.target.value))}
                    >
                      {TAX_SLABS.map(t => <option key={t} value={t}>{t}%</option>)}
                    </select>
                  </td>
                  {/* Discount */}
                  <td className="px-3 py-2">
                    <input
                      type="number" min="0" step="0.01"
                      className="input-3d text-xs"
                      style={{ padding: '5px 8px', width: '80px' }}
                      placeholder="0"
                      value={row.discount}
                      onChange={e => update(idx, 'discount', e.target.value)}
                    />
                  </td>
                  {/* Line total */}
                  <td className="px-3 py-2 text-right font-bold" style={{ color: '#a78bfa' }}>
                    {fmt(line.total)}
                  </td>
                  {/* Remove */}
                  <td className="px-3 py-2">
                    <button
                      onClick={() => removeRow(idx)}
                      disabled={rows.length === 1}
                      className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)] disabled:opacity-30"
                    >
                      <Trash2 size={12} style={{ color: '#f87171' }} />
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Add row */}
      <button
        onClick={addRow}
        className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl transition-colors hover:bg-[rgba(124,58,237,0.08)]"
        style={{ color: '#a78bfa' }}
      >
        <Plus size={13} /> Add Line Item
      </button>

      {/* Totals */}
      <div className="ml-auto w-72 space-y-0 text-xs rounded-xl overflow-hidden"
        style={{ border: '1px solid var(--border)' }}>
        {[
          { label: 'Subtotal',  value: fmt(subtotal) },
          discountTotal > 0 && { label: 'Discount',   value: `− ${fmt(discountTotal)}`, color: '#10b981' },
          { label: 'Tax (GST)', value: fmt(taxTotal) },
        ].filter(Boolean).map((row, i) => (
          <div key={i} className="flex justify-between px-4 py-2.5"
            style={{ background: i % 2 === 0 ? 'transparent' : 'rgba(124,58,237,0.02)', borderBottom: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            <span>{row.label}</span>
            <span className="font-semibold" style={{ color: row.color || 'var(--text-h)' }}>{row.value}</span>
          </div>
        ))}
        <div className="flex justify-between px-4 py-3 font-black text-sm"
          style={{ background: 'rgba(124,58,237,0.05)', color: 'var(--text-h)' }}>
          <span>Grand Total</span>
          <span style={{ color: '#a78bfa' }}>{fmt(grandTotal)}</span>
        </div>
      </div>
    </div>
  )
}
