// Moved to @/components/ui/SearchPicker so non-helpdesk modules can use it
// without importing across a module boundary. Re-exported here so existing
// helpdesk imports keep working.
export { default, ConfirmModal, InputModal } from '@/components/ui/SearchPicker'
