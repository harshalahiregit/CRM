/**
 * SIRE host bridge — Sangoe OS wiring.
 *
 * The one file that connects SIRE's frontend to this CRM's. Imported once from
 * main.jsx, before anything renders, so every SIRE screen and the global Report
 * Issue button use our axios client, our toasts and our UI kit rather than the
 * package's plain fallbacks.
 *
 * Nothing here reaches into SIRE. If a slot is removed below, SIRE keeps working
 * on its own component for that slot -- that is the point of the bridge.
 */

import api from '@/lib/api'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/context/AuthContext'
import HostAsyncButton from '@/components/ui/AsyncButton'
import HostModal from '@/components/ui/Modal'
import HostEmptyState from '@/components/ui/EmptyState'
import HostDataTable from '@/components/ui/DataTable'
import { SireHost } from './index'

/**
 * SIRE says `loading`; our AsyncButton says `busy`. Adapted here rather than in
 * SIRE, so the package stays upgradeable.
 *
 * `variant` is consumed rather than forwarded: our button styles through
 * className, and passing an unknown `variant` attribute down to the DOM makes
 * React warn on every render.
 */
const Button = ({ loading, variant, className = '', ...rest }) => (
  <HostAsyncButton
    busy={loading}
    className={[className, variant === 'danger' ? 'btn-danger' : ''].filter(Boolean).join(' ')}
    {...rest}
  />
)

/**
 * Our Modal has no `title` slot, so the heading is rendered here.
 *
 * closeOnBackdrop stays false deliberately: in this CRM a popup closes only via
 * its X or Cancel button. A stray backdrop click must never discard a
 * half-written issue report.
 */
const Modal = ({ open, onClose, title, children, className = '' }) => (
  <HostModal open={open} onClose={onClose} closeOnBackdrop={false} className={className}>
    {title && (
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold">{title}</h3>
        <button type="button" onClick={onClose} aria-label="Close" className="text-xl leading-none px-2">
          &times;
        </button>
      </div>
    )}
    {children}
  </HostModal>
)

/**
 * The host empty state, without the full-page height.
 */
const CompactEmptyState = (props) => (
  <div className="[&>div]:min-h-0 [&>div]:py-6">
    <HostEmptyState {...props} />
  </div>
)

/**
 * SIRE's tables, rendered by the CRM's own DataTable.
 *
 * Without this SIRE fell back to its bundled plain <table>, which is why the
 * register looked foreign and cramped: none of the app's table styling applied,
 * so columns sat at their natural widths with text butting into the next cell
 * ("Functional defect tasks"), headers were centred, and there was no row hover
 * or sorting. The two components only disagreed about prop NAMES.
 *
 *   SIRE says          the CRM says
 *   header       ->    label
 *   rowKey       ->    keyField
 *   emptyLabel   ->    emptyState
 *
 * Sorting is enabled for the plain data columns and left off for the ones whose
 * cell is a component (status pills, SLA chips) -- sorting those would order by
 * nothing meaningful.
 */
const UNSORTABLE = ['status', 'priority', 'sla'];

const DataTable = ({ columns = [], rows = [], loading, rowKey = 'id', emptyLabel, onRowClick }) => (
  <HostDataTable
    columns={columns.map((c) => ({
      key: c.key,
      label: c.header ?? c.label ?? c.key,
      render: c.render,
      align: c.align,
      sortable: c.sortable ?? !UNSORTABLE.includes(c.key),
    }))}
    rows={loading ? [] : rows}
    keyField={rowKey}
    onRowClick={onRowClick}
    emptyState={<HostEmptyState title={emptyLabel ?? 'Nothing to show'} />}
  />
)

SireHost.configure({
  // Gives SIRE our baseURL, bearer token, upload compression, session-failure
  // handling and error interceptors for free.
  api,

  // useToast is a hook; SIRE calls it during render, which is the shape its
  // own docs specify.
  toast: useToast,

  // Same contract: called during render, so a hook is legal here. SIRE uses this
  // only to hide controls a user cannot use -- every real permission decision is
  // made server-side and arrives as `available_actions`.
  auth: () => useAuth().user,

  ui: {
    Button,
    AsyncButton: Button,
    Modal,
    // Prop-compatible, but the host version reserves min-h-[40vh] because it is
    // built for a whole page. SIRE uses it INSIDE panels -- test cases, relations,
    // knowledge links -- where an empty one then punched a screen-high hole in
    // the middle of the case page. Same component, page-height removed.
    EmptyState: CompactEmptyState,
    DataTable,
  },
})

export default SireHost
