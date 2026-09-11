/**
 * SIRE — operational dashboard.
 *
 * Counts and a filtered register. No trend lines, no MTTR, no per-developer
 * throughput: the brief says quality analytics come later, and each of those needs
 * a decision about what it actually measures before it needs code.
 *
 * Tiles and the table share one filter state and one scope, so the number on a
 * tile and the rows behind it are the same query.
 */
import { useCallback, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { sireApi, toPage } from '../../../services/sireApi';
import { toQueryParams } from '../../../lib/sire/dashboardFilters';
import DashboardTiles from '../components/DashboardTiles';
import IssueFilters from '../components/IssueFilters';
import IssueTable from '../components/IssueTable';
import SavedViews from '../components/SavedViews';

const EMPTY = {};

export default function DashboardPage() {
  const [filters, setFilters] = useState(EMPTY);
  const [scope, setScope] = useState('open');
  const [pageNo, setPageNo] = useState(1);

  const params = useMemo(() => toQueryParams(filters), [filters]);

  const optionsQuery = useQuery({
    queryKey: ['sire', 'dashboard', 'options'],
    queryFn: () => sireApi.dashboardOptions().then((r) => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000, // masters change rarely; do not refetch on every focus
  });

  const tilesQuery = useQuery({
    queryKey: ['sire', 'dashboard', 'tiles', params],
    queryFn: () => sireApi.dashboardTiles(params).then((r) => (r.data?.data ?? r.data)?.tiles ?? {}),
  });

  const registerQuery = useQuery({
    queryKey: ['sire', 'dashboard', 'register', params, scope, pageNo],
    queryFn: () => sireApi
      .dashboardRegister({ ...toQueryParams(filters, scope), page: pageNo })
      .then(toPage),
    placeholderData: (previous) => previous, // no flash-to-empty while paging
  });

  const changeFilters = useCallback((next) => {
    setFilters(next);
    setPageNo(1); // a new filter means page 1; staying on page 7 of 2 shows nothing
  }, []);

  const changeScope = useCallback((next) => {
    setScope(next ?? 'all');
    setPageNo(1);
  }, []);

  return (
    <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
      <header>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Issues</h1>
        <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
          Counts respect the filters below. Selecting a tile filters the table to the same set.
        </p>
      </header>

      <DashboardTiles
        counts={tilesQuery.data ?? {}}
        active={scope}
        onSelect={changeScope}
        loading={tilesQuery.isLoading}
      />

      {/* Filters and the register sit on the app's card surface, not on a bare
          grey outline — the same treatment every other module's list uses. */}
      <div
        className="rounded-2xl p-4"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <IssueFilters
          filters={filters}
          options={optionsQuery.data ?? {}}
          onChange={changeFilters}
          onClear={() => changeFilters(EMPTY)}
        />

        {/* Saved filter sets. localStorage by design -- the CRM has no per-user
            preference store, and the control says "this browser only" rather
            than letting someone discover that on their laptop. */}
        <div className="mt-3 border-t pt-3" style={{ borderColor: 'var(--border)' }}>
          <SavedViews
            listKey="sire.register"
            filters={filters}
            scope={scope}
            onApply={(view) => { changeFilters(view?.filters ?? EMPTY); changeScope(view?.scope ?? 'all'); }}
          />
        </div>
      </div>

      <div
        className="overflow-hidden rounded-2xl"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <IssueTable
          page={registerQuery.data}
          loading={registerQuery.isLoading}
          onPageChange={setPageNo}
        />
      </div>
    </div>
  );
}
