import { Plus, Trash2 } from 'lucide-react'
import { useTaxRates } from '@/hooks/useTaxRates'
import TaxSelect from './TaxSelect'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')

const EMPTY_ROW = {
  item_name: '',
  description: '',
  qty: 1,
  unit: 'pcs',
  rate: 0,
  tax: 18,
  taxes: [],           // [{name, rate}] — several may apply (CGST+SGST)
  discount: 0,   // flat discount per line in ₹
}

const UNITS = ['pcs', 'hrs', 'days', 'months', 'kg', 'ltr', 'box', 'set']

/** A line's named taxes; legacy rows (flat `tax` only) yield an empty list. */
function rowTaxes(row) {
  return Array.isArray(row.taxes) ? row.taxes : []
}

/**
 * Tax grouped by NAME across all lines (CGST 9%, SGST 9%, IGST 18% …), so the
 * totals block itemises each one. Mirrors calcLine's before/after-discount
 * base exactly, so the breakdown always sums to the overall tax total.
 */
function taxBreakdown(rows, taxAfterDiscount) {
  const byName = new Map()
  for (const row of rows) {
    const taxes = rowTaxes(row)
    if (!taxes.length) continue
    const { base, afterDis } = calcLine(row)
    const taxBase = taxAfterDiscount ? afterDis : base
    for (const t of taxes) {
      const rate = Number(t.rate || 0)
      const key = `${t.name}|${rate}`
      const amount = taxBase * (rate / 100)
      const prev = byName.get(key)
      if (prev) prev.amount += amount
      else byName.set(key, { name: t.name, rate, amount })
    }
  }
  return [...byName.values()].sort((a, b) => a.name.localeCompare(b.name))
}

function lineDiscount(row) {
  const base = Number(row.qty) * Number(row.rate)
  const val = Number(row.discount || 0)
  const amt = row.discount_mode === 'percent' ? base * val / 100 : val
  return Math.min(Math.max(amt, 0), Math.max(base, 0))
}

/**
 * `taxAfterDiscount` mirrors the document's discount_type:
 *   before_tax → tax worked out BEFORE the discount (on the full line value)
 *   after_tax  → discount first, tax on the discounted value
 */
function calcLine(row, taxAfterDiscount = false) {
  const base = Number(row.qty) * Number(row.rate)
  const dis = lineDiscount(row)
  const afterDis = base - dis
  const taxAmt = (taxAfterDiscount ? afterDis : base) * (Number(row.tax) / 100)
  return { base, dis, afterDis, taxAmt, total: afterDis + taxAmt }
}

/**
 * Line items + totals. Optional document-level extras:
 *   discount        {type: 'before_tax'|'after_tax'} — when tax is computed
 *                   relative to the line discounts
 *   onDiscountChange(next)  — enables the tax-timing control when passed
 *   supplyType      'intra' | 'inter' | null — drives the CGST/SGST vs IGST rows
 */
