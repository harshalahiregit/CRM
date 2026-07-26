import AccountGroupsManager from '@/modules/accounts/components/AccountGroupsManager'

/**
 * Main-workspace Settings surface for the Chart-of-Accounts groups — the same
 * manager mounted in Accounts Settings, so groups can be curated from either
 * place and feed every ledger/group dropdown.
 */
export default function AccountGroupsSettings() {
  return (
    <div className="card-3d">
      <div className="mb-4">
        <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Account Groups</h2>
        <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
          Manage the Chart-of-Accounts classification tree used across the Accounts module.
        </p>
      </div>
      <AccountGroupsManager />
    </div>
  )
}
