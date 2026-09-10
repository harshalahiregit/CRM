/**
 * SIRE — release gates.
 *
 * Every gate shows what it FOUND, not just whether it passed. "2 open critical
 * issues" tells you what to do next; "blocked" does not.
 *
 * Five states, and the distinctions matter:
 *   pass        checked, satisfied
 *   fail        checked, not satisfied
 *   skipped     disabled in settings — nobody checked
 *   overridden  failed, and someone authorised shipping anyway
 *   unknown     configured but this build cannot evaluate it — blocks, on purpose
 */
import { gateToken, GATE_GLYPH } from '../../../lib/sire/tokens';

export default function ReleaseGatePanel({ evaluation, onOverride, canOverride }) {
  if (!evaluation) return null;

  const { gates = [], status, blocking_failures: blocking, override_active: overridden, ungoverned } = evaluation;

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span
            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
              status === 'ready'
                ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-200'
                : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200'
            }`}
          >
            {status === 'ready' ? 'Gates passing' : `${blocking} gate${blocking === 1 ? '' : 's'} blocking`}
          </span>
          {overridden && (
            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200">
              Shipping on an override
            </span>
          )}
        </div>

        {canOverride && status !== 'ready' && (
          <button type="button" onClick={onOverride} className="text-xs font-medium text-amber-700 hover:underline">
            Record an emergency override
          </button>
        )}
      </div>

      {ungoverned && (
        <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
          No gates are configured for this workspace. Nothing was checked — this is not the same as
          everything passing.
        </div>
      )}

      <ul className="divide-y divide-gray-200 dark:divide-gray-700">
        {gates.map((gate) => {
          const t = gateToken(gate.status);
          return (
            <li key={gate.key} className="flex items-start gap-3 py-2">
              {/* A glyph, not a chip: a column of chips is heavier than a column of marks. */}
              <span className={`mt-0.5 w-4 shrink-0 text-center font-bold ${t.classes.split(' ').find((c) => c.startsWith('text-'))}`} aria-label={t.label}>
                {GATE_GLYPH[gate.status] ?? GATE_GLYPH.unknown}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium">{gate.label}</span>
                  {!gate.blocking && (
                    <span className="rounded bg-gray-100 px-1 text-[9px] font-medium uppercase text-gray-500 dark:bg-gray-800">
                      advisory
                    </span>
                  )}
                </div>
                <p className={`text-[11px] ${gate.status === 'fail' ? 'text-red-600 dark:text-red-400' : 'text-gray-500'}`}>
                  {gate.detail}
                </p>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
