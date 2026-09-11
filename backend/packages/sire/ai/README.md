# AI

| File | What it is |
|---|---|
| `AI-CAPABILITIES.md` | Generated table — 13 capabilities, all implemented, all local |
| `AiContextSchema.reference.php` | **The file to read when someone asks what SIRE would send to a provider** |

The code lives in `src/Ai/` and
`src/Ai/`. Full design in
[`../docs/AI.md`](../docs/AI.md).

## In one paragraph

AI is **off by default**, writes to **one table**, and has **no write path to an
issue** — enforced by a boundary test, not by convention. All 13 capabilities run
**locally** as deterministic engines, so nothing leaves the tenant and no vendor,
key or external service is required. Delete `Services/Sire/Ai/` and SIRE still
compiles, runs and ships releases.