export default function LineItemsTable({ items = [], onChange, discount = null, onDiscountChange = null, supplyType }) {
  const TAX_RATES = useTaxRates()
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

  // Totals — mirrors backend CalculatesDocumentTotals
  const subtotal      = rows.reduce((s, r) => s + Number(r.qty) * Number(r.rate), 0)
  const lineDiscounts = rows.reduce((s, r) => s + lineDiscount(r), 0)
  const baseAfterLines = subtotal - lineDiscounts

  // Document-level discount removed — discounts live on each line now.
  // discount_type only decides whether tax is computed before or after them.
  const docType = discount?.type || 'before_tax'
  const taxAfterDiscount = docType === 'after_tax'
  const taxTotal = rows.reduce((s, r) => s + calcLine(r, taxAfterDiscount).taxAmt, 0)
  const grandTotal = baseAfterLines + taxTotal
  const afterDiscount = baseAfterLines
  const breakdown = taxBreakdown(rows, taxAfterDiscount)

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
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '116px' }}>Tax</th>
              <th className="px-3 py-2.5 text-left label-caps" style={{ width: '90px' }}>Discount</th>
              <th className="px-3 py-2.5 text-right label-caps" style={{ width: '100px' }}>Total</th>
              <th className="px-3 py-2.5 w-8" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, idx) => {
              const line = calcLine(row, taxAfterDiscount)
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
                  {/* Tax — several named taxes may apply to one line */}
                  <td className="px-3 py-2">
                    <TaxSelect
                      options={TAX_RATES}
                      value={rowTaxes(row)}
                      onChange={(taxes) => {
                        // Mirror the summed rate into `tax` so line/doc math stays one source of truth.
                        const sum = taxes.reduce((s, t) => s + Number(t.rate || 0), 0)
                        onChange(rows.map((r, i) => i === idx ? { ...r, taxes, tax: sum } : r))
                      }}
                    />
                  </td>
                  {/* Discount — flat amount or % of this line */}
                  <td className="px-3 py-2">
                    <div className="flex items-center gap-1">
                      <input
                        type="number" min="0" step="0.01"
                        className="input-3d text-xs"
                        style={{ padding: '5px 8px', width: '70px' }}
                        placeholder="0"
                        value={row.discount}
                        onChange={e => update(idx, 'discount', e.target.value)}
                      />
                      <select
                        className="input-3d text-xs"
                        style={{ padding: '5px 4px', width: '52px' }}
                        value={row.discount_mode || 'fixed'}
                        onChange={e => update(idx, 'discount_mode', e.target.value)}
                        title="Fixed amount or percentage of this line"
                      >
                        <option value="fixed">₹</option>
                        <option value="percent">%</option>
                      </select>
                    </div>
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

      {/* When tax is calculated relative to the line discounts */}
      {onDiscountChange && (
        <div className="flex flex-wrap items-center gap-2 justify-end text-xs">
          <span className="label-caps" style={{ color: 'var(--accent)' }}>Tax Calculation</span>
          <select className="input-3d text-xs" style={{ width: 'auto', padding: '5px 8px' }} value={docType}
            onChange={e => onDiscountChange({ ...discount, type: e.target.value })}>
            <option value="before_tax">Before tax — tax before discount</option>
            <option value="after_tax">After tax — tax after discount</option>
          </select>
        </div>
      )}

      {/* Totals */}
      <div className="ml-auto w-80 space-y-0 text-xs rounded-xl overflow-hidden"
        style={{ border: '1px solid var(--border)' }}>
        {(() => {
          // Itemise each selected tax by name; legacy lines fall back to the
          // state-derived split, then to a single combined row.
          const taxRows = breakdown.length
            ? breakdown.map(t => ({ label: `${t.name} (${t.rate}%)`, value: fmt(t.amount) }))
            : supplyType === 'intra'
              ? [{ label: 'CGST', value: fmt(taxTotal / 2) }, { label: 'SGST', value: fmt(taxTotal / 2) }]
              : supplyType === 'inter'
                ? [{ label: 'IGST', value: fmt(taxTotal) }]
                : [{ label: 'Tax (GST)', value: fmt(taxTotal) }]
          return [
            { label: 'Subtotal', value: fmt(subtotal) },
            lineDiscounts > 0 && { label: 'Discounts', value: `− ${fmt(lineDiscounts)}`, color: '#10b981' },
            lineDiscounts > 0 && { label: 'After discount', value: fmt(baseAfterLines) },
            ...taxRows,
          ].filter(Boolean)
        })().map((row, i) => (
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
      {supplyType == null && !breakdown.length && (
        <p className="text-right text-[11px]" style={{ color: '#f59e0b' }}>
          CGST/SGST vs IGST is detected from your registered state (Settings → Company & Finance) and the customer's billing state — set both to see the split.
        </p>
      )}
    </div>
  )
}
