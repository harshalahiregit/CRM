/**
 * SIRE — SLA state, in four words or fewer.
 *
 * The server sends the state; this never computes one. Showing the remaining or
 * overrun time matters more than the label — "Breached" tells you less than
 * "Breached by 4h".
 */
import { slaToken, chipClasses } from '../../../lib/sire/tokens';

function humanMinutes(total) {
  const m = Math.abs(Math.round(total));
  if (m < 60) return `${m}m`;
  if (m < 60 * 24) return `${Math.floor(m / 60)}h`;
  return `${Math.floor(m / (60 * 24))}d`;
}

export default function SlaChip({ clock, size = 'md' }) {
  if (!clock?.state) return <span className="text-xs text-gray-300">—</span>;

  const { state, elapsed_minutes: elapsed, target_minutes: target, stopped, met } = clock;
  const t = slaToken(state);

  let detail = null;
  if (target != null && elapsed != null) {
    const remaining = target - elapsed;
    if (stopped) detail = met ? `in ${humanMinutes(elapsed)}` : `by ${humanMinutes(-remaining)}`;
    else if (state === 'breached') detail = `by ${humanMinutes(-remaining)}`;
    else if (state !== 'paused') detail = `${humanMinutes(remaining)} left`;
  }

  return (
    <span
      title={target != null ? `${elapsed}m elapsed of a ${target}m target` : undefined}
      className={chipClasses(t, size)}
    >
      {t.marker && <span aria-hidden>{t.marker}</span>}
      {t.label}
      {detail && <span className="font-normal opacity-80">{detail}</span>}
    </span>
  );
}
