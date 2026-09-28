import { stateClasses, stateLabel } from '../../../lib/sire/workflow';

/** One pill, one vocabulary. Colour comes from the generated workflow file. */
export default function StatusBadge({ status, size = 'md' }) {
  const pad = size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs';
  return (
    <span className={`inline-flex items-center rounded-full font-medium ring-1 ring-inset ${pad} ${stateClasses(status)}`}>
      {stateLabel(status)}
    </span>
  );
}
