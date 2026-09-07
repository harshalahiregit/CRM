/**
 * The Minutes of Meeting, as information rather than a scan.
 *
 * TPV showed the vendor an embedded PDF in a zoomable grey viewer. A PDF is the
 * record, not the reading experience: it does not reflow on a phone, it cannot
 * be searched by the person who has to act on it, and it turns "what was I asked
 * to do?" into hunting through a page image. The PDF stays, as the two things a
 * PDF is actually for: View and Download.
 *
 * ── It shows what the document shows ────────────────────────────────────────
 * The first version rendered four sections and read only the STRUCTURED agenda
 * rows, so a meeting whose agenda was typed as a paragraph told the vendor "No
 * agenda was recorded" while the PDF beside it printed that agenda in full. It
 * also omitted the participants entirely. The sections here now mirror the
 * document's: details, participants and attendance, agenda, minutes, decisions,
 * actions, issues.
 *
 * The payload is `VendorMomView` (server side) — one agreed shape for both
 * engines, so this reads the same keys either way.
 *
 * @param {object|null} mom  the VendorMomView payload, or null while loading
 */
export default function KickoffMomReview({ mom }) {
  if (!mom) {
    return <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Loading the minutes…</span>
  }

  const meeting = mom.meeting ?? {}
  const participants = mom.participants ?? []
  const agenda = mom.agenda ?? []
  const decisions = mom.decisions ?? []
  const actions = mom.actions ?? []
  const issues = mom.issues ?? []

  const details = [
    ['Meeting no.', meeting.meeting_no || meeting.reference],
    ['Type', meeting.meeting_type_label],
    ['Held', meeting.scheduled_at ? new Date(meeting.scheduled_at).toLocaleString() : null],
    ['Duration', meeting.held_minutes ? `${meeting.held_minutes} min` : (meeting.duration_minutes ? `${meeting.duration_minutes} min (booked)` : null)],
    ['Mode', meeting.mode ? String(meeting.mode).replace(/_/g, ' ') : null],
    ['Location', meeting.location],
    ['Chairperson', meeting.chairperson],
    ['Coordinator', meeting.coordinator],
    ['Department', meeting.department],
  ].filter(([, v]) => v)

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {details.length > 0 && (
        <Block title="Meeting details">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '8px 20px' }}>
            {details.map(([k, v]) => (
              <div key={k}>
                <span style={{ display: 'block', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{k}</span>
                <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{v}</span>
              </div>
            ))}
          </div>
        </Block>
      )}

      {participants.length > 0 && (
        <Block title={`Participants & attendance (${participants.filter(p => p.attended).length}/${participants.length} attended)`}>
          <div style={{ display: 'grid', gap: 6 }}>
            {participants.map((p, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{p.name || '—'}</span>
                <span style={meta}>
                  {[p.designation, p.organisation, p.role].filter(Boolean).join(' · ')}
                </span>
                <span style={{
                  marginLeft: 'auto', fontSize: 10.5, fontWeight: 800, padding: '2px 8px', borderRadius: 999,
                  background: p.attended ? 'rgba(16,185,129,.14)' : 'var(--bg-input)',
                  color: p.attended ? '#059669' : 'var(--text-muted)',
                }}>
                  {p.attendance_status || (p.attended ? 'Attended' : 'Not marked')}
                </span>
              </div>
            ))}
          </div>
        </Block>
      )}

      {/* Agenda can be a paragraph, a list of rows, or both — the document
          prints whichever exist, so this does too. */}
      <Block title="Agenda">
        {mom.agenda_text && <p style={{ ...li, margin: '0 0 8px' }}>{mom.agenda_text}</p>}
        {agenda.length > 0 ? (
          <ul style={ul}>
            {agenda.map((a, i) => (
              <li key={a.id ?? i} style={li}>
                {a.item || a.title || a.description}
                {a.discussion ? <span style={meta}> — {a.discussion}</span> : null}
                {a.decision ? <span style={meta}> · decided: {a.decision}</span> : null}
                {a.owner ? <span style={meta}> · {a.owner}</span> : null}
              </li>
            ))}
          </ul>
        ) : (!mom.agenda_text && <Empty>No agenda was recorded.</Empty>)}
      </Block>

      {mom.minutes && (
        <Block title="Minutes">
          <p style={{ ...li, margin: 0, whiteSpace: 'pre-line' }}>{mom.minutes}</p>
        </Block>
      )}

      <Block title={`Decisions${decisions.length ? ` (${decisions.length})` : ''}`}>
        {decisions.length > 0 ? (
          <ul style={ul}>
            {decisions.map((d, i) => (
              <li key={d.id ?? i} style={li}>
                {d.decision || d.description}
                {d.decided_by ? <span style={meta}> — {d.decided_by}</span> : null}
                {d.effective_date ? <span style={meta}> · effective {new Date(d.effective_date).toLocaleDateString()}</span> : null}
              </li>
            ))}
          </ul>
        ) : <Empty>No decisions were recorded.</Empty>}
      </Block>

      {/* Owner and due date lead, because "what do I have to do, by when" is the
          only reason most vendors open the minutes at all. */}
      <Block title={`Action items${actions.length ? ` (${actions.length})` : ''}`}>
        {actions.length > 0 ? (
          <ul style={ul}>
            {actions.map((a, i) => (
              <li key={a.id ?? i} style={li}>
                {a.ref ? <strong style={{ color: 'var(--text-h)' }}>{a.ref} </strong> : null}
                {a.description || a.action}
                <span style={meta}>
                  {a.owner ? ` — ${a.owner}` : ''}
                  {a.target_date ? ` · due ${new Date(a.target_date).toLocaleDateString()}` : ''}
                  {a.status ? ` · ${String(a.status).replace(/_/g, ' ')}` : ''}
                  {a.priority ? ` · ${a.priority}` : ''}
                </span>
              </li>
            ))}
          </ul>
        ) : <Empty>No actions were assigned.</Empty>}
      </Block>

      {issues.length > 0 && (
        <Block title={`Issues raised (${issues.length})`}>
          <ul style={ul}>
            {issues.map((it, i) => (
              <li key={it.id ?? i} style={li}>
                {it.ref ? <strong style={{ color: 'var(--text-h)' }}>{it.ref} </strong> : null}
                {it.description || it.issue}
                <span style={meta}>
                  {it.severity ? ` · ${it.severity}` : ''}
                  {it.status ? ` · ${String(it.status).replace(/_/g, ' ')}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </Block>
      )}
    </div>
  )
}

function Block({ title, children }) {
  return (
    <div>
      <h4 style={{
        margin: '0 0 7px', fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)',
        textTransform: 'uppercase', letterSpacing: '0.05em',
      }}>
        {title}
      </h4>
      {children}
    </div>
  )
}

const Empty = ({ children }) => <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{children}</span>

const ul = { margin: 0, paddingLeft: 18, display: 'grid', gap: 5 }
const li = { fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.55 }
const meta = { color: 'var(--text-muted)', fontWeight: 500 }
