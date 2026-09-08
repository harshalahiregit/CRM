import { useState, useEffect, useCallback } from 'react'
import { FileText, Download, Lock, CheckCircle2 } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'
import { GRAD } from '@/components/ui/brand'

/*  ────────────────────────────────────────────────────────────────────────
    Relieving, experience and salary revision letters.

    The reason a letter cannot be issued is shown, not just the fact that it
    cannot. "Not yet" and "never" look identical on a greyed-out button, and
    the difference is what HR needs — whether they are waiting on clearance to
    finish or looking at the wrong employee.

    The server decides. This screen asks which letters are available and
    renders the answer; it does not reimplement the rules, because a screen
    that decided locally would offer buttons the API has already started
    refusing.
    ──────────────────────────────────────────────────────────────────────── */

const LETTERS = {
  relieving:  { label: 'Relieving Letter',   blurb: 'Confirms release and that all dues are settled. Needs a completed clearance.' },
  experience: { label: 'Experience Certificate', blurb: 'States the service period and role. Needs the last working day to have passed.' },
  appraisal:  { label: 'Salary Revision Letter', blurb: 'States what the salary changed from and to. Needs an earlier salary on record.' },
}

export default function EmployeeLetters({ employeeId, employeeName, showToast }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.employees.letters(employeeId)
      .then(setRows)
      .catch(() => showToast?.('Could not check which letters are available', 'error'))
      .finally(() => setLoading(false))
  }, [employeeId, showToast])

  useEffect(() => { load() }, [load])

  const download = async (type) => {
    setBusy(type)
    try {
      const blob = await hrApi.employees.letterPdf(employeeId, type)
      // Handed to the browser rather than opened in a tab: a PDF opened inline
      // loses the filename, and these are filed by name.
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${type}_letter_${(employeeName || employeeId).toString().replace(/\s+/g, '_')}.pdf`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      showToast?.(`${LETTERS[type]?.label || 'Letter'} downloaded`)
    } catch (e) {
      showToast?.(e?.response?.data?.message || 'Could not generate that letter', 'error')
    } finally { setBusy(null) }
  }

  if (loading) return <HrLoading label="Checking which letters can be issued…" />

  return (
    <div className="space-y-3">
      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
        Generated from records already on file — nothing is typed twice. A letter that cannot be issued says why.
      </p>

      {rows.map(r => {
        const meta = LETTERS[r.type] || { label: r.type }
        return (
          <div key={r.type} className="card-3d flex items-start justify-between gap-4 flex-wrap" style={{ padding: 14 }}>
            <div style={{ minWidth: 220, flex: 1 }}>
              <p className="text-[13px] font-black flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
                <FileText size={14} style={{ color: '#a78bfa' }} /> {meta.label}
                {r.available
                  ? <CheckCircle2 size={13} style={{ color: '#10b981' }} />
                  : <Lock size={12} style={{ color: 'var(--text-muted)' }} />}
              </p>
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{meta.blurb}</p>
              {!r.available && r.reason && (
                <p className="text-[11px] mt-1.5 rounded-lg px-2.5 py-1.5"
                  style={{ background: 'rgba(245,158,11,0.08)', color: '#b45309' }}>
                  {r.reason}
                </p>
              )}
            </div>

            <button
              onClick={() => download(r.type)}
              disabled={!r.available || busy === r.type}
              className="px-3.5 py-2 rounded-xl text-[12px] font-bold inline-flex items-center gap-1.5 shrink-0"
              style={{
                background: r.available ? GRAD : 'var(--bg-input)',
                color: r.available ? '#fff' : 'var(--text-muted)',
                opacity: busy === r.type ? 0.7 : 1,
                cursor: r.available ? 'pointer' : 'not-allowed',
              }}>
              <Download size={13} /> {busy === r.type ? 'Generating…' : 'Download'}
            </button>
          </div>
        )
      })}
    </div>
  )
}
