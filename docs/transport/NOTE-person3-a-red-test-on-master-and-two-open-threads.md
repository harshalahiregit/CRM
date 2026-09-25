# A red test on master that is yours, and two threads still open

**From:** Person 1 · **To:** Person 3 (Zafar) · **2026-09-21**

## The one that needs you

`Tests\Unit\Frontend\BannedPatternsTest` is **red on master**:

```
modules/hr/components/SelfieCapture.jsx introduces inline-gradient (2)
— use the shared button component
— this gradient is copy-pasted into 274 places and cannot be restyled centrally
```

I merged master into my branch, saw the suite go from 9 failures to 10, and checked it out at
`origin/master` in a clean worktree before telling you — **it is red on master itself, not
something my merge created.** One component, two gradients, and the guard names the fix.

Everything else is fine: 5536 passing, the same 9 long-standing failures by name, Transport green
at 1482, frontend builds.

## Your ScopeResolver is the best thing I have read in this repo this week

`app/Services/Auth/ScopeResolver.php` — the docblock describes one of our oldest open defects
almost word for word:

> *"scope existed, was computed correctly, and was consulted in a single place that rendered
> menus."*

That is **D-46** exactly. Transport declares `SCOPE_OWN` and `SCOPE_ASSIGNED` in its permission
matrix, calls `scope()` in one place, and no production path consumes either — so every one of our
21 permissions answers *"may this role touch this area"* and none answers *"which rows"*.

I am not going to lift it, and I want to be clear why rather than leave you wondering. **The axis
is different.** `DataScope` is `global / own / department / branch / team` — an employee hierarchy,
resolved through `HrEmployee`. Ours is a customer axis: "own" for you means *my employee record*,
"own" for us means *my company's consignments*. Only two of the five words even overlap.

But the shape is right, and the new package has just specified **six client roles**
(CLP §3 — Admin, Operations, Warehouse/Gate, Quality/Compliance, Finance, Management, with
permissions required to be organisation-, branch-, role-, transaction- and document-type aware).
When that gets designed, it should be a conversation with you and a second implementation of your
pattern, not a fresh design. I have recorded that in D-46 with your file named, so nobody solves
it twice.

## Two threads still open, mentioned once more and then dropped

**The invoice button.** Your route `POST /transport/trips/{id}/bill/invoiced` still has no caller.
`BillingPanel.jsx` has exactly one button (*Prepare billing*) and `transportApi.js` has no method
for the endpoint — the only mention of it anywhere in the frontend is a comment of mine. So a trip
still cannot get past *Billable* by clicking, and step 12 of the demonstration can only be shown on
a trip that was billed by hand.

**`collection.recorded`.** The last of your nine event types with no emitter.
`TripCollectionService::record()` moves the money and writes no event, so a timeline still shows a
trip billed and then closed with the payment invisible in between. One line, after the commit,
with the type as a literal.

Neither is urgent and I am not going to chase either again — just noting them while I was writing.
