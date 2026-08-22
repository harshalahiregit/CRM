# UI consistency scorecard

Counted from the source on 2026-08-22, not estimated. Companion to
`UI-SURVEY.md`, which records how each module *looks*; this records what
each module *uses*.

## The numbers

| module | owner | .jsx | DataTable | hand &lt;table&gt; | ListToolbar | ListControls | ConfirmDialog | ConfirmModal | window.confirm | alert() | useToast | EmptyState | AsyncButton | inline gradient |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **sales** | Zafar | 56 | · | 22 | 48 | · | 49 | · | · | 7 | 105 | 36 | · | 34 |
| **accounts** | Zafar | 34 | 8 | 24 | · | · | 41 | · | · | · | 57 | · | · | 4 |
| **customer** | Zafar | 24 | · | 11 | 3 | · | 16 | · | 1 | · | 33 | · | 14 | 23 |
| **hr** | Harshal | 67 | · | 90 | · | · | · | · | 27 | 14 | · | · | · | 121 |
| **tpv** | Harshal | 65 | · | 32 | · | · | · | 3 | 19 | 115 | · | · | · | 26 |
| **purchase** | Harshal | 44 | · | 50 | · | · | · | · | 13 | 50 | · | 10 | · | 21 |
| **inventory** | Shivam | 31 | · | 26 | · | 3 | · | 8 | · | · | · | · | · | · |
| **helpdesk** | Shivam | 24 | · | 2 | · | 3 | · | 7 | · | · | · | · | · | · |
| **tasks** | Shivam | 9 | · | 1 | · | 3 | · | 4 | · | 2 | · | · | · | · |
| **projects** | Shivam | 8 | · | 6 | · | 3 | · | 9 | · | 1 | · | · | · | · |
| **settings** | mixed | 16 | · | 1 | · | · | 9 | 2 | 4 | · | 39 | · | · | 6 |
| **shared** | mixed | 3 | · | 1 | · | · | · | · | 1 | · | · | 2 | · | 2 |
| **compliance** | mixed | 3 | · | 1 | · | · | · | · | · | · | · | · | · | · |
| **notifications** | mixed | 3 | · | 4 | · | · | · | · | · | · | · | · | · | · |
| **total** | | | **8** | **271** | **51** | **12** | **115** | **33** | **65** | **189** | **234** | **48** | **14** | **237** |

## What the numbers say

There are not small inconsistencies here. There are **three separate house
styles**, and they disagree on every question a UI has to answer.

| | Zafar — sales, accounts, customer | Harshal — hr, tpv, purchase | Shivam — inventory, helpdesk, tasks, projects |
|---|---|---|---|
| Tell the user something | `useToast` (195 uses) | **`alert()` (179 uses)**, no toast at all | neither — silent, or inline |
| Confirm a delete | `ConfirmDialog` (106) | **`window.confirm` (59)** | `ConfirmModal` (28) |
| List toolbar | `ListToolbar` (51) | none | `ListControls` (12) |
| Tabular data | mostly hand-rolled; `DataTable` only in accounts | hand-rolled (172 tables) | hand-rolled |
| Primary button | inline gradient (61) | **inline gradient (168)** | neither — module-local buttons |

Three findings worth stating plainly.

**1. `alert()` appears 179 times in Harshal's modules and zero times anywhere
else.** A browser alert blocks the page, cannot be styled, looks like a virus
warning on a phone, and stacks unusably when two fire together. This is the
single largest visible difference between one developer's screens and another's.

**2. `useToast` is used 195 times by Zafar and 0 times by Harshal and Shivam.**
A user moving between modules gets a toast in the bottom-right on one screen and
a modal browser dialog on the next.

**3. `DataTable` exists and is used by exactly one module.** 260 hand-rolled
`<table>` elements against 8 uses of the shared component. Every one of those
tables re-decides header casing, column alignment, row height, empty state and
where the row actions live — which is why money is right-aligned in accounts and
left-aligned everywhere else.

The design system is not missing. `components/ui/` has 28 components. They are
simply not being used.

## The standardisation plan

Ordered by visible improvement per unit of work. Every step adopts something
that already exists — none of it requires new components.

### Step 1 — ban `alert()` (179 sites, mechanical)

Replace with `useToast`. Nearly all are one-liners:

```js
alert('Saved')            → toast.success('Saved')
alert('Something failed') → toast.error('Something failed')
```

Highest visible win in the codebase and almost entirely find-and-replace. TPV
alone has 115.

### Step 2 — ban `window.confirm` (59 sites)

`ConfirmDialog` is the winner: 106 uses, the richest API (title, message,
confirmLabel, tone), and already the majority. `ConfirmModal` and
`ConfirmIconButton` stay only for the inline-row-delete case, which
`ConfirmDialog` does not cover.

Not purely mechanical — `window.confirm` is synchronous and `ConfirmDialog` is
state-driven, so each call site needs a small refactor.

### Step 3 — one primary button (229 inline gradients)

Every one of these is a copy of the same three-stop gradient. Extract
`components/ui/Btn.jsx` with `variant="primary|ghost|danger"`, wrapping
`AsyncButton` so the double-submit guard comes for free — it is currently used
in **3 files out of 387**.

### Step 4 — one list shell

Not a rewrite of 260 tables. Agree the rules, then apply them as each list is
next touched:

- header text uppercase via `label-caps`
- **numeric and currency columns right-aligned with `font-variant-numeric: tabular-nums`** — currently right-aligned only in accounts
- row actions in the last column, kebab menu, consistent icon set
- shared `EmptyState`, with distinct copy for "nothing yet" and "nothing matched your search" — presently the same string in most modules
- skeleton rows that keep the header visible, rather than replacing the table

### Step 5 — one overlay, and make Esc work

`.drawer-panel` is a centred modal despite its name, is written inline rather
than imported, and in every module surveyed **neither Esc nor a backdrop click
closes it**. Pick one container, portal it to `body`, and add both.

## The guards that keep it

Documentation will not hold this. Three tests will:

1. **Banned patterns** — no `alert(`, no `window.confirm`, no
   `linear-gradient(135deg,#7C3AED` outside `components/ui/`. Fails the build
   on a new one; existing sites grandfathered by an explicit list that only
   ever shrinks.
2. **Component usage** — a page rendering a `<table>` must import the shared
   table shell, and a destructive action must import a confirm component.
3. **Link contracts** — already built (`CustomerLinkTargetsTest`); widen it to
   the frontend's own `<Link to>` and `navigate()` calls, not just the paths
   the backend emits.

The first is worth writing before any migration starts, so the count can only
go down.

