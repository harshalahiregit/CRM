/**
 * SangoeTrack API Service
 * All HTTP calls to /api/hr/track/*
 *
 * These relay to track.sangoe.in through our own backend rather than calling it
 * from the browser — so no SangoeTrack token ever reaches the client, and no
 * CORS change is needed on the live attendance system.
 *
 * Two limits come from SangoeTrack's API, not from this file:
 *   attendance.today()  is today only — their endpoint takes no date.
 *   approvals.pending() is pending only — approved items leave the queue and
 *                       there is no history endpoint to ask for them back.
 * Screens must say so rather than showing an empty list, which reads as
 * "nothing ever happened".
 */

import axios from 'axios'
import { getToken, clearAuth } from '@/lib/authStorage'
import { isSessionFailure } from '@/lib/sessionFailure'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

const api = axios.create({ baseURL: BASE })

api.interceptors.request.use(cfg => {
  const token = getToken()
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

// Same rule as hrApi: only a genuine auth failure ends the session. A 502 from
// SangoeTrack being unreachable must not sign an HR user out mid-approval.
api.interceptors.response.use(
  res => res,
  err => {
    if (isSessionFailure(err, !!getToken())) {
      clearAuth()
      window.location.href = '/auth/login'
    }
    return Promise.reject(err)
  }
)

/**
 * SangoeTrack failures arrive as 502 with their own message, because they answer
 * refusals with HTTP 200 and `status: 0` — our backend translates that. Surface
 * their wording; it is more specific than anything we could invent.
 */
export function trackErrorMessage(err, fallback = 'SangoeTrack could not complete this.') {
  return err?.response?.data?.message || fallback
}

export const sangoeTrackApi = {
  // ── Overview ────────────────────────────────────────────────────────
  dashboard: {
    get: () => api.get('/hr/track/dashboard').then(r => r.data),
  },

  attendance: {
    /** @param status all | present | absent | late | on_leave */
    today: (status = 'all') =>
      api.get('/hr/track/attendance', { params: { status } }).then(r => r.data),
  },

  // ── Approval queues ─────────────────────────────────────────────────
  approvals: {
    pending:     () => api.get('/hr/track/approvals').then(r => r.data),
    settlements: () => api.get('/hr/track/settlements').then(r => r.data),
  },

  // ── Decisions ───────────────────────────────────────────────────────
  // status is 'approved' or 'rejected' throughout.
  leave: {
    decide: (leaveId, status, remark) =>
      api.post('/hr/track/leave/decide', { leave_id: leaveId, status, remark }).then(r => r.data),
  },

  corrections: {
    // Approving also writes the attendance row on their side and notifies the
    // employee's phone — both handled by SangoeTrack, not by us.
    decide: (raiseId, status, remark) =>
      api.post('/hr/track/correction/decide', { raise_id: raiseId, status, remark }).then(r => r.data),
  },

  reimbursements: {
    decide: (reimbursementId, status, remark) =>
      api.post('/hr/track/reimbursement/decide', { reimbursement_id: reimbursementId, status, remark })
        .then(r => r.data),
  },

  advances: {
    /** amount approves for less than was requested; omit to approve in full. */
    decide: (advanceId, status, remark, amount) =>
      api.post('/hr/track/advance/decide', { advance_id: advanceId, status, remark, amount })
        .then(r => r.data),
    /** mode: cash | cheque | bank_transfer. reference is the cheque no. or UTR. */
    disburse: (advanceId, mode, reference) =>
      api.post('/hr/track/advance/disburse', { advance_id: advanceId, mode, reference })
        .then(r => r.data),
    reviewSettlement: (settlementId, status, remark) =>
      api.post('/hr/track/settlement/review', { settlement_id: settlementId, status, remark })
        .then(r => r.data),
  },

  // ── People ──────────────────────────────────────────────────────────
  staff: {
    list:  ()     => api.get('/hr/track/employees').then(r => r.data),
    roles: ()     => api.get('/hr/track/roles').then(r => r.data),
    create: (data) => api.post('/hr/track/employees', data).then(r => r.data),
    // No password argument: SangoeTrack generates one, emails it to the employee,
    // and returns it as `temp_password`. Anything sent here was discarded.
    resetPassword: (employeeUserId) =>
      api.post('/hr/track/employees/password', { employee_user_id: employeeUserId })
        .then(r => r.data),
  },

  // ── Payroll ─────────────────────────────────────────────────────────
  payroll: {
    overview:  ()  => api.get('/hr/track/payroll').then(r => r.data),
    setSalary: (employeeId, salary, salaryType) =>
      api.post('/hr/track/payroll/salary', {
        employee_id: employeeId, salary, salary_type: salaryType,
      }).then(r => r.data),
  },

  // ── Reporting ───────────────────────────────────────────────────────
  reports: {
    /** @param month YYYY-MM. Comes back pre-formatted for a phone screen. */
    get: (month) => api.get('/hr/track/reports', { params: { month } }).then(r => r.data),
  },

  // ── Demo requests ───────────────────────────────────────────────────
  demoRequests: {
    list:   ()                    => api.get('/hr/track/demo-requests').then(r => r.data),
    update: (id, status, notes)   => api.post('/hr/track/demo-requests', { id, status, notes })
      .then(r => r.data),
  },

  // ── Holidays ────────────────────────────────────────────────────────
  //
  // `calendar` is SangoeTrack's own read — title/start/end, no id, so nothing
  // can be edited from it. `list` is ours and returns rows WITH ids.
  //
  // These write company-wide reference data: one wrong holiday shifts everyone's
  // leave calculation, which is why delete asks first on the screen.
  holidays: {
    calendar: ()        => api.get('/hr/track/holidays').then(r => r.data),
    list:     (year)    => api.get('/hr/track/holidays/list', { params: year ? { year } : {} }).then(r => r.data),
    create:   (data)    => api.post('/hr/track/holidays', data).then(r => r.data),
    update:   (id, data) => api.put('/hr/track/holidays', { id, ...data }).then(r => r.data),
    remove:   (id)      => api.delete('/hr/track/holidays', { data: { id } }).then(r => r.data),
  },

  // ── History ─────────────────────────────────────────────────────────
  // The queues above are PENDING-only and attendance is TODAY-only. These are
  // the only way to see what already happened.
  //
  // params: { status, employee, from, to, page, per_page }
  //   advances also takes `type`; leaves also takes `leave_type`.
  // Omitting from/to defaults to this month (attendance) or this year.
  //
  // Every one returns { rows: [...], meta: { page, per_page, total, pages, ... } }
  // — so `total` is the real count, not the length of the page you were given.
  history: {
    attendance:     (params = {}) => api.get('/hr/track/history/attendance', { params }).then(r => r.data),
    corrections:    (params = {}) => api.get('/hr/track/history/corrections', { params }).then(r => r.data),
    leaves:         (params = {}) => api.get('/hr/track/history/leaves', { params }).then(r => r.data),
    // Also returns meta.totals — counts and amounts per status across the whole
    // filtered set, not just the current page.
    reimbursements: (params = {}) => api.get('/hr/track/history/reimbursements', { params }).then(r => r.data),
    advances:       (params = {}) => api.get('/hr/track/history/advances', { params }).then(r => r.data),
  },

  // ── Settings ────────────────────────────────────────────────────────
  // These relay to endpoints added ON SangoeTrack for this CRM. Unlike
  // everything above, they do not exist until that side is deployed — so the
  // screen must handle a 502 as "not deployed yet", not as a bug.
  settings: {
    get:          ()       => api.get('/hr/track/settings').then(r => r.data),
    save:         (values) => api.post('/hr/track/settings', values).then(r => r.data),
    saveWhatsapp: (values) => api.post('/hr/track/settings/whatsapp', values).then(r => r.data),
  },
}

export default sangoeTrackApi
