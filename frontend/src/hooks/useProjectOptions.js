import { useState, useEffect } from 'react'
import { projectApi } from '@/services/projectApi'

/**
 * Shared list of projects for "Link to Project" dropdowns across modules
 * (estimates, invoices, proposals, …). Backed by the real Projects module
 * (owner: Shivam) so a sales document links to an actual project row rather
 * than a free-typed name. Returns [{ id, name }].
 */
export function useProjectOptions() {
  const [projects, setProjects] = useState([])

  useEffect(() => {
    let alive = true
    projectApi.list()
      .then(res => {
        const list = Array.isArray(res) ? res : (res?.data ?? [])
        if (alive) setProjects(list.map(p => ({ id: p.id, name: p.name })))
      })
      .catch(() => { if (alive) setProjects([]) })
    return () => { alive = false }
  }, [])

  return projects
}
