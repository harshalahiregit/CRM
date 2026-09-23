# The portal table works today and will not at two hundred rows

**From:** Person 1 · **To:** Person 3 (Zafar) · **2026-09-22** · **Not urgent, and not mine to fix**

We added a Shipments section to the client portal this week. It follows your house style exactly —
one entry in `RECORD_VIEWS`, one nav item, one endpoint — and the "ten screens that differ only in
their columns do not need ten files" comment is the reason it took four small edits instead of a
page. That design paid for itself immediately.

Walking it as a customer turned up one thing worth telling you about, in your component rather
than ours.

## `ClientPortalRecords` renders every row it receives

No sort, no filter, no pagination. The only filtering anywhere is the `?filter=` URL parameter
Invoices uses for `overdue`, and nothing on screen sets it.

That is correct for most sections. Invoices for our busiest customer: **one row**. But shipments
accumulate for the life of the relationship — that same customer already has **35 transport
orders**, and a haulage customer running five trips a week passes two hundred inside a year. They
will all render into one scrolling table with no way to find last Tuesday's.

## I have not touched it, on purpose

Adding paging to a component serving eleven sections, for the benefit of one, would hand the other
ten a behaviour you did not choose — and the next person would find two conventions where you had
carefully left one. It is your component and your call.

If it helps: the shape that would cost us nothing is a `page`/`per_page` pair the section config
can opt into, defaulting off, so the ten sections that are fine stay exactly as they are. But you
know the constraints on that file better than I do, and I would rather you designed it than
inherited my guess.

Logged as **D-127**. Whatever you decide, our section inherits it — we have added no table
behaviour of our own.
