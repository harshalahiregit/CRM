/**
 * SIRE — the six components SIRE needs, when the host registers none.
 *
 * These are FALLBACKS, not a design system. They are deliberately plain:
 * semantic HTML, no colours beyond what a browser gives, no icon set, no
 * animation, no dependency. A module that shipped its own opinionated kit would
 * look like a module every time, which is the opposite of the goal.
 *
 * They are, however, ACCESSIBLE — focusable, labelled, keyboard-operable, with
 * real disabled and busy states. A fallback that is ugly is a cosmetic problem;
 * one that a keyboard user cannot operate is an exclusion, and "the host will
 * probably override it" is not a reason to ship that.
 *
 * Register your own and these disappear:
 *
 *     SireHost.configure({ ui: { Button: MyButton, Modal: MyDialog } });
 *
 * Partial registration is normal. Give SIRE your Button and Modal, let it fall
 * back for TagInput, and only the tag input looks unstyled.
 */

import { useEffect, useRef } from 'react';

/** Last resort: renders children in a div. Never crashes on an unknown name. */
export const Unstyled = ({ children, ...props }) => <div {...props}>{children}</div>;

/**
 * A button that knows it is busy.
 *
 * `loading` disables AND announces, because a spinner alone tells a screen
 * reader nothing, and a button that merely looks busy still submits twice.
 */
export const AsyncButton = ({ loading, disabled, variant = 'default', children, ...props }) => (
  <button
    type="button"
    disabled={disabled || loading}
    aria-busy={loading || undefined}
    data-variant={variant}
    className="sire-btn"
    {...props}
  >
    {loading ? <span aria-hidden="true">… </span> : null}
    {children}
  </button>
);

export const Button = AsyncButton;

/**
 * A modal that behaves like one.
 *
 * Uses <dialog>, which the browser already knows how to make modal: focus is
 * trapped, Escape closes, and the rest of the page is inert. Reimplementing
 * that in JavaScript is how modals end up untabbable.
 */
export const Modal = ({ open, onClose, title, children }) => {
  const ref = useRef(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return undefined;

    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();

    // Escape fires 'cancel', not 'close', and without this the parent's state
    // and the dialog's disagree about whether it is open.
    const onCancel = (event) => { event.preventDefault(); onClose?.(); };
    dialog.addEventListener('cancel', onCancel);

    return () => dialog.removeEventListener('cancel', onCancel);
  }, [open, onClose]);

  return (
    <dialog ref={ref} className="sire-modal" aria-label={title}>
      {title ? <h2 className="sire-modal__title">{title}</h2> : null}
      {children}
      <form method="dialog" className="sire-modal__close">
        <button type="submit" onClick={onClose}>Close</button>
      </form>
    </dialog>
  );
};

/**
 * A table with a real header association.
 *
 * `scope="col"` is the whole reason this is not a grid of divs: without it a
 * screen reader reads forty cells with no idea which column each belongs to.
 */
export const DataTable = ({ columns = [], rows = [], loading, emptyLabel = 'Nothing to show' }) => {
  if (loading) return <p className="sire-table__loading" role="status">Loading…</p>;
  if (rows.length === 0) return <EmptyState title={emptyLabel} />;

  return (
    <div className="sire-table__scroll" style={{ overflowX: 'auto' }}>
      <table className="sire-table">
        <thead>
          <tr>{columns.map((c) => <th key={c.key} scope="col">{c.header}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={row.id ?? i}>
              {columns.map((c) => <td key={c.key}>{c.render ? c.render(row) : row[c.key]}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

/**
 * "Nothing here" — which must never be how an ERROR looks.
 *
 * SIRE distinguishes the two everywhere (see SireStateBoundary); this component
 * is only ever the genuinely-empty case.
 */
export const EmptyState = ({ title, description, action }) => (
  <div className="sire-empty" role="status">
    <p className="sire-empty__title">{title}</p>
    {description ? <p className="sire-empty__description">{description}</p> : null}
    {action ?? null}
  </div>
);

export const TablePagination = ({ page, onPageChange }) => {
  const current = page?.meta?.page ?? page?.page ?? 1;
  const pages = page?.meta?.pages ?? page?.pages ?? 1;

  if (pages <= 1) return null;

  return (
    <nav className="sire-pagination" aria-label="Pagination">
      <button type="button" disabled={current <= 1} onClick={() => onPageChange(current - 1)}>
        Previous
      </button>
      <span aria-current="page">Page {current} of {pages}</span>
      <button type="button" disabled={current >= pages} onClick={() => onPageChange(current + 1)}>
        Next
      </button>
    </nav>
  );
};

/**
 * Comma-separated tags.
 *
 * The simplest thing that works everywhere. A host with a real tag input should
 * register it — this one cannot do chips, autocomplete or drag-reorder, and does
 * not pretend to.
 */
export const TagInput = ({ value = [], onChange, placeholder = 'Comma separated' }) => (
  <input
    type="text"
    className="sire-taginput"
    value={Array.isArray(value) ? value.join(', ') : (value ?? '')}
    placeholder={placeholder}
    onChange={(e) => onChange?.(
      e.target.value.split(',').map((v) => v.trim()).filter(Boolean),
    )}
  />
);
