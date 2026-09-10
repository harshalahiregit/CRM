# SIRE AI capabilities

**13 declared capabilities. All 13 are implemented, and all run
LOCALLY — no AI vendor, no external service, no vector database, no API key.**

| Capability | Subject | Module | Advises |
|---|---|---|---|
| Issue classification | report | core | category_id, module, release_class |
| Severity recommendation | report | core | severity_id |
| Priority recommendation | report | core | priority |
| Possible duplicates | report | quality | duplicate_of_id |
| Root cause suggestion | report | quality | sire_root_causes.category, contributing_factors |
| Developer test cases | report | core | dev_test_notes |
| QA test cases | report | core | qa_notes |
| Regression risk | report | quality | requires_regression_test |
| Recurrence risk | recurrence_group | quality | recurrence_risk (advisory only) |
| Relevant knowledge base articles | report | knowledge | sire_kb_links |
| Release risk | release | release | release readiness commentary (never a gate) |
| Release note wording | release | release | sire_release_notes.body_override |
| Engineering insights | tenant | quality | commentary on quality trends |

## Every one is advisory

`AiCapability::isAdvisoryOnly()` returns true for all of them. Nothing gates,
blocks or auto-applies on a suggestion. Two are worth calling out:

- **Recurrence risk** — `SireRecurrenceService` already computes a deterministic
  group score. The AI opinion sits *alongside* it on different inputs and never
  replaces it. A formula you can explain should not be overwritten by one you
  cannot.
- **Release risk** — explicitly **not a gate**. Gates are deterministic and block;
  this informs. The payload carries `is_gate: false`.

## Local, not an LLM

The current implementations are deterministic: a weighted k-nearest-neighbour vote
over the tenant's own history, a relational inverted index for duplicates,
template-driven test checklists, and weighted-factor risk engines. They are not
language models and the package does not describe them as such.

They satisfy the hardest requirements for free — nothing leaves the tenant, every
reason is a checkable sentence, and they cannot be unavailable.

## Adding an external provider

See `docs/AI.md`. In short: implement `AiProvider`, add **one line** to
`SireAiRegistry::PROVIDERS`. Provider names resolve from an **allowlisted map** —
never from a class name in tenant settings, which would be a remote-code-execution
shape rather than a configuration feature.

**Before adding one:** the discovery report mentions `User::canGenerateAiJd()`,
which suggests the host already generates job descriptions somehow, yet no AI vendor
appears in `composer.json`. Find that integration and reuse it rather than
standing up a second key, bill and data-processing agreement.
