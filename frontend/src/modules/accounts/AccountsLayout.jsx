import { Outlet } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Landmark, Loader2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { useToast } from '@/hooks/useToast'

/**
 * Gates the whole module behind first-run setup: until the tenant's books are
 * initialised (chart of accounts + voucher types + financial year), every
 * accounts page shows a one-click "Set up" screen instead.
 */
export default function AccountsLayout() {
  const toast = useToast()
  const qc = useQueryClient()

  const { data: status, isLoading } = useQuery({
    queryKey: ['accounts', 'setup'],
    queryFn: accountsApi.setupStatus,
  })

  const setup = useMutation({
    mutationFn: accountsApi.runSetup,
    onSuccess: () => {
      toast.success('Books set up — chart of accounts ready')
      qc.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (e) => toast.error(e.message),
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} />
      </div>
    )
  }

  if (!status?.is_setup) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[55vh] gap-5 animate-fade-in text-center">
        <div
          className="w-16 h-16 rounded-3xl flex items-center justify-center"
          style={{ background: 'linear-gradient(135deg,rgba(124,58,237,0.15),rgba(91,33,182,0.1))', border: '1px solid rgba(124,58,237,0.2)' }}
        >
          <Landmark size={26} style={{ color: '#a78bfa' }} />
        </div>
        <div>
          <h2 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Set up your books</h2>
          <p className="text-sm mt-1 max-w-md" style={{ color: 'var(--text-muted)' }}>
            This seeds the standard Indian chart of accounts, the voucher types, and the current
            financial year (Apr–Mar) so you can start posting straight away.
          </p>
        </div>
        <button
          onClick={() => setup.mutate()}
          disabled={setup.isPending}
          className="btn-3d flex items-center gap-2"
        >
          {setup.isPending && <Loader2 size={15} className="animate-spin" />}
          Set up Chart of Accounts
        </button>
      </div>
    )
  }

  return <Outlet />
}
