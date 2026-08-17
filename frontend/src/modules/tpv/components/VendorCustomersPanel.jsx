import { useState, useEffect, useMemo } from 'react'
import { Building2 } from 'lucide-react'
import { projectApi } from '@/services/projectApi'
import { SectionTable } from './VendorSectionTable'

/**
 * The customers this TPV vendor works for.
 *
 * No customer is linked to a vendor anywhere in the schema, and none was linked
 * here. The relationship already exists one hop out: a project carries both
 * parties — projects.vendor_id (this vendor) and projects.customer_id (the
 * client) — so "who does this vendor work for" is answered by the vendor's own
 * projects.
 *
 * That means NO new endpoint and no new query: this reads the same authorised
 * GET /projects?vendor_id= the Projects tab uses, where every row already
 * arrives decorated with its resolved customer. The grouping below is
 * presentation over data the caller is already permitted to see — the access
 * decision stays entirely server-side, in the vendor_id filter.
 */
export function VendorCustomers({ vendorId }) {
  const [projects, setProjects] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let live = true
    setLoading(true); setError(null)

    projectApi.list({ vendor_id: vendorId })
      .then(d => { if (live) setProjects(d?.data ?? d ?? []) })
      .catch(e => { if (live) setError(e?.title || e?.message || 'Could not load customers.') })
      .finally(() => { if (live) setLoading(false) })

    return () => { live = false }
  }, [vendorId])

  // One row per customer, carrying the projects that connect them to this vendor.
  const rows = useMemo(() => {
    const byId = new Map()

    projects.forEach(p => {
      const c = p.customer
      if (!c?.id) return   // an internal project has no client — not a customer

      const entry = byId.get(c.id) || { id: c.id, name: c.name || c.company, email: c.email, projects: [] }
      entry.projects.push(p)
      byId.set(c.id, entry)
    })

    return [...byId.values()].sort((a, b) => b.projects.length - a.projects.length)
  }, [projects])

  const unlinked = projects.length - rows.reduce((n, r) => n + r.projects.length, 0)

  return (
    <SectionTable
      icon={Building2} title="Customers" loading={loading} error={error} rows={rows}
      hint={
        rows.length
          ? `Reached through this vendor's projects.${unlinked > 0 ? ` ${unlinked} project${unlinked === 1 ? ' has' : 's have'} no customer.` : ''}`
          : "Customers are reached through this vendor's projects — this vendor has none with a customer yet."
      }
      searchFields={['name', 'email']}
      columns={[
        { key: 'name', label: 'Customer', render: c => <span style={{ fontWeight: 700 }}>{c.name || '—'}</span> },
        { key: 'email', label: 'Email' },
        {
          key: 'count',
          label: 'Projects',
          render: c => (
            <span style={{ fontSize: 11.5, fontWeight: 700, color: '#a78bfa' }}>
              {c.projects.length}
            </span>
          ),
        },
        {
          key: 'projects',
          label: 'Via',
          render: c => c.projects.map(p => p.name).join(', '),
        },
      ]}
    />
  )
}
