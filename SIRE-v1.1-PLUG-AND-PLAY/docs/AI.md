# Phase 3 — AI

## The five properties

**Optional · fail-safe · tenant-safe · auditable · human-controlled.**

| Property | How it is guaranteed |
|---|---|
| Optional | Off by default. Delete `Services/Sire/Ai/` and SIRE still compiles, runs and ships releases — enforced by a boundary test. |
| Fail-safe | Every capability is guarded and returns `null` on any failure. `null` means "no suggestions", never an error. Nothing AI is on the issue-creation path. |
| Tenant-safe | `AiContextSchema` is a per-capability **allowlist**. All 13 current capabilities run **locally**, so nothing leaves the tenant at all. |
| Auditable | Every suggestion stores provider, model, version, confidence, evidence, the redaction report and the human decision. |
| Human-controlled | The AI layer writes to **one table**. It has no write path to an issue — enforced by a second boundary test. |

## AI never overwrites core data

The strongest form of the requirement, and it cost a design compromise worth
naming.

**Accepting a suggestion does not apply it.** The service records the decision and
returns the value; the human then performs the ordinary action through the ordinary
endpoint, as themselves.

One extra call buys three things: the AI layer writes only to
`sire_ai_suggestions`; every value on an issue was demonstrably put there by a
person; and a human who accepts but edits before applying is recorded as
**MODIFIED** — the most useful feedback signal there is, which auto-apply would
have collapsed into "accepted".

## What a suggestion stores

```
capability · payload · confidence · evidence {summary, signals[], references[]}
provider · model · model_version · redaction_report · context_fingerprint
status: pending | accepted | rejected | modified | deferred | superseded
decided_by · decided_at · decision_note · final_value
```

**`deferred` — "not sure" — is its own state.** Rejecting says the suggestion was
wrong; deferring says the reader could not tell. Collapsing them records
uncertainty as disagreement.

## The 13 capabilities

See [`../ai/AI-CAPABILITIES.md`](../ai/AI-CAPABILITIES.md) for the generated table.

**All 13 are implemented, and all run locally.** They are deterministic engines —
not language models, and this package does not describe them as such:

| Capability | What it actually is |
|---|---|
| Classification | Weighted k-nearest-neighbour vote over the tenant's own history |
| Duplicate detection | Two-stage retrieve-then-score over a relational inverted index |
| Root cause suggestion | Category voted from **human-confirmed** analyses; description **quoted** and attributed |
| Developer / QA test cases | Template checklist from the issue's own words |
| Regression / recurrence / release risk | Weighted-factor engines, each factor carrying its own sentence |
| Knowledge recommendation | `SireKnowledgeProvider::search()`, re-ranked by SIRE's scorer |
| Engineering insights | Counts, rates and medians |
| Release-note analysis | A **review**, not a rewrite |

They satisfy the hardest requirements for free: nothing leaves the tenant, every
reason is a checkable sentence, and they cannot be unavailable.

### Two that deserve care

**Root cause suggestion never invents.** A generator writing root-cause prose
produces plausible claims with no evidence — and plausible prose is what gets
pasted into a field labelled *Confirmed Root Cause*. So the category is voted from
analyses a human already confirmed, and the description is **quoted verbatim** from
the nearest agreeing one. The output is never *"the cause is X"* but *"SIR-00123,
88% similar, was caused by X"*, under a heading that reads **AI Suggested Root
Cause** and never *Confirmed*.

**AI release notes review rather than rewrite.** Without a language model SIRE
cannot rewrite prose and does not pretend to. It reports what will read badly:
which shipped issues have no customer-facing summary and will be silently omitted,
which contain internal jargon, how many security fixes are counted rather than
described. A checkable list beats generated wording.

## Data egress — `AiContextSchema`

**The file to read when someone asks what SIRE would send to a provider.** If a
field is not named there, no provider ever sees it. A reference copy is at
[`../ai/AiContextSchema.reference.php`](../ai/AiContextSchema.reference.php).

Four layers:

1. **Allowlist per capability** — not a blocklist. A blocklist is wrong the first
   time a new column appears.
2. **Key refusal** — credential-shaped, person-identifying and identity keys are
   dropped *even if an allowlist names them by mistake*.
3. **Value scrubbing** — emails, bearer tokens, URL query secrets and opaque
   high-entropy runs removed from surviving text.
4. **Bounds** — 4,000 characters per value, 40 fields per context.

**Identity never leaves.** No capability improves by knowing who reported an issue.

Every field is either kept or explained in a stored redaction report. Verified by
`tests/aiRedaction.test.mjs` and the matching PHPUnit test against one fixture — a
claim about a security boundary should be executable.

## Adding an external provider

**Not required.** All 13 capabilities work with no vendor.

1. Implement `Contracts\Sire\Ai\AiProvider` — must **never throw**, never mutate,
   receive only what it is given, and declare itself.
2. Add **one line** to `SireAiRegistry::PROVIDERS`.

Provider names resolve from an **allowlisted map**, never from a class name in
tenant settings — that would be a remote-code-execution shape, not a configuration
feature. Adding one is deliberately a code change: **a new AI provider is a new
data processor** and belongs in review.

> **Before you do:** the discovery report mentions `User::canGenerateAiJd()`,
> suggesting the host already generates job descriptions somehow — yet no AI vendor
> appears in `composer.json`. Find that integration and reuse it rather than
> standing up a second key, bill and data-processing agreement.

## Local engines are not providers

Deliberate. A provider receives redacted context and **must not reach back for
more** — that rule is what makes an external provider safe. But duplicate detection
is a *search*, and a search needs the database. Forcing it through the provider
interface would either punch a hole in the one rule protecting tenant data, or
cripple the engine.

```
SireAiGateway::suggest()   external providers · redacted context · data leaves
SireLocalInsights          local engines · full tenant data · NOTHING leaves
```

Both record into the same store, so the human feedback loop is identical.

## Enabling

```php
$settings->set($tenantId, 'sire.ai.enabled', true);
$settings->set($tenantId, 'sire.ai.capabilities', ['classification' => true, /* … */]);
```

Both flags required — a capability shipped later must not switch itself on for a
tenant that enabled AI for something else. Add `sire:index-issues` to the scheduler
for duplicate detection and classification.
