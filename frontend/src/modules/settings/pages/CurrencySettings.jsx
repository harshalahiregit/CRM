import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'
import { invalidateFormats, formatMoneyWith } from '@/hooks/useFormats'

// Options mirror the backend SettingRegistry 'currency' rules.
const POSITIONS = [
  ['before', 'Before  (₹100)'],
  ['after', 'After  (100₹)'],
  ['before_space', 'Before + space  (₹ 100)'],
  ['after_space', 'After + space  (100 ₹)'],
]

export default function CurrencySettings() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))

  useEffect(() => {
    settingsApi.group.get('currency').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('currency', values)
      setValues(d?.values || {})
      invalidateFormats()  // every screen re-reads the new currency contract
      toast.success('Currency settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  // Live preview uses the same formatter every module consumes.
  const preview = (n) => formatMoneyWith(values, n)

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Currency</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>
          Used by Finance, Sales, Purchase, Payroll, Inventory, Reports and PDFs — no module formats money on its own.
        </p>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">Currency Code</label>
            <input className="input-3d text-sm" maxLength={3} value={values.code ?? ''} onChange={e => sv('code', e.target.value.toUpperCase())} placeholder="INR" /></div>
          <div><label className="label">Symbol</label>
            <input className="input-3d text-sm" value={values.symbol ?? ''} onChange={e => sv('symbol', e.target.value)} placeholder="₹" /></div>
          <div><label className="label">Symbol Position</label>
            <select className="input-3d text-sm" value={values.symbol_position || 'before'} onChange={e => sv('symbol_position', e.target.value)}>
              {POSITIONS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </select></div>
          <div><label className="label">Decimal Places</label>
            <input type="number" min="0" max="6" className="input-3d text-sm" value={values.decimal_places ?? 2} onChange={e => sv('decimal_places', e.target.value === '' ? '' : Number(e.target.value))} /></div>
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Number Format</h2>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">Decimal Separator</label>
            <input className="input-3d text-sm" maxLength={1} value={values.decimal_separator ?? '.'} onChange={e => sv('decimal_separator', e.target.value)} /></div>
          <div><label className="label">Thousands Separator</label>
            <input className="input-3d text-sm" maxLength={1} value={values.thousands_separator ?? ','} onChange={e => sv('thousands_separator', e.target.value)} /></div>
          <div><label className="label">Digit Grouping</label>
            <select className="input-3d text-sm" value={values.grouping || 'indian'} onChange={e => sv('grouping', e.target.value)}>
              <option value="indian">Indian  (12,34,567.89)</option>
              <option value="western">Western  (1,234,567.89)</option>
            </select></div>
          <div><label className="label">Negative Numbers</label>
            <select className="input-3d text-sm" value={values.negative_format || 'minus'} onChange={e => sv('negative_format', e.target.value)}>
              <option value="minus">Minus sign  (-100)</option>
              <option value="parentheses">Parentheses  ((100))</option>
            </select></div>
        </div>

        <div className="mt-5 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="text-[11px] font-bold uppercase mb-2" style={{ color: 'var(--text-muted)' }}>Live preview</div>
          <div className="flex flex-wrap gap-5 text-sm font-bold" style={{ color: 'var(--text-h)' }}>
            <span>{preview(1234567.891)}</span>
            <span>{preview(1234.5)}</span>
            <span>{preview(-1234.5)}</span>
            <span>{preview(0)}</span>
          </div>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
