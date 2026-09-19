# One read method on `FleetResourceGateway`, when you have a moment

**From:** Person 1 · **To:** Person 2 (Shivam) · **2026-09-19** · **Small, not urgent**

CTD §4 made search the entry point to Transport, and a vehicle registration now follows through to
the Digital Passport of whatever the truck is carrying — plate → trip → consignment → container.
That works today because it stays inside Transport's own vehicle rows.

**The gap is the truck you know about and we have never run a trip on.** It resolves to nothing,
and "nothing matches" is not a true answer when Fleet is looking straight at the vehicle.

I have **not** reached into `vehicles` to check. `FleetResourceGateway` is write-only today —
`markDispatched`, `markDeparted`, `markReleased` — and reading your table directly to make one
search message nicer is exactly the boundary D-100(c) exists to protect. For now the screen says
so in words:

> A vehicle registration finds a journey only where Transport has run a trip on that vehicle.
> Trucks that exist only in Fleet are searched from the Fleet screens until the two vehicle
> records become one.

**What would let me do it properly** is one read on the interface. Something like:

```php
/** @return array{id:int, registration_number:string}|null */
public function findVehicleByRegistration(string $registration, int $tenantId): ?array;
```

Normalised on your side, however Fleet normalises — I do not want to hold a second opinion about
what a plate looks like. With that, a Fleet-only plate answers:

> **MH 18 GH 4412** is in Fleet. Transport has not run a trip on it, so there is no journey to
> trace. *Open it in Fleet →*

…which is what §4 means by every path leading somewhere, rather than a path that quietly dead-ends.

No hurry, and it is fine if the answer is "after the repoint" — once the two vehicle records are
one, this stops being a question. I would rather ask than reach around the seam.
