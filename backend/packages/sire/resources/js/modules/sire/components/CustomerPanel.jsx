/**
 * SIRE — which customer this issue affects.
 *
 * The question this answers is a prioritisation one: a P3 nobody has mentioned
 * and a P3 that three customers raised this month are not the same defect, and
 * until the register could name a customer it could not tell them apart.
 *
 * READ-ONLY against the host's directory. Nothing here creates or edits a
 * customer — it searches the Customer Directory and stores an id against the
 * issue. The name and the deep link are resolved server-side on every read, so a
 * company that gets renamed is renamed here too rather than leaving a stale copy
 * on a defect from last quarter.
 *
 * Renders NOTHING when no directory is connected. A search box that can only
 * ever return nothing is worse than no search box: the person using it concludes
 * their customer is missing, rather than that the feature is not wired up.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import { sireHostToast } from '../../../lib/sire/host';

const useToast = sireHostToast();

/** Long enough that typing a company name is one request, not eleven. */
const DEBOUNCE_MS = 250;

export default function CustomerPanel({ reportId, customer, canEdit }) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState('');
  const [debounced, setDebounced] = useState('');
  const boxRef = useRef(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(term), DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [term]);

  // Asked once, not per keystroke: whether a directory exists at all does not
  // change while somebody is typing.
  const availability = useQuery({
    queryKey: ['sire', 'customers', 'available'],
    queryFn: () => sireApi.searchCustomers('').then((r) => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  const results = useQuery({
    queryKey: ['sire', 'customers', debounced],
    queryFn: () => sireApi.searchCustomers(debounced).then((r) => r.data?.data ?? r.data),
    enabled: open && Boolean(availability.data?.available),
  });

  const save = useMutation({
    mutationFn: (customerId) => sireApi.setCustomer(reportId, customerId),
    onSuccess: (_res, customerId) => {
      queryClient.invalidateQueries({ queryKey: ['sire', 'issue', String(reportId)] });
      setOpen(false);
      setTerm('');
      toast?.success?.(customerId ? 'Customer linked.' : 'Customer cleared.');
    },
    onError: (err) => {
      toast?.error?.(err?.response?.data?.message || 'Could not update the customer.');
    },
  });

  // Close on an outside click. The panel sits inside a sidebar that scrolls, so
  // leaving a dropdown open while the page moves underneath reads as a bug.
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (!boxRef.current?.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  const rows = useMemo(() => results.data?.customers ?? [], [results.data]);

  // No directory connected: say nothing at all.
  if (availability.isLoading || !availability.data?.available) return null;

  return (
    <div ref={boxRef} className="relative rounded-xl border border-gray-200 p-3 dark:border-gray-700">
      <div className="mb-2 flex items-center justify-between">
        <span className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
          Affected customer
        </span>
        {canEdit && (
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
          >
            {customer ? 'Change' : 'Link a customer'}
          </button>
        )}
      </div>

      {customer ? (
        <div className="flex items-center justify-between gap-2">
          <a
            href={customer.url || undefined}
            className="truncate text-sm font-medium text-gray-900 hover:underline dark:text-gray-100"
            title={customer.name}
          >
            {customer.name}
          </a>
          {canEdit && (
            <button
              type="button"
              onClick={() => save.mutate(null)}
              disabled={save.isPending}
              className="shrink-0 text-xs text-gray-400 hover:text-red-500"
            >
              clear
            </button>
          )}
        </div>
      ) : (
        <p className="text-sm text-gray-400">
          {canEdit ? 'Not linked to a customer.' : 'None.'}
        </p>
      )}

      {open && canEdit && (
        <div className="absolute left-3 right-3 top-full z-20 mt-1 rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
          <input
            autoFocus
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            placeholder="Search the customer directory…"
            className="w-full rounded-t-lg border-b border-gray-200 px-3 py-2 text-sm outline-none dark:border-gray-700 dark:bg-gray-800"
          />
          <ul className="max-h-56 overflow-y-auto">
            {results.isLoading && (
              <li className="px-3 py-2 text-xs text-gray-400">Searching…</li>
            )}
            {!results.isLoading && rows.length === 0 && (
              <li className="px-3 py-2 text-xs text-gray-400">
                {debounced ? 'No customer matches that.' : 'Type to search.'}
              </li>
            )}
            {rows.map((c) => (
              <li key={c.id}>
                <button
                  type="button"
                  onClick={() => save.mutate(c.id)}
                  disabled={save.isPending}
                  className="w-full truncate px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  {c.name}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
