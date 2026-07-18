// Module Registry — All available CRM modules (like Perfex CRM)
export const ALL_MODULES = [
  {
    id: 'hr',
    name: 'HR & Recruitment',
    description: 'Full HR lifecycle: manpower requests, job postings, candidate pipeline, interviews, offers & onboarding.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '👥',
    color: 'linear-gradient(135deg,#7C3AED,#5b21b6)',
    shadowColor: '#7C3AED',
    category: 'Human Resources',
    features: ['Manpower Requests','Job Postings','Candidate Pipeline','Interview Scheduling','AI Recommendation','Offer Letters','Onboarding','Employee Records'],
    basePath: '/app/hr',
    navItems: [
      { label: 'HR Dashboard',       path: '/app/hr/dashboard',          icon: '📊' },
      { label: 'Manpower Requests',  path: '/app/hr/manpower-requests',  icon: '📋' },
      { label: 'Job Postings',       path: '/app/hr/jobs',               icon: '💼' },
      { label: 'Candidates',         path: '/app/hr/candidates',         icon: '👤' },
      { label: 'Interviews',         path: '/app/hr/interviews',         icon: '📅' },
      { label: 'Offer Letters',      path: '/app/hr/offers',             icon: '📄' },
      { label: 'Onboarding',         path: '/app/hr/onboarding',         icon: '🚀' },
      { label: 'Employees',          path: '/app/hr/employees',          icon: '🏢' },
    ],
  },
  {
    id: 'inventory',
    name: 'Inventory',
    description: 'Manage stock, warehouses, purchase orders and inventory movements.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '📦',
    color: 'linear-gradient(135deg,#10B981,#059669)',
    shadowColor: '#10b981',
    category: 'Operations',
    features: ['Stock Management','Warehouses','Purchase Orders','Low Stock Alerts','Barcode Scanning'],
    basePath: '/app/inventory',
    navItems: [],
  },
  {
    id: 'payroll',
    name: 'Payroll',
    description: 'Automated payroll, salary slips, tax calculations and compliance reports.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '💰',
    color: 'linear-gradient(135deg,#F59E0B,#d97706)',
    shadowColor: '#f59e0b',
    category: 'Finance',
    features: ['Salary Processing','Pay Slips','TDS Calculation','PF & ESI','Form 16'],
    basePath: '/app/payroll',
    navItems: [],
  },
  {
    id: 'crm_ai',
    name: 'AI Assistant',
    description: 'AI-powered insights, lead scoring, deal prediction and automated follow-ups.',
    version: '0.9.0',
    author: 'Sangoe Team',
    icon: '🤖',
    color: 'linear-gradient(135deg,#3b82f6,#1d4ed8)',
    shadowColor: '#3b82f6',
    category: 'Intelligence',
    features: ['Lead Scoring','Deal Prediction','AI Follow-ups','Sentiment Analysis','Smart Reports'],
    basePath: '/app/ai',
    navItems: [],
  },
  {
    id: 'whatsapp',
    name: 'WhatsApp CRM',
    description: 'Integrate WhatsApp Business for lead nurturing and customer support.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '💬',
    color: 'linear-gradient(135deg,#25D366,#128C7E)',
    shadowColor: '#25D366',
    category: 'Communication',
    features: ['WhatsApp API','Template Messages','Broadcast Lists','Chatbot','Lead Capture'],
    basePath: '/app/whatsapp',
    navItems: [],
  },
  {
    id: 'project_mgmt',
    name: 'Project Management',
    description: 'Full project lifecycle with Kanban boards, Gantt charts and time tracking.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '🗂️',
    color: 'linear-gradient(135deg,#ec4899,#be185d)',
    shadowColor: '#ec4899',
    category: 'Operations',
    features: ['Kanban Board','Gantt Chart','Time Tracking','Milestones','Resource Planning'],
    basePath: '/app/projects',
    navItems: [],
  },
  {
    id: 'tpv',
    name: 'Third-Party Vendors',
    description: 'Vendor onboarding, statutory documents, workforce registration, HSSE compliance & site gate access.',
    version: '1.0.0',
    author: 'Sangoe Team',
    icon: '🦺',
    color: 'linear-gradient(135deg,#7C3AED,#5b21b6)',
    shadowColor: '#7C3AED',
    category: 'Operations',
    features: ['Kickoff Meetings','Vendor Onboarding','Document Verification','Workforce Registration','HSSE Compliance','QR Gate Access'],
    basePath: '/app/tpv',
    navItems: [
      { label: 'TPV Dashboard',     path: '/app/tpv/dashboard',  icon: '📊' },
      { label: 'Kickoff Meetings',  path: '/app/tpv/kickoff',    icon: '📅' },
      { label: 'Vendor Onboarding', path: '/app/tpv/onboarding', icon: '🚀' },
      { label: 'Documents',         path: '/app/tpv/documents',  icon: '📄' },
      { label: 'Workforce',         path: '/app/tpv/workforce',  icon: '👷' },
      { label: 'Compliance',        path: '/app/tpv/compliance', icon: '✅' },
      { label: 'Gate Log',          path: '/app/tpv/gate-log',   icon: '🔳' },
    ],
  },
]

// Persist installed modules in localStorage
const STORAGE_KEY = 'sangoe_installed_modules'

export function getInstalledModules() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    return stored ? JSON.parse(stored) : []
  } catch { return [] }
}

export function installModule(moduleId) {
  const installed = getInstalledModules()
  if (!installed.includes(moduleId)) {
    installed.push(moduleId)
    localStorage.setItem(STORAGE_KEY, JSON.stringify(installed))
  }
}

export function uninstallModule(moduleId) {
  const installed = getInstalledModules().filter(id => id !== moduleId)
  localStorage.setItem(STORAGE_KEY, JSON.stringify(installed))
}

export function isModuleInstalled(moduleId) {
  return getInstalledModules().includes(moduleId)
}
