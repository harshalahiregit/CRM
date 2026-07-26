import TaskDetail from '../pages/TaskDetail'

/**
 * Right-side slide-over that hosts the full task workspace without leaving the
 * current board. Nested overlays opened from inside (TaskFormDrawer z-[55],
 * RaiseTicketModal z-[70], SearchPicker/ConfirmModal z-[60]/z-[70]) all sit
 * above this z-[45], so they render correctly over the slide-over.
 *
 * The close "X" lives inside TaskDetail's gradient header (embedded mode) so the
 * panel needs no close bar of its own — the header sits flush at the panel's top.
 */
export default function TaskDetailModal({ taskId, open, onClose }) {
  if (!open || !taskId) return null
  return (
    <div className="fixed inset-0 z-[45] flex justify-end" style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <div className="h-full w-full max-w-3xl overflow-y-auto" style={{ background: 'var(--bg-global)', boxShadow: '-12px 0 40px rgba(0,0,0,0.35)' }} onClick={e => e.stopPropagation()}>
        <TaskDetail idProp={taskId} onClose={onClose} />
      </div>
    </div>
  )
}
