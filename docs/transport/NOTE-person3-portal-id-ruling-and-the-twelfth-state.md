# The portal id ruling, the twelfth state, and two defects in the P3 band

**By:** P3 (Zafar) · **2026-09-23** · **For:** P1 and P2
**Answers:** P1's note on `ClientPortalTest` and the flow-map corrections.

---

## 1 · The portal route — ruled, and the test is already changed

**Option 1. The route stands as designed.** No shape change, nothing to remove.

But not as an exception granted to one route — the test was wrong, and here is the evidence
rather than the courtesy.

### The assertion had outgrown its own name

It read:

```php
/** There is no endpoint that takes a client id, so this is the proof. */
->filter(fn ($r) => str_contains($r->uri(), '{'));
```

The comment says *client id*. The name says *client id*. The filter says *any id*. Those were
equivalent when it was written, because the client portal had no detail endpoint of any kind, and
the blanket check was the cheapest available proof. They stopped being equivalent the moment
anyone needed a detail endpoint.

### And the convention it claimed to defend does not exist

I counted the portal surface before ruling:

```
portal routes already taking a path parameter: 125
  worker 31 · onboarding 20 · document 12 · medical 10 · id 9 · task 9 · contact 6 …
```

125 routes across the vendor and purchase portals already do exactly what P1's route does — take
a resource id in the path, scope the owner off the token. **The client portal was the outlier, not
the convention.** Read literally, my test banned the shape the rest of the product had settled on
years of work ago.

### What it asserts now

Two guards, both green on master today, both product-wide where the rule is product-wide:

1. **No portal route lets the caller name an owner.** `{client}`, `{customer}`, `{tenant}`,
   `{company}`, `{account}` and their `_id` forms, across every `api/portal` prefix. An identifier
   may name a *resource* — which trip — never an *owner*. Zero in use today; this stops the first
   one arriving unnoticed.

2. **Any id the client portal accepts must be numerically constrained.** An unconstrained `{id}`
   matches a slug, a path segment or an encoded traversal, and a controller that scopes perfectly
   can still be handed something it was never meant to look up. Constrained at the route, a
   malformed id fails to match before any code runs — which is what turns "a foreign id 404s" from
   a promise about the controller into a property of the route.

Verified against three registered routes rather than assumed:

| Route | Result |
|---|---|
| `…/shipments/{id}` with `->where('id','[0-9]+')` | **admitted** |
| `…/shipments/{ref}` unconstrained | **caught** by guard 2 |
| `…/by/{client}` | **caught** by guard 1 |

So P1's route passes as written **provided it carries the numeric constraint.** That is the whole
cost of the ruling.

### This also covers P2's route on merit

`/api/portal/assigned-tasks/{task}` escaped the old test by sitting under a different prefix, not
by obeying anything. Guard 1 is product-wide, so it now passes because `{task}` names a resource —
which is the right reason. Nothing about it needs to change.

### One correction to P1's note

**The test is green on master, and P1's route is not on it.** I checked before replying:

```
$ git grep -c "transport/shipments" origin/master -- backend/
  NOT on master
```

The `portal/client` group on `origin/master` contains no route with a `{` at all. So master was
never red from this, and no red test sat on master overnight. It is red on one unpushed branch,
which is what a branch is for. The process point is worth keeping — full suite, failures compared
by name — but it did not cost anything here, and P1's note is harder on P1 than the facts support.

---

## 2 · The spine is twelve states, not eleven — P1 is right, and it is in my own code

`pretrip_ok` is a real state between `allocated` and `dispatched`, and it is wired in the file I
wrote:

```
backend/app/Support/Transport/TripStatus.php:73   public const PRETRIP_OK = 'pretrip_ok';
                                        :159   ALLOCATED  => [APPROVED, PRETRIP_OK]
                                        :174   PRETRIP_OK => [APPROVED, DISPATCHED]
                                        :336   PRETRIP_OK => 'Ready to dispatch'
```

Two different people move those two edges, which is exactly why collapsing them loses something.
My summary said eleven and moved `dispatched` on "driver pre-trip passes". That was wrong.

**It is also wrong in `STOS-REAL-FLOW-AND-DEV2-WORKLIST.md` §2, which is titled "The spine — 11
states and who moves each one".** That file is P2's and I have not edited it — flagging so the
owner of the file can correct it rather than having it corrected underneath them. The same
paragraph is otherwise accurate and I would not change anything else in it.

**On writing the flow map into `docs/transport/`:** P2 has already written it, in that file, on
the same day and from the same two quotations — CLP §1 and DVR §2, "no screen ever sends data to
another screen". A third copy is the duplicate-entry-point failure that
`STOS-PROCESS-FLOW-AND-OWNERSHIP.md` §0 exists to stop, so I have not written one. The P3 tail of
the belt is already in §3 of that file and is correct.

Keeping **"GPS is not a screen"** in those words — agreed, and for P1's stated reason. DVR §11's
*"driver cannot fabricate or edit GPS coordinates"* is the kind of line that gets softened for a
demo and never hardened again.

---

## 3 · The two defects — filed as D-300 and D-301

Taken in the P3 band, as agreed after the D-58..D-61 collision. P1 is right that the band is worth
taking even for two entries; the collision it prevents is exactly how the last one happened.

- **D-300** — M06/M07/M08 written by two applications with no arbitration rule. Escalated for an
  owner ruling. Needed **before** any of the three of us builds, because the milestone APIs are
  P1's, the driver half is P2's and the client half is mine, and a ruling after two have built is
  a rewrite of two modules.
- **D-301** — M12 feedback: two producers, no table, no event, no endpoint. Mine to build once
  ruled. Worth knowing: `client_feedback` and `GET/POST /api/portal/client/feedback` already
  exist, so the client half has a home and the driver half does not — which means "reuse what
  exists" would settle a specification question by convenience. That is the trap, not the answer.

Both were reached independently from the milestone-API side, which is the argument for putting
them in front of the owner together rather than one at a time.

---

## 4 · Where I am

Not blocked by anyone, and blocking nobody.

The invoice door P1 needed is on master and a trip has closed through it. This week's work has
been outside Transport — three Sales defects from the SIRE register, which turned up a cross-tenant
validation hole on an endpoint that had never been reachable from any screen. Closed in the same
change that opened the door to it.

M01–M14 stays out of the journey view under D-121, for the reason D-300 makes concrete: the
mapping needs a decision nobody has the authority to make yet.
