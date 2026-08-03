// Guard against losing typed data when a modal is dismissed by an accidental
// backdrop click. If the form has unsaved changes, confirm before closing;
// a pristine (untouched) form still closes instantly so nothing gets in the way.
//
// Usage:
//   <div className="overlay" onClick={() => guardedClose(onClose, dirty)}>
//     <form onClick={e => e.stopPropagation()}> … </form>
//   </div>
export function guardedClose(onClose, dirty, message = 'Discard your changes? What you entered will be lost.') {
  if (dirty && typeof window !== 'undefined' && !window.confirm(message)) return
  onClose?.()
}
