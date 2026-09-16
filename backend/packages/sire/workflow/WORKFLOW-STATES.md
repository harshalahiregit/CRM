# SIRE workflow — states and transitions

**Authoritative source: `src/Support/SireWorkflow.php`.**

`workflow.json` here and `resources/js/lib/sire/workflow.generated.js` are EXPORTS of
that file, produced by `php artisan sire:export-workflow`. The SPA uses them only
for labels, colours and ordering — **legality is decided server-side**, and the
case payload carries `available_transitions` computed there.

`tests/workflow-parity.test.mjs` parses the PHP independently and fails the build
if the export drifts. That is what stops the PHP and JS definitions diverging.

## States (22)

| State | Label | Group | Terminal |
|---|---|---|---|
| `on_hold` | On Hold | paused |  |
| `new` | New | intake |  |
| `reopened` | Reopened | intake |  |
| `triaged` | Triaged | intake |  |
| `business_review` | Business Review | change |  |
| `impact_analysis` | Impact Analysis | change |  |
| `approval` | Awaiting Approval | change |  |
| `planned` | Planned | change |  |
| `assigned` | Assigned | development |  |
| `in_development` | In Development | development |  |
| `ready_for_qa` | Ready for QA | qa |  |
| `qa_in_progress` | QA in Progress | qa |  |
| `qa_failed` | QA Failed | qa |  |
| `qa_passed` | QA Passed | qa |  |
| `ready_for_release` | Ready for Release | release |  |
| `released` | Released | release |  |
| `production_validated` | Production Validated | release |  |
| `closed` | Closed | closed | yes |
| `duplicate` | Duplicate | closed | yes |
| `rejected` | Rejected | closed | yes |
| `wont_fix` | Won't Fix | closed | yes |
| `cannot_reproduce` | Cannot Reproduce | closed | yes |

## Transitions (26)

| Action | From | To | Capability | Requires | Track |
|---|---|---|---|---|---|
| `triage` | new, reopened | `triaged` | `sire.report.triage` | severity_id, priority | defect, change |
| `assign` | triaged, reopened, on_hold, planned | `assigned` | `sire.report.assign` | assignee_id | defect, change |
| `unassign` | assigned | `triaged` | `sire.report.assign` | — | defect, change |
| `start_development` | assigned, qa_failed | `in_development` | `sire.report.develop` | — | defect, change |
| `mark_ready_for_qa` | in_development | `ready_for_qa` | `sire.report.develop` | fix_summary | defect, change |
| `pull_back_to_development` | ready_for_qa | `in_development` | `sire.report.develop` | — | defect, change |
| `start_qa` | ready_for_qa | `qa_in_progress` | `sire.qa.execute` | — | defect, change |
| `qa_pass` | qa_in_progress | `qa_passed` | `sire.qa.execute` | — | defect, change |
| `qa_fail` | qa_in_progress | `qa_failed` | `sire.qa.execute` | qa_notes | defect, change |
| `mark_ready_for_release` | qa_passed | `ready_for_release` | `sire.release.manage` | — | defect, change |
| `release` | ready_for_release | `released` | `sire.release.manage` | release_ref | defect, change |
| `validate_production` | released | `production_validated` | `sire.release.manage` | — | defect, change |
| `close` | production_validated | `closed` | `sire.report.close` | — | defect, change |
| `hold` | triaged, assigned, in_development, ready_for_qa, qa_failed, ready_for_release, business_review, impact_analysis, planned | `on_hold` | `sire.report.triage` | hold_reason | defect, change |
| `resume` | on_hold | `@held_from_status` | `sire.report.triage` | — | defect, change |
| `mark_duplicate` | new, triaged, assigned, reopened | `duplicate` | `sire.report.triage` | duplicate_of_id | defect, change |
| `reject` | new, triaged, reopened, business_review, impact_analysis | `rejected` | `sire.report.triage` | resolution_note | defect, change |
| `wont_fix` | new, triaged, assigned, in_development, reopened | `wont_fix` | `sire.report.close` | resolution_note | defect, change |
| `cannot_reproduce` | new, triaged, assigned, in_development, qa_in_progress | `cannot_reproduce` | `sire.report.triage` | resolution_note | defect, change |
| `reopen` | closed, released, production_validated, duplicate, rejected, wont_fix, cannot_reproduce | `reopened` | `sire.report.reopen` | — | defect, change |
| `submit_business_review` | triaged | `business_review` | `sire.change.review` | — | change |
| `complete_business_review` | business_review | `impact_analysis` | `sire.change.review` | business_justification | change |
| `return_to_business_review` | impact_analysis | `business_review` | `sire.change.review` | — | change |
| `request_change_approval` | impact_analysis | `approval` | `sire.change.review` | impact_summary | change |
| `approve_change` | approval | `planned` | `sire.change.approve` | — | change |
| `reject_change` | approval | `rejected` | `sire.change.approve` | resolution_note | change |

## Actions that record work without moving the issue

| Action | Available in | Capability |
|---|---|---|
| `accept_assignment` | assigned | `sire.report.develop` |
| `add_investigation_notes` | assigned, in_development, qa_failed | `sire.report.develop` |
| `add_fix_summary` | in_development, ready_for_qa | `sire.report.develop` |
| `submit_dev_testing` | in_development | `sire.report.develop` |
| `add_qa_notes` | qa_in_progress, qa_failed, qa_passed | `sire.qa.execute` |

## SLA-paused states

`on_hold` · `ready_for_release` · `released` · `business_review` · `approval`

The clock stops while the issue is parked or waiting on a release train or a
business decision. It keeps running through development and QA — that time is the
team's own, and hiding it defeats the point of measuring it.
