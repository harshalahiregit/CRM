/* eslint-disable */
// GENERATED FILE — do not edit. Written by `php artisan sire:export-workflow`.
export default {
    "version": 1,
    "generated_by": "php artisan sire:export-workflow",
    "warning": "GENERATED FILE \u2014 do not edit. PHP (Sire\\Support\\SireWorkflow) is the authority; this mirror exists so the SPA can label and colour states without a round-trip. CI fails if it drifts.",
    "initial": "new",
    "states": {
        "new": {
            "label": "New",
            "group": "intake",
            "tone": "slate",
            "terminal": false,
            "order": 10
        },
        "reopened": {
            "label": "Reopened",
            "group": "intake",
            "tone": "orange",
            "terminal": false,
            "order": 15
        },
        "triaged": {
            "label": "Triaged",
            "group": "intake",
            "tone": "slate",
            "terminal": false,
            "order": 20
        },
        "business_review": {
            "label": "Business Review",
            "group": "change",
            "tone": "slate",
            "terminal": false,
            "order": 22
        },
        "impact_analysis": {
            "label": "Impact Analysis",
            "group": "change",
            "tone": "slate",
            "terminal": false,
            "order": 24
        },
        "approval": {
            "label": "Awaiting Approval",
            "group": "change",
            "tone": "amber",
            "terminal": false,
            "order": 26
        },
        "planned": {
            "label": "Planned",
            "group": "change",
            "tone": "blue",
            "terminal": false,
            "order": 28
        },
        "assigned": {
            "label": "Assigned",
            "group": "development",
            "tone": "blue",
            "terminal": false,
            "order": 30
        },
        "in_development": {
            "label": "In Development",
            "group": "development",
            "tone": "blue",
            "terminal": false,
            "order": 40
        },
        "ready_for_qa": {
            "label": "Ready for QA",
            "group": "qa",
            "tone": "violet",
            "terminal": false,
            "order": 50
        },
        "qa_in_progress": {
            "label": "QA in Progress",
            "group": "qa",
            "tone": "violet",
            "terminal": false,
            "order": 60
        },
        "qa_failed": {
            "label": "QA Failed",
            "group": "qa",
            "tone": "red",
            "terminal": false,
            "order": 70
        },
        "qa_passed": {
            "label": "QA Passed",
            "group": "qa",
            "tone": "green",
            "terminal": false,
            "order": 80
        },
        "ready_for_release": {
            "label": "Ready for Release",
            "group": "release",
            "tone": "amber",
            "terminal": false,
            "order": 90
        },
        "released": {
            "label": "Released",
            "group": "release",
            "tone": "amber",
            "terminal": false,
            "order": 100
        },
        "production_validated": {
            "label": "Production Validated",
            "group": "release",
            "tone": "green",
            "terminal": false,
            "order": 110
        },
        "on_hold": {
            "label": "On Hold",
            "group": "paused",
            "tone": "amber",
            "terminal": false,
            "order": 0
        },
        "closed": {
            "label": "Closed",
            "group": "closed",
            "tone": "gray",
            "terminal": true,
            "order": 120
        },
        "duplicate": {
            "label": "Duplicate",
            "group": "closed",
            "tone": "gray",
            "terminal": true,
            "order": 130
        },
        "rejected": {
            "label": "Rejected",
            "group": "closed",
            "tone": "gray",
            "terminal": true,
            "order": 140
        },
        "wont_fix": {
            "label": "Won't Fix",
            "group": "closed",
            "tone": "gray",
            "terminal": true,
            "order": 150
        },
        "cannot_reproduce": {
            "label": "Cannot Reproduce",
            "group": "closed",
            "tone": "gray",
            "terminal": true,
            "order": 160
        }
    },
    "sla_paused_states": [
        "on_hold",
        "ready_for_release",
        "released",
        "business_review",
        "approval"
    ],
    "transitions": [
        {
            "action": "triage",
            "from": [
                "new",
                "reopened"
            ],
            "to": "triaged",
            "label": "Triage",
            "capability": "sire.report.triage",
            "requires": [
                "severity_id",
                "priority"
            ],
            "optional": [
                "category_id"
            ],
            "note": "Severity is impact, priority is scheduling. Both are set here."
        },
        {
            "action": "assign",
            "from": [
                "triaged",
                "reopened",
                "on_hold",
                "planned"
            ],
            "to": "assigned",
            "label": "Assign developer",
            "capability": "sire.report.assign",
            "requires": [
                "assignee_id"
            ]
        },
        {
            "action": "submit_business_review",
            "from": [
                "triaged"
            ],
            "to": "business_review",
            "label": "Send to business review",
            "capability": "sire.change.review",
            "tracks": [
                "change"
            ],
            "note": "Only a change request takes this path. A defect goes straight from triage to a developer."
        },
        {
            "action": "complete_business_review",
            "from": [
                "business_review"
            ],
            "to": "impact_analysis",
            "label": "Complete business review",
            "capability": "sire.change.review",
            "tracks": [
                "change"
            ],
            "requires": [
                "business_justification"
            ]
        },
        {
            "action": "return_to_business_review",
            "from": [
                "impact_analysis"
            ],
            "to": "business_review",
            "label": "Return for business review",
            "capability": "sire.change.review",
            "tracks": [
                "change"
            ]
        },
        {
            "action": "request_change_approval",
            "from": [
                "impact_analysis"
            ],
            "to": "approval",
            "label": "Request approval",
            "capability": "sire.change.review",
            "tracks": [
                "change"
            ],
            "requires": [
                "impact_summary"
            ],
            "raises_approval": "change_request",
            "note": "Raises a sire_approvals row using the purchase/tpv register shape. No sixth approval engine."
        },
        {
            "action": "approve_change",
            "from": [
                "approval"
            ],
            "to": "planned",
            "label": "Approve change",
            "capability": "sire.change.approve",
            "tracks": [
                "change"
            ],
            "notifies": "sire.change.approved"
        },
        {
            "action": "reject_change",
            "from": [
                "approval"
            ],
            "to": "rejected",
            "label": "Reject change",
            "capability": "sire.change.approve",
            "tracks": [
                "change"
            ],
            "requires": [
                "resolution_note"
            ],
            "notifies": "sire.change.rejected"
        },
        {
            "action": "unassign",
            "from": [
                "assigned"
            ],
            "to": "triaged",
            "label": "Return to triage",
            "capability": "sire.report.assign",
            "clears": [
                "assignee_id",
                "assignment_accepted_at"
            ]
        },
        {
            "action": "start_development",
            "from": [
                "assigned",
                "qa_failed"
            ],
            "to": "in_development",
            "label": "Start development",
            "capability": "sire.report.develop",
            "guard": "actor_is_assignee_or_lead"
        },
        {
            "action": "mark_ready_for_qa",
            "from": [
                "in_development"
            ],
            "to": "ready_for_qa",
            "label": "Mark ready for QA",
            "capability": "sire.report.develop",
            "guard": "actor_is_assignee_or_lead",
            "requires": [
                "fix_summary"
            ]
        },
        {
            "action": "pull_back_to_development",
            "from": [
                "ready_for_qa"
            ],
            "to": "in_development",
            "label": "Pull back to development",
            "capability": "sire.report.develop"
        },
        {
            "action": "start_qa",
            "from": [
                "ready_for_qa"
            ],
            "to": "qa_in_progress",
            "label": "Start QA",
            "capability": "sire.qa.execute"
        },
        {
            "action": "qa_pass",
            "from": [
                "qa_in_progress"
            ],
            "to": "qa_passed",
            "label": "Pass QA",
            "capability": "sire.qa.execute",
            "auto_advance": "ready_for_release",
            "note": "Auto-advance is governed by the tenant setting sire.auto_ready_for_release (default true). With it off the issue rests at qa_passed and a lead advances it explicitly."
        },
        {
            "action": "qa_fail",
            "from": [
                "qa_in_progress"
            ],
            "to": "qa_failed",
            "label": "Fail QA",
            "capability": "sire.qa.execute",
            "requires": [
                "qa_notes"
            ],
            "notifies": "sire.qa.failed",
            "note": "Notifies the assigned developer through SireNotificationProvider."
        },
        {
            "action": "mark_ready_for_release",
            "from": [
                "qa_passed"
            ],
            "to": "ready_for_release",
            "label": "Mark ready for release",
            "capability": "sire.release.manage"
        },
        {
            "action": "release",
            "from": [
                "ready_for_release"
            ],
            "to": "released",
            "label": "Mark released",
            "capability": "sire.release.manage",
            "requires": [
                "release_ref"
            ]
        },
        {
            "action": "validate_production",
            "from": [
                "released"
            ],
            "to": "production_validated",
            "label": "Validate in production",
            "capability": "sire.release.manage"
        },
        {
            "action": "close",
            "from": [
                "production_validated"
            ],
            "to": "closed",
            "label": "Close",
            "capability": "sire.report.close",
            "guard": "root_cause_confirmed_when_serious",
            "note": "Critical, P1, recurring and reopened issues need a CONFIRMED root cause first."
        },
        {
            "action": "close_directly",
            "from": [
                "new",
                "triaged",
                "assigned",
                "in_development",
                "ready_for_qa",
                "qa_in_progress",
                "qa_failed",
                "qa_passed",
                "ready_for_release",
                "released",
                "reopened",
                "on_hold"
            ],
            "to": "closed",
            "label": "Close",
            "capability": "sire.report.close",
            "requires": [
                "resolution_note"
            ],
            "note": "Deliberately NOT guarded by root cause: a hidden button reads as a broken one, since a failing guard removes the action rather than refusing it. Serious issues keep their RCA requirement on the production_validated path."
        },
        {
            "action": "hold",
            "from": [
                "triaged",
                "assigned",
                "in_development",
                "ready_for_qa",
                "qa_failed",
                "ready_for_release",
                "business_review",
                "impact_analysis",
                "planned"
            ],
            "to": "on_hold",
            "label": "Put on hold",
            "capability": "sire.report.triage",
            "requires": [
                "hold_reason"
            ],
            "note": "Remembers held_from_status so resume returns exactly where it paused."
        },
        {
            "action": "resume",
            "from": [
                "on_hold"
            ],
            "to": "@held_from_status",
            "label": "Resume",
            "capability": "sire.report.triage"
        },
        {
            "action": "mark_duplicate",
            "from": [
                "new",
                "triaged",
                "assigned",
                "reopened"
            ],
            "to": "duplicate",
            "label": "Mark duplicate",
            "capability": "sire.report.triage",
            "requires": [
                "duplicate_of_id"
            ]
        },
        {
            "action": "reject",
            "from": [
                "new",
                "triaged",
                "reopened",
                "business_review",
                "impact_analysis"
            ],
            "to": "rejected",
            "label": "Reject",
            "capability": "sire.report.triage",
            "requires": [
                "resolution_note"
            ]
        },
        {
            "action": "wont_fix",
            "from": [
                "new",
                "triaged",
                "assigned",
                "in_development",
                "reopened"
            ],
            "to": "wont_fix",
            "label": "Won't fix",
            "capability": "sire.report.close",
            "requires": [
                "resolution_note"
            ]
        },
        {
            "action": "cannot_reproduce",
            "from": [
                "new",
                "triaged",
                "assigned",
                "in_development",
                "qa_in_progress"
            ],
            "to": "cannot_reproduce",
            "label": "Cannot reproduce",
            "capability": "sire.report.triage",
            "requires": [
                "resolution_note"
            ]
        },
        {
            "action": "reopen",
            "from": [
                "closed",
                "released",
                "production_validated",
                "duplicate",
                "rejected",
                "wont_fix",
                "cannot_reproduce"
            ],
            "to": "reopened",
            "label": "Reopen",
            "capability": "sire.report.reopen",
            "note": "Increments reopen_count and clears the resolution."
        }
    ],
    "actions_without_transition": [
        {
            "action": "accept_assignment",
            "states": [
                "assigned"
            ],
            "capability": "sire.report.develop",
            "guard": "actor_is_assignee",
            "label": "Accept assignment",
            "note": "Stamps assignment_accepted_at. Deliberately not a status change \u2014 acceptance and starting work are different moments."
        },
        {
            "action": "add_investigation_notes",
            "states": [
                "assigned",
                "in_development",
                "qa_failed"
            ],
            "capability": "sire.report.develop",
            "label": "Add investigation notes"
        },
        {
            "action": "add_fix_summary",
            "states": [
                "in_development",
                "ready_for_qa"
            ],
            "capability": "sire.report.develop",
            "label": "Add fix summary"
        },
        {
            "action": "submit_dev_testing",
            "states": [
                "in_development"
            ],
            "capability": "sire.report.develop",
            "label": "Submit developer testing"
        },
        {
            "action": "add_qa_notes",
            "states": [
                "qa_in_progress",
                "qa_failed",
                "qa_passed"
            ],
            "capability": "sire.qa.execute",
            "label": "Add QA notes"
        }
    ]
};
