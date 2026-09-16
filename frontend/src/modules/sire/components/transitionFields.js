import { priorityToken } from '../../../lib/sire/tokens';

export { priorityToken };

/**
 * How to ask for each field a transition requires.
 *
 * The SERVER decides which fields are needed (`requires` on the available
 * transition); this only says how to render them. Adding a required field to the
 * workflow means adding a line here, not touching any component.
 */
export const FIELD_SPECS = {
  severity_id:     { label: 'Severity',        type: 'severity', help: 'How bad it is when it happens.' },
  priority:        { label: 'Priority',        type: 'priority', help: 'When we are going to deal with it.' },
  category_id:     { label: 'Category',        type: 'category', help: 'What kind of issue this is. Fills the Type column on the register.' },
  // multiple: the first person picked OWNS the issue and the rest work it
  // alongside them. One owner is not a limitation -- every workflow guard is
  // written against a single assignee, and a defect with four equal owners has
  // none.
  assignee_id:     { label: 'Developer',       type: 'user', multiple: true },
  qa_assignee_id:  { label: 'QA engineer',     type: 'user' },
  fix_summary:     { label: 'Fix summary',     type: 'textarea', placeholder: 'What changed, and where. QA reads this first.' },
  qa_notes:        { label: 'QA notes',        type: 'textarea', placeholder: 'What failed, on which step, with what result.' },
  hold_reason:     { label: 'Reason for hold', type: 'text',     placeholder: 'Waiting on the vendor API fix' },
  resolution_note: { label: 'Reason',          type: 'textarea', placeholder: 'Why this issue is stopping here.' },
  release_ref:     { label: 'Release',         type: 'text',     placeholder: 'v2.14.0 / build 3312' },
  duplicate_of_id: { label: 'Duplicate of',    type: 'issue',    placeholder: 'SIRE issue id' },
};

export const PRIORITIES = [
  { value: 'p1', label: 'P1 — Urgent' },
  { value: 'p2', label: 'P2 — High' },
  { value: 'p3', label: 'P3 — Medium' },
  { value: 'p4', label: 'P4 — Low' },
];

export const priorityLabel = (value) => (value ? priorityToken(value).label : null);

/**
 * Priority colours come from the shared vocabulary. This module owns the SELECT
 * OPTIONS; tokens.js owns how a priority looks — and P1 carries a marker there,
 * so it survives greyscale and a table skimmed at speed.
 */
export const priorityClasses = (value) => priorityToken(value).classes;
