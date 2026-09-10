/**
 * SIRE — defect trend, created vs resolved.
 *
 * Hand-rolled SVG. No chart library is installed in this CRM and the brief says
 * not to introduce unnecessary external services, so adding recharts or d3 for
 * two series of bars would be a dependency decision taken by accident.
 *
 * Two series on purpose: created alone says nothing. A rising line is either bad
 * news or better reporting, and only the pair tells you which — if resolved
 * tracks created, the team is keeping up whatever the absolute numbers do.
 */
import { useMemo, useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const EmptyState = sireHostUi('EmptyState');

const HEIGHT = 160;
const BAR_GAP = 2;

export default function TrendChart({ data = [], bucket = 'week' }) {
  const [hover, setHover] = useState(null);

  const max = useMemo(
    () => Math.max(1, ...data.flatMap((d) => [d.created, d.resolved])),
    [data],
  );

  if (data.length === 0) {
    return (
      <EmptyState
        title="No issues in this period"
        description="Widen the date range, or clear a filter."
      />
    );
  }

  const slot = 100 / data.length;
  const barWidth = Math.max(1, slot / 2 - BAR_GAP / 2);

  return (
    <div className="relative">
      <svg
        viewBox={`0 0 100 ${HEIGHT}`}
        preserveAspectRatio="none"
        className="h-40 w-full"
        role="img"
        aria-label={`Defects created and resolved per ${bucket}`}
      >
        {/* Gridlines at quarters. Drawn behind, deliberately faint. */}
        {[0.25, 0.5, 0.75, 1].map((f) => (
          <line
            key={f}
            x1="0" x2="100"
            y1={HEIGHT - f * HEIGHT} y2={HEIGHT - f * HEIGHT}
            className="stroke-gray-200 dark:stroke-gray-700"
            strokeWidth="0.5"
            vectorEffect="non-scaling-stroke"
          />
        ))}

        {data.map((d, i) => {
          const x = i * slot;
          const createdH = (d.created / max) * (HEIGHT - 8);
          const resolvedH = (d.resolved / max) * (HEIGHT - 8);

          return (
            <g key={d.bucket} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
              {/* Invisible full-height target so hovering does not require
                  hitting a 3px bar. */}
              <rect x={x} y={0} width={slot} height={HEIGHT} fill="transparent" />
              <rect
                x={x + BAR_GAP / 2} y={HEIGHT - createdH}
                width={barWidth} height={createdH}
                className="fill-red-400 dark:fill-red-500"
              />
              <rect
                x={x + slot / 2 + BAR_GAP / 2} y={HEIGHT - resolvedH}
                width={barWidth} height={resolvedH}
                className="fill-green-400 dark:fill-green-500"
              />
            </g>
          );
        })}
      </svg>

      <div className="mt-2 flex items-center justify-between text-[11px] text-gray-500">
        <span className="flex items-center gap-3">
          <span className="flex items-center gap-1">
            <span className="inline-block h-2 w-2 rounded-sm bg-red-400" /> Created
          </span>
          <span className="flex items-center gap-1">
            <span className="inline-block h-2 w-2 rounded-sm bg-green-400" /> Resolved
          </span>
        </span>
        <span>
          {hover !== null
            ? `${data[hover].bucket}: ${data[hover].created} created, ${data[hover].resolved} resolved`
            : `${data.length} ${bucket}s · peak ${max}`}
        </span>
      </div>
    </div>
  );
}
