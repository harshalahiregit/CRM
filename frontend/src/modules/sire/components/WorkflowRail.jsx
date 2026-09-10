import { MAIN_PATH, railPosition, stateLabel } from '../../../lib/sire/workflow';

/**
 * The happy path, with a marker on where this issue actually is.
 *
 * Side states (QA Failed, On Hold, Reopened, and the terminal resolutions) are
 * deliberately NOT rail stops — putting them inline would suggest every issue
 * passes through them. When the issue is off-rail, the marker rests on the last
 * main-path state it reached and the deviation is named above the rail.
 */
export default function WorkflowRail({ status, lastMainPathStatus }) {
  const { index, offRail } = railPosition(status, lastMainPathStatus);

  return (
    <div className="w-full">
      {offRail && (
        <p className="mb-2 text-xs font-medium text-amber-700 dark:text-amber-300">
          Currently {stateLabel(status)} — off the main path
        </p>
      )}
      <ol className="flex items-center gap-1 overflow-x-auto pb-1">
        {MAIN_PATH.map((state, i) => {
          const done = i < index;
          const here = i === index && !offRail;
          return (
            <li key={state} className="flex shrink-0 items-center gap-1">
              <span
                title={stateLabel(state)}
                className={[
                  'h-1.5 w-10 rounded-full transition-colors',
                  here ? 'bg-blue-500' : done ? 'bg-blue-300 dark:bg-blue-800' : 'bg-gray-200 dark:bg-gray-700',
                  offRail && i === index ? 'bg-amber-400' : '',
                ].join(' ')}
              />
            </li>
          );
        })}
      </ol>
      <div className="mt-1 flex justify-between text-[10px] text-gray-400">
        <span>{stateLabel(MAIN_PATH[0])}</span>
        <span className="font-medium text-gray-600 dark:text-gray-300">{stateLabel(status)}</span>
        <span>{stateLabel(MAIN_PATH[MAIN_PATH.length - 1])}</span>
      </div>
    </div>
  );
}
