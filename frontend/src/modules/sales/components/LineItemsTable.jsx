import { useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'

const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN')

const EMPTY_ROW = { item_name: '', description: '', qty: 1, rate: 0, tax: 18, amount: 0 }

export default function LineItemsTable({ items = [], onChange }) {
  const rows = items.length > 0 ? items : [{ ...EMPTY_ROW }]

  const update = (idx, field, value) => {
    const updated = rows.map((r, i) => {
      if (i !== idx) return r
      const row = { ...r, [field]: value }
      row.amount = Number(row.qty) * Number(row.rate)
      return row
    })
    onChange(updated)
  }

  const addRow = () => onChange([...rows, { ...EMPTY_ROW }])
  const removeRow = (idx) => { if (rows.length === 1) return; onChange(rows.filter((_, i) => i !== idx)) }

  const subtotal = rows.reduce((s, r) => s + r.amount, 0)
  const taxTotal = rows.reduce((s, r) => s + (r.amount * r.tax / 100), 0)
  const grand = subtotal + taxTotal

  return (
    <div>
      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-xs">
          <thead>
            <tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
              {['Item / Service', 'Description', 'Qty', 'Rate (₹)', 'Tax %', 'Amount'].map(h => (
                <th key={h} className="px-3 py-2.5 text-left label-caps">{h}</th>
              ))}
              <th className="px-3 py-2.5 w-8" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, idx) => (
              <tr key={idx} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2">
                  <input
                    className="input-3d text-xs w-full min-w-[120px]"
                    style={{ padding: '6px 10px' }}
                    placeholder="Item name"
                    value={row.item_name}
                    onChange={e => update(idx, 'item_name', e.target.value)}
                  />
                </td>
                <td className="px-3 py-2">
                  <input
                    className="input-3d text-xs w-full min-w-[160px]"
                    style={{ padding: '6px 10px' }}
                    placeholder="Description"
                    value={row.description}
                    onChange={e => update(idx, 'description', e.target.value)}
                  />
                </td>
                <td className="px-3 py-2">
                  <input
                    type="number" min="1"
                    className="input-3d text-xs w-16"
                    style={{ padding: '6px 10px' }}
                    value={row.qty}
                    onChange={e => update(idx, 'qty', e.target.value)}
                  />
                </td>
                <td className="px-3 py-2">
                  <input
                    type="number" min="0"
                    className="input-3d text-xs w-28"
                    style={{ padding: '6px 10px' }}
                    value={row.rate}
                    onChange={e => update(idx, 'rate', e.target.value)}
                  />
                </td>
                <td className="px-3 py-2">
                  <select
                    className="input-3d text-xs w-20"
                    style={{ padding: '6px 10px' }}
                    value={row.tax}
                    onChange={e => update(idx, 'tax', Number(e.target.value))}
                  >
                    {[0, 5, 12, 18, 28].map(t => <option key={t} value={t}>{t}%</option>)}
                  </select>
                </td>
                <td className="px-3 py-2 font-bold text-right" style={{ color: '#a78bfa', minWidth: '90px' }}>
                  {fmt(row.amount)}
                </td>
                <td className="px-3 py-2">
                  <button onClick={() => removeRow(idx)} className="p-1 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)]">
                    <Trash2 size={12} style={{ color: '#f87171' }} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <button onClick={addRow} className="mt-2 flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl transition-colors hover:bg-[rgba(124,58,237,0.08)]" style={{ color: '#a78bfa' }}>
        <Plus size={13} /> Add Line Item
      </button>

      {/* Totals */}
      <div className="mt-4 ml-auto w-64 space-y-1.5 text-xs">
        <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}>
          <span>Subtotal</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(subtotal)}</span>
        </div>
        <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}>
          <span>Tax (GST)</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(taxTotal)}</span>
        </div>
        <div className="flex justify-between pt-2 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <span>Grand Total</span><span style={{ color: '#a78bfa' }}>{fmt(grand)}</span>
        </div>
      </div>
    </div>
  )
}
