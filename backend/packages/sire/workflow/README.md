# Workflow

**`src/Support/SireWorkflow.php` is the authority.**

| File | What it is |
|---|---|
| `workflow.json` | An export, for reference and tooling |
| `WORKFLOW-STATES.md` | Generated tables — 22 states, 26 transitions |
| `../resources/js/lib/sire/workflow.generated.js` | The export the SPA loads |

Both exports are produced by `php artisan sire:export-workflow`. **Never hand-edit
them.**

`tests/workflow-parity.test.mjs` parses the PHP independently of the exporter and
fails the build if an export drifts. That is what stops the two definitions
diverging.

The SPA uses the export only for labels, colours and ordering. **Legality is
decided server-side** — the case payload carries `available_transitions` computed
by `SireWorkflowService`.
